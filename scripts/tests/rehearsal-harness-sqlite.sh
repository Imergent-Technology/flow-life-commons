#!/usr/bin/env bash
# A local check of the rehearsal's PHP harness (scripts/rehearsal/php/rehearse.php) against the REAL v0.3.0 application and the REAL current
# application, on SQLite, with the script's own comparison functions reading what the harness prints.
#
# It is NOT part of `./flow check repo`, and it does not replace the live validation: SQLite is not MariaDB (no information_schema, no
# mariadb-dump), so the dump, the clearing and the loading are simulated here by copying the database file. What it does show is the part the
# fake-daemon tests cannot: that the harness boots the historical application, loads the fixture, gives the administrator derived credentials,
# signs in over the application's real HTTP stack (password, TOTP whose secret needs the PREVIOUS key, /me), rolls that back, takes digests
# that the two application trees agree on, and that the shell comparisons (original, restored, upgraded) accept the right states and refuse
# the wrong ones.
#
# Needs: php with pdo_sqlite, composer (offline: only `dump-autoload`), git with the v0.3.0 commit, and apps/platform/vendor (a normal
# `composer install`). It starts no container and touches no rehearsal or development database: everything is in a temporary directory.
#
#   scripts/tests/rehearsal-harness-sqlite.sh
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
HISTORICAL=2f385b79a7b83323c6f15a6685ee610ccd85b5bf
for tool in php composer git; do
    command -v "$tool" >/dev/null 2>&1 || { echo "rehearsal-harness-sqlite: $tool is needed" >&2; exit 2; }
done
[[ -d "$ROOT/apps/platform/vendor" ]] || { echo "rehearsal-harness-sqlite: apps/platform/vendor is needed (composer install)" >&2; exit 2; }
git -C "$ROOT" cat-file -e "$HISTORICAL^{commit}" 2>/dev/null || { echo "rehearsal-harness-sqlite: the v0.3.0 commit $HISTORICAL is not in this clone" >&2; exit 2; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
FAILURES=0
check() { # DESC CONDITION-EXIT-STATUS
    if [[ "$2" == 0 ]]; then printf '  ok    %s\n' "$1"; else printf '  FAIL  %s\n' "$1" >&2; FAILURES=$((FAILURES + 1)); fi
}

# The layout the runner has: historical/, current/ (with tests/Support), harness/. The harness is the working tree's, the applications are commits.
mkdir -p "$T/src/historical" "$T/src/current" "$T/src/harness"
git -C "$ROOT" archive "$HISTORICAL" apps/platform | tar -x -C "$T/src/historical" --strip-components=2
git -C "$ROOT" archive HEAD apps/platform | tar -x -C "$T/src/current" --strip-components=2
cp "$ROOT"/scripts/rehearsal/php/*.php "$T/src/harness/"
for tree in historical current; do
    cp -r "$ROOT/apps/platform/vendor" "$T/src/$tree/vendor"
    mkdir -p "$T/src/$tree/storage/framework/cache" "$T/src/$tree/storage/framework/sessions" "$T/src/$tree/storage/framework/views" "$T/src/$tree/storage/logs" "$T/src/$tree/bootstrap/cache"
    (cd "$T/src/$tree" && composer dump-autoload --no-scripts -o >/dev/null 2>&1)
done

key() { printf 'base64:%s' "$(php -r 'echo base64_encode(random_bytes(32));')"; }
CURRENT_KEY="$(key)"
PREVIOUS_KEY="$(key)"
DB="$T/rehearsal.sqlite"
: >"$DB"
# php TREE ARGS...: the runner's environment, on SQLite, in that tree.
app() {
    local tree="$1"
    shift
    (cd "$T/src/$tree" && env -i PATH="$PATH" HOME="$T" APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
        DB_CONNECTION=sqlite DB_DATABASE="$DB" APP_KEY="${KEY_OVERRIDE:-$CURRENT_KEY}" APP_PREVIOUS_KEYS="${PREVIOUS_OVERRIDE-$PREVIOUS_KEY}" php "$@")
}

# The script's own functions, sourced (this defines them and runs nothing).
# shellcheck source=/dev/null
source "$ROOT/scripts/rehearsal/restore-rehearsal.sh"

echo "Harness against the real applications on SQLite"

app historical artisan migrate --force --no-interaction >/dev/null
FIXTURE="$(app historical ../harness/rehearse.php fixture-load)"
ORIGINAL="$(table_lines table "$FIXTURE")"
UPGRADE_EXPECTED="$(table_lines upgrade "$FIXTURE")"
STRUCTURE="$(structure_lines "$FIXTURE" "$(historical_inventory)")"
check 'the fixture loads in the v0.3.0 application and reports itself complete' "$(grep -Fxq 'fixture:complete=ok' <<<"$FIXTURE" && echo 0 || echo 1)"
check 'the application used the keys this check generated' "$([[ "$(sed -n 's/^key:current=//p' <<<"$FIXTURE")" == "$(key_fingerprint "$CURRENT_KEY")" && "$(sed -n 's/^key:previous=//p' <<<"$FIXTURE")" == "$(key_fingerprint "$PREVIOUS_KEY")" ]] && echo 0 || echo 1)"
check 'the fixture has 13 people and a durable record in every durable table' "$(fixture_problem "$ORIGINAL" >/dev/null && echo 0 || echo 1)"
SNAPSHOT="$(app historical ../harness/rehearse.php snapshot)"
check 'a snapshot straight after equals the fixture output' "$([[ -z "$(digest_differences "$ORIGINAL" "$(table_lines table "$SNAPSHOT")")" ]] && echo 0 || echo 1)"

# The dump, as a file copy: what a restore of the two-pass dump would give back (the transient tables empty).
cp "$DB" "$T/backup.sqlite"
for table in "${TRANSIENT_TABLES[@]}"; do sqlite3 "$T/backup.sqlite" "DELETE FROM $table" 2>/dev/null || php -r "(new PDO('sqlite:$T/backup.sqlite'))->exec('DELETE FROM $table');"; done

SIGNIN="$(app historical ../harness/rehearse.php signin)"
for line in signin:enrolment-needs-previous-key=ok signin:password=ok signin:second-factor=ok signin:me=ok signin:rolled-back=ok signin:complete=ok; do
    check "the restored v0.3.0 application's sign-in reports $line" "$(grep -Fxq "$line" <<<"$SIGNIN" && echo 0 || echo 1)"
done
check 'signing in changed nothing (the table digests after equal those before)' "$([[ -z "$(digest_differences "$ORIGINAL" "$(table_lines table "$SIGNIN")")" ]] && echo 0 || echo 1)"
check 'no secret appears in what the harness printed' "$([[ "$FIXTURE$SNAPSHOT$SIGNIN" != *"${CURRENT_KEY#base64:}"* && "$FIXTURE$SNAPSHOT$SIGNIN" != *"${PREVIOUS_KEY#base64:}"* ]] && echo 0 || echo 1)"

# The keyring is what makes the enrolment readable: without the previous key, the second factor fails (and still nothing changes).
if PREVIOUS_OVERRIDE="$(key)" app historical ../harness/rehearse.php signin >"$T/nokey.out" 2>"$T/nokey.err"; then rc=0; else rc=$?; fi
check 'without the previous key the sign-in is refused at the second factor' "$([[ "$rc" == 3 ]] && grep -q 'authenticator code was not accepted' "$T/nokey.err" && echo 0 || echo 1)"
if KEY_OVERRIDE="$(key)" app historical ../harness/rehearse.php signin >"$T/wrongkey.out" 2>"$T/wrongkey.err"; then rc=0; else rc=$?; fi
check 'under a different key the derived password is not accepted' "$([[ "$rc" == 3 ]] && grep -q 'password was not accepted' "$T/wrongkey.err" && echo 0 || echo 1)"
check 'and the failed attempts changed nothing' "$([[ -z "$(digest_differences "$ORIGINAL" "$(table_lines table "$(app historical ../harness/rehearse.php snapshot)")")" ]] && echo 0 || echo 1)"

# The upgrade.
app current artisan migrate --force --no-interaction >/dev/null
UPGRADED="$(app current ../harness/rehearse.php snapshot)"
check 'after the upgrade every v0.3.0 table is what HistoricalSnapshot::expected said (the two application trees agree on the digests)' "$([[ -z "$(digest_differences "$UPGRADE_EXPECTED" "$(table_lines table "$UPGRADED")")" ]] && echo 0 || echo 1)"
UPGRADED_STRUCTURE="$(structure_lines "$UPGRADED" "$(upgraded_inventory)")"
check 'the structure is reported for exactly the 34 tables' "$([[ "$(grep -c . <<<"$UPGRADED_STRUCTURE")" == 34 ]] && echo 0 || echo 1)"
check 'the upgrade changed the structure of the tables it should have (it is not the historical structure)' "$([[ -n "$(digest_differences "$STRUCTURE" "$UPGRADED_STRUCTURE")" ]] && echo 0 || echo 1)"
check 'the marker is written' "$(app current ../harness/rehearse.php mark | grep -Fxq 'marker:written=ok' && echo 0 || echo 1)"
check 'a second marker is refused (the table is no longer empty)' "$(app current ../harness/rehearse.php mark >/dev/null 2>&1 && echo 1 || echo 0)"
MARKED="$(app current ../harness/rehearse.php snapshot)"
check 'the marker is a difference: the people table no longer equals the upgrade expectation' "$([[ "$(digest_differences "$UPGRADE_EXPECTED" "$(table_lines table "$MARKED")")" == people ]] && echo 0 || echo 1)"

# The restore, simulated by putting the backup back; then the checks of restore-historical.
cp "$T/backup.sqlite" "$DB"
RESTORED="$(app historical ../harness/rehearse.php snapshot)"
RESTORED_TABLES="$(table_lines table "$RESTORED")"
check 'the restored database equals the ORIGINAL snapshot with the transient tables emptied' "$([[ -z "$(restore_state_differences "$ORIGINAL" "$RESTORED_TABLES")" ]] && echo 0 || echo 1)"
check 'and it is not the original: exactly the two tables that had fixture rows differ' "$([[ "$(digest_differences "$ORIGINAL" "$RESTORED_TABLES")" == $'password_reset_tokens\nsessions' ]] && echo 0 || echo 1)"
check 'the structure of the restored tables is the original structure' "$([[ -z "$(digest_differences "$STRUCTURE" "$(structure_lines "$RESTORED" "$(historical_inventory)")")" ]] && echo 0 || echo 1)"
SIGNIN_AGAIN="$(app historical ../harness/rehearse.php signin)"
check 'the restored application signs the administrator in, and nothing changes' "$(grep -Fxq 'signin:complete=ok' <<<"$SIGNIN_AGAIN" && [[ -z "$(restore_state_differences "$ORIGINAL" "$(table_lines table "$SIGNIN_AGAIN")")" ]] && echo 0 || echo 1)"

# The re-upgrade.
app current artisan migrate --force --no-interaction >/dev/null
REUPGRADED="$(app current ../harness/rehearse.php snapshot)"
check 'after the re-upgrade the tables are what the upgrade expects, with the transient tables empty' "$([[ -z "$(digest_differences "$(emptied_transients "$UPGRADE_EXPECTED")" "$(table_lines table "$REUPGRADED")")" ]] && echo 0 || echo 1)"
check 'and the structure of all 34 tables equals the structure after the first upgrade' "$([[ -z "$(digest_differences "$UPGRADED_STRUCTURE" "$(structure_lines "$REUPGRADED" "$(upgraded_inventory)")")" ]] && echo 0 || echo 1)"
check 'and the marker is gone (13 people)' "$([[ "$(awk '$1 == "people" { print $2 }' <<<"$(table_lines table "$REUPGRADED")")" == 13 ]] && echo 0 || echo 1)"

echo
if ((FAILURES > 0)); then
    printf '%d check(s) failed\n' "$FAILURES" >&2
    exit 1
fi
echo 'The harness agrees with the real applications on SQLite'
