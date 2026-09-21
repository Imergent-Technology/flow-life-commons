#!/usr/bin/env bash
# Behavioural tests for ./flow release. Run by `./flow check repo`.
#
# What is REAL here: git (each fixture is a genuine repository with tags and branches), the ./flow
# code under test, and the artifact validator (scripts/release/artifact.php runs in the project's PHP
# image, exactly as in a real build). What is STUBBED: `docker run` for Composer, npm and the cache
# commands, because those need the network and are what makes a real build a minute long. The stub
# fabricates their output and records every mount, so these tests can also prove WHICH tree was mounted.
#
# These tests prove the release INVARIANTS of the tooling. They do not prove anything about the
# production host: that evidence is the probe record in docs/runbooks/production-readiness.md.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
WORK="$(mktemp -d)"
STUB_DIR="$WORK/stub"
FX="$WORK/fixture"
export DOCKER_CALLS="$WORK/docker-calls"
FAILURES=0
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$STUB_DIR" "$WORK/tmp"
: >"$DOCKER_CALLS"

finish() {
    if ((FAILURES > 0)); then
        printf '\n%d release check(s) failed\n' "$FAILURES" >&2
        exit 1
    fi
    printf '\nAll release checks passed\n'
    exit 0
}

pass() { printf '  ok    %s\n' "$1"; }
fail() {
    printf '  FAIL  %s\n' "$1" >&2
    FAILURES=$((FAILURES + 1))
}

# --- The docker stub -------------------------------------------------------------------------------
# Steered by STUB_* environment variables. Everything it does not emulate goes to the real docker.
cat >"$STUB_DIR/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$DOCKER_CALLS"
real() { PATH="$REAL_PATH" exec docker "$@"; }
case "${1:-}" in
    compose | info) exit 0 ;;
    image) exit 0 ;;
    run) ;;
    *) real "$@" ;;
esac
orig=("$@")
shift
mounts=()
image=""
cmd=()
while (($# > 0)); do
    case "$1" in
        --rm) shift ;;
        --user | -e | -w | --network) shift 2 ;;
        -v) mounts+=("$2"); shift 2 ;;
        *) image="$1"; shift; cmd=("$@"); break ;;
    esac
done
mount_for() {
    local m dst
    for m in "${mounts[@]}"; do
        dst="${m#*:}"
        dst="${dst%%:*}"
        if [[ "$dst" == "$1" ]]; then printf '%s' "${m%%:*}"; return 0; fi
    done
    return 1
}
# The artifact validator is the real thing.
if [[ "${cmd[0]:-}" == php && "${cmd[1]:-}" == /release/artifact.php ]]; then real "${orig[@]}"; fi

printf 'MOUNTS %s\n' "${mounts[*]:-}" >>"$DOCKER_CALLS"
case "$image" in
    flowlife-dev/php:8.3)
        app="$(mount_for /var/www/platform)"
        case "${cmd[0]:-} ${cmd[1]:-}" in
            "composer validate") exit "${STUB_COMPOSER_VALIDATE_EXIT:-0}" ;;
            "composer install")
                [[ "${STUB_COMPOSER_INSTALL_EXIT:-0}" == 0 ]] || exit "$STUB_COMPOSER_INSTALL_EXIT"
                mkdir -p "$app/vendor/composer" "$app/vendor/pkg/bin" "$app/vendor/bin"
                echo '<?php // autoload' >"$app/vendor/autoload.php"
                printf '{"packages":[],"dev":false,"dev-package-names":[]}' >"$app/vendor/composer/installed.json"
                echo '#!/usr/bin/env php' >"$app/vendor/pkg/bin/tool"
                ln -s ../pkg/bin/tool "$app/vendor/bin/tool"
                if [[ -n "${STUB_VENDOR_ENV:-}" ]]; then echo SECRET=1 >"$app/vendor/pkg/.env"; fi
                exit 0
                ;;
            "php artisan")
                [[ "${STUB_CACHE_EXIT:-0}" == 0 ]] || { echo "stub: ${cmd[2]} failed" >&2; exit "$STUB_CACHE_EXIT"; }
                mkdir -p "$app/bootstrap/cache"
                : >"$app/bootstrap/cache/config.php"
                : >"$app/bootstrap/cache/events.php"
                : >"$app/bootstrap/cache/routes-v7.php"
                exit 0
                ;;
        esac
        echo "docker stub: unexpected php command: ${cmd[*]}" >&2
        exit 99
        ;;
    node:*)
        app="$(mount_for /app)"
        case "${cmd[0]:-} ${cmd[1]:-} ${cmd[2]:-}" in
            "npm ci "*) mkdir -p "$app/node_modules"; exit 0 ;;
            "npm run build")
                [[ "${STUB_NPM_BUILD_EXIT:-0}" == 0 ]] || exit "$STUB_NPM_BUILD_EXIT"
                mkdir -p "$app/dist/assets"
                echo '<!doctype html><title>Console</title>' >"$app/dist/index.html"
                echo 'body{}' >"$app/dist/assets/index-abc123.css"
                if [[ -n "${STUB_DIST_COLLIDE:-}" ]]; then echo evil >"$app/dist/index.php"; fi
                exit 0
                ;;
            "npm run verify:build") exit 0 ;;
        esac
        echo "docker stub: unexpected node command: ${cmd[*]}" >&2
        exit 99
        ;;
esac
echo "docker stub: unexpected image '$image'" >&2
exit 99
STUB
chmod +x "$STUB_DIR/docker"

# --- The fixture: a repository that is its own flow root ---------------------------------------------

g() { git -c user.name=Fixture -c user.email=fixture@example.invalid -c commit.gpgsign=false -c tag.gpgsign=false "$@"; }

write_migration() { # DIR NAME UP_BODY
    mkdir -p "$1"
    cat >"$1/$2" <<PHP
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
$3
    }

    public function down(): void
    {
        Schema::dropIfExists('things');
    }
};
PHP
}

make_fixture() {
    local d="$1" m="$1/apps/platform/database/migrations"
    mkdir -p "$d"
    cp -a "$ROOT/flow" "$d/flow"
    mkdir -p "$d/scripts"
    cp -a "$ROOT/scripts/lib" "$ROOT/scripts/commands" "$ROOT/scripts/release" "$d/scripts/"
    printf 'dist/\n' >"$d/.gitignore"
    (
        cd "$d"
        git init -q -b main
        local p=apps/platform
        mkdir -p $p/app $p/bootstrap/cache $p/config $p/routes $p/public $p/tests $p/openapi \
            $p/storage/logs $p/storage/framework/cache $p/storage/app apps/guardian-console
        echo '<?php // artisan' >$p/artisan
        echo '{"name":"fixture/platform"}' >$p/composer.json
        echo '{"content-hash":"fixture-lock-1"}' >$p/composer.lock
        echo '<?php // Example' >$p/app/Example.php
        echo '<?php // app' >$p/bootstrap/app.php
        echo '<?php return [];' >$p/bootstrap/providers.php
        echo '<?php return [];' >$p/config/app.php
        echo '<?php // routes' >$p/routes/api.php
        echo '<?php // front controller' >$p/public/index.php
        # The REAL composed public surface, not a stub: the validator judges these files, so the
        # fixture has to carry the ones production will actually serve.
        cp "$ROOT/apps/platform/public/.htaccess" $p/public/.htaccess
        cp "$ROOT/apps/platform/public/maintenance.php" $p/public/maintenance.php
        echo '<?php // test' >$p/tests/ExampleTest.php
        echo 'openapi: 3.1.0' >$p/openapi/openapi.yaml
        echo 'APP_DEBUG=true' >$p/.env.example
        echo '<phpunit/>' >$p/phpunit.xml
        for skel in bootstrap/cache storage/logs storage/framework/cache storage/app; do : >$p/$skel/.gitignore; done
        echo '{"name":"console"}' >apps/guardian-console/package.json
        echo '{"lockfileVersion":3}' >apps/guardian-console/package-lock.json
        write_migration "$m" 2026_01_01_000001_create_things_table.php "        Schema::create('things', function (\$table) { \$table->id(); });"
        g add -A
        g commit -q -m 'feat: first release content'
        g tag -a v1.0.0 -m 'release 1.0.0'

        write_migration "$m" 2026_02_01_000001_add_color_to_things.php "        Schema::table('things', function (\$table) { \$table->timestamp('changed_at')->nullable(); });"
        g add -A
        g commit -q -m 'feat: add a nullable column'
        g tag -a v1.1.0 -m 'release 1.1.0'
        g tag light-1.1.0

        write_migration "$m" 2026_03_01_000001_drop_legacy_from_things.php "        Schema::table('things', function (\$table) { \$table->dropColumn('legacy'); });"
        g add -A
        g commit -q -m 'feat: drop a column'
        g tag -a v1.2.0 -m 'release 1.2.0'

        # Off main: an annotated tag that is NOT reachable from main.
        g switch -q -c feature
        echo '<?php // feature' >$p/app/Feature.php
        g add -A
        g commit -q -m 'feat: unmerged work'
        g tag -a v9.0.0 -m 'off-main tag'

        # Migration history edited: one applied migration modified, one removed.
        g switch -q -c edited v1.1.0
        write_migration "$m" 2026_01_01_000001_create_things_table.php "        Schema::create('things', function (\$table) { \$table->id(); \$table->string('name'); });"
        g rm -q "$m/2026_02_01_000001_add_color_to_things.php"
        g add -A
        g commit -q -m 'chore: rewrite history'

        # Views appear: the approved cache sequence must be reconsidered.
        g switch -q -c views v1.1.0
        mkdir -p $p/resources/views
        echo '<p>hi</p>' >$p/resources/views/welcome.blade.php
        g add -A
        g commit -q -m 'feat: a blade view'

        g switch -q main
    )
}

# --- Running the CLI under test ------------------------------------------------------------------------

printf 'Global gitconfig for the fixture: a named classifier, no ambient identity.\n' >/dev/null
printf '[user]\n\tname = Release Tester\n\temail = tester@example.invalid\n' >"$WORK/gitconfig"

# flow_run ARGS...: run the fixture's ./flow; sets RC and OUT (stdout+stderr).
flow_run() {
    RC=0
    OUT="$(cd "$FX" && env PATH="$STUB_DIR:$PATH" REAL_PATH="$PATH" TMPDIR="$WORK/tmp" \
        GIT_CONFIG_GLOBAL="${TEST_GITCONFIG:-$WORK/gitconfig}" GIT_CONFIG_NOSYSTEM=1 \
        SOURCE_DATE_EPOCH="${TEST_EPOCH:-1790000000}" "$FX/flow" "$@" 2>&1)" || RC=$?
}

expect_rc() { # DESC EXPECTED
    if [[ "$RC" == "$2" ]]; then pass "$1"; else
        fail "$1 (expected exit $2, got $RC)"
        printf '        output: %s\n' "$(printf '%s' "$OUT" | tail -5 | tr '\n' '|')" >&2
    fi
}
expect_out() { # DESC PATTERN
    if [[ "$OUT" == *"$2"* ]]; then pass "$1"; else
        fail "$1 (output did not contain: $2)"
        printf '        output: %s\n' "$(printf '%s' "$OUT" | tail -5 | tr '\n' '|')" >&2
    fi
}
expect_no_out() { # DESC PATTERN
    if [[ "$OUT" != *"$2"* ]]; then pass "$1"; else fail "$1 (output unexpectedly contained: $2)"; fi
}
expect_true() { # DESC COMMAND...
    local desc="$1"
    shift
    if "$@"; then pass "$desc"; else fail "$desc"; fi
}
expect_false() {
    local desc="$1"
    shift
    if "$@"; then fail "$desc"; else pass "$desc"; fi
}

# Both are passed BY NAME to expect_true/expect_false, which is why shellcheck cannot see them called.
# shellcheck disable=SC2329
no_docker_runs() { ! grep -q '^run ' "$DOCKER_CALLS"; }
# shellcheck disable=SC2329
fixture_clean() {
    [[ "$(git -C "$FX" worktree list | wc -l)" == 1 ]] && [[ -z "$(ls -A "$WORK/tmp")" ]]
}

printf 'flow release: fixture repository\n'
make_fixture "$FX"
pass "fixture built (main, annotated tags, an off-main tag, a lightweight tag, edited and views branches)"

printf 'flow release: the boundary\n'
flow_run help
expect_out "help documents ./flow release" "release build --ref REF"
flow_run release
expect_rc "a bare 'release' exits 2" 2
flow_run release --help
expect_rc "'release --help' exits cleanly" 0
for forbidden in deploy backup restore rollback; do
    flow_run release "$forbidden"
    expect_rc "there is no 'release $forbidden' (ADR 0027: it stays a runbook action)" 2
done
flow_run "$forbidden"
expect_rc "there is no top-level '$forbidden' either" 2
# The tooling must be unable to reach production: no remote-access or transfer tools, and none of the
# steps the host probes showed to be unnecessary.
if grep -nE '\b(ssh|scp|sftp|rsync|curl|wget|nc|ftp)\b' "$ROOT/scripts/lib/release.sh" "$ROOT/scripts/commands/release.sh" | grep -v '^\S*:[0-9]*:\s*#' >"$WORK/hits"; then
    fail "release tooling references a remote-access or transfer tool: $(head -3 "$WORK/hits" | tr '\n' '|')"
else
    pass "the release tooling references no remote-access or transfer tool"
fi
if grep -nE 'opcache|opcache_reset|\bsleep\b|ErrorDocument|LSCache|sucuri' "$ROOT/scripts/lib/release.sh" "$ROOT/scripts/commands/release.sh" >"$WORK/hits"; then
    fail "release tooling contains a step the host verification ruled out: $(head -3 "$WORK/hits" | tr '\n' '|')"
else
    pass "no opcache reset, sleep, ErrorDocument or cache-purge step exists"
fi
# The build images are duplicated from compose.yaml on purpose (a build must not need the compose
# project); this is the drift check that makes the duplication safe.
php_image="$(grep -E '^RELEASE_PHP_IMAGE=' "$ROOT/scripts/lib/release.sh" | cut -d'"' -f2)"
node_image="$(grep -E '^RELEASE_NODE_IMAGE=' "$ROOT/scripts/lib/release.sh" | cut -d'"' -f2)"
expect_true "the PHP build image is the one compose.yaml runs ($php_image)" grep -qF "image: $php_image" "$ROOT/compose.yaml"
expect_true "the Node build image is the one compose.yaml runs ($node_image)" grep -qF "image: $node_image" "$ROOT/compose.yaml"
expect_true "release output (dist/) is gitignored, so an artifact cannot be committed by accident" \
    git -C "$ROOT" check-ignore -q dist/releases/commons-x.tar.gz

printf 'flow release: required inputs and provenance (dry run; no containers)\n'
: >"$DOCKER_CALLS"
flow_run release build --ref v1.1.0 --schema-rollback code-only --dry-run
expect_rc "--previous is required" 1
expect_out "…and the refusal says why the build never guesses it" "never guesses"
flow_run release build --previous v1.0.0 --schema-rollback code-only --dry-run
expect_rc "--ref is required" 1
flow_run release build --ref v1.1.0 --previous v1.0.0 --dry-run
expect_rc "--schema-rollback is required" 1
flow_run release build --ref nope --previous none --schema-rollback restore-required --dry-run
expect_rc "an unresolvable --ref is refused" 1
flow_run release build --ref v1.1.0 --previous nope --schema-rollback code-only --dry-run
expect_rc "an unresolvable --previous is refused" 1
flow_run release build --ref --oops --previous none --schema-rollback restore-required --dry-run
expect_rc "an option-shaped --ref is refused" 1
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --bogus --dry-run
expect_rc "an unknown option is refused" 1

flow_run release build --ref feature --previous v1.2.0 --schema-rollback code-only --dry-run
expect_rc "a branch is refused without --allow-untagged" 1
expect_out "…because it is not an annotated tag" "not an annotated tag"
flow_run release build --ref light-1.1.0 --previous v1.0.0 --schema-rollback code-only --dry-run
expect_rc "a lightweight tag is refused" 1
expect_out "…as not annotated" "not an annotated tag"
expect_no_out "…while being reachable from main is not the complaint" "not reachable from main"
flow_run release build --ref v9.0.0 --previous v1.2.0 --schema-rollback not-applicable --dry-run
expect_rc "an annotated tag off main is refused" 1
expect_out "…as not reachable from main" "not reachable from main"
flow_run release build --ref v9.0.0 --previous v1.2.0 --schema-rollback not-applicable --allow-untagged --dry-run
expect_rc "--allow-untagged lets a rehearsal through" 0
expect_out "…loudly" "--allow-untagged"
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --dry-run
expect_rc "an annotated tag on main passes with no override" 0
expect_out "a dry run says nothing was built" "Nothing was built"
expect_true "a dry run starts no container" no_docker_runs
expect_true "…and leaves nothing behind" fixture_clean
flow_run release build --ref=v1.1.0 --previous=v1.0.0 --schema-rollback=code-only --dry-run
expect_rc "--opt=value is accepted as well as --opt value" 0

flow_run release build --ref views --previous v1.1.0 --schema-rollback not-applicable --allow-untagged --dry-run
expect_rc "resources/views appearing stops the build" 1
expect_out "…and names the ADR question it reopens" "view:cache"

printf 'flow release: the classification is a human decision, and the tooling only refuses\n'
flow_run release build --ref v1.0.0 --previous none --schema-rollback code-only --dry-run
expect_rc "first release: code-only is refused" 1
expect_out "…because there is no earlier code" "no earlier code"
flow_run release build --ref v1.0.0 --previous none --schema-rollback not-applicable --dry-run
expect_rc "first release: not-applicable is refused" 1
flow_run release build --ref v1.0.0 --previous none --schema-rollback restore-required --dry-run
expect_rc "first release: restore-required is the classification" 0
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback not-applicable --dry-run
expect_rc "not-applicable is refused when a migration is new" 1
flow_run release build --ref v1.1.0 --previous v1.1.0 --schema-rollback code-only --dry-run
expect_rc "code-only is refused when nothing is new" 1
flow_run release build --ref v1.1.0 --previous v1.1.0 --schema-rollback restore-required --dry-run
expect_rc "restore-required is refused when nothing is new" 1
flow_run release build --ref v1.1.0 --previous v1.1.0 --schema-rollback not-applicable --dry-run
expect_rc "not-applicable holds exactly when nothing is new" 0
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback maybe --dry-run
expect_rc "a value outside the three is refused" 1

# v1.2.0 adds a migration whose up() calls dropColumn: the scanner has a finding.
flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback code-only --dry-run
expect_rc "scanner findings + code-only needs an acknowledgement" 1
expect_out "…and shows the finding" "dropColumn"
expect_out "…and names the flag" "--acknowledge-scanner-findings"
flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback code-only --acknowledge-scanner-findings --dry-run
expect_rc "…which lets the human's judgement stand" 0
flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --dry-run
expect_rc "restore-required needs no acknowledgement despite findings" 0
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback restore-required --dry-run
expect_rc "restore-required despite a clean scan needs no acknowledgement (the NOT NULL case)" 0
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --dry-run
expect_rc "a clean scan + code-only needs none either" 0
expect_no_out "…and a column named changed_at is not mistaken for CHANGE" "changed_at"
# An applied migration that was edited or deleted is a finding too.
flow_run release build --ref edited --previous v1.1.0 --schema-rollback not-applicable --allow-untagged --dry-run
expect_rc "an edited or removed applied migration needs acknowledgement" 1
expect_out "…reporting the modified migration" "modified"
expect_out "…and the removed one" "removed"

printf 'flow release: who classified\n'
TEST_GITCONFIG=/dev/null flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --dry-run
expect_rc "with no git identity and no --classified-by the build refuses" 1
expect_out "…and says how to supply it" "--classified-by"
TEST_GITCONFIG=/dev/null flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --classified-by 'Ada <ada@example.invalid>' --dry-run
expect_rc "--classified-by supplies it explicitly" 0
expect_out "…and is what gets recorded" "by Ada <ada@example.invalid>"
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --dry-run
expect_out "the default is the git identity" "by Release Tester <tester@example.invalid>"
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --classified-by $'Eve\x01' --dry-run
expect_rc "a control character in --classified-by is refused" 1

printf 'flow release migrations\n'
: >"$DOCKER_CALLS"
flow_run release migrations --ref v1.2.0 --previous v1.1.0
expect_rc "lists a comparison" 0
expect_out "…the new migration" "new       2026_03_01_000001_drop_legacy_from_things.php"
expect_out "…the scanner's finding, with its line" "2026_03_01_000001_drop_legacy_from_things.php:"
expect_out "…and says the scanner proves nothing" "red-flag generator"
flow_run release migrations --ref v1.0.0
expect_out "with no --previous every migration is shown as new" "no --previous given"
expect_no_out "down() is never scanned: a create migration's dropIfExists is not a finding" "dropIfExists"
expect_no_out "…so the first migration has no scanner finding" "scanner: 1 finding"
flow_run release migrations --ref edited --previous v1.1.0
expect_out "a modified applied migration is reported" "modified  2026_01_01_000001_create_things_table.php"
expect_out "a removed one is reported" "removed   2026_02_01_000001_add_color_to_things.php"
flow_run release migrations --ref nope
expect_rc "an unresolvable ref is refused" 1
expect_true "listing migrations starts no container" no_docker_runs

printf 'flow release build: a tagged build\n'
# Uncommitted state in the WORKING TREE must never reach an artifact: only the commit does.
echo 'APP_KEY=base64:SECRETSECRETSECRET' >"$FX/apps/platform/.env"
echo 'leaked log line' >"$FX/apps/platform/storage/logs/laravel.log"
echo '<?php // UNCOMMITTED EDIT' >>"$FX/apps/platform/app/Example.php"
: >"$DOCKER_CALLS"
OUTDIR="$WORK/out"
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --out "$OUTDIR"
expect_rc "a tagged build succeeds" 0
ART="$OUTDIR/commons-v1.1.0.tar.gz"
expect_true "the artifact is named after the tag" test -f "$ART"
expect_true "…with a checksum file that verifies" bash -c "cd '$OUTDIR' && sha256sum --check --status commons-v1.1.0.tar.gz.sha256"
expect_out "the build validated what it packaged" "VALID"
expect_out "…and reports the release id built from the UTC time and short sha" "20260921T141320-"
expect_true "the release id carries the commit's short sha" bash -c "tar -xzOf '$ART' ./release.json | grep -q '\"release_id\": \"20260921T141320-$(git -C "$FX" rev-parse --short=7 "v1.1.0^{commit}")\"'"
expect_true "no partial files are left in the output directory" bash -c "[[ \$(ls -A '$OUTDIR' | wc -l) == 2 ]]"
expect_true "the worktree and every temp directory are gone" fixture_clean

# Isolation: every path handed to a container is inside the temporary worktree, never the developer's tree.
if grep '^MOUNTS' "$DOCKER_CALLS" | grep -qF "$FX"; then
    fail "a container mounted the developer's working tree"
else
    pass "no container ever mounted the developer's working tree"
fi
expect_true "containers mounted the isolated worktree (under the temp dir)" bash -c "grep '^MOUNTS' '$DOCKER_CALLS' | grep -qF '$WORK/tmp/flow-release.'"
expect_true "the cache commands ran with no network" bash -c "grep -E '^run .*--network none .* php artisan config:cache' '$DOCKER_CALLS' | grep -q ."
expect_false "'php artisan optimize' is never run (ADR 0027)" grep -qE 'artisan optimize' "$DOCKER_CALLS"
expect_true "the three approved cache commands all ran" bash -c "for c in config:cache event:cache route:cache; do grep -q \"php artisan \$c\" '$DOCKER_CALLS' || exit 1; done"

# Nothing below makes sense without an artifact: stop cleanly rather than cascade.
if [[ ! -f "$ART" ]]; then
    fail "no artifact was built; the remaining checks are skipped"
    finish
fi

TREE="$WORK/tree"
mkdir -p "$TREE"
tar -xzf "$ART" -C "$TREE"
expect_false "the working tree's .env is not in the artifact" test -e "$TREE/.env"
expect_false "…nor in the platform tree" bash -c "find '$TREE' -name '.env*' | grep -q ."
expect_false "the working tree's log is not in the artifact" bash -c "find '$TREE' -name '*.log' | grep -q ."
expect_false "an uncommitted edit is not in the artifact (the commit is)" grep -q UNCOMMITTED "$TREE/app/Example.php"
expect_false "tests/ is not shipped" test -e "$TREE/tests"
expect_false "openapi/ is not shipped" test -e "$TREE/openapi"
expect_false ".env.example is not shipped (it is a development file)" test -e "$TREE/.env.example"
expect_false "phpunit.xml is not shipped" test -e "$TREE/phpunit.xml"
expect_true "the platform code is" test -f "$TREE/app/Example.php"
expect_true "the Console build is merged into public/" test -f "$TREE/public/index.html"
expect_true "…with its assets" test -f "$TREE/public/assets/index-abc123.css"
expect_true "Laravel's own front controller is untouched" grep -q 'front controller' "$TREE/public/index.php"
expect_false "bootstrap/cache holds nothing but its placeholder" bash -c "find '$TREE/bootstrap/cache' -type f ! -name .gitignore | grep -q ."
expect_false "no cache the smoke check built is shipped" test -e "$TREE/bootstrap/cache/config.php"
# The runbook seeds shared/storage from this skeleton on first deployment (`cp -an storage/. ...`),
# so whatever ships here becomes PERSISTENT state on the host. That makes the skeleton's contents a
# security boundary rather than packaging trivia: only inert placeholders and empty directories.
expect_false "nothing but .gitignore placeholders ships in the storage skeleton" bash -c "find '$TREE/storage' -type f ! -name .gitignore | grep -q ."
expect_false "…and no symlink, which the seeding copy would follow out of the artifact" bash -c "find '$TREE/storage' '$TREE/bootstrap/cache' -type l | grep -q ."
expect_false "…nor anything but placeholders in bootstrap/cache" bash -c "find '$TREE/bootstrap/cache' -type f ! -name .gitignore | grep -q ."
expect_true "the document root carries the maintenance responder" test -f "$TREE/public/maintenance.php"
expect_true "…and the composed .htaccess, with its maintenance arm" grep -q 'storage/framework/down' "$TREE/public/.htaccess"
expect_true "…its API carve-out" grep -q 'index.php \[L\]' "$TREE/public/.htaccess"
expect_true "…its SPA fallback" grep -q '/index.html \[L\]' "$TREE/public/.htaccess"
expect_true "…and its private-path denials" grep -q '\[F,L\]' "$TREE/public/.htaccess"
expect_true "release.json records the classification" grep -q '"value": "code-only"' "$TREE/release.json"
expect_true "…and who supplied it" grep -q '"classified_by": "Release Tester <tester@example.invalid>"' "$TREE/release.json"
expect_true "…and the previous release as a full sha" grep -qE '"previous": \{"ref": "v1.0.0", "commit": "[0-9a-f]{40}"\}' "$TREE/release.json"
expect_true "…and the new migration" grep -q '"new": \["2026_02_01_000001_add_color_to_things.php"\]' "$TREE/release.json"
expect_true "…and the recorded lockfile digest" grep -q "\"composer.lock\": \"$(sha256sum "$TREE/composer.lock" | cut -c1-64)\"" "$TREE/release.json"
expect_true "…and the acknowledgement state" grep -q '"acknowledgement": {"required": false, "provided": false}' "$TREE/release.json"
expect_true "…and that provenance was met" grep -q '"provenance_override": false' "$TREE/release.json"

printf 'flow release build: refusals, determinism, and preserving what already exists\n'
BEFORE="$(sha256sum "$ART" | cut -c1-64)"
flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --out "$OUTDIR"
expect_rc "an existing artifact is never overwritten" 1
expect_out "…and the refusal says so" "already exists"
expect_true "…and it is untouched" bash -c "[[ \$(sha256sum '$ART' | cut -c1-64) == '$BEFORE' ]]"

flow_run release build --ref v1.1.0 --previous v1.0.0 --schema-rollback code-only --out "$WORK/out-again"
expect_true "the same ref and time build to identical bytes" bash -c "[[ \$(sha256sum '$WORK/out-again/commons-v1.1.0.tar.gz' | cut -c1-64) == '$BEFORE' ]]"

flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback code-only --acknowledge-scanner-findings --out "$WORK/out-ack"
expect_rc "an acknowledged build succeeds" 0
expect_true "…and records the finding" bash -c "tar -xzOf '$WORK/out-ack/commons-v1.2.0.tar.gz' ./release.json | grep -q '\"kind\": \"keyword\"'"
expect_true "…and that acknowledgement was required and provided" bash -c "tar -xzOf '$WORK/out-ack/commons-v1.2.0.tar.gz' ./release.json | grep -q '\"acknowledgement\": {\"required\": true, \"provided\": true}'"

flow_run release build --ref v9.0.0 --previous v1.2.0 --schema-rollback not-applicable --allow-untagged --out "$WORK/out-override"
expect_rc "an --allow-untagged build succeeds" 0
OVERRIDE="$(ls "$WORK"/out-override/*.tar.gz)"
expect_true "…but is named by release id, never by version" bash -c "[[ '$OVERRIDE' == */commons-2026*-*.tar.gz ]]"
expect_true "…and is stamped as an override" bash -c "tar -xzOf '$OVERRIDE' ./release.json | grep -q '\"provenance_override\": true'"
expect_out "…and the build says it is not a release" "not a release"

# A failed build must leave the output directory exactly as it found it, and leak nothing.
LISTING="$(ls -A "$OUTDIR")"
STUB_COMPOSER_INSTALL_EXIT=1 flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --out "$OUTDIR"
expect_rc "a failing composer install fails the build" 1
expect_true "…leaving the previous artifacts exactly as they were" bash -c "[[ \$(ls -A '$OUTDIR') == '$LISTING' ]]"
expect_true "…and the earlier artifact still verifies" bash -c "cd '$OUTDIR' && sha256sum --check --status commons-v1.1.0.tar.gz.sha256"
expect_true "…and the worktree and temp directories are cleaned up" fixture_clean
STUB_COMPOSER_VALIDATE_EXIT=1 flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --out "$OUTDIR"
expect_rc "a composer.json/lock disagreement fails the build" 1
expect_out "…naming the lockfile" "lockfile"
STUB_NPM_BUILD_EXIT=1 flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --out "$OUTDIR"
expect_rc "a failing Console build fails the build" 1
STUB_CACHE_EXIT=1 flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --out "$OUTDIR"
expect_rc "a failing cache command fails the build" 1
expect_out "…naming the command" "config:cache"
STUB_DIST_COLLIDE=1 flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --out "$OUTDIR"
expect_rc "a Console file colliding with a platform file fails the build" 1
expect_out "…refusing to overwrite" "Refusing to overwrite"
STUB_VENDOR_ENV=1 flow_run release build --ref v1.2.0 --previous v1.1.0 --schema-rollback restore-required --out "$OUTDIR"
expect_rc "an artifact that fails its own validation is never published" 1
expect_out "…and the build says so" "failed its own validation"
expect_true "…so the output directory is still exactly as it was" bash -c "[[ \$(ls -A '$OUTDIR') == '$LISTING' ]]"
expect_true "…and nothing is leaked after any failure" fixture_clean

printf 'flow release inspect: a good artifact\n'
: >"$DOCKER_CALLS"
flow_run release inspect "$ART"
expect_rc "a good artifact is valid" 0
expect_out "…its checksum is verified first" "checksum matches"
expect_out "…the classification is shown" "schema_rollback  code-only"
expect_out "…and who supplied it" "classified by    Release Tester <tester@example.invalid>"
expect_out "…and the previous release" "previous release v1.0.0 @"
expect_no_out "…and no longer calls the artifact rehearsal-only" "NOT DEPLOYABLE"
expect_no_out "…without an untagged warning when provenance was met" "BUILT WITH --allow-untagged"
flow_run release inspect "$OVERRIDE"
expect_out "an --allow-untagged artifact is flagged by inspect" "BUILT WITH --allow-untagged"
flow_run release inspect "$WORK/does-not-exist.tar.gz"
expect_rc "a missing artifact is refused" 1

printf 'flow release inspect: malformed artifacts are refused\n'
# The checksum is regenerated after each mutation, so it is the VALIDATOR that has to refuse.
mutate() { # NAME SNIPPET   (snippet runs at the root of the extracted tree)
    local name="$1" snippet="$2" dir="$WORK/mut-$1"
    rm -rf "$dir"
    mkdir -p "$dir/tree"
    tar -xzf "$ART" -C "$dir/tree" --same-permissions
    (cd "$dir/tree" && eval "$snippet")
    tar --sort=name -czf "$dir/$name.tar.gz" -C "$dir/tree" .
    (cd "$dir" && sha256sum "$name.tar.gz" >"$name.tar.gz.sha256")
    MUTANT="$dir/$name.tar.gz"
}
refuses() { # DESC NAME SNIPPET EXPECTED_TEXT
    mutate "$2" "$3"
    flow_run release inspect "$MUTANT"
    if [[ "$RC" == 1 && "$OUT" == *"$4"* ]]; then pass "$1"; else
        fail "$1 (exit $RC; wanted output containing: $4)"
        printf '        output: %s\n' "$(printf '%s' "$OUT" | tail -4 | tr '\n' '|')" >&2
    fi
}
refuses "an .env at the root" dotenv 'echo SECRET=1 >.env' "unexpected top-level entry '.env'"
refuses "an .env in public/" pubenv 'echo SECRET=1 >public/.env' "environment file"
refuses "a top-level tests/ directory" tests 'mkdir tests' "unexpected top-level entry 'tests'"
refuses "a nested tests/ directory outside vendor" nested 'mkdir app/tests' "forbidden directory: app/tests"
refuses "node_modules" nodemods 'mkdir app/node_modules' "forbidden directory"
refuses "a log file in storage/" log 'echo x >storage/logs/laravel.log' "runtime state in the shipped skeleton"
refuses "populated storage/framework" sess 'echo x >storage/framework/cache/abc' "runtime state in the shipped skeleton"
refuses "a cached config in bootstrap/cache" cache 'echo "<?php" >bootstrap/cache/config.php' "runtime state in the shipped skeleton"
refuses "a database dump" dump 'echo x >database/backup.sql' "runtime state or a backup"
refuses "key material" pem 'echo x >config/server.pem' "key material"
refuses "a .git directory anywhere, even in vendor" git 'mkdir -p vendor/pkg/.git' "forbidden directory: vendor/pkg/.git"
refuses "an .env inside vendor" vendorenv 'mkdir -p vendor/pkg && echo x >vendor/pkg/.env' "environment file inside vendor"
refuses "an unlisted top-level file" stray 'echo hi >notes.txt' "unexpected top-level entry 'notes.txt'"
refuses "a symlink outside vendor" link 'ln -s /etc/passwd app/link' "symlink outside vendor/"
refuses "the storage link shipped instead of created on the host" storlink 'rm -rf storage && ln -s ../../shared/storage storage' "symlink outside vendor/"
refuses "a vendor symlink that escapes the artifact" escape 'ln -s ../../../../../../etc vendor/escape' "escapes the artifact"
refuses "an absolute vendor symlink" abslink 'ln -s /etc vendor/abs' "absolute"
refuses "a world-writable file" ww 'chmod o+w app/Example.php' "world-writable"
refuses "a Vite dev-server marker file" hot 'echo http://localhost:5173 >public/hot' "public/hot"
refuses "a Console shell pointing at the dev server" devhtml 'echo "<script src=\"/@vite/client\"></script>" >>public/index.html' "Vite dev server"
refuses "an empty assets directory" noassets 'rm -rf public/assets/* ' "public/assets is empty"
refuses "development packages installed" devpkgs 'printf "{\"packages\":[],\"dev\":true,\"dev-package-names\":[\"pestphp/pest\"]}" >vendor/composer/installed.json' "development packages are installed"
refuses "a missing manifest" nomanifest 'rm release.json' "required file missing: release.json"
refuses "a manifest that is not JSON" badjson 'echo "{" >release.json' "not valid JSON"
refuses "an unknown classification" badclass "sed -i 's/\"value\": \"code-only\"/\"value\": \"yolo\"/' release.json" "schema_rollback.value must be one of"
refuses "not-applicable when a migration is new" mismatch "sed -i 's/\"value\": \"code-only\"/\"value\": \"not-applicable\"/' release.json" "not-applicable is valid exactly when"
refuses "a manifest whose lockfile digest disagrees with the artifact" lockdrift 'echo "{}" >composer.lock' "does not match the composer.lock"
refuses "a migration list that disagrees with the directory" miglist 'touch database/migrations/2099_01_01_000001_sneaked_in.php' "migrations.all does not match"
refuses "a hand-edited acknowledgement flag" ackflip "sed -i 's/\"required\": false/\"required\": true/' release.json" "acknowledgement.required disagrees"
refuses "a provenance override claimed although provenance was met" ovr "sed -i 's/\"provenance_override\": false/\"provenance_override\": true/' release.json" "although the provenance requirements were met"
refuses "a release id that does not match its commit" idmismatch "sed -i 's/\"release_id\": \"\\([0-9T]*\\)-[0-9a-f]*\"/\"release_id\": \"\\1-0000000\"/' release.json" "does not end in the short sha"

printf 'flow release inspect: the composed production surface is required\n'
# Work package 1 shipped rehearsal-only artifacts and said so in a note. That ends here: an artifact
# without the production public surface is INVALID, not merely flagged.
refuses "a missing maintenance responder" noresponder 'rm public/maintenance.php' "required file missing: public/maintenance.php"
refuses "a missing .htaccess" nohtaccess 'rm public/.htaccess' "required file missing: public/.htaccess"
refuses "an .htaccess with no maintenance arm" nomaint \
    "sed -i '/storage.framework.down/d' public/.htaccess" \
    "the maintenance arm must read"
refuses "an .htaccess with no SPA fallback" nofallback \
    "sed -i '/index.html .L./d' public/.htaccess" \
    "client-side routes must fall back"
refuses "an .htaccess with no private-path denials" nodeny \
    "sed -i '/.F,L./d' public/.htaccess" \
    "private paths must be denied"
refuses "an .htaccess whose maintenance rule would swallow the API" noexclude \
    "sed -i '/REQUEST_URI. !/d' public/.htaccess" \
    "must be excluded from the maintenance rewrite"
refuses "the ErrorDocument mechanism that failed on this host" errordoc \
    "printf 'ErrorDocument 503 /maintenance.html\\n' >>public/.htaccess" \
    "forbidden mechanism 'ErrorDocument'"
refuses "an external maintenance redirect" r503 \
    "printf 'RewriteRule ^ - [R=503,L]\\n' >>public/.htaccess" \
    "forbidden mechanism 'R=503'"
refuses "a legacy static maintenance page" mhtml \
    "printf 'ErrorDoc\\n' >/dev/null; printf 'RewriteRule ^ /maintenance.html [L]\\n' >>public/.htaccess" \
    "forbidden mechanism 'maintenance.html'"
refuses "a cache-purge step for an edge Commons does not have" lscache \
    "printf 'Header set X-LSCache-Purge \"*\"\\n' >>public/.htaccess" \
    "forbidden mechanism 'LSCache'"
refuses "an unexpected file in the document root" strayfile 'echo notes >public/README.txt' \
    "unexpected entry in the document root: public/README.txt"
refuses "a responder that bootstraps the framework" bootstrapped \
    "printf '<?php require __DIR__.\"/../vendor/autoload.php\"; http_response_code(503);' >public/maintenance.php" \
    "must answer even when the release around it is broken"
refuses "a responder that forgets its headers" noheaders \
    "printf '<?php http_response_code(503); echo \"<h1>Down for maintenance</h1>\";' >public/maintenance.php" \
    "does not set the Retry-After header"
refuses "a responder that depends on an asset the rewrite blocks" responderasset \
    "printf '<?php http_response_code(503);\\nheader(\"Retry-After: 120\");\\nheader(\"Cache-Control: no-store, no-cache, must-revalidate\");\\nheader(\"Content-Type: text/html; charset=utf-8\");\\necho \"<link rel=stylesheet href=/m.css><h1>Down for maintenance</h1>\";' >public/maintenance.php" \
    "references an external asset"

# Ordering: each of these still serves an ordinary request correctly and fails only for the request
# class nobody tried by hand, which is exactly why the validator pins the order rather than the set.
# Shipped into the mutation snippets by `declare -f`, which is why shellcheck cannot see it called.
# shellcheck disable=SC2329
reordered() { # BODY: an .htaccess carrying every required rule, in the given order
    printf '%s\n' \
        'DirectoryIndex index.html index.php' \
        'Header always set Content-Security-Policy "default-src '"'"'none'"'"'"' \
        "$@"
}
refuses "an SPA fallback placed before the API carve-out" apilate \
    "$(declare -f reordered); reordered 'RewriteRule \"(^|/)\\.\" - [F,L]' 'RewriteCond %{DOCUMENT_ROOT}/../storage/framework/down -f' 'RewriteCond %{REQUEST_URI} !^/(api(\$|/)|up\$|maintenance\\.php\$)' 'RewriteRule ^ /maintenance.php [L]' 'RewriteRule ^ /index.html [L]' 'RewriteRule \"^(api(\$|/)|up\$)\" index.php [L]' >public/.htaccess" \
    "routes the API AFTER the SPA fallback"
refuses "private denials placed after the maintenance arm" denylate \
    "$(declare -f reordered); reordered 'RewriteCond %{DOCUMENT_ROOT}/../storage/framework/down -f' 'RewriteCond %{REQUEST_URI} !^/(api(\$|/)|up\$|maintenance\\.php\$)' 'RewriteRule ^ /maintenance.php [L]' 'RewriteRule \"^(api(\$|/)|up\$)\" index.php [L]' 'RewriteRule \"(^|/)\\.\" - [F,L]' 'RewriteRule ^ /index.html [L]' >public/.htaccess" \
    "denies private paths AFTER the maintenance arm"

# The acknowledged artifact: findings + code-only must carry a provided acknowledgement.
ART_ACK="$WORK/out-ack/commons-v1.2.0.tar.gz"
flow_run release inspect "$ART_ACK"
expect_rc "an acknowledged artifact is valid" 0
expect_out "…and inspect shows the finding" "[keyword]"
ART="$ART_ACK"
refuses "findings + code-only with the acknowledgement stripped" ackgone "sed -i 's/\"provided\": true/\"provided\": false/' release.json" "need the acknowledgement"
ART="$OUTDIR/commons-v1.1.0.tar.gz"

printf 'flow release inspect: checksum and archive safety\n'
cp "$ART" "$WORK/t.tar.gz"
cp "$ART.sha256" "$WORK/t.tar.gz.sha256"
sed -i 's/commons-v1.1.0/t/' "$WORK/t.tar.gz.sha256"
printf 'x' >>"$WORK/t.tar.gz"
flow_run release inspect "$WORK/t.tar.gz"
expect_rc "a tampered artifact is refused on its checksum" 1
expect_out "…before anything is extracted" "CHECKSUM MISMATCH"
cp "$ART" "$WORK/nosum.tar.gz"
flow_run release inspect "$WORK/nosum.tar.gz"
expect_rc "an artifact with no checksum file is refused" 1
cp "$ART" "$WORK/wrongname.tar.gz"
cp "$ART.sha256" "$WORK/wrongname.tar.gz.sha256"
flow_run release inspect "$WORK/wrongname.tar.gz"
expect_rc "a checksum file that names a different artifact is refused" 1

# A traversal entry: made with a transform that puts the manifest outside the extraction root.
mkdir -p "$WORK/evil"
tar -xzf "$ART" -C "$WORK/evil"
(cd "$WORK/evil" && tar -czPf "$WORK/traversal.tar.gz" --transform='s,^\./release.json,../escaped.json,' . 2>/dev/null)
(cd "$WORK" && sha256sum traversal.tar.gz >traversal.tar.gz.sha256)
flow_run release inspect "$WORK/traversal.tar.gz"
expect_rc "an archive with a parent-relative entry is refused" 1
expect_out "…before extraction" "parent-relative"
expect_false "…and nothing escaped the extraction directory" test -e "$WORK/escaped.json"

printf 'flow release: repeated use\n'
flow_run release inspect "$ART"
expect_rc "inspecting is repeatable" 0
expect_true "…and leaves nothing behind" fixture_clean

finish
