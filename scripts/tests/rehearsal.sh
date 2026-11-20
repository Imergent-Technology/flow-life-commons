#!/usr/bin/env bash
# Guard tests for the local recovery rehearsal (G6 WP3c, slice 1). Run by `./flow check repo`.
#
# These tests create no container, network or volume and touch no database. The rehearsal script is run against a FAKE daemon: a
# `docker` shim in a private tool directory that answers from a directory of files (the "world") and logs every call. That tool
# directory holds symlinks to the handful of ordinary tools the script needs and NOTHING ELSE, so the real `docker` is not on the
# script's PATH at all: a test that went wrong would fail with "docker: command not found", never reach the shared daemon.
# The one place the real `docker` is used is `docker compose config`, which renders a file and contacts no daemon, to check what
# the committed compose.yaml says.
#
# Mutation matrix. Every safeguard in restore-rehearsal.sh is one line ending in `# guard:<ID>`. For each ID this file copies the
# script, replaces that line with `:` and runs the tests listed for the ID in GUARD_TESTS; at least one must fail, or the guard is
# not tested. Both directions are enforced: a marker with no entry here, and an entry whose marker is gone, fail the run. Nothing
# is mutated in the repository: the copy is in a temporary directory.
#
# Running it. The ordinary tests run once. The mutation matrix runs each mutant exactly once, in one of two ways that must give the same answer:
#
#   REHEARSAL_WORKERS=0   sequential: every mutant in this process, in order. The debugging and verification mode.
#   REHEARSAL_WORKERS=N   N worker processes (1 to 16), each given a fixed, disjoint share of the mutants, while this process runs the ordinary
#                         tests. A worker is this same script started as `rehearsal.sh --worker INDEX COUNT ID...` (arguments, never the
#                         environment, so nothing inherited can turn a gate run into a worker), with a temporary directory, fake daemon and
#                         mutation copies of its own. The parent reads every worker's output and fails unless each mutant was reported exactly
#                         once, as caught, by the worker it was assigned to, and every worker finished.
#   (unset)               N = the CPUs this process may really use (affinity and any cgroup quota) minus one for the ordinary tests, at most 4,
#                         and at most one worker per 512 MiB of available memory; 0 when that leaves none.
#
# Which verification this is is chosen by an ARGUMENT (see "how the file is run" near the end of this file):
#   rehearsal.sh             FULL: ordinary tests, registry check and the whole matrix above. The complete verification.
#   rehearsal.sh --routine   ROUTINE: every ordinary test once and the registry check, and NO mutation experiment (it says so). Used by `./flow check`.
#
# REHEARSAL_WORKERS means something a little different in the two modes, because the two have different work to share:
#   full     the workers share the MUTANTS (above); the ordinary tests run in this process, beside them.
#   routine  the workers share the ORDINARY TESTS, and this process only waits for them and checks what each reported (there are no mutants to run).
#            0 runs every ordinary test in this process, one after another: the deterministic reference and debugging mode. N (1 to 16) starts N
#            workers, `rehearsal.sh --tests-worker INDEX COUNT NAME...` (arguments, never the environment), each given a fixed, disjoint share of the
#            sorted tests (the same round-robin as the matrix) and a temporary directory, fake daemon and caches of its own. Unset: the CPUs this process
#            may really use, at most 4, at most one per 512 MiB of available memory; sequential when that leaves fewer than two.
#            The parent fails unless every test was assigned exactly once and reported exactly once, as the worker it was assigned to, by a record
#            the worker wrote to a file of its own (not by anything a test printed), and every worker finished, in time, with a status that agrees.
# REHEARSAL_TESTS="t_a t_b" runs just those tests (no registry check, no matrix, no summary; not a mode of verification; every name must be a test, and an empty selection
# is refused). REHEARSAL_SRC names the script directory under test. `./flow check` refuses both variables: they are for running this file directly.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
THIS_SCRIPT="$ROOT/scripts/tests/rehearsal.sh"
SRC="${REHEARSAL_SRC:-$ROOT/scripts/rehearsal}"
T="$(mktemp -d)"
# The workers this process started, so that an interrupted run does not leave them behind. Each worker removes only its own $T.
WORKER_PIDS=()
TESTS_WORKER_PIDS=() # the same for the ordinary-test workers of a routine run, which are ended differently (each is a process group of its own: see end_ordinary_workers)
trap 'if declare -F stop_workers >/dev/null; then stop_workers; fi; if declare -F stop_tests_workers >/dev/null; then stop_tests_workers; fi; rm -rf "$T"' EXIT
trap 'exit 143' TERM INT HUP
# Run from a throwaway directory. A mutant with a path guard removed would otherwise create its "relative" state directory here.
mkdir "$T/cwd"
cd "$T/cwd"
BIN="$T/bin"
REAL_DOCKER="$(command -v docker || true)"
SUT="$SRC" # the script under test: the repository's, or a mutated copy
QUIET=0
# The guard a mutant removed, while the matrix runs: a test of a table of cases may then run only the case written for that guard (want_case).
CURRENT_GUARD=''
FAILURES=0
TEST_FAILS=0

TOKEN='0123456789abcdef0123456789abcdef'
OTHER_TOKEN='fedcba9876543210fedcba9876543210'
CID="$(printf 'a%.0s' {1..64})"
NID="$(printf 'b%.0s' {1..64})"
FOREIGN="$(printf 'f%.0s' {1..64})"
NAME_C='flc-rehearsal-restore-mariadb'
NAME_N='flc-rehearsal-restore-db'
NAME_V='flc-rehearsal-restore-db-data'
IMAGE='mirror.gcr.io/library/mariadb:10.11.18'
ROOT_PW="$(printf '7e%.0s' {1..24})"
APP_PW="$(printf '5c%.0s' {1..24})"
HIST='2f385b79a7b83323c6f15a6685ee610ccd85b5bf'
HEAD_SHA="$(printf 'e%.0s' {1..40})"
IMG_ID="sha256:$(printf 'd%.0s' {1..64})"
NAME_SRC='flc-rehearsal-restore-src'
NAME_R='flc-rehearsal-restore-runner'
NAME_P='flc-rehearsal-restore-prepare'
NAME_F='flc-rehearsal-restore-files'
NAME_FV='flc-rehearsal-restore-files-data'
RID="$(printf '1%.0s' {1..64})"
PID_="$(printf '2%.0s' {1..64})"
# Slice 2.2A. The keys the fixture state holds (bytes 0..31 and 32 zero bytes), their fingerprints and their ring's, as computed independently
# of the script (Python's hashlib, base64 and the runbook's definitions): the test vectors the deployment runbook's fingerprint wording lacks.
KEY_CUR='base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8='
KEY_PREV='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
KEY_FF='base64://////////////////////////////////////////8='
FP_CUR=2c8a734376e488fb
FP_PREV=dd55ac8a8f7837a8
FP_FF=da15800f513e503f
RING_CUR_PREV=5fa4bb2695669a8f
RING_CUR_ONLY=aa8b784552ea86fa
RING_THREE=357d42c365bacbce
HIST_TABLES=(account_invitations account_recovery_codes account_totp_factors accounts cache cache_locks failed_jobs job_batches jobs membership_grants migrations password_reset_tokens people role_assignments security_events sessions)
NEWER_TABLES=(contact_interactions contact_methods contact_profiles contact_tag_assignments contact_tags discussion_messages discussions person_relationship_field_values person_relationship_role_provisions person_relationship_status_changes person_relationships resource_assets resource_card_audiences resource_cards resource_categories resource_pack_audiences resource_packs sourced_role_grants)
TRANSIENT=(cache cache_locks failed_jobs job_batches jobs password_reset_tokens sessions)

# --- the tool directory ------------------------------------------------------------------------------------------------------------

mkdir -p "$BIN"
for tool in bash env cat grep awk tr od id mktemp mv chmod rm rmdir mkdir dirname cut tail head wc flock sed sort date sleep ln cp tee basename true tar base64 sha256sum; do
    path="$(command -v "$tool" || true)"
    [[ -n "$path" ]] || { echo "rehearsal tests: the tool '$tool' is needed" >&2; exit 1; }
    ln -s "$path" "$BIN/$tool"
done
REAL_STAT="$(command -v stat)"
REAL_DATE="$(command -v date)"

# rm, except that a test can ask for an entry to appear in the state directory just before run.lock is removed (a race the real
# thing leaves to chance): $T/rm-race present means "create $T/root/late-file first".
REAL_RM="$(command -v rm)"
"$REAL_RM" -f "$BIN/rm"
cat >"$BIN/rm" <<STUB
#!/usr/bin/env bash
if [[ -f "$T/rm-race" && "\$*" == *run.lock* ]]; then printf late >"$T/root/late-file"; fi
exec "$REAL_RM" "\$@"
STUB
chmod +x "$BIN/rm"

# stat, except where a test has asked for a different answer (the owner and mode checks cannot otherwise be made to fail by a user
# who owns everything they create): $T/stat-override holds lines `<format>|<path suffix>|<answer>`.
cat >"$BIN/stat" <<STUB
#!/usr/bin/env bash
if [[ -f "$T/stat-override" ]]; then
    fmt="\$2"
    path="\${@: -1}"
    while IFS='|' read -r f suffix answer; do
        if [[ "\$fmt" == "\$f" && "\$path" == *"\$suffix" ]]; then printf '%s\n' "\$answer"; exit 0; fi
    done <"$T/stat-override"
fi
exec "$REAL_STAT" "\$@"
STUB
chmod +x "$BIN/stat"

# date, except where a test has asked for another time ($T/fixed-epoch, seconds) or another answer ($T/date-raw, printed whatever the format).
"$REAL_RM" -f "$BIN/date"
cat >"$BIN/date" <<STUB
#!/usr/bin/env bash
if [[ -f "$T/date-raw" ]]; then cat "$T/date-raw"; exit 0; fi
if [[ -f "$T/fixed-epoch" ]]; then exec "$REAL_DATE" -d "@\$(cat "$T/fixed-epoch")" "\$@"; fi
exec "$REAL_DATE" "\$@"
STUB
chmod +x "$BIN/date"

# --- the fake daemon -----------------------------------------------------------------------------------------------------------------

cat >"$BIN/docker" <<'FAKE'
#!/usr/bin/env bash
# A stand-in for the docker CLI. Answers from $W (a directory of files), logs every call to $W/calls, and refuses anything it does not
# know with status 97, so an unexpected call is a visible failure. It evaluates only the template expressions the script uses.
W="$(dirname "$0")/.world"
printf '%s\n' "$*" >>"$W/calls"
env | cut -d= -f1 | sort >>"$W/envnames"
# $W/fail_when holds one glob per line; a call matching any of them fails, as a daemon that errors on one listing would.
if [[ -f "$W/fail_when" ]]; then
    while IFS= read -r pat; do
        [[ -z "$pat" ]] || case "$*" in $pat) echo "fake docker: injected failure: $*" >&2; exit 1 ;; esac
    done <"$W/fail_when"
fi

declare -A CX=(
    ['{{.Id}}']=id ['{{.Name}}']=name ['{{.Config.Image}}']=image
    ['{{index .Config.Labels "io.flowlife.rehearsal"}}']=l_ns
    ['{{index .Config.Labels "io.flowlife.rehearsal.role"}}']=l_role
    ['{{index .Config.Labels "io.flowlife.rehearsal.run-token"}}']=l_token
    ['{{len .HostConfig.PortBindings}}']=ports ['{{.HostConfig.Privileged}}']=privileged ['{{json .HostConfig.CapAdd}}']=capadd
    ['{{.HostConfig.NetworkMode}}']=netmode
    ['{{range $k,$v := .NetworkSettings.Networks}}{{$k}},{{end}}']=nets
    ['{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}},{{end}}']=mounts
)
declare -A EX=(
    ['{{.Id}}']=id ['{{.Name}}']=name ['{{.Image}}']=image
    ['{{index .Config.Labels "io.flowlife.rehearsal"}}']=l_ns
    ['{{index .Config.Labels "io.flowlife.rehearsal.role"}}']=l_role
    ['{{index .Config.Labels "io.flowlife.rehearsal.run-token"}}']=l_token
    ['{{len .HostConfig.PortBindings}}']=ports ['{{.HostConfig.Privileged}}']=privileged ['{{json .HostConfig.CapAdd}}']=capadd
    ['{{json .HostConfig.CapDrop}}']=capdrop ['{{json .HostConfig.SecurityOpt}}']=secopt ['{{.HostConfig.ReadonlyRootfs}}']=readonly_root
    ['{{.Config.User}}']=user ['{{.HostConfig.NetworkMode}}']=netmode
    ['{{range $k,$v := .NetworkSettings.Networks}}{{$k}},{{end}}']=nets
    ['{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}}:{{.RW}},{{end}}']=mounts
    ['{{range $k,$v := .HostConfig.Tmpfs}}{{$k}},{{end}}']=tmpfs
    ['{{.HostConfig.Memory}}']=memory ['{{.HostConfig.PidsLimit}}']=pids ['{{.HostConfig.NanoCpus}}']=nanocpus
)
declare -A NX=(
    ['{{.Id}}']=id ['{{.Name}}']=name ['{{.Internal}}']=internal ['{{.Driver}}']=driver
    ['{{index .Labels "io.flowlife.rehearsal"}}']=l_ns
    ['{{index .Labels "io.flowlife.rehearsal.role"}}']=l_role
    ['{{index .Labels "io.flowlife.rehearsal.run-token"}}']=l_token
    ['{{range $id,$c := .Containers}}{{$id}},{{end}}']=endpoints
)
declare -A VX=(
    ['{{.Name}}']=name ['{{.Driver}}']=driver ['{{len .Options}}']=options
    ['{{index .Labels "io.flowlife.rehearsal"}}']=l_ns
    ['{{index .Labels "io.flowlife.rehearsal.role"}}']=l_role
    ['{{index .Labels "io.flowlife.rehearsal.run-token"}}']=l_token
)

render() { # KIND DIR TEMPLATE
    local -n table="$1"
    local dir="$2" out="$3" expr value
    # The real daemon cannot render this container template for a bind mount (a bind mount has no Name), and exits 1.
    if [[ "$1" == CX && "$(cat "$dir/mounts" 2>/dev/null)" == *bind:* ]]; then
        echo 'template parsing error: template: :1:402: executing "" at <.Name>: map has no entry for key "Name"' >&2
        exit 1
    fi
    # Longest expression first: some expressions contain a shorter one, which must not be replaced inside it.
    while IFS= read -r expr; do
        value="$(cat "$dir/${table[$expr]}" 2>/dev/null || true)"
        # A label that is absent renders as "<no value>" for containers and networks (empty for volumes), as on the real daemon.
        if [[ -z "$value" && "${table[$expr]}" == l_* && "$1" != VX ]]; then value='<no value>'; fi
        out="${out//"$expr"/$value}"
    done < <(printf '%s\n' "${!table[@]}" | awk '{print length($0) "\t" $0}' | sort -rn | cut -f2-)
    if [[ "$out" == *'{{'* ]]; then echo "fake docker: unsupported template: $3" >&2; exit 97; fi
    printf '%s\n' "$out"
}

formatarg() { local a; while [[ $# -gt 0 ]]; do if [[ "$1" == --format ]]; then printf '%s' "$2"; return; fi; shift; done; }
filters() { while [[ $# -gt 0 ]]; do if [[ "$1" == --filter ]]; then printf '%s\n' "$2"; shift; fi; shift; done; }
lastarg() { printf '%s' "${@: -1}"; }
hook() { if [[ -f "$W/hook_$1" ]]; then bash "$W/hook_$1" && rm -f "$W/hook_$1"; fi; return 0; } # a hook that exits non-zero is kept for the next call (WU1: one that waits for something)

list() { # KIND(c|n|v) -- applies the --filter arguments; prints one line per match: "<id> <name>"
    local kind="$1"
    shift
    local dir f key val match
    for dir in "$W/$kind"/*; do
        [[ -d "$dir" ]] || continue
        match=1
        while IFS= read -r f; do
            [[ -n "$f" ]] || continue
            key="${f%%=*}"
            val="${f#*=}"
            case "$key" in
                label)
                    local lk="${val%%=*}" lv="${val#*=}"
                    case "$lk" in
                        io.flowlife.rehearsal) [[ "$(cat "$dir/l_ns" 2>/dev/null)" == "$lv" ]] || match=0 ;;
                        io.flowlife.rehearsal.role) [[ "$(cat "$dir/l_role" 2>/dev/null)" == "$lv" ]] || match=0 ;;
                        com.docker.compose.project) [[ "$(cat "$dir/l_project" 2>/dev/null)" == "$lv" ]] || match=0 ;;
                        *) match=0 ;;
                    esac ;;
                name) [[ "$(cat "$dir/name")" == *"${val#^}"* ]] || match=0 ;;
                volume) [[ "$(cat "$dir/mounts" 2>/dev/null)" == *":$val:"* ]] || match=0 ;;
                *) echo "fake docker: unsupported filter $f" >&2; exit 97 ;;
            esac
        done < <(filters "$@")
        if [[ $match == 1 ]]; then printf '%s %s\n' "$(cat "$dir/id" 2>/dev/null || basename "$dir")" "$(cat "$dir/name")"; fi
    done
}

case "$1 ${2:-}" in
    "context show") cat "$W/context"; echo ;;
    "context inspect") cat "$W/endpoint"; echo ;;
    "info "*) [[ ! -e "$W/info_fail" ]] || exit 1 ;;
    "ps "*)
        if [[ "$(formatarg "$@")" == '{{.ID}} {{.Names}}' ]]; then list c "$@" | sed 's#^\([^ ]*\) /#\1 #'; else list c "$@" | cut -d' ' -f1; fi ;;
    "network ls") if [[ "$(formatarg "$@")" == '{{.ID}} {{.Name}}' ]]; then list n "$@"; else list n "$@" | cut -d' ' -f1; fi ;;
    "volume ls") if [[ "$(formatarg "$@")" == '{{.Name}}' ]]; then list v "$@" | cut -d' ' -f2; else list v "$@" | cut -d' ' -f2; fi ;;
    "inspect --type")
        id="$(lastarg "$@")"
        [[ -d "$W/c/$id" ]] || { echo "Error: No such container: $id" >&2; exit 1; }
        if [[ "$(formatarg "$@")" == *ReadonlyRootfs* ]]; then render EX "$W/c/$id" "$(formatarg "$@")"; else render CX "$W/c/$id" "$(formatarg "$@")"; fi
        # The hook models a container changing between a full identity check and the next one; reading only the role label is not one.
        [[ "$(formatarg "$@")" == '{{index .Config.Labels "io.flowlife.rehearsal.role"}}' ]] || hook inspect_c ;;
    "network inspect")
        id="$(lastarg "$@")"
        [[ -d "$W/n/$id" ]] || { echo "Error: No such network: $id" >&2; exit 1; }
        render NX "$W/n/$id" "$(formatarg "$@")"
        hook inspect_n ;;
    "volume inspect")
        id="$(lastarg "$@")"
        [[ -d "$W/v/$id" ]] || { echo "Error: No such volume: $id" >&2; exit 1; }
        render VX "$W/v/$id" "$(formatarg "$@")"
        hook inspect_v ;;
    "stop "*) [[ ! -e "$W/stop_fail" ]] || exit 1 ;;
    "rm "*)
        [[ ! -e "$W/rm_fail" ]] || exit 1
        gone="$(lastarg "$@")"
        rm -rf "$W/c/$gone"
        # Removing a container detaches it from its networks, as the real daemon does.
        for f in "$W"/n/*/endpoints; do
            [[ -f "$f" ]] || continue
            printf '%s' "$(sed "s/$gone,//" "$f")" >"$f"
        done ;;
    "network rm") [[ ! -e "$W/network_rm_fail" ]] || exit 1; rm -rf "$W/n/$(lastarg "$@")" ;;
    "volume rm") [[ ! -e "$W/volume_rm_fail" ]] || exit 1; rm -rf "$W/v/$(lastarg "$@")" ;;
    "exec --user") # the application account's dump client: `exec --user U ID sh -c SNIPPET sh ARGS...` (no stdin)
        [[ -d "$W/c/$4" ]] || { echo "Error: No such container: $4" >&2; exit 1; }
        printf 'DUMP args=[%s]\n' "${*:9}" >>"$W/dumpcalls"
        [[ ! -e "$W/dump_fail" ]] || exit 1
        case " $* " in *" --no-data "*) cat "$W/dump_pass1" 2>/dev/null ;; *) cat "$W/dump_pass2" 2>/dev/null ;; esac ;;
    "exec -i")
        if [[ "$3" == --user ]]; then # the restricted application-user client: `exec -i --user U ID sh -c SNIPPET sh ARGS...`
            [[ -d "$W/c/$5" ]] || { echo "Error: No such container: $5" >&2; exit 1; }
            sql="$(cat)"
            hook app_sql
            [[ ! -e "$W/app_cannot_connect" ]] || exit 1
            case "$sql" in
                *"table_name = 'resource_assets'"*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/assets_table" 2>/dev/null || echo 1 ;; # slice 2.2C-2: does the table exist
                *'FROM resource_assets ORDER BY storage_key'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/asset_rows" 2>/dev/null ;;
                *'INSERT INTO resource_assets'*) printf '%s' "$sql" >"$W/assets_insert.sql"; printf 'INSERT args=[%s]\n' "${*:10}" >>"$W/appsql.log"
                    if [[ -f "$W/runs" ]]; then cp "$W/runs" "$W/runs_at_insert"; else : >"$W/runs_at_insert"; fi
                    [[ ! -f "$W/insert_fail" ]] || { echo 'ERROR 1062 (23000): the fake insert failed' >&2; exit 1; }
                    if [[ -f "$W/on_insert" ]]; then bash "$W/on_insert"; fi ;;
                *'CREATE TABLE `person_relationship_field_values`'*) # slice 2.2C-1: the stub. Logged whole; the helpers that had run by now are recorded; the world may change as it would
                    printf '%s' "$sql" >"$W/stub.sql"; printf 'STUB args=[%s]\n' "${*:10}" >>"$W/appsql.log"
                    if [[ -f "$W/runs" ]]; then cp "$W/runs" "$W/runs_at_stub"; else : >"$W/runs_at_stub"; fi
                    [[ ! -f "$W/stub_fail" ]] || { echo 'ERROR 1050 (42S01): the fake stub failed' >&2; exit 1; }
                    if [[ -f "$W/on_stub" ]]; then bash "$W/on_stub"; fi ;;
                *'GROUP_CONCAT(CONCAT(c.column_name'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/stub_row" 2>/dev/null; echo ;;
                *'FROM migrations ORDER BY id'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/ledger" 2>/dev/null; echo ;;
                *') + (SELECT COUNT(*) FROM `'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/partial_rows" 2>/dev/null || echo 0 ;;
                *'FROM `account_invitations`'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/durable_rows" 2>/dev/null; echo ;;
                *'MD5(IFNULL(GROUP_CONCAT'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/security_digest" 2>/dev/null; echo ;;
                *'create_options'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/schema_1" 2>/dev/null; echo ;;
                *'IFNULL(column_default'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/schema_2" 2>/dev/null; echo ;;
                *'FROM information_schema.statistics WHERE table_schema'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/schema_3" 2>/dev/null; echo ;;
                *'referenced_table_name IS NOT NULL'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/schema_4" 2>/dev/null; echo ;;
                *'information_schema.referential_constraints'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/schema_5" 2>/dev/null; echo ;;
                *"type = 'role.revoked'"*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; if [[ -f "$W/revoke_counts" ]]; then cat "$W/revoke_counts"; else printf '12\t8\t1\t0\t0\n'; fi ;;
                *'DROP TABLE IF EXISTS'*) # loading a dump: logged whole, and the world may change as it would
                    printf '%s' "$sql" >"$W/import.sql"; printf 'IMPORT args=[%s]\n' "${*:10}" >>"$W/appsql.log"
                    [[ ! -f "$W/import_fail" ]] || { echo 'ERROR 1050 (42S01): the fake load failed' >&2; exit 1; }
                    if [[ -f "$W/on_import" ]]; then bash "$W/on_import"; fi ;;
                *"table_type <> 'BASE TABLE'"*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/nonbase" 2>/dev/null || echo 0 ;;
                *'information_schema.triggers'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/other_objects" 2>/dev/null || printf '0\t0\t0\n' ;;
                *'DROP TABLE `'*) # slice 2.2B: clearing the schema. Logged whole; the helpers that had run by now are recorded; the world may change as it would
                    printf '%s' "$sql" >"$W/clear.sql"; printf 'CLEAR args=[%s]\n' "${*:10}" >>"$W/appsql.log"
                    if [[ -f "$W/runs" ]]; then cp "$W/runs" "$W/runs_at_clear"; else : >"$W/runs_at_clear"; fi
                    [[ ! -f "$W/clear_fail" ]] || { echo 'ERROR 1051 (42S02): the fake clear failed' >&2; exit 1; }
                    if [[ -f "$W/on_clear" ]]; then bash "$W/on_clear"; else : >"$W/inventory"; fi ;;
                *'information_schema.tables'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/inventory" 2>/dev/null; echo ;;
                *'SELECT (SELECT COUNT(*) FROM people)'*) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; cat "$W/counts" 2>/dev/null; echo ;;
                *'SELECT CURRENT_USER();'*)
                    cat "$W/app_identity" 2>/dev/null || echo 'flc_rehearsal@%'
                    if [[ -f "$W/app_grants" ]]; then cat "$W/app_grants"; else
                        echo "GRANT USAGE ON *.* TO \`flc_rehearsal\`@\`%\` IDENTIFIED BY PASSWORD '*0123456789ABCDEF0123456789ABCDEF01234567'"
                        printf '%s\n' 'GRANT ALL PRIVILEGES ON `flc\\_rehearsal\\_app`.* TO `flc_rehearsal`@`%`'
                    fi
                    echo ;;
                *'flc_rehearsal_identity.run_token'*) [[ -f "$W/app_sees_sentinel" ]] || { echo 'ERROR 1142 (42000): SELECT command denied' >&2; exit 1; }; cat "$W/sentinel_token"; echo ;;
                *'\! echo'*) if [[ -f "$W/sandbox_escape" ]]; then echo FLC_ESCAPED; else echo 'ERROR: the sandbox does not allow system commands' >&2; exit 1; fi ;;
                *) printf 'APPSQL args=[%s] %s\n' "${*:10}" "$sql" >>"$W/appsql.log"; echo 1 ;;
            esac
            exit 0
        fi
        id="$3"
        [[ -n "$id" ]] || { echo "Error: No container specified" >&2; exit 1; }
        [[ -d "$W/c/$id" ]] || { echo "Error: No such container: $id" >&2; exit 1; }
        sql="$(cat)"
        case "$sql" in
            *'SELECT VERSION()'*) cat "$W/version"; echo ;;
            *'FROM mysql.user'*) cat "$W/account_count" 2>/dev/null || echo 1 ;;
            *'SELECT token FROM flc_rehearsal_identity.run_token'*) [[ -f "$W/sentinel_token" ]] || exit 1; cat "$W/sentinel_token"; echo ;;
            *'CREATE DATABASE flc_rehearsal_identity'*)
                printf '%s' "$sql" | sed -n "s/.*VALUES ('\([0-9a-f]*\)').*/\1/p" >"$W/sentinel_token"
                printf 'SENTINEL %s\n' "$sql" >>"$W/sql.log" ;;
            *) printf 'SQL args=[%s] %s\n' "${*:8}" "$sql" >>"$W/sql.log"; echo 1 ;;
        esac ;;
    "image inspect") [[ -f "$W/image_id" ]] || { echo 'Error: No such image' >&2; exit 1; }; cat "$W/image_id"; echo ;;
    "volume create")
        args=("$@")
        vname="${args[-1]}"
        if [[ ! -d "$W/v/$vname" ]]; then # as the real daemon: creating a volume that exists is not an error and changes nothing
            mkdir -p "$W/v/$vname"
            printf '%s' "$vname" >"$W/v/$vname/id"; printf '%s' "$vname" >"$W/v/$vname/name"; printf local >"$W/v/$vname/driver"; printf 0 >"$W/v/$vname/options"
            for ((i = 0; i < ${#args[@]}; i++)); do
                if [[ "${args[i]}" == --label ]]; then
                    case "${args[i + 1]}" in
                        io.flowlife.rehearsal=*) printf '%s' "${args[i + 1]#*=}" >"$W/v/$vname/l_ns" ;;
                        io.flowlife.rehearsal.role=*) printf '%s' "${args[i + 1]#*=}" >"$W/v/$vname/l_role" ;;
                        io.flowlife.rehearsal.run-token=*) printf '%s' "${args[i + 1]#*=}" >"$W/v/$vname/l_token" ;;
                    esac
                fi
            done
        fi
        if [[ -f "$W/volume_create_override" ]]; then while read -r field value; do printf '%s' "$value" >"$W/v/$vname/$field"; done <"$W/volume_create_override"; fi
        printf '%s\n' "$vname" ;;
    "create "*)
        shift
        [[ ! -e "$W/create_fail" ]] || exit 1
        cname='' cuser='' ccapdrop='' csecopt='' cnet='' cmem='' cpids='' ccpus='' cro=false cimage='' cworkdir='' ctmpfs=() cmounts_out='' cenvs=() clabels=()
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --name) cname="$2"; shift 2 ;;
                --label) clabels+=("$2"); shift 2 ;;
                --user) cuser="$2"; shift 2 ;;
                --cap-drop) ccapdrop="$2"; shift 2 ;;
                --security-opt) csecopt="$2"; shift 2 ;;
                --network) cnet="$2"; shift 2 ;;
                --memory) cmem="$2"; shift 2 ;;
                --pids-limit) cpids="$2"; shift 2 ;;
                --cpus) ccpus="$2"; shift 2 ;;
                --tmpfs) ctmpfs+=("${2%%:*}"); shift 2 ;;
                -e) cenvs+=("${2%%=*}"); shift 2 ;;
                --workdir) cworkdir="$2"; shift 2 ;;
                --memory-swap | --pull | --entrypoint) shift 2 ;;
                --read-only) cro=true; shift ;;
                -i) shift ;;
                --mount)
                    msrc='' mtgt='' mrw=true
                    IFS=',' read -ra mparts <<<"$2"
                    for mp in "${mparts[@]}"; do case "$mp" in source=*) msrc="${mp#*=}" ;; target=*) mtgt="${mp#*=}" ;; readonly) mrw=false ;; esac; done
                    cmounts_out+="volume:$msrc:$mtgt:$mrw,"
                    shift 2 ;;
                -*) echo "fake docker: unsupported create option $1" >&2; exit 97 ;;
                *) cimage="$1"; shift; break ;;
            esac
        done
        n=$(($(cat "$W/seq" 2>/dev/null || echo 0) + 1)); printf '%s' "$n" >"$W/seq"
        cid="$(printf 'c%063x' "$n")"
        d="$W/c/$cid"; mkdir -p "$d"
        printf '%s' "$cid" >"$d/id"; printf '%s' "/$cname" >"$d/name"; printf '%s' "$cimage" >"$d/image"
        for l in "${clabels[@]}"; do
            case "$l" in
                io.flowlife.rehearsal=*) printf '%s' "${l#*=}" >"$d/l_ns" ;;
                io.flowlife.rehearsal.role=*) printf '%s' "${l#*=}" >"$d/l_role" ;;
                io.flowlife.rehearsal.run-token=*) printf '%s' "${l#*=}" >"$d/l_token" ;;
            esac
        done
        printf 0 >"$d/ports"; printf false >"$d/privileged"; printf null >"$d/capadd"
        if [[ -n "$ccapdrop" ]]; then printf '["%s"]' "$ccapdrop" >"$d/capdrop"; else printf null >"$d/capdrop"; fi
        if [[ -n "$csecopt" ]]; then printf '["%s"]' "$csecopt" >"$d/secopt"; else printf null >"$d/secopt"; fi
        printf '%s' "$cro" >"$d/readonly_root"; printf '%s' "$cuser" >"$d/user"; printf '%s' "$cnet" >"$d/netmode"; printf '%s,' "$cnet" >"$d/nets"
        printf '%s' "$cmounts_out" >"$d/mounts"
        [[ ! -e "$W/tmpfs_in_mounts" ]] || for t in "${ctmpfs[@]}"; do printf 'tmpfs::%s:true,' "$t" >>"$d/mounts"; done
        printf '%s\n' "${ctmpfs[@]}" | sort | tr '\n' ',' | sed 's/^,$//' >"$d/tmpfs"
        case "$cmem" in 1g) printf 1073741824 ;; *) printf '%s' "$cmem" ;; esac >"$d/memory"
        printf '%s' "$cpids" >"$d/pids"
        case "$ccpus" in 1) printf 1000000000 ;; *) printf '%s' "$ccpus" ;; esac >"$d/nanocpus"
        printf '%s\n' "${cenvs[@]}" >"$d/envnames"
        printf '%s' "$cworkdir" >"$d/workdir"; printf '%s' "$*" >"$d/cmd"
        # The password reaches the daemon client only through its environment; record whether it arrived, never print it.
        printf '%s' "${DB_PASSWORD:-}" >"$W/create_password_seen.$n"
        printf '%s' "${APP_KEY:-}" >"$W/create_appkey_seen.$n"; printf '%s' "${APP_PREVIOUS_KEYS:-}" >"$W/create_previouskeys_seen.$n"
        env | cut -d= -f1 | sort >"$W/create_env_names.$n"
        if [[ -f "$W/create_override" ]]; then while read -r field value; do printf '%s' "$value" >"$d/$field"; done <"$W/create_override"; fi
        HOOK_CID="$cid" hook create # slice 2.2C-3 (WU1): what happens to the world while the daemon sets up the mounts (a volume replaced, a consumer added)
        printf '%s\n' "$cid" ;;
    "start "*)
        id="$(lastarg "$@")"
        [[ -d "$W/c/$id" ]] || { echo "Error: No such container: $id" >&2; exit 1; }
        printf '%s\n' "$(cat "$W/c/$id/name")" >>"$W/started"
        case " $* " in *" -i "*) cat >"$W/stdin.$(wc -l <"$W/started")" ;; esac
        [[ ! -e "$W/start_fail" ]] || exit "$(cat "$W/start_fail")"
        # Slice 2.2A: which helper this is (by its command and tree), what it prints (out_<key>), what it changes in the world afterwards
        # (after_<key>, a script) and whether it fails (fail_<key>, an exit status). The order of the helpers is recorded in $W/runs.
        # (Slice 2.2B adds the keys signin and harness_sums, the digests of the helper scripts as the volume holds them.)
        cmd="$(cat "$W/c/$id/cmd" 2>/dev/null)"; wd="$(cat "$W/c/$id/workdir" 2>/dev/null)"; key=''
        case "$cmd" in
            *'rehearse.php fixture-load'*) key=fixture_load ;;
            *'rehearse.php snapshot-all'*) key=snapshot_all ;; # slice 2.2C-3 (WU4): BEFORE `snapshot`, which is a prefix of it (out_snapshot_all, out_snapshot_all.<n> for the nth run)
            *'rehearse.php snapshot'*) key="snapshot_${wd##*/}" ;;
            *'rehearse.php mark'*) key=mark ;;
            *'rehearse.php signin'*) key=signin ;;
            *' exec sha256sum -- '*'tests/Support'*) key=support_sums ;; # slice 2.2C-3 (WU1): the support classes' digests as the volume holds them
            *' exec sha256sum -- '*) key=harness_sums ;;
            *'artisan resources:assets:prune'*) key=prune ;; # slice 2.2C-3 (WU2): the Resources prune dry-run (out_prune, fail_prune)
            *'rehearse.php half-migrate'*) key=half_migrate ;;
            *'rehearse.php revoke'*) key=revoke ;;
            *'harness/files.php check'*) key=files_check ;;
            *'harness/files.php seed'*) key=files_seed ;;
            *'harness/files.php archive'*) key=files_archive ;;
            *'harness/files.php verify-archive'*) key=files_verify_archive ;;
            *'harness/files.php aside'*) key=files_aside ;;
            *'harness/files.php restore'*) key=files_restore ;;
            *' migrate --force'*) key="migrate_${wd##*/}" ;;
        esac
        [[ -z "$key" ]] || printf '%s\n' "$key" >>"$W/runs"
        # Slice 2.2C-2: the files helper is run several times in one command, so out_<key>.<n> (the nth run of that helper) takes precedence over out_<key>.
        nth=0; [[ -z "$key" ]] || nth="$(grep -c "^$key\$" "$W/runs")"
        if [[ -n "$key" && -f "$W/fail_$key" ]]; then echo 'harness:refused the fake helper was told to fail' >&2; exit "$(cat "$W/fail_$key")"; fi
        if [[ -n "$key" && -f "$W/out_$key.$nth" ]]; then cat "$W/out_$key.$nth"; elif [[ -n "$key" && -f "$W/out_$key" ]]; then cat "$W/out_$key"; elif [[ -f "$W/run_stdout" ]]; then cat "$W/run_stdout"; else echo ok; fi
        if [[ -n "$key" && -f "$W/after_$key" ]]; then bash "$W/after_$key"; fi ;;
    "compose "*)
        case " $* " in
            *" config"*) [[ ! -f "$W/rendered" ]] || cat "$W/rendered" ;;
            *" up "*)
                [[ ! -e "$W/up_fail" ]] || exit 1
                if [[ -e "$W/up_creates" ]]; then
                    envfile=''
                    args=("$@")
                    for ((i = 0; i < ${#args[@]}; i++)); do [[ "${args[i]}" == --env-file ]] && envfile="${args[i + 1]}"; done
                    token="$(sed -n 's/^REHEARSAL_RUN_TOKEN=//p' "$envfile")"
                    bash "$W/make_world" "$token"
                fi ;;
            *) echo "fake docker: unexpected compose call: $*" >&2; exit 97 ;;
        esac ;;
    *) echo "fake docker: unexpected call: $*" >&2; exit 97 ;;
esac
FAKE
chmod +x "$BIN/docker"

# A stand-in for git: answers from $W/commits (lines `<rev> <full id>`), logs its calls and the NAMES of its environment, and "archives"
# to a line of text naming the commit and path, which the fake daemon's `start -i` records as the container's stdin.
cat >"$BIN/git" <<'FAKEGIT'
#!/usr/bin/env bash
W="$(dirname "$0")/.world"
printf '%s\n' "$*" >>"$W/gitcalls"
env | cut -d= -f1 | sort >>"$W/gitenvnames"
args=("$@")
[[ "${args[0]}" == -C ]] && args=("${args[@]:2}")
case "${args[0]}" in
    rev-parse)
        if [[ "${args[1]}" == --is-shallow-repository ]]; then echo true; exit 0; fi
        rev="${args[-1]%^\{commit\}}"
        line="$(grep "^$rev " "$W/commits" 2>/dev/null)" || exit 1
        [[ ! -f "$W/commit_wrong" || "$rev" != 2f385b79* ]] || { echo "$(printf '9%.0s' {1..40})"; exit 0; }
        echo "${line#* }" ;;
    cat-file)
        if [[ "${args[1]}" == blob ]]; then # slice 2.2C-3 (WU1): a support class as a commit holds it; its text names the commit and path, which the tests digest too
            [[ ! -f "$W/no_support" ]] || exit 1
            printf 'BLOB %s\n' "${args[2]}"
            exit 0
        fi
        [[ ! -f "$W/no_lockfile" ]] || exit 1 ;;
    status) [[ ! -f "$W/git_status" ]] || cat "$W/git_status" ;;
    archive) echo "GIT-ARCHIVE ${args[2]} ${args[3]}" ;;
    *) echo "fake git: unexpected call: $*" >&2; exit 97 ;;
esac
FAKEGIT
chmod +x "$BIN/git"

# --- building a world ------------------------------------------------------------------------------------------------------------------

put() { printf '%s' "$3" >"$1/$2"; }

new_world() {
    W="$(mktemp -d "$T/w.XXXXXX")"
    mkdir -p "$W/c" "$W/n" "$W/v"
    ln -sfn "$W" "$BIN/.world"
    : >"$W/calls"
    : >"$W/envnames"
    put "$W" context default
    put "$W" endpoint 'unix:///var/run/docker.sock'
    put "$W" version '10.11.18-MariaDB-ubu2204'
    rm -rf "${T:?}/root" "${T:?}/home" "${T:?}/stat-override" "${T:?}/rm-race" "${T:?}/fixed-epoch" "${T:?}/date-raw"
    mkdir -p "$T/home"
    unset WORLD_ENV
}

# add_container ID [field=value ...]: the rehearsal database as `up` creates it, with any field overridden.
add_container() {
    local id="$1" d kv
    shift
    d="$W/c/$id"
    mkdir -p "$d"
    put "$d" id "$id"; put "$d" name "/$NAME_C"; put "$d" image "$IMAGE"; put "$d" l_ns restore-v1; put "$d" l_role database
    put "$d" l_token "$TOKEN"; put "$d" ports 0; put "$d" privileged false; put "$d" capadd null; put "$d" netmode "$NAME_N"
    put "$d" nets "$NAME_N,"; put "$d" mounts "volume:$NAME_V:/var/lib/mysql,"
    for kv in "$@"; do put "$d" "${kv%%=*}" "${kv#*=}"; done
}

add_network() {
    local id="$1" d kv
    shift
    d="$W/n/$id"
    mkdir -p "$d"
    put "$d" id "$id"; put "$d" name "$NAME_N"; put "$d" internal true; put "$d" driver bridge; put "$d" l_ns restore-v1
    put "$d" l_role database-network; put "$d" l_token "$TOKEN"; put "$d" endpoints "$CID,"
    for kv in "$@"; do put "$d" "${kv%%=*}" "${kv#*=}"; done
}

add_volume() {
    local name="$1" d kv
    shift
    d="$W/v/$name"
    mkdir -p "$d"
    put "$d" id "$name"; put "$d" name "$NAME_V"; put "$d" driver local; put "$d" options 0; put "$d" l_ns restore-v1
    put "$d" l_role database-data; put "$d" l_token "$TOKEN"
    for kv in "$@"; do put "$d" "${kv%%=*}" "${kv#*=}"; done
}

make_root() {
    mkdir -m 700 "$T/root"
    printf 'restore-rehearsal-v1\n' >"$T/root/.flc-rehearsal-root"
    printf 'REHEARSAL_DB_ROOT_PASSWORD=%s\nREHEARSAL_DB_APP_PASSWORD=%s\nREHEARSAL_RUN_TOKEN=%s\nREHEARSAL_APP_KEY=%s\nREHEARSAL_APP_PREVIOUS_KEY=%s\n' "$ROOT_PW" "$APP_PW" "${1:-$TOKEN}" "$KEY_CUR" "$KEY_PREV" >"$T/root/compose.env"
    chmod 600 "$T/root/compose.env"
}

# A complete, healthy rehearsal project and its state directory.
good_world() {
    new_world
    make_root
    add_container "$CID"
    add_network "$NID"
    add_volume "$NAME_V"
    put "$W" sentinel_token "$TOKEN"
}

# What `docker compose up` does in the fake: it makes the objects, with the token from the env file it was given.
enable_up() {
    cat >"$W/make_world" <<MAKE
token="\$1"
d="$W"
mkdir -p "\$d/c/$CID" "\$d/n/$NID" "\$d/v/$NAME_V"
$(declare -f put)
for kv in id=$CID name=/$NAME_C image=$IMAGE l_ns=restore-v1 l_role=database l_token=\$token ports=0 privileged=false capadd=null netmode=$NAME_N nets=$NAME_N, mounts=volume:$NAME_V:/var/lib/mysql,; do put "\$d/c/$CID" "\${kv%%=*}" "\${kv#*=}"; done
for kv in id=$NID name=$NAME_N internal=true driver=bridge l_ns=restore-v1 l_role=database-network l_token=\$token endpoints=$CID,; do put "\$d/n/$NID" "\${kv%%=*}" "\${kv#*=}"; done
for kv in id=$NAME_V name=$NAME_V driver=local options=0 l_ns=restore-v1 l_role=database-data l_token=\$token; do put "\$d/v/$NAME_V" "\${kv%%=*}" "\${kv#*=}"; done
[[ ! -e "\$d/up_extra_volume" ]] || { mkdir -p "\$d/v/extra"; for kv in id=extra name=other driver=local options=0 l_ns=restore-v1 l_role=database-data l_token=\$token; do put "\$d/v/extra" "\${kv%%=*}" "\${kv#*=}"; done; }
[[ ! -f "\$d/up_override" ]] || while read -r kind field value; do case "\$kind" in c) put "\$d/c/$CID" "\$field" "\$value" ;; n) put "\$d/n/$NID" "\$field" "\$value" ;; v) put "\$d/v/$NAME_V" "\$field" "\$value" ;; esac; done <"\$d/up_override"
[[ ! -e "\$d/up_extra_network" ]] || { mkdir -p "\$d/n/extra"; for kv in id=extra name=other internal=true driver=bridge l_ns=restore-v1 l_role=database-network l_token=\$token endpoints=; do put "\$d/n/extra" "\${kv%%=*}" "\${kv#*=}"; done; }
MAKE
    touch "$W/up_creates"
    # The real rendering of the committed file is what `up` checks, so the fake hands back exactly that.
    render_committed >"$W/rendered"
}

# up_override KIND(c|n|v) FIELD VALUE: after the fake `compose up` makes its objects, one field of one of them is changed.
up_override() { printf '%s %s %s\n' "$1" "$2" "$3" >>"$W/up_override"; }
# inject_failure GLOB: the fake daemon fails any call whose arguments match.
inject_failure() { printf '%s\n' "$1" >>"$W/fail_when"; }

render_committed() {
    local envf="$T/render.env"
    printf 'REHEARSAL_DB_ROOT_PASSWORD=x\nREHEARSAL_DB_APP_PASSWORD=y\nREHEARSAL_RUN_TOKEN=%s\n' "$TOKEN" >"$envf"
    "$REAL_DOCKER" compose -p flc-rehearsal-restore --project-directory "$SRC" -f "$SRC/compose.yaml" --env-file "$envf" config
}

# --- running the script ------------------------------------------------------------------------------------------------------------------

RC=0 OUT='' ERR=''
# run_script [VAR=value ...] -- ARGS...: the script as a user would run it, with only the given extra variables.
run_script() {
    local extra=()
    while [[ $# -gt 0 && "$1" != -- ]]; do extra+=("$1"); shift; done
    shift
    RC=0
    env -i PATH="$BIN" HOME="$T/home" FLC_REHEARSAL_ROOT="$T/root" ${extra[@]+"${extra[@]}"} \
        "$BIN/bash" "$SUT/restore-rehearsal.sh" "$@" >"$T/out" 2>"$T/err" || RC=$?
    OUT="$(<"$T/out")"
    ERR="$(<"$T/err")"
}

# run_fn CODE: source the script and run CODE (a function call), under the same scrubbed environment.
run_fn() {
    local code="$1"
    shift
    RC=0
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    env -i PATH="$BIN" HOME="$T/home" FLC_REHEARSAL_ROOT="$T/root" "$@" \
        "$BIN/bash" -c 'source "$1"; eval "$2"' x "$SUT/restore-rehearsal.sh" "$code" >"$T/out" 2>"$T/err" || RC=$?
    OUT="$(<"$T/out")"
    ERR="$(<"$T/err")"
}

# --- assertions -------------------------------------------------------------------------------------------------------------------------

FIRST_BAD=''
ABORT_ON_BAD=0
bad() {
    TEST_FAILS=$((TEST_FAILS + 1))
    [[ -n "$FIRST_BAD" ]] || FIRST_BAD="$*"
    if [[ $QUIET == 0 ]]; then printf '        FAIL: %s\n' "$*" >&2; fi
    # In the mutation matrix one failed assertion is the whole answer: end the test (it runs in a subshell there) and say why.
    if [[ $ABORT_ON_BAD == 1 ]]; then
        printf '%s' "$FIRST_BAD" >"$T/first_bad"
        exit 1
    fi
}
expect_rc() { [[ "$RC" == "$2" ]] || bad "$1: expected exit $2, got $RC (stderr: ${ERR:0:300})"; }
expect_refused() { # DESC NEEDLE
    [[ "$RC" == 3 ]] || { bad "$1: expected a refusal (exit 3), got $RC (stderr: ${ERR:0:300})"; return 0; }
    [[ "$ERR" == *"$2"* ]] || bad "$1: refused, but not for the expected reason '$2' (stderr: ${ERR:0:300})"
}
expect_has() { [[ "$2" == *"$3"* ]] || bad "$1: expected to find '$3' in: ${2:0:300}"; }
expect_lacks() { [[ "$2" != *"$3"* ]] || bad "$1: did not expect '$3' in: ${2:0:300}"; }
calls() { cat "$W/calls"; }

# Only these may ever be asked of the daemon in a run that must not change anything.
assert_read_only() { # DESC
    local line
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        case "$line" in
            "context show" | "context inspect "* | "info "* | "ps "* | "inspect --type container --format "* | "network ls "* | "network inspect --format "* | "volume ls "* | "volume inspect --format "*) ;;
            "compose "*" config") ;;
            "exec -i "*) ;; # the SQL itself is checked below
            *) bad "$1: a mutating or unexpected docker call was made: $line" ;;
        esac
    done <"$W/calls"
    if [[ -f "$W/sql.log" ]] && grep -qiE 'CREATE|DROP|INSERT|DELETE|ALTER|UPDATE|TRUNCATE|GRANT' "$W/sql.log"; then bad "$1: a statement that changes data or schema was sent"; fi
}
assert_no_docker_call() { [[ ! -s "$W/calls" ]] || bad "$1: docker was called: $(head -n 3 "$W/calls" | tr '\n' ';')"; }

# --- tests: the commands --------------------------------------------------------------------------------------------------------------

t_surface_no_restore_command() {
    local c
    for c in restore drop reset down prune backup deploy rollback ''; do
        new_world
        run_script -- ${c:+"$c"}
        expect_rc "command '$c'" 64
        assert_no_docker_call "command '$c'"
    done
    run_script -- --help
    expect_rc help 0
    expect_has help "$OUT" 'cleanup'
    expect_lacks help "$OUT" $'\n  restore '
    expect_has help "$OUT" 'restore-historical'
}

t_static_no_application_env() {
    # The script reads no .env and names no application directory. (G3)
    # Slice 2.1 archives apps/platform from git, so the tree is named; what must never appear is a path to an application .env or FLOW_ROOT.
    if grep -nE '(apps/platform/\.env|FLOW_ROOT)' "$SRC/restore-rehearsal.sh" >/dev/null; then bad 'the script names an application .env or FLOW_ROOT'; fi
    if grep -nE '^[[:space:]]*(source|\.)[[:space:]]+[^[:space:]]*\.env' "$SRC/restore-rehearsal.sh" >/dev/null; then bad 'the script sources an .env file'; fi
    if grep -nE 'docker\.sock' "$SRC/restore-rehearsal.sh" "$SRC/compose.yaml" | grep -vE '^[^:]+:[0-9]+:[[:space:]]*#' | grep -q .; then bad 'a Docker socket is named outside comments'; fi
}

t_registered_in_the_repository_checks() {
    # check.sh must lint the rehearsal scripts, validate their compose file and run these tests (shellcheck's list is explicit).
    local check="$ROOT/scripts/commands/check.sh"
    grep -qF 'scripts/rehearsal/*.sh' "$check" || bad 'check.sh does not shellcheck scripts/rehearsal/*.sh'
    grep -qF 'scripts/tests/rehearsal.sh' "$check" || bad 'check.sh does not run scripts/tests/rehearsal.sh'
    grep -qF 'scripts/rehearsal/compose.yaml' "$check" || bad 'check.sh does not validate the rehearsal compose file'
}

# --- tests: G1, the local daemon --------------------------------------------------------------------------------------------------------

t_g1_local_daemon_passes() {
    good_world
    run_script -- self-check
    expect_rc 'a local default-context daemon' 0
    assert_read_only 'self-check'
}

t_g1_context() {
    local ctx
    for ctx in desktop-linux remote-staging; do
        good_world
        put "$W" context "$ctx"
        run_script -- self-check
        expect_refused "context $ctx" "not 'default'"
        [[ "$(calls)" != *"ps "* ]] || bad "context $ctx: it went on to list containers"
    done
}

t_g1_context_env() {
    good_world
    run_script DOCKER_CONTEXT=other -- self-check
    expect_refused 'DOCKER_CONTEXT' 'DOCKER_CONTEXT is set'
    assert_no_docker_call 'DOCKER_CONTEXT'
}

t_g1_host() {
    local value
    for value in tcp://10.0.0.5:2375 ssh://ops@staging unix:///var/run/docker.sock; do
        good_world
        run_script DOCKER_HOST="$value" -- self-check
        expect_refused "DOCKER_HOST=$value" 'DOCKER_HOST is set'
        assert_no_docker_call "DOCKER_HOST=$value"
    done
}

t_g1_tls() {
    good_world
    run_script DOCKER_TLS_VERIFY=1 -- self-check
    expect_refused 'DOCKER_TLS_VERIFY' 'DOCKER_TLS_VERIFY'
    good_world
    run_script DOCKER_CERT_PATH=/certs -- self-check
    expect_refused 'DOCKER_CERT_PATH' 'DOCKER_TLS_VERIFY or DOCKER_CERT_PATH'
}

t_g1_endpoint() {
    local endpoint
    for endpoint in tcp://10.0.0.5:2375 ssh://ops@staging 'npipe:////./pipe/docker_engine'; do
        good_world
        put "$W" endpoint "$endpoint"
        run_script -- self-check
        expect_refused "endpoint $endpoint" 'not a local unix socket'
    done
}

t_g1_reachable() {
    good_world
    : >"$W/info_fail"
    run_script -- self-check
    expect_refused 'daemon not reachable' 'not reachable'
}

t_g1_applies_to_every_command() {
    local cmd
    for cmd in up self-check 'cleanup' 'cleanup --dry-run'; do
        good_world
        put "$W" context desktop-linux
        # shellcheck disable=SC2086 # word splitting of the command and its flag is intended
        run_script -- $cmd
        expect_refused "$cmd on a non-default context" "not 'default'"
        [[ "$(calls)" != *"ps "* && "$(calls)" != *" rm "* ]] || bad "$cmd: it acted after the daemon check refused"
    done
}

# --- tests: G2, inherited environment -----------------------------------------------------------------------------------------------------

t_g2_environment() {
    local var
    for var in COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES DB_HOST DB_PASSWORD MYSQL_PWD MYSQL_HOST MARIADB_ROOT_PASSWORD PGHOST PGPASSWORD POSTGRES_PASSWORD APP_KEY APP_ENV DATABASE_URL REHEARSAL_RUN_TOKEN FILES_CREATE_OK FILES_RW FILES_SPEC; do
        good_world
        run_script "$var=do-not-print-this-value" -- self-check
        expect_refused "$var" "$var"
        expect_lacks "$var" "$ERR$OUT" 'do-not-print-this-value'
        assert_no_docker_call "$var"
    done
}

t_g2_unrelated_variables_pass() {
    good_world
    run_script LANG=C TERM=xterm FLC_REHEARSAL_ROOT="$T/root" -- self-check
    expect_rc 'unrelated variables' 0
}

t_g2_applies_to_cleanup() {
    good_world
    run_script DB_HOST=prod.example.org -- cleanup --dry-run
    expect_refused 'cleanup with DB_HOST' 'DB_HOST'
    assert_no_docker_call 'cleanup with DB_HOST'
}

t_g2_docker_sees_a_scrubbed_environment() {
    good_world
    run_script FOO_SECRET=hunter2 TERM=xterm -- self-check
    expect_rc 'scrub' 0
    local names
    names="$(sort -u "$W/envnames" | tr '\n' ' ')"
    expect_lacks 'docker environment' "$names" 'FOO_SECRET'
    expect_lacks 'docker environment' "$names" 'TERM'
}

# --- tests: G3, credentials and the state directory -----------------------------------------------------------------------------------------

t_g3_credentials_per_run_and_private() {
    new_world
    enable_up
    run_script -- up
    expect_rc 'first up' 0
    local first second f
    f="$T/root/compose.env"
    first="$(<"$f")"
    [[ "$("$REAL_STAT" -c %a "$f")" == 600 ]] || bad 'compose.env is not mode 600'
    expect_has 'compose.env' "$first" 'REHEARSAL_DB_ROOT_PASSWORD='
    local value name line
    while IFS= read -r line; do
        name="${line%%=*}" value="${line#*=}" # not `IFS='=' read`: it would drop a key's trailing = padding
        if [[ "$name" == REHEARSAL_APP_KEY || "$name" == REHEARSAL_APP_PREVIOUS_KEY ]]; then
            [[ "$value" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || bad "$name is not a base64: key of 32 bytes"
        else
            [[ ${#value} -ge 32 && "$value" =~ ^[0-9a-f]+$ ]] || bad "a generated value is too short or not hex: ${#value}"
        fi
        expect_lacks 'script output' "$OUT$ERR" "$value"
    done <<<"$first"
    [[ "$(grep -E '^REHEARSAL_APP_KEY=' <<<"$first")" != "$(grep -E '^REHEARSAL_APP_PREVIOUS_KEY=' <<<"$first" | sed 's/PREVIOUS_//')" ]] || bad 'the current and previous keys are the same'
    run_script -- cleanup
    expect_rc 'cleanup' 0
    new_world
    enable_up
    run_script -- up
    expect_rc 'second up' 0
    second="$(<"$f")"
    [[ "$first" != "$second" ]] || bad 'two runs produced identical credentials'
    [[ "$(grep -c . <<<"$second")" == 5 ]] || bad 'compose.env should hold exactly five values (three credentials and two keys)'
    local line
    while IFS= read -r line; do
        grep -qxF "$line" <<<"$first" && bad "a value was reused between runs: ${line%%=*}"
    done <<<"$second"
}

t_g3_compose_env_mode() {
    new_world
    enable_up
    printf '%s|compose.env|644\n' %a >"$T/stat-override"
    run_script -- up
    expect_refused 'credentials mode' 'credentials file is not private'
}

t_g3_dotenv_beside_compose() {
    new_world
    enable_up
    local copy="$T/dotenv-copy"
    rm -rf "$copy"
    cp -r "$SUT" "$copy"
    printf 'DB_HOST=prod\n' >"$copy/.env"
    local saved="$SUT"
    SUT="$copy"
    run_script -- up
    SUT="$saved"
    expect_refused '.env beside compose.yaml' 'a .env file sits beside the compose file'
    [[ ! -e "$T/root/compose.env" ]] || bad 'credentials were written despite the .env'
}

t_g3_root_path_rules() {
    local bad_root
    for bad_root in 'relative/path' '/' "$T/home" "$T/a/../b"; do
        new_world
        run_script FLC_REHEARSAL_ROOT="$bad_root" -- up
        [[ "$RC" == 3 ]] || bad "root '$bad_root' was not refused (exit $RC)"
        assert_no_docker_call "root '$bad_root'"
    done
    new_world
    run_script FLC_REHEARSAL_ROOT='relative/path' -- up
    expect_refused 'relative root' 'not an absolute path'
    new_world
    run_script FLC_REHEARSAL_ROOT='/' -- up
    expect_refused 'filesystem root' 'not an acceptable location'
    new_world
    mkdir -p "$T/elsewhere"
    ln -s "$T/elsewhere" "$T/linkroot"
    run_script FLC_REHEARSAL_ROOT="$T/linkroot" -- up
    expect_refused 'symlinked root' 'symbolic link'
    rm -f "$T/linkroot"
}

t_g3_home_unset() {
    new_world
    run_script HOME= FLC_REHEARSAL_ROOT= -- up
    expect_refused 'no HOME and no FLC_REHEARSAL_ROOT' 'HOME is not set'
    assert_no_docker_call 'no HOME'
}

t_g3_root_must_be_ours() {
    new_world
    mkdir -m 700 "$T/root"
    run_script -- up
    expect_refused 'directory without the marker' 'marker missing'
    new_world
    printf 'x' >"$T/root"
    run_script -- up
    expect_refused 'a file instead of a directory' 'not a directory'
    new_world
    mkdir -m 755 "$T/root"
    printf 'restore-rehearsal-v1\n' >"$T/root/.flc-rehearsal-root"
    run_script -- up
    expect_refused 'a world-readable directory' 'not private'
    new_world
    mkdir -m 700 "$T/root"
    printf 'restore-rehearsal-v1\n' >"$T/root/.flc-rehearsal-root"
    printf '%%u|/root|99999\n' >"$T/stat-override"
    run_script -- up
    expect_refused 'a directory owned by someone else' 'not owned by you'
}

# --- tests: G4, identity ---------------------------------------------------------------------------------------------------------------------

# identity_case KIND FIELD VALUE NEEDLE: a healthy object with one field changed must be reported as not the rehearsal's.
identity_case() {
    local kind="$1" field="$2" value="$3" needle="$4"
    good_world
    case "$kind" in
        c) add_container "$CID" "$field=$value"; run_fn "container_identity_problem $CID $TOKEN" ;;
        n) add_network "$NID" "$field=$value"; run_fn "network_identity_problem $NID $TOKEN ''" ;;
        v) add_volume "$NAME_V" "$field=$value"; run_fn "volume_identity_problem $NAME_V $TOKEN ''" ;;
    esac
    [[ "$RC" == 1 ]] || bad "$kind $field=$value: expected a reported problem (exit 1), got $RC"
    expect_has "$kind $field=$value" "$OUT" "$needle"
}

t_identity_healthy_objects_have_no_problem() {
    good_world
    run_fn "container_identity_problem $CID $TOKEN; network_identity_problem $NID $TOKEN '$CID,'; volume_identity_problem $NAME_V $TOKEN '$CID,'"
    expect_rc 'healthy objects' 0
    [[ -z "$OUT" ]] || bad "healthy objects were reported: $OUT"
}

t_g4_c_name() { identity_case c name /portal_db "its name is"; identity_case c name "/${NAME_C}-old" "its name is"; }
t_g4_c_image() { identity_case c image mirror.gcr.io/library/mariadb:10.11.19 "its image is"; identity_case c image mariadb:10.11.18 "its image is"; }
t_g4_c_label() { identity_case c l_ns '' "lacks the"; identity_case c l_ns other "lacks the"; }
t_g4_c_role() { identity_case c l_role application "role label"; }
t_g4_c_ports() { identity_case c ports 1 "publishes 1 port"; }
t_g4_c_privilege() { identity_case c privileged true "privileged"; identity_case c capadd '["SYS_ADMIN"]' "privileged or adds"; }
t_g4_c_netmode() { identity_case c netmode host "network mode"; identity_case c netmode bridge "network mode"; }
t_g4_c_networks() { identity_case c nets "$NAME_N,flowlife-platform_default," "attached to"; }
t_g4_c_mounts() {
    identity_case c mounts "volume:$NAME_V:/var/lib/mysql,volume:extra-volume:/data," "mounts are"
    identity_case c mounts "volume:other-volume:/var/lib/mysql," "mounts are"
    # A bind mount (a host path, or the Docker socket) cannot be rendered by the inspect template on the real daemon, which errors; the
    # fake does the same. The container must still not count as the rehearsal database: an unreadable container fails closed.
    identity_case c mounts "volume:$NAME_V:/var/lib/mysql,bind::/home/me:/x," "cannot be inspected"
    identity_case c mounts "bind::/var/run/docker.sock:/var/run/docker.sock," "cannot be inspected"
}
t_g4_c_token() {
    good_world
    add_container "$CID" "l_token=$OTHER_TOKEN"
    run_fn "container_identity_problem $CID $TOKEN"
    expect_has 'container token' "$OUT" 'run token differs'
    [[ "$RC" == 1 ]] || bad 'a container with another run token was accepted'
}
t_g4_n_name() { identity_case n name flowlife-platform_default "its name is"; }
t_g4_n_internal() { identity_case n internal false "not an internal network"; }
t_g4_n_driver() { identity_case n driver overlay "driver is"; }
t_g4_n_label() { identity_case n l_ns '' "labels are not"; identity_case n l_role database "labels are not"; }
t_g4_n_token() {
    good_world
    add_network "$NID" "l_token=$OTHER_TOKEN"
    run_fn "network_identity_problem $NID $TOKEN ''"
    expect_has 'network token' "$OUT" 'run token differs'
}
t_g4_n_endpoints() {
    good_world
    add_network "$NID" "endpoints=$CID,$FOREIGN,"
    run_fn "network_identity_problem $NID $TOKEN '$CID,'"
    expect_has 'foreign endpoint' "$OUT" 'is attached'
    [[ "$RC" == 1 ]] || bad 'a network with a foreign container attached was accepted'
}
t_g4_v_name() { identity_case v name flowlife-platform_mariadb-data "its name is"; }
t_g4_v_options() { identity_case v options 1 "plain local volume"; identity_case v driver nfs "plain local volume"; }
t_g4_v_label() { identity_case v l_ns '' "labels are not"; identity_case v l_role database "labels are not"; }
t_g4_v_token() {
    good_world
    add_volume "$NAME_V" "l_token=$OTHER_TOKEN"
    run_fn "volume_identity_problem $NAME_V $TOKEN ''"
    expect_has 'volume token' "$OUT" 'run token differs'
}
t_g8_volume_in_use() {
    good_world
    add_container "$FOREIGN" "name=/someone-elses-db" "l_ns=" "mounts=volume:$NAME_V:/var/lib/mysql,"
    run_fn "volume_identity_problem $NAME_V $TOKEN '$CID,'"
    expect_has 'volume in use' "$OUT" 'that is not ours uses it'
    [[ "$RC" == 1 ]] || bad 'a volume used by a foreign container was accepted'
}

t_g4_version_is_asked_of_the_server() {
    local reported
    for reported in 10.11.19-MariaDB-ubu2204 11.4.13-MariaDB 10.11.180-MariaDB 10.11.1 8.0.36; do
        good_world
        put "$W" version "$reported"
        run_script -- self-check
        expect_refused "server reporting $reported" 'not 10.11.18'
    done
    good_world
    put "$W" version '10.11.18'
    run_script -- self-check
    expect_rc 'a server reporting exactly 10.11.18' 0
}

t_g4_single_container() {
    good_world
    add_container "$FOREIGN" # a second labelled container
    put "$W/c/$FOREIGN" name "/flc-rehearsal-restore-mariadb-2"
    run_script -- self-check
    expect_refused 'two labelled containers' 'exactly one rehearsal container'
    good_world
    rm -rf "$W/c/$CID"
    run_script -- self-check
    expect_refused 'no labelled container but a network' 'exactly one rehearsal container'
}

t_g4_policy_committed_compose_passes() {
    [[ -n "$REAL_DOCKER" ]] || { bad 'the real docker CLI is needed to render the compose file'; return 0; }
    new_world
    local rendered
    rendered="$(render_committed)"
    printf '%s\n' "$rendered" >"$T/rendered-ok"
    RC=0
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    env -i PATH="$BIN" HOME="$T/home" "$BIN/bash" -c 'source "$1"; compose_policy_problem <"$2"' x "$SUT/restore-rehearsal.sh" "$T/rendered-ok" >"$T/out" 2>"$T/err" || RC=$?
    OUT="$(<"$T/out")"
    expect_rc 'the committed compose file' 0
    [[ -z "$OUT" ]] || bad "the committed compose file was reported: $OUT"
}

# policy_case DESC SEDEXPR NEEDLE: the committed file's real rendering, edited, must be refused.
policy_case() {
    local sedexpr="$2" needle="$3"
    render_committed | sed -e "$sedexpr" >"$T/rendered-bad"
    RC=0
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    env -i PATH="$BIN" HOME="$T/home" "$BIN/bash" -c 'source "$1"; compose_policy_problem <"$2"' x "$SUT/restore-rehearsal.sh" "$T/rendered-bad" >"$T/out" 2>"$T/err" || RC=$?
    OUT="$(<"$T/out")"
    [[ "$RC" == 1 ]] || bad "$1: an unsafe compose file was accepted"
    expect_has "$1" "$OUT" "$needle"
}
t_g4_pol_internal() { policy_case 'network not internal' '/^    internal: true$/d' 'not internal'; }
t_g4_pol_forbidden_key() {
    policy_case 'published ports' 's/^    restart: .no.$/    restart: "no"\n    ports:\n      - 3306:3306/' "uses 'ports'"
    policy_case 'host network' 's/^    restart: .no.$/    restart: "no"\n    network_mode: host/' "uses 'network_mode'"
    policy_case 'privileged' 's/^    restart: .no.$/    restart: "no"\n    privileged: true/' "uses 'privileged'"
    policy_case 'capabilities' 's/^    restart: .no.$/    restart: "no"\n    cap_add:\n      - SYS_ADMIN/' "uses 'cap_add'"
    policy_case 'env file' 's/^    restart: .no.$/    restart: "no"\n    env_file:\n      - .env/' "uses 'env_file'"
}
t_g4_pol_forbidden_mount() {
    policy_case 'bind mount' 's/type: volume$/type: bind/' 'mounts a host path'
    policy_case 'docker socket' 's#target: /var/lib/mysql$#target: /var/run/docker.sock#' 'mounts a host path'
}
t_g4_pol_one_service() { policy_case 'a second service' 's/^services:$/services:\n  extra:\n    image: x/' 'exactly one service'; }
t_g4_pol_one_network() { policy_case 'a second network' 's/^networks:$/networks:\n  second:\n    name: x/' 'exactly one network'; }
t_g4_pol_image() { policy_case 'another MariaDB patch version' 's/10\.11\.18/10.11.19/' 'the image is not'; policy_case 'a floating tag' 's/mariadb:10\.11\.18/mariadb:10.11/' 'the image is not'; }
t_g4_pol_name() { policy_case 'container name' 's/^    container_name: .*/    container_name: mariadb/' 'container name is not'; }
t_g4_pol_net_name() { policy_case 'network name' 's/^    name: flc-rehearsal-restore-db$/    name: flowlife-platform_default/' 'network name is not'; }
t_g4_pol_vol_name() { policy_case 'volume name' 's/^    name: flc-rehearsal-restore-db-data$/    name: flowlife-platform_mariadb-data/' 'volume name is not'; }

# Each generated value is required on its own. Compose reports only the first missing variable it meets, and which one that is is not
# specified (the same commit has failed in CI naming a different one each time), so each case supplies the other two and asserts that the
# one withheld is the one named. A fully supplied control shows the file renders, so the refusals are about the missing variable.
t_compose_requires_generated_values() {
    [[ -n "$REAL_DOCKER" ]] || { bad 'the real docker CLI is needed'; return 0; }
    local var supplied other out rc=0
    local all=(REHEARSAL_DB_ROOT_PASSWORD REHEARSAL_DB_APP_PASSWORD REHEARSAL_RUN_TOKEN)
    local envf="$T/required-vars.env"
    # Test-only placeholders, and an environment holding nothing but what the real CLI needs to run.
    compose_with() { env -i PATH="$PATH" HOME="${HOME:-/nonexistent}" "$REAL_DOCKER" compose -p flc-rehearsal-restore --project-directory "$SRC" -f "$SRC/compose.yaml" --env-file "$envf" config 2>&1; }

    printf '%s=placeholder-root\n%s=placeholder-app\n%s=%s\n' "${all[0]}" "${all[1]}" "${all[2]}" "$TOKEN" >"$envf"
    rc=0
    out="$(compose_with)" || rc=$?
    [[ "$rc" == 0 ]] || bad "the compose file did not render with every generated value supplied (exit $rc): ${out:0:300}"

    for var in "${all[@]}"; do
        : >"$envf"
        for supplied in "${all[@]}"; do
            [[ "$supplied" == "$var" ]] && continue
            if [[ "$supplied" == REHEARSAL_RUN_TOKEN ]]; then printf '%s=%s\n' "$supplied" "$TOKEN" >>"$envf"; else printf '%s=placeholder\n' "$supplied" >>"$envf"; fi
        done
        rc=0
        out="$(compose_with)" || rc=$?
        [[ "$rc" != 0 ]] || bad "$var withheld: the compose file rendered anyway"
        expect_has "$var withheld" "$out" "required variable $var is missing a value"
        for other in "${all[@]}"; do
            [[ "$other" == "$var" ]] && continue
            expect_lacks "$var withheld (reports only the withheld one)" "$out" "required variable $other"
        done
    done
}

t_up_refuses_an_unsafe_rendering_before_creating_anything() {
    new_world
    enable_up
    sed 's/^    internal: true$/    internal: false/' "$W/rendered" >"$W/rendered.bad"
    mv "$W/rendered.bad" "$W/rendered"
    run_script -- up
    expect_refused 'unsafe rendering' 'not internal'
    [[ "$(calls)" != *" up "* ]] || bad 'compose up ran although the rendering was unsafe'
}

# --- tests: G5 and G6, sentinel and allowlist -----------------------------------------------------------------------------------------

t_g5_sentinel_token() {
    good_world
    put "$W" sentinel_token "$OTHER_TOKEN"
    run_script -- self-check
    expect_refused 'a sentinel from another run' "not this run's"
    expect_lacks 'a sentinel from another run' "$OUT" 'sentinel database carries'
    good_world
    rm -f "$W/sentinel_token"
    run_script -- self-check
    expect_refused 'a missing sentinel' 'sentinel database cannot be read'
}

t_g5_sql_is_preceded_by_the_sentinel_check() {
    good_world
    put "$W" sentinel_token "$OTHER_TOKEN"
    run_fn "echo 'DROP TABLE x;' | guarded_sql '$T/root' flc_rehearsal_app"
    expect_refused 'SQL against a server with another sentinel' "not this run's"
    [[ ! -s "$W/sql.log" ]] || bad 'SQL reached the server despite the sentinel mismatch'
}

t_g5_token_format() {
    good_world
    rm -rf "${T:?}/root"
    make_root 'not-a-token'
    run_script -- self-check
    expect_refused 'a malformed token in the state file' '32-character hex'
    good_world
    run_fn "create_sentinel $CID \"x');DROP DATABASE flc_rehearsal_app;--\""
    expect_refused 'a token that is not hex, at creation' '32-character hex'
    [[ ! -s "$W/sql.log" ]] || bad 'SQL was sent with a malformed token'
}

t_g6_allowlist() {
    local db
    for db in flowlife flowlife_test flowlife_upgrade_test mysql information_schema performance_schema sys flc_rehearsal_identity FLC_REHEARSAL_APP 'flc_rehearsal_app ' 'flc_rehearsal_app;DROP' flc_rehearsal_scratch_4 flc_rehearsal_scratch_0 ''; do
        good_world
        RC=0
        # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
        env -i PATH="$BIN" HOME="$T/home" FLC_REHEARSAL_ROOT="$T/root" "$BIN/bash" -c 'source "$1"; echo "DROP DATABASE x;" | guarded_sql "$2" "$3"' x "$SUT/restore-rehearsal.sh" "$T/root" "$db" >"$T/out" 2>"$T/err" || RC=$?
        ERR="$(<"$T/err")"
        expect_refused "target '$db'" "not one of the rehearsal's databases"
        [[ ! -s "$W/sql.log" ]] || bad "SQL reached the server for target '$db'"
        [[ "$(calls)" != *"exec"* ]] || bad "target '$db': the server was contacted"
    done
}

t_g6_allowed_targets_pass() {
    local db
    for db in flc_rehearsal_app flc_rehearsal_scratch_1 flc_rehearsal_scratch_2 flc_rehearsal_scratch_3; do
        good_world
        RC=0
        # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
        env -i PATH="$BIN" HOME="$T/home" FLC_REHEARSAL_ROOT="$T/root" "$BIN/bash" -c 'source "$1"; echo "SELECT 1;" | guarded_sql "$2" "$3"' x "$SUT/restore-rehearsal.sh" "$T/root" "$db" >"$T/out" 2>"$T/err" || RC=$?
        ERR="$(<"$T/err")"
        expect_rc "target '$db'" 0
        expect_has "target '$db'" "$(<"$W/sql.log")" "args=[-D $db]"
    done
}

# --- tests: G7, artisan --------------------------------------------------------------------------------------------------------------------

artisan_check() { # EXPECT(allow|deny) NEEDLE ARGS...
    local expect="$1" needle="$2"
    shift 2
    new_world
    local quoted
    quoted="$(printf '%q ' "$@")"
    run_fn "artisan_problem $quoted"
    if [[ "$expect" == deny ]]; then
        [[ "$RC" == 1 ]] || bad "artisan $*: should be refused"
        [[ -z "$needle" ]] || expect_has "artisan $*" "$OUT" "$needle"
    else
        [[ "$RC" == 0 ]] || bad "artisan $*: should be allowed ($OUT)"
    fi
}
t_g7_allowlist() {
    artisan_check allow '' about
    artisan_check allow '' migrate --force
    artisan_check allow '' migrate:status
    artisan_check allow '' relationships:check
    local c
    for c in migrate:fresh migrate:rollback migrate:reset db:wipe db:seed schedule:run schedule:work queue:work tinker down 'migrate ' ' migrate' MIGRATE ''; do
        artisan_check deny 'not an artisan command the rehearsal allows' "$c"
    done
    artisan_check deny 'not an artisan command' -v resources:assets:prune
}
t_g7_prune_abbreviations_are_refused() {
    # Symfony Console resolves unambiguous abbreviations, so refusing the prune command by its name would not be enough.
    local c
    for c in res:ass:pru resources:assets:pr resources:a:prune r:a:p resources:assets:prun; do artisan_check deny 'not an artisan command' "$c"; done
}
t_g7_prune_dry_run() {
    artisan_check allow '' resources:assets:prune --dry-run
    artisan_check deny 'only with --dry-run' resources:assets:prune
    artisan_check deny 'no option but an exact --dry-run' resources:assets:prune --dry-run=false
}
t_g7_prune_options() {
    artisan_check deny 'no option but an exact --dry-run' resources:assets:prune --dry-run --force
    artisan_check deny 'no option but an exact --dry-run' resources:assets:prune --dry-run -v
    artisan_check deny 'no option but an exact --dry-run' resources:assets:prune --dry-run=1
}

# --- tests: G9, one run at a time, and stale projects ---------------------------------------------------------------------------------------------

t_up_happy_path() {
    new_world
    enable_up
    run_script FOO_SECRET=hunter2 -- up
    expect_rc 'up' 0
    expect_has 'up output' "$OUT" 'Ready.'
    expect_has 'up output' "$OUT" 'nothing is published to the host'
    local log
    log="$(calls)"
    expect_has 'compose project' "$log" '-p flc-rehearsal-restore'
    expect_has 'compose file' "$log" "--env-file $T/root/compose.env"
    expect_has 'compose up' "$log" 'up -d --wait'
    expect_lacks 'prune or global commands' "$log" 'prune'
    expect_lacks 'prune or global commands' "$log" 'system'
    expect_has 'sentinel' "$(<"$W/sql.log")" 'SENTINEL'
    [[ "$(<"$W/sentinel_token")" == "$(sed -n 's/^REHEARSAL_RUN_TOKEN=//p' "$T/root/compose.env")" ]] || bad 'the sentinel does not hold this run'"'"'s token'
    expect_has 'guarded probe' "$(<"$W/sql.log")" "args=[-D flc_rehearsal_app]"
    expect_lacks 'docker environment' "$(sort -u "$W/envnames")" 'FOO_SECRET'
    local secret
    secret="$(sed -n 's/^REHEARSAL_DB_ROOT_PASSWORD=//p' "$T/root/compose.env")"
    expect_lacks 'secrets in output' "$OUT$ERR$log" "$secret"
    run_script -- self-check
    expect_rc 'self-check after up' 0
}

t_g9_stale_state_file() {
    new_world
    make_root
    run_script -- up
    expect_refused 'a state file from a previous run' 'previous run left its state'
    expect_has 'recovery instructions' "$ERR" 'cleanup --dry-run'
    assert_read_only 'stale state'
}

t_g9_labelled_leftovers() {
    local what
    for what in container network volume; do
        new_world
        case "$what" in
            container) add_container "$CID" ;;
            network) add_network "$NID" ;;
            volume) add_volume "$NAME_V" ;;
        esac
        run_script -- up
        expect_refused "a leftover $what" 'earlier run exist'
        expect_has 'recovery instructions' "$ERR" 'cleanup --dry-run'
        assert_read_only "leftover $what"
        [[ ! -e "$T/root/compose.env" ]] || bad "credentials were written despite a leftover $what"
    done
}

t_g9_lookalikes_by_name() {
    new_world
    add_container "$FOREIGN" "l_ns="
    run_script -- up
    expect_refused 'an unlabelled container with the rehearsal'"'"'s name' 'cannot prove it created'
    assert_read_only 'lookalike container'
    new_world
    add_network "$NID" "l_ns="
    run_script -- up
    expect_refused 'an unlabelled network with the rehearsal'"'"'s name' 'cannot prove it created'
    new_world
    add_volume "$NAME_V" "l_ns="
    run_script -- up
    expect_refused 'an unlabelled volume with the rehearsal'"'"'s name' 'cannot prove it created'
    new_world
    add_container "$FOREIGN" "name=/portal_db" "l_ns=" "l_project=flc-rehearsal-restore"
    run_script -- up
    expect_refused 'a container in the Compose project of the same name' 'cannot prove it created'
}

t_g9_lock() {
    new_world
    enable_up
    mkdir -m 700 "$T/root"
    printf 'restore-rehearsal-v1\n' >"$T/root/.flc-rehearsal-root"
    # Another run holds the lock.
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    "$BIN/bash" -c 'exec 9>"$1/run.lock"; flock -n 9 || exit 1; echo held; exec sleep 30' x "$T/root" >"$T/holder.out" 2>&1 &
    local holder=$!
    for _ in {1..50}; do [[ -s "$T/holder.out" ]] && break; sleep 0.1; done
    [[ -s "$T/holder.out" ]] || { bad 'the lock holder did not start'; kill "$holder" 2>/dev/null || true; return 0; }
    run_script -- up
    expect_refused 'a second run' 'holds the lock'
    assert_read_only 'locked run'
    run_script -- cleanup
    expect_refused 'cleanup while another run holds the lock' 'holds the lock'
    kill "$holder" 2>/dev/null || true
    wait "$holder" 2>/dev/null || true
}

t_g9_up_failure_leaves_recovery_instructions() {
    new_world
    enable_up
    : >"$W/up_fail"
    run_script -- up
    expect_rc 'a failed compose up' 1
    expect_has 'recovery' "$ERR" 'cleanup --dry-run'
    expect_has 'recovery' "$ERR" 'nothing was removed'
    [[ -e "$T/root/compose.env" ]] || bad 'the state was discarded; cleanup would lose its token'
    run_script -- up
    expect_refused 'up after a failed up' 'previous run left its state'
}

t_g9_second_up_is_refused() {
    new_world
    enable_up
    run_script -- up
    expect_rc 'first up' 0
    run_script -- up
    expect_refused 'second up' 'previous run left its state'
}

t_g4_up_one_network_and_volume() {
    new_world
    enable_up
    : >"$W/up_extra_network"
    run_script -- up
    expect_refused 'a second labelled network' 'exactly one rehearsal network'
    expect_has 'recovery' "$ERR" 'cleanup --dry-run'
}

t_g4_up_one_volume() {
    new_world
    enable_up
    : >"$W/up_extra_volume"
    run_script -- up
    expect_refused 'a second labelled volume' 'exactly one rehearsal volume'
}

t_g9_selfcheck_state() {
    good_world
    rm -f "$T/root/compose.env"
    run_script -- self-check
    expect_refused 'objects but no state file' 'state file is missing'
    good_world
    add_container "$FOREIGN" "name=/$NAME_C-2" "l_ns="
    rm -rf "$W/c/$CID" "$W/n/$NID" "$W/v/$NAME_V"
    put "$W/c/$FOREIGN" name "/$NAME_C"
    run_script -- self-check
    expect_refused 'a lookalike alone' 'cannot prove it created'
}

# --- tests: G8, cleanup ---------------------------------------------------------------------------------------------------------------------

# A world with the rehearsal project and a crowd of things that must never be touched.
crowded_world() {
    good_world
    add_container "$FOREIGN" "name=/portal_db" "image=mariadb:11.4.13" "l_ns=" "ports=1" "nets=portal_net," "netmode=portal_net" "mounts=volume:portal_db_data:/var/lib/mysql,"
    add_container "$(printf 'e%.0s' {1..64})" "name=/flc-rehearsal-restore-sibling" "l_ns=restore-v1" "nets=" "mounts=" # labelled, but not the rehearsal database
    add_network "$(printf 'c%.0s' {1..64})" "name=flc-rehearsal-restore-db-old" "l_ns=" "endpoints="
    add_network "$(printf 'd%.0s' {1..64})" "name=portal_net" "l_ns=" "internal=false" "endpoints=$FOREIGN,"
    mkdir -p "$W/v/portal_db_data" "$W/v/flc-rehearsal-restore-db-data-old"
    put "$W/v/portal_db_data" name portal_db_data; put "$W/v/flc-rehearsal-restore-db-data-old" name flc-rehearsal-restore-db-data-old
}
SIBLING="$(printf 'e%.0s' {1..64})"

t_g8_cleanup_removes_exactly_the_rehearsal() {
    crowded_world
    run_script -- cleanup
    # The labelled sibling is not the rehearsal's, so it is kept; and a cleanup that keeps a labelled object is incomplete.
    expect_rc 'cleanup that keeps a labelled sibling' 1
    local mutating
    mutating="$(grep -E '^(stop|rm|network rm|volume rm|compose)' "$W/calls" || true)"
    [[ "$mutating" == "$(printf 'stop -t 30 %s\nrm %s\nnetwork rm %s\nvolume rm %s' "$CID" "$CID" "$NID" "$NAME_V")" ]] || bad "unexpected mutating calls: $mutating"
    expect_lacks 'global commands' "$(calls)" 'prune'
    expect_lacks 'global commands' "$(calls)" 'compose down'
    expect_lacks 'global commands' "$(calls)" 'system'
    [[ ! -d "$W/c/$CID" && ! -d "$W/n/$NID" && ! -d "$W/v/$NAME_V" ]] || bad 'a rehearsal object remains'
    local keep
    for keep in "c/$FOREIGN" "c/$SIBLING" "n/$(printf 'c%.0s' {1..64})" "n/$(printf 'd%.0s' {1..64})" "v/portal_db_data" "v/flc-rehearsal-restore-db-data-old"; do
        [[ -d "$W/$keep" ]] || bad "an unrelated object was removed: $keep"
    done
    expect_has 'unknown labelled container is reported' "$OUT" "keep    container ${SIBLING:0:12}"
    expect_has 'the incomplete cleanup says what remains' "$ERR" "remains: container ${SIBLING:0:12}"
    [[ -f "$T/root/compose.env" ]] || bad 'the state was discarded although a labelled object remains'
}

t_g8_unrelated_objects_do_not_block_a_complete_cleanup() {
    crowded_world
    rm -rf "$W/c/$SIBLING" # the labelled sibling was the only thing that is kept; the rest are unlabelled lookalikes and other projects
    run_script -- cleanup
    expect_rc 'cleanup beside unrelated objects' 0
    local keep
    for keep in "c/$FOREIGN" "n/$(printf 'c%.0s' {1..64})" "n/$(printf 'd%.0s' {1..64})" "v/portal_db_data" "v/flc-rehearsal-restore-db-data-old"; do
        [[ -d "$W/$keep" ]] || bad "an unrelated object was removed: $keep"
    done
    [[ ! -e "$T/root" ]] || bad 'the state directory was not removed after a complete cleanup'
}

t_g8_dry_run_changes_nothing() {
    crowded_world
    run_script -- cleanup --dry-run
    expect_rc 'dry run' 0
    assert_read_only 'dry run'
    expect_has 'dry run' "$OUT" "would remove container ${CID:0:12}"
    expect_has 'dry run' "$OUT" "would remove network ${NID:0:12}"
    expect_has 'dry run' "$OUT" "would remove volume $NAME_V"
    [[ -d "$T/root" && -f "$T/root/compose.env" ]] || bad 'a dry run removed the state'
}

t_g8_run_token_mismatch_keeps_everything() {
    good_world
    add_container "$CID" "l_token=$OTHER_TOKEN"
    run_script -- cleanup
    expect_has 'token mismatch' "$OUT" "keep    container ${CID:0:12}"
    expect_has 'token mismatch' "$OUT" 'run token differs'
    expect_rc 'a token mismatch' 1
    [[ -f "$T/root/compose.env" ]] || bad 'the state was discarded although every object was kept'
    [[ -d "$W/c/$CID" ]] || bad 'a container carrying another run token was removed'
    [[ -d "$W/n/$NID" ]] || bad 'the network of a kept container was removed'
    [[ -d "$W/v/$NAME_V" ]] || bad 'the volume of a kept container was removed'
}

t_g8_without_state_a_stale_flag_is_required() {
    good_world
    rm -rf "$T/root"
    run_script -- cleanup
    expect_refused 'cleanup without a state file' 'no state file'
    assert_read_only 'cleanup without state'
    run_script -- cleanup --dry-run
    expect_refused 'cleanup --dry-run without a state file' 'no state file'
    run_script -- cleanup --dry-run --stale
    expect_rc 'cleanup --dry-run --stale' 0
    assert_read_only 'cleanup --dry-run --stale'
    run_script -- cleanup --stale
    expect_rc 'cleanup --stale' 0
    [[ ! -d "$W/c/$CID" && ! -d "$W/n/$NID" && ! -d "$W/v/$NAME_V" ]] || bad '--stale did not remove the verified rehearsal objects'
}

t_g8_stale_does_not_widen_identity() {
    good_world
    rm -rf "$T/root"
    add_container "$CID" "image=mariadb:11.4.13"
    run_script -- cleanup --stale
    expect_rc 'stale with a wrong image' 1
    expect_has 'stale with a wrong image' "$OUT" "keep    container ${CID:0:12}"
    [[ -d "$W/c/$CID" ]] || bad '--stale removed a container whose identity did not verify'
}

t_g8_foreign_container_on_our_network() {
    good_world
    add_container "$FOREIGN" "name=/intruder" "l_ns=" "nets=$NAME_N,"
    add_network "$NID" "endpoints=$CID,$FOREIGN,"
    run_script -- cleanup
    expect_rc 'a foreign container on the network' 1
    expect_has 'foreign endpoint' "$OUT" "keep    network ${NID:0:12}"
    [[ -d "$W/n/$NID" ]] || bad 'a network with a foreign container attached was removed'
    [[ -d "$W/c/$FOREIGN" ]] || bad 'the foreign container was removed'
    [[ ! -d "$W/c/$CID" ]] || bad 'the rehearsal container was not removed'
}

t_g8_volume_in_use_by_a_foreign_container() {
    good_world
    add_container "$FOREIGN" "name=/intruder" "l_ns=" "mounts=volume:$NAME_V:/data,"
    run_script -- cleanup
    expect_rc 'a volume used by a foreign container' 1
    expect_has 'volume in use' "$OUT" 'keep    volume'
    [[ -d "$W/v/$NAME_V" ]] || bad 'a volume used by a foreign container was removed'
}

t_g8_names_alone_select_nothing() {
    good_world
    rm -rf "$W/c" "$W/n" "$W/v"
    mkdir -p "$W/c" "$W/n" "$W/v"
    add_container "$FOREIGN" "l_ns="
    add_network "$NID" "l_ns=" "endpoints="
    add_volume "$NAME_V" "l_ns="
    run_script -- cleanup
    expect_rc 'cleanup of unlabelled lookalikes' 0
    [[ -d "$W/c/$FOREIGN" && -d "$W/n/$NID" && -d "$W/v/$NAME_V" ]] || bad 'an unlabelled object was removed because of its name'
    expect_has 'lookalikes are reported' "$OUT" 'never selected for removal'
    local mutating
    mutating="$(grep -E '^(stop|rm|network rm|volume rm)' "$W/calls" || true)"
    [[ -z "$mutating" ]] || bad "mutating calls were made: $mutating"
}

t_g8_nothing_present() {
    new_world
    make_root
    run_script -- cleanup
    expect_rc 'cleanup of nothing' 0
    expect_has 'cleanup of nothing' "$OUT" 'nothing of the rehearsal'
    [[ ! -e "$T/root" ]] || bad 'the stale state directory was kept'
    new_world
    run_script -- cleanup
    expect_rc 'cleanup with no state and nothing present' 0
}

t_g8_a_failed_removal_keeps_the_state() {
    good_world
    : >"$W/rm_fail"
    run_script -- cleanup
    expect_rc 'a container that cannot be removed' 1
    [[ -f "$T/root/compose.env" ]] || bad 'the state was removed although an object remains'
    [[ -d "$W/n/$NID" && -d "$W/v/$NAME_V" ]] || bad 'a network or volume was removed while the container remained'
}

t_g8_arguments() {
    new_world
    run_script -- cleanup --everything
    expect_rc 'unknown cleanup option' 64
    assert_no_docker_call 'unknown cleanup option'
    run_script -- cleanup all
    expect_rc 'unknown cleanup argument' 64
}

t_g8_reverify_container() {
    good_world
    printf 'printf "%%s" /someone-else >"%s/c/%s/name"\n' "$W" "$CID" >"$W/hook_inspect_c"
    run_script -- cleanup
    expect_rc 'a container that changed after it was classified' 1
    [[ -d "$W/c/$CID" ]] || bad 'a container that stopped matching after it was classified was removed'
    expect_has 'changed container' "$OUT" 'changed since it was checked'
    [[ "$(calls)" != *" rm $CID"* ]] || bad 'rm was called on a container that no longer verified'
}

t_g8_reverify_network() {
    good_world
    printf 'printf "%%s" false >"%s/n/%s/internal"\n' "$W" "$NID" >"$W/hook_inspect_n"
    run_script -- cleanup
    expect_rc 'a network that changed after it was classified' 1
    [[ -d "$W/n/$NID" ]] || bad 'a network that stopped matching after it was classified was removed'
    [[ "$(calls)" != *"network rm"* ]] || bad 'network rm was called on a network that no longer verified'
}

t_g8_reverify_volume() {
    good_world
    printf 'printf "%%s" nfs >"%s/v/%s/driver"\n' "$W" "$NAME_V" >"$W/hook_inspect_v"
    run_script -- cleanup
    expect_rc 'a volume that changed after it was classified' 1
    [[ -d "$W/v/$NAME_V" ]] || bad 'a volume that stopped matching after it was classified was removed'
    [[ "$(calls)" != *"volume rm"* ]] || bad 'volume rm was called on a volume that no longer verified'
}

# --- tests: remediation of the independent review (partial cleanup, call sites, failed listings, fidelity) --------------------------------

mutating_calls() { grep -E '^(stop|rm|network rm|volume rm|compose .* up )' "$W/calls" || true; }

# A cleanup that cannot finish says so, keeps its state, and finishes normally once the cause is gone (no --stale involved).
t_g8_incomplete_cleanup_keeps_state_and_can_be_retried() {
    good_world
    add_container "$FOREIGN" "name=/intruder" "l_ns=" "nets=$NAME_N," "mounts=volume:$NAME_V:/data,"
    add_network "$NID" "endpoints=$CID,$FOREIGN,"
    run_script -- cleanup
    expect_rc 'a cleanup obstructed by a foreign container' 1
    expect_has 'the container is removed' "$OUT" "removed container ${CID:0:12}"
    expect_has 'the network is reported' "$OUT" "keep    network ${NID:0:12}"
    expect_has 'the volume is reported' "$OUT" 'keep    volume'
    expect_has 'incomplete is stated' "$ERR" 'cleanup is NOT complete: 2 rehearsal-labelled object(s) remain'
    expect_has 'the network remains' "$ERR" "remains: network ${NID:0:12}: container ${FOREIGN:0:12} that is not ours is attached"
    expect_has 'the volume remains' "$ERR" "remains: volume $NAME_V"
    expect_has 'the way on is stated' "$ERR" 'then run: restore-rehearsal.sh cleanup'
    expect_lacks 'no removal is reported for the state' "$OUT" 'removed state directory'
    [[ ! -d "$W/c/$CID" && -d "$W/n/$NID" && -d "$W/v/$NAME_V" && -d "$W/c/$FOREIGN" ]] || bad 'the first attempt did not leave exactly the network, the volume and the intruder'
    [[ -f "$T/root/compose.env" ]] || bad 'the state was discarded although labelled objects remain'

    # Still obstructed: the same outcome, and nothing more is removed.
    : >"$W/calls"
    run_script -- cleanup
    expect_rc 'a retry while still obstructed' 1
    [[ -z "$(mutating_calls)" ]] || bad "a retry while obstructed removed something: $(mutating_calls)"
    [[ -f "$T/root/compose.env" ]] || bad 'the state was discarded by a retry that could not finish'

    # The obstruction goes; a plain cleanup (no --stale) completes what is left, although the container is already gone.
    rm -rf "${W:?}/c/$FOREIGN"
    put "$W/n/$NID" endpoints ''
    : >"$W/calls"
    run_script -- cleanup
    expect_rc 'the retry once the obstruction is gone' 0
    expect_has 'the network is removed' "$OUT" "removed network ${NID:0:12}"
    expect_has 'the volume is removed' "$OUT" "removed volume $NAME_V"
    expect_has 'the state is removed last' "$OUT" 'removed state directory'
    [[ ! -d "$W/n/$NID" && ! -d "$W/v/$NAME_V" && ! -e "$T/root" ]] || bad 'the retry did not finish the cleanup'
    expect_lacks 'no --stale was needed' "$(calls)" 'stale'
}

# remove_state removes the three files it wrote and rmdirs. If the directory holds anything else it removes nothing at all, so the marker
# and run token survive and the directory stays one this script recognises; and it never removes what it did not write.
t_g8_state_with_other_entries_is_kept_whole() {
    good_world
    mkdir "$T/root/evidence"
    printf 'x' >"$T/root/evidence/f"
    printf 'precious' >"$T/root/notes.txt"
    printf 'h' >"$T/root/.hidden"
    ln -s "$T/root/notes.txt" "$T/root/link"
    local env_before marker_before
    env_before="$(<"$T/root/compose.env")"
    marker_before="$(<"$T/root/.flc-rehearsal-root")"
    local attempt
    for attempt in first retry; do
        run_script -- cleanup
        expect_rc "cleanup ($attempt) with other entries in the state directory" 1
        expect_has "the entries are named ($attempt)" "$ERR" 'evidence'
        expect_has "the entries are named ($attempt)" "$ERR" 'notes.txt'
        expect_has "dotfiles are named ($attempt)" "$ERR" '.hidden'
        expect_has "links are named ($attempt)" "$ERR" 'link'
        expect_has "the reason is stated ($attempt)" "$ERR" 'nothing in it was removed, and its marker and run token were kept'
        expect_lacks "no removal is reported ($attempt)" "$OUT" 'removed state directory'
        [[ -f "$T/root/compose.env" && "$(<"$T/root/compose.env")" == "$env_before" && "$("$REAL_STAT" -c %a "$T/root/compose.env")" == 600 ]] || bad "compose.env was removed or changed ($attempt)"
        [[ -f "$T/root/.flc-rehearsal-root" && "$(<"$T/root/.flc-rehearsal-root")" == "$marker_before" ]] || bad "the marker was removed or changed ($attempt)"
        [[ -f "$T/root/evidence/f" && -f "$T/root/notes.txt" && -f "$T/root/.hidden" && -L "$T/root/link" ]] || bad "an entry this script did not write was removed ($attempt)"
        [[ ! -d "$W/c/$CID" && ! -d "$W/n/$NID" && ! -d "$W/v/$NAME_V" ]] || bad "the objects were not removed before the state was considered ($attempt)"
    done
    # A known name that is not a plain file (a symlink) is not this script's either.
    rm -rf "${T:?}/root/evidence" "${T:?}/root/notes.txt" "${T:?}/root/.hidden" "${T:?}/root/link"
    printf 'keep' >"$T/elsewhere-target"
    rm -f "${T:?}/root/run.lock"
    ln -s "$T/elsewhere-target" "$T/root/run.lock"
    run_script -- cleanup
    expect_rc 'cleanup with a symlink where the lock file should be' 1
    [[ -f "$T/elsewhere-target" && -f "$T/root/compose.env" ]] || bad 'a symlinked lock file led to a removal'
    # Once the directory holds only what this script wrote, a plain cleanup finishes.
    rm -f "${T:?}/root/run.lock" "${T:?}/elsewhere-target"
    run_script -- cleanup
    expect_rc 'cleanup once only the script'"'"'s own files remain' 0
    expect_has 'the state is removed last' "$OUT" 'removed state directory'
    [[ ! -e "$T/root" ]] || bad 'the state directory was not removed once it held only the script'"'"'s own files'
}

# An entry that appears after the check, while the files are being removed, is still never removed.
t_g8_state_removal_race_is_never_recursive() {
    good_world
    : >"$T/rm-race"
    run_script -- cleanup
    expect_rc 'an entry appears while the state is being removed' 1
    expect_has 'the directory is kept, and says why' "$ERR" 'gained entries while it was being emptied'
    [[ -f "$T/root/late-file" ]] || bad 'an entry that appeared during the removal was deleted'
    [[ -d "$T/root" ]] || bad 'the state directory was removed although it held another entry'
}

# A dry run against a complete, valid project changes nothing: not a Docker object, not the state files, not their permissions.
# (The lock file is the one thing the script touches: it opens it to take the lock.)
t_g8_dry_run_preserves_a_complete_project() {
    good_world
    snapshot() {
        local f
        (cd "$W" && for f in $(find c n v -type f | sort); do printf '%s=%s\n' "$f" "$(<"$f")"; done)
        (cd "$T/root" && shopt -s dotglob nullglob && for f in *; do [[ "$f" == run.lock ]] || printf '%s %s %s\n' "$f" "$("$REAL_STAT" -c %a "$f")" "$(<"$f")"; done)
        "$REAL_STAT" -c '%a %U' "$T/root"
    }
    local before after
    before="$(snapshot)"
    run_script -- cleanup --dry-run
    expect_rc 'a dry run against a complete project' 0
    after="$(snapshot)"
    [[ "$before" == "$after" ]] || bad 'a dry run changed a Docker object, a state file, its content or its permissions'
    expect_has 'the container is listed' "$OUT" "would remove container ${CID:0:12}"
    expect_has 'the network is listed' "$OUT" "would remove network ${NID:0:12}"
    expect_has 'the volume is listed' "$OUT" "would remove volume $NAME_V"
    expect_lacks 'nothing is kept or noted' "$OUT" 'keep '
    assert_read_only 'a dry run of a complete project'
    [[ "$("$REAL_STAT" -c %a "$T/root/compose.env")" == 600 && "$("$REAL_STAT" -c %a "$T/root")" == 700 ]] || bad 'a dry run changed the secret-file or directory permissions'
}

# self-check verifies the network and the volume itself, not only the container.
t_g4_selfcheck_verifies_network() {
    good_world
    add_network "$NID" "internal=false"
    run_script -- self-check
    expect_refused 'self-check with a network that is not internal' "the network is not the rehearsal's"
    expect_lacks 'self-check with a network that is not internal' "$OUT" 'network is internal'
    good_world
    add_container "$FOREIGN" "name=/intruder" "l_ns=" "nets=$NAME_N,"
    add_network "$NID" "endpoints=$CID,$FOREIGN,"
    run_script -- self-check
    expect_refused 'self-check with a foreign container on the network' 'is attached'
}
t_g4_selfcheck_verifies_volume() {
    good_world
    add_volume "$NAME_V" "options=1"
    run_script -- self-check
    expect_refused 'self-check with a volume that has driver options' "the volume is not the rehearsal's"
    expect_lacks 'self-check with a volume that has driver options' "$OUT" 'volume is a plain local volume'
    good_world
    add_container "$FOREIGN" "name=/intruder" "l_ns=" "mounts=volume:$NAME_V:/data,"
    run_script -- self-check
    expect_refused 'self-check with a volume used by a foreign container' 'that is not ours uses it'
}

# Each kind of failed removal is reported as what remains, keeps the state, and is finished by a plain retry.
t_g8_failed_removals_are_reported_and_retried() {
    local flag kind remains
    for flag in rm_fail network_rm_fail volume_rm_fail; do
        good_world
        : >"$W/$flag"
        run_script -- cleanup
        expect_rc "cleanup with $flag" 1
        case "$flag" in
            rm_fail) kind=container; remains="remains: container ${CID:0:12}: could not be removed" ;;
            network_rm_fail) kind=network; remains="remains: network ${NID:0:12}: could not be removed" ;;
            volume_rm_fail) kind=volume; remains="remains: volume $NAME_V: could not be removed" ;;
        esac
        expect_has "$kind removal failure is named" "$ERR" "$remains"
        expect_has "$kind removal failure says the cleanup is incomplete" "$ERR" 'cleanup is NOT complete'
        [[ -f "$T/root/compose.env" ]] || bad "the state was discarded after a failed $kind removal"
        rm -f "${W:?}/$flag"
        run_script -- cleanup
        expect_rc "the retry after a failed $kind removal" 0
        [[ ! -d "$W/c/$CID" && ! -d "$W/n/$NID" && ! -d "$W/v/$NAME_V" && ! -e "$T/root" ]] || bad "the retry after a failed $kind removal did not finish"
    done
}

t_g8_bind_mounted_container_is_kept() {
    good_world
    add_container "$CID" "mounts=volume:$NAME_V:/var/lib/mysql,bind::/home/me:/x,"
    run_script -- cleanup
    expect_rc 'cleanup with a container the template cannot render' 1
    expect_has 'unreadable container' "$OUT" "keep    container ${CID:0:12}: cannot be inspected"
    [[ -z "$(mutating_calls)" ]] || bad "a container that could not be verified led to removals: $(mutating_calls)"
    [[ -d "$W/c/$CID" && -d "$W/n/$NID" && -d "$W/v/$NAME_V" && -f "$T/root/compose.env" ]] || bad 'something was removed on the strength of an unreadable container'
}

# --- cleanup applies the state-directory rules itself ---

t_g3_cleanup_root_path_rules() {
    local r
    for r in 'relative/path' '/' "$T/home" "$T/a/../b"; do
        new_world
        run_script FLC_REHEARSAL_ROOT="$r" -- cleanup --dry-run
        [[ "$RC" == 3 ]] || bad "cleanup accepted the state directory '$r' (exit $RC)"
        assert_no_docker_call "cleanup with root '$r'"
    done
    new_world
    run_script FLC_REHEARSAL_ROOT='relative/path' -- cleanup --dry-run
    expect_refused 'relative root' 'not an absolute path'
    new_world
    run_script FLC_REHEARSAL_ROOT='/' -- cleanup --dry-run
    expect_refused 'filesystem root' 'not an acceptable location'
    new_world
    mkdir -p "$T/elsewhere"
    ln -s "$T/elsewhere" "$T/linkroot"
    run_script FLC_REHEARSAL_ROOT="$T/linkroot" -- cleanup --dry-run
    expect_refused 'symlinked root' 'symbolic link'
    rm -f "$T/linkroot"
}

t_g3_cleanup_root_must_be_ours() {
    # In every case the world holds a complete rehearsal whose token matches the compose.env in the directory; none of it may be touched
    # on the strength of a directory that is not provably this script's.
    local case_name needle
    for case_name in marker mode owner file; do
        good_world
        case "$case_name" in
            marker) rm -f "$T/root/.flc-rehearsal-root"; needle='marker missing' ;;
            mode) chmod 755 "$T/root"; needle='not private' ;;
            owner) printf '%%u|/root|99999\n' >"$T/stat-override"; needle='not owned by you' ;;
            file) rm -rf "${T:?}/root"; printf 'x' >"$T/root"; needle='not a directory' ;;
        esac
        run_script -- cleanup
        expect_refused "cleanup with a state directory failing on '$case_name'" "$needle"
        [[ -z "$(mutating_calls)" ]] || bad "cleanup ($case_name) removed objects despite the state directory: $(mutating_calls)"
        [[ -d "$W/c/$CID" && -d "$W/n/$NID" && -d "$W/v/$NAME_V" ]] || bad "cleanup ($case_name) removed rehearsal objects"
        [[ ! -e "$T/root/run.lock" ]] || bad "cleanup ($case_name) created a lock file in a directory that is not its own"
    done
}

# --- up applies the inherited-environment refusal itself ---

t_g2_applies_to_up() {
    local var
    for var in COMPOSE_FILE DB_HOST; do
        new_world
        enable_up
        run_script "$var=do-not-print-this-value" -- up
        expect_refused "up with $var" "$var"
        expect_lacks "up with $var" "$ERR$OUT" 'do-not-print-this-value'
        assert_no_docker_call "up with $var"
        [[ ! -e "$T/root" ]] || bad "up with $var created the state directory"
    done
}

# --- up verifies what it started before it writes to the server ---

# up_identity_case DESC NEEDLE KIND FIELD VALUE: the fake `compose up` makes one object wrong; up must refuse before creating the sentinel.
up_identity_case() {
    new_world
    enable_up
    up_override "$3" "$4" "$5"
    run_script -- up
    expect_refused "$1" "$2"
    expect_lacks "$1" "$OUT" 'Ready.'
    if [[ -f "$W/sql.log" ]] && grep -q SENTINEL "$W/sql.log"; then bad "$1: the sentinel was created before the identity was verified"; fi
}
t_g4_up_container_identity() {
    up_identity_case 'a container with another image' 'is not the rehearsal database' c image mariadb:11.4.13
    up_identity_case 'a privileged container' 'is not the rehearsal database' c privileged true
    up_identity_case 'a container with another run token' 'run token differs' c l_token "$OTHER_TOKEN"
}
t_g4_up_network_identity() {
    up_identity_case 'a network that is not internal' "the network is not the rehearsal's" n internal false
    up_identity_case 'a network with a foreign container attached' 'is attached' n endpoints "$CID,$FOREIGN,"
}
t_g4_up_volume_identity() {
    up_identity_case 'a volume with driver options' "the volume is not the rehearsal's" v options 1
    up_identity_case 'a volume of another driver' 'plain local volume' v driver nfs
}
t_g4_up_server_version() {
    new_world
    enable_up
    put "$W" version '10.11.19-MariaDB-ubu2204'
    run_script -- up
    expect_refused 'a server reporting another version' 'not 10.11.18'
    if [[ -f "$W/sql.log" ]] && grep -q SENTINEL "$W/sql.log"; then bad 'the sentinel was created on a server of the wrong version'; fi
}

# --- the SQL entry point verifies the container and the server itself, in a shell that verified nothing before ---

t_g5_sql_verifies_container_identity() {
    good_world
    add_container "$CID" "image=mariadb:11.4.13"
    # A CID left over from an earlier verification must not stand in for verifying now.
    run_fn "CID=$CID; echo 'SELECT 1;' | guarded_sql '$T/root' flc_rehearsal_app"
    expect_refused 'SQL against a container that is not the rehearsal database' 'is not the rehearsal database'
    [[ ! -s "$W/sql.log" ]] || bad 'SQL reached a container whose identity did not verify'
}
t_g5_sql_verifies_server_version() {
    good_world
    put "$W" version '11.4.13-MariaDB'
    run_fn "echo 'SELECT 1;' | guarded_sql '$T/root' flc_rehearsal_app"
    expect_refused 'SQL against a server of the wrong version' 'not 10.11.18'
    [[ ! -s "$W/sql.log" ]] || bad 'SQL reached a server of the wrong version'
}

# --- a listing that failed is not an empty inventory ---

LISTINGS=(
    'ps -aq --no-trunc --filter label=io.flowlife*'
    'network ls -q --no-trunc --filter label=io.flowlife*'
    'volume ls -q --filter label=io.flowlife*'
    'ps -a --no-trunc --filter name=*'
    'network ls --no-trunc --filter name=*'
    'volume ls --filter name=*'
    'ps -aq --no-trunc --filter label=com.docker.compose.project=*'
)

t_g9_listing_failure_up() {
    local glob
    for glob in "${LISTINGS[@]}"; do
        new_world
        enable_up
        inject_failure "$glob"
        run_script -- up
        expect_refused "up when '$glob' fails" 'could not list'
        [[ -z "$(mutating_calls)" ]] || bad "up when '$glob' fails went on to change something: $(mutating_calls)"
        [[ ! -e "$T/root/compose.env" ]] || bad "up when '$glob' fails wrote credentials"
    done
}
t_g9_listing_failure_self_check() {
    local glob
    for glob in "${LISTINGS[@]}"; do
        good_world
        inject_failure "$glob"
        run_script -- self-check
        expect_refused "self-check when '$glob' fails" 'could not list'
    done
}
t_g9_listing_failure_cleanup() {
    local glob
    for glob in "${LISTINGS[@]}"; do
        good_world
        inject_failure "$glob"
        run_script -- cleanup
        expect_refused "cleanup when '$glob' fails" 'could not list'
        [[ -z "$(mutating_calls)" ]] || bad "cleanup when '$glob' fails removed something: $(mutating_calls)"
        [[ -f "$T/root/compose.env" && -d "$W/c/$CID" ]] || bad "cleanup when '$glob' fails changed the state or the objects"
    done
}
t_g9_listing_failure_before_sql() {
    good_world
    inject_failure 'ps -aq --no-trunc --filter label=io.flowlife*'
    run_fn "echo 'SELECT 1;' | guarded_sql '$T/root' flc_rehearsal_app"
    expect_refused 'SQL when the container cannot be listed' 'could not list'
    [[ ! -s "$W/sql.log" ]] || bad 'SQL was sent although the container could not be found'
}
t_g8_volume_users_listing_failure() {
    good_world
    inject_failure 'ps -aq --no-trunc --filter volume=*'
    run_fn "volume_identity_problem $NAME_V $TOKEN '$CID,'"
    [[ "$RC" == 1 ]] || bad "a volume whose users cannot be listed was accepted (exit $RC)"
    expect_has 'unlistable users' "$OUT" 'cannot be listed'
    good_world
    inject_failure 'ps -aq --no-trunc --filter volume=*'
    run_script -- cleanup
    expect_rc 'cleanup when the volume users cannot be listed' 1
    [[ -d "$W/v/$NAME_V" ]] || bad 'a volume was removed although its users could not be listed'
    expect_has 'the volume remains' "$ERR" "remains: volume $NAME_V"
}

# --- the fake daemon is faithful where the script's safety depends on it ---

t_fake_daemon_fidelity() {
    good_world
    add_container "$CID" "l_ns="
    run_fn "dkr inspect --type container --format \"\$CONTAINER_FMT\" $CID"
    expect_has 'an absent label renders as the real daemon renders it' "$OUT" '<no value>'
    run_fn "container_identity_problem $CID $TOKEN"
    expect_has 'an absent label is not the rehearsal label' "$OUT" 'lacks the'
    RC=0
    "$BIN/docker" exec -i '' sh -c true </dev/null >/dev/null 2>&1 || RC=$?
    [[ "$RC" != 0 ]] || bad 'the fake daemon accepts an exec with no container id'
}

# --- a malformed state file: why cleanup refuses, and the way on that keeps the evidence ---

t_g5_malformed_state_recovery_path() {
    good_world
    rm -rf "${T:?}/root"
    make_root 'not-a-token'
    local before
    before="$(<"$T/root/compose.env")"
    run_script -- cleanup --stale
    expect_refused 'cleanup --stale with a malformed state file' '32-character hex'
    expect_has 'evidence' "$ERR" 'cp -p'
    expect_has 'never edited' "$ERR" 'never edits, discards or rebuilds'
    expect_has 'the way on' "$ERR" 'compose.env.unreadable'
    expect_has 'the way on' "$ERR" 'outside'
    expect_has 'the way on' "$ERR" 'cleanup --dry-run --stale'
    [[ -z "$(mutating_calls)" ]] || bad 'objects were removed on the strength of a malformed state file'
    [[ "$(<"$T/root/compose.env")" == "$before" ]] || bad 'the malformed state file was altered'
    # Follow the instructions: move it out of the state directory, review, clean up. The moved file is evidence and stays.
    mkdir "$T/evidence-out"
    mv "$T/root/compose.env" "$T/evidence-out/compose.env.unreadable"
    run_script -- cleanup --dry-run --stale
    expect_rc 'dry run after moving the file aside' 0
    expect_has 'the list' "$OUT" "would remove container ${CID:0:12}"
    run_script -- cleanup --stale
    expect_rc 'cleanup --stale after moving the file out' 0
    [[ ! -d "$W/c/$CID" && ! -d "$W/n/$NID" && ! -d "$W/v/$NAME_V" ]] || bad 'the recovery path did not remove the verified objects'
    [[ -f "$T/evidence-out/compose.env.unreadable" && "$(<"$T/evidence-out/compose.env.unreadable")" == "$before" ]] || bad 'the moved-out evidence was lost or altered'
    [[ ! -e "$T/root" ]] || bad 'the state directory was kept although it held only the script'"'"'s own files'
}

# --- the registry: which tests prove which guard ---------------------------------------------------------------------------------------------

# --- tests: slice 2.1, the PHP execution substrate -------------------------------------------------------------------------------------

# A world with the verified PHP image and both commits, with (src_world) or without the prepared source volume.
# The helper scripts' digests as a container would print them (`sha256sum` of each file of the harness directory under test).
harness_sums() { (cd "$SUT/php" && sha256sum -- db-ping.php probe.php rehearse.php files.php); }
# The test-support classes the helpers load, as WU1 attests them (HalfFailedShape ... UpgradeDatabase), and their digests as the fake git gives a commit's blob
# (`BLOB <commit>:<path>`, one line) and as a container would print them from the support directory.
SUPPORT_EXPECTED=(CurrentSnapshot CurrentSnapshotRefusal HalfFailedShape HistoricalFixture HistoricalSnapshot ResourceFileArchive ResourceFileRefusal Totp UpgradeDatabase)
support_sums() { local n; for n in "${SUPPORT_EXPECTED[@]}"; do printf '%s  %s.php\n' "$(printf 'BLOB %s:apps/platform/tests/Support/%s.php\n' "$HEAD_SHA" "$n" | sha256sum | cut -c1-64)" "$n"; done; }
# What `prepare` records, for a state directory that has been prepared: the two commits, each helper script's digest and each support class's.
prepared_text() { printf 'prepared-v2\nhistorical=%s\ncurrent=%s\n' "$HIST" "$HEAD_SHA"; harness_sums | sed 's/^/harness /'; support_sums | sed 's/^/support /'; }
put_prepared_source() {
    prepared_text >"$T/root/prepared.source"
    chmod 600 "$T/root/prepared.source"
}
prep_world() {
    good_world
    put "$W" image_id "$IMG_ID"
    printf '%s %s\nHEAD %s\n' "$HIST" "$HIST" "$HEAD_SHA" >"$W/commits"
    harness_sums >"$W/out_harness_sums"
    support_sums >"$W/out_support_sums"
}
src_world() {
    prep_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source
    put_prepared_source
}

# add_ephemeral ID ROLE [field=value ...]: a runner or preparation container as `docker create` makes it from this script's arguments.
add_ephemeral() {
    local id="$1" role="$2" d name="$NAME_R" mode="$NAME_N" rw=false tmpfs kv mounts
    shift 2
    tmpfs='/tmp,/var/www/html/current/bootstrap/cache,/var/www/html/current/storage,/var/www/html/historical/bootstrap/cache,/var/www/html/historical/storage,'
    if [[ "$role" == prepare ]]; then name="$NAME_P" mode=none rw=true tmpfs='/tmp,'; fi
    mounts="volume:$NAME_SRC:/var/www/html:$rw,"
    # Slice 2.2C-2: the files container has no network, the source volume read-only at its own path, and the files volume (writable when the action writes).
    if [[ "$role" == files ]]; then name="$NAME_F" mode=none tmpfs='/tmp,' mounts="volume:$NAME_SRC:/rehearsal/src:false,volume:$NAME_FV:/var/www/html:true,"; fi
    d="$W/c/$id"
    mkdir -p "$d"
    put "$d" id "$id"; put "$d" name "/$name"; put "$d" image "$IMG_ID"; put "$d" l_ns restore-v1; put "$d" l_role "$role"; put "$d" l_token "$TOKEN"
    put "$d" ports 0; put "$d" privileged false; put "$d" capadd null; put "$d" capdrop '["ALL"]'; put "$d" secopt '["no-new-privileges"]'
    put "$d" readonly_root true; put "$d" user 33:33; put "$d" netmode "$mode"; put "$d" nets "$mode,"
    put "$d" mounts "$mounts"; put "$d" tmpfs "$tmpfs"
    put "$d" memory 1073741824; put "$d" pids 256; put "$d" nanocpus 1000000000
    for kv in "$@"; do put "$d" "${kv%%=*}" "${kv#*=}"; done
}

only_database_left() {
    local d left=''
    for d in "$W/c"/*; do [[ ! -e "$d" ]] || left+="${d##*/} "; done
    [[ "$left" == "$CID " ]] || bad "$1: a container other than the database remains: $left"
}
no_secret_anywhere() { # DESC -- the generated passwords and keys appear in no docker argument, git argument, output or log
    local secret what
    for secret in "$ROOT_PW" "$APP_PW" "$KEY_CUR" "$KEY_PREV" "${KEY_CUR#base64:}" "${KEY_PREV#base64:}"; do
        for what in "$(cat "$W/calls")" "$(cat "$W/gitcalls" 2>/dev/null)" "$OUT" "$ERR"; do
            [[ "$what" != *"$secret"* ]] || bad "$1: a generated password appears in a docker or git argument, in the output or in the error output"
        done
    done
}

t_g10_run_happy_path() {
    local create name allowed_env='^(PATH|HOME|LANG|DB_PASSWORD|APP_KEY|APP_PREVIOUS_KEYS|_|PWD|OLDPWD|SHLVL)$'
    src_world
    run_script -- run current migrate:status
    expect_rc 'run' 0
    expect_has 'run' "$OUT" ok
    only_database_left run
    create="$(grep -E '^create ' "$W/calls" || true)"
    [[ "$(grep -c . <<<"$create")" == 1 ]] || bad "run: expected exactly one create, got: $create"
    expect_has 'image by ID' "$create" " $IMG_ID "
    expect_lacks 'image by tag' "$create" 'flowlife-dev/php'
    [[ "$(grep -cE '^image inspect ' "$W/calls")" == 1 ]] || bad 'the PHP image was not resolved exactly once'
    [[ "$(cat "$W/started")" == "/$NAME_R" ]] || bad "the runner was not started exactly once: $(cat "$W/started" 2>/dev/null)"
    # The password reaches the Docker client only through its environment, which holds nothing else of the caller's.
    [[ "$(cat "$W/create_password_seen.1")" == "$APP_PW" ]] || bad 'the application password was not exported to the docker client'
    [[ "$(cat "$W/create_appkey_seen.1")" == "$KEY_CUR" ]] || bad 'the current key was not exported to the docker client'
    [[ "$(cat "$W/create_previouskeys_seen.1")" == "$KEY_PREV" ]] || bad 'the previous key was not exported to the docker client'
    while IFS= read -r name; do
        [[ "$name" =~ $allowed_env ]] || bad "the docker client that created the runner had the variable $name"
    done <"$W/create_env_names.1"
    no_secret_anywhere 'run'
    # A daemon that lists tmpfs mounts among the mounts must not make a correct runner look wrong.
    src_world
    touch "$W/tmpfs_in_mounts"
    run_script -- run current about
    expect_rc 'tmpfs listed in mounts' 0
}

t_g10_run_argv_isolation() {
    local create flag
    src_world
    run_script -- run historical about
    expect_rc run 0
    create="$(grep -E '^create ' "$W/calls")"
    for flag in '--read-only' '--cap-drop ALL' '--security-opt no-new-privileges' '--user 33:33' '--pids-limit 256' '--memory 1g' '--pull never' \
        "--network $NAME_N" "type=volume,source=$NAME_SRC,target=/var/www/html,readonly" '--workdir /var/www/html/historical' '-e DB_PASSWORD '; do
        expect_has 'runner isolation' "$create" "$flag"
    done
    create="${create%% "$IMG_ID" *}" # the options, not the command that follows the image (it has its own mkdir -p)
    for flag in ' -p ' '--publish' '--privileged' 'docker.sock' 'type=bind' ' -v ' '--env-file' '--network host' '--cap-add' '--add-host' '--volumes-from' 'DB_PASSWORD=' 'ROOT_PASSWORD' '--device'; do
        expect_lacks 'runner isolation' "$create" "$flag"
    done
}

t_g11_fixed_argument_vectors() {
    local name tree
    declare -A want=(
        [about]='php artisan about' [migrate:status]='php artisan migrate:status' [migrate]='php artisan migrate --force --no-interaction'
        [relationships:check]='php artisan relationships:check' [release:show]='php artisan release:show'
        [resources:assets:prune]='php artisan resources:assets:prune --dry-run' [helper:db-ping]='php /var/www/html/harness/db-ping.php'
        [helper:fixture-load]='php /var/www/html/harness/rehearse.php fixture-load' [helper:snapshot]='php /var/www/html/harness/rehearse.php snapshot'
        [helper:snapshot-all]='php /var/www/html/harness/rehearse.php snapshot-all'
        [helper:post-upgrade-mark]='php /var/www/html/harness/rehearse.php mark' [helper:sign-in]='php /var/www/html/harness/rehearse.php signin'
        [helper:half-migrate]='php /var/www/html/harness/rehearse.php half-migrate' [helper:revoke]='php /var/www/html/harness/rehearse.php revoke'
    )
    for name in "${!want[@]}"; do
        tree=historical
        if [[ "$name" == resources:assets:prune ]]; then tree=current; prune_world; else src_world; fi # (the prune dry-run is only run in the current tree, with the files volume)
        run_script -- run "$tree" "$name"
        expect_rc "run $name" 0
        if [[ "$name" == resources:assets:prune ]]; then
            [[ "$(<"$W/calls")" == *' sh /rehearsal/files '"${want[$name]}"* ]] || bad "run $name: the command was not exactly '${want[$name]}', after the files volume's path"
            continue
        fi
        [[ "$(grep -E '^create ' "$W/calls")" == *' sh '"${want[$name]}" ]] || bad "run $name: the command was not exactly '${want[$name]}': $(grep -E '^create ' "$W/calls" | tail -c 120)"
    done
}

t_g11_run_policy() {
    local name
    for name in migrate:fresh migrate:rollback db:wipe tinker res:ass:pru 'migrate --pretend' '--env=testing' '../harness/probe.php' helper:probe schedule:run 'resources:assets:prune --dry-run' ''; do
        src_world
        run_script -- run current "$name"
        expect_refused "run '$name'" 'is not a command the rehearsal runs'
        assert_no_docker_call "run '$name'"
    done
    for args in '' 'current' 'current about extra' 'current about --force' 'main about' 'harness about' '../x about' 'historical,current about'; do
        src_world
        # shellcheck disable=SC2086 # the words are the point
        run_script -- run $args
        expect_rc "run $args" 64
        assert_no_docker_call "run $args"
    done
}

t_g11_artisan_policy_is_applied_to_the_built_vector() {
    # runner_command must ask the artisan policy about the vector it built, so the two cannot drift apart.
    run_fn 'artisan_problem() { echo denied; return 1; }; runner_command migrate:status'
    expect_rc 'a vector the policy refuses' 3
}

t_g2_substrate_commands_refuse_inherited_variables() {
    local c
    for c in prepare 'run current about' probe; do
        src_world
        # shellcheck disable=SC2086
        run_script DB_HOST=elsewhere -- $c
        expect_refused "$c with DB_HOST" 'DB_HOST'
        assert_no_docker_call "$c with DB_HOST"
    done
}

# After `docker create` and before `docker start`, each isolation property is checked against the container Docker made. The fake derives
# the container from the arguments it was given; create_override changes one field afterwards, as a daemon that did not honour it would.
ephemeral_case() { # FIELD VALUE NEEDLE
    src_world
    printf '%s %s\n' "$1" "$2" >"$W/create_override"
    run_script -- run current about
    expect_refused "$1=$2" 'was not created as asked'
    expect_has "$1=$2" "$ERR" "$3"
    [[ ! -e "$W/started" ]] || bad "$1=$2: the runner was started"
    only_database_left "$1=$2"
}
t_g10_e_name() { ephemeral_case name /someone-else 'its name is'; }
t_g10_e_image() { ephemeral_case image "sha256:$(printf 'e%.0s' {1..64})" 'verified PHP image'; }
t_g10_e_label() { ephemeral_case l_ns other 'labels are not'; }
t_g10_e_token() { ephemeral_case l_token "$OTHER_TOKEN" 'run token differs'; }
t_g10_e_ports() { ephemeral_case ports 1 'publishes 1 port'; }
t_g10_e_privilege() { ephemeral_case privileged true 'privileged'; }
t_g10_e_capdrop() { ephemeral_case capdrop null 'drop all capabilities'; }
t_g10_e_nnp() { ephemeral_case secopt null 'no-new-privileges'; }
t_g10_e_readonly() { ephemeral_case readonly_root false 'root filesystem is writable'; }
t_g10_e_user() { ephemeral_case user 0:0 'runs as'; }
t_g10_e_network() { ephemeral_case netmode bridge 'its network is'; }
t_g10_e_mounts() {
    ephemeral_case mounts "volume:$NAME_SRC:/var/www/html:true," 'mounts are'
    ephemeral_case mounts "volume:$NAME_SRC:/var/www/html:false,bind::/var/run/docker.sock:true," 'mounts are'
}
t_g10_e_tmpfs() { ephemeral_case tmpfs '/tmp,' 'temporary filesystems'; }
t_g10_e_limits() {
    ephemeral_case memory 0 'limit'
    ephemeral_case pids 0 'limit'
    ephemeral_case nanocpus 0 'limit'
}

t_g10_a_start_failure_still_removes_the_runner() {
    src_world
    printf 3 >"$W/start_fail"
    run_script -- run current about
    expect_rc 'a runner that fails' 3
    only_database_left 'failed runner'
}

t_g10_the_image_must_resolve_to_an_id() {
    src_world
    rm "$W/image_id"
    run_script -- run current about
    expect_refused 'no image' 'not present locally'
    [[ "$(grep -cE '^(create|pull|build)' "$W/calls")" == 0 ]] || bad 'a missing image led to a create, pull or build'
    src_world
    put "$W" image_id latest
    run_script -- run current about
    expect_refused 'a tag instead of an ID' 'image ID'
    [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad 'a non-ID image led to a create'
}

t_g10_password_must_be_the_generated_form() {
    src_world
    printf 'REHEARSAL_DB_ROOT_PASSWORD=%s\nREHEARSAL_DB_APP_PASSWORD=bbbb\nREHEARSAL_RUN_TOKEN=%s\n' "$ROOT_PW" "$TOKEN" >"$T/root/compose.env"
    run_script -- run current about
    expect_refused 'a short password' 'application database password'
    [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad 'a malformed password led to a create'
}

t_g10_leftover_ephemeral_containers_stop_everything() {
    local c
    for c in 'run current about' prepare self-check; do
        src_world
        [[ "$c" != prepare ]] || rm -rf "$W/v/$NAME_SRC"
        add_ephemeral "$RID" runner
        # shellcheck disable=SC2086
        run_script -- $c
        case "$c" in
            self-check) expect_refused "$c" 'other than the database' ;;
            *) expect_refused "$c" 'other than the database' ;;
        esac
        [[ "$(grep -cE '^(create|volume create)' "$W/calls")" == 0 ]] || bad "$c: created something beside a leftover container"
    done
}

t_g10_run_needs_the_prepared_source() {
    prep_world
    run_script -- run current about
    expect_refused 'no source volume' "has 'prepare' been run"
    [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad 'a run without source led to a create'
    prep_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source l_token="$OTHER_TOKEN"
    run_script -- run current about
    expect_refused 'a source volume of another run' 'run token differs'
}

t_g10_source_volume_identity() {
    vol_case() { # FIELD VALUE NEEDLE
        src_world
        add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source "$1=$2"
        run_fn "source_volume_identity_problem $NAME_SRC $TOKEN ''"
        [[ "$RC" == 1 ]] || bad "$1=$2: expected a reported problem, got $RC"
        expect_has "$1=$2" "$OUT" "$3"
    }
    src_world
    run_fn "source_volume_identity_problem $NAME_SRC $TOKEN ''"
    expect_rc 'a healthy source volume' 0
    vol_case name other 'its name is'
    vol_case options 1 'not a plain local volume'
    vol_case driver nfs 'not a plain local volume'
    vol_case l_role database-data 'labels are not'
    vol_case l_ns other 'labels are not'
    vol_case l_token "$OTHER_TOKEN" 'run token differs'
    src_world
    add_container "$FOREIGN" "name=/someone-else" "l_ns=" "mounts=volume:$NAME_SRC:/data,"
    run_fn "source_volume_identity_problem $NAME_SRC $TOKEN ''"
    expect_has 'a foreign user of the volume' "$OUT" 'that is not ours uses it'
    src_world
    inject_failure 'ps *volume=*'
    run_fn "source_volume_identity_problem $NAME_SRC $TOKEN ''"
    expect_has 'users cannot be listed' "$OUT" 'cannot be listed'
}

# --- tests: prepare ------------------------------------------------------------------------------------------------------------------

t_g10_prepare_happy_path() {
    local creates line i
    prep_world
    run_script -- prepare
    expect_rc prepare 0
    expect_has prepare "$OUT" 'Prepared'
    only_database_left prepare
    expect_has 'volume labels' "$(grep '^volume create' "$W/calls")" "io.flowlife.rehearsal.role=source"
    expect_has 'volume token' "$(grep '^volume create' "$W/calls")" "io.flowlife.rehearsal.run-token=$TOKEN"
    expect_has 'verified commits' "$(cat "$W/gitcalls")" "archive --format=tar $HIST apps/platform"
    expect_has 'current commit' "$(cat "$W/gitcalls")" "archive --format=tar $HEAD_SHA apps/platform"
    expect_has 'historical stdin' "$(cat "$W/stdin.1")" "GIT-ARCHIVE $HIST apps/platform"
    expect_has 'current stdin' "$(cat "$W/stdin.2")" "GIT-ARCHIVE $HEAD_SHA apps/platform"
    # Composer (steps 3 and 4, the only ones with a route out) runs before the harness is copied in (step 5, stdin.5 below).
    expect_has 'harness' "$(tar -tf "$W/stdin.5" | tr '\n' ' ')" 'db-ping.php probe.php'
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c . <<<"$creates")" == 7 ]] || bad "expected seven preparation containers (WU1 adds the support classes' check), got: $creates"
    i=0
    while IFS= read -r line; do
        i=$((i + 1))
        expect_has "step $i" "$line" "--name $NAME_P"
        expect_has "step $i" "$line" '--read-only --cap-drop ALL --security-opt no-new-privileges'
        expect_has "step $i" "$line" "type=volume,source=$NAME_SRC,target=/var/www/html "
        expect_lacks "step $i" "$line" "$NAME_N"
        expect_lacks "step $i" "$line" ' -e '
        expect_lacks "step $i" "$line" 'DB_PASSWORD'
        [[ -z "$(cat "$W/create_password_seen.$i")" ]] || bad "step $i: a password reached the preparation docker client"
        if ((i == 3 || i == 4)); then expect_has "step $i" "$line" '--network bridge'; else expect_has "step $i" "$line" '--network none'; fi
    done <<<"$creates"
    for line in historical current; do
        expect_has "composer $line" "$creates" "composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader --working-dir=/var/www/html/$line"
    done
    # Slice 2.2B: the helper scripts are digested INSIDE the volume (the sixth container, after the harness is copied in), and what prepare
    # records is the two commits and those digests, in a private file.
    expect_has 'the volume check' "$(tail -n 2 <<<"$creates" | head -n 1)" 'exec sha256sum -- "$@" sh /var/www/html/harness db-ping.php probe.php rehearse.php files.php'
    # WU1: and the support classes the helpers load, digested inside the volume by a container with no network (the seventh), after the harness check.
    expect_has 'the support classes check' "$(tail -n 1 <<<"$creates")" 'exec sha256sum -- "$@" sh /var/www/html/current/tests/Support CurrentSnapshot.php CurrentSnapshotRefusal.php HalfFailedShape.php HistoricalFixture.php HistoricalSnapshot.php ResourceFileArchive.php ResourceFileRefusal.php Totp.php UpgradeDatabase.php'
    expect_has 'the support classes check has no network' "$(tail -n 1 <<<"$creates")" '--network none'
    expect_has 'recorded' "$OUT" 'recorded: both commits'
    [[ "$("$REAL_STAT" -c %a "$T/root/prepared.source")" == 600 ]] || bad 'prepared.source is not mode 600'
    [[ "$(<"$T/root/prepared.source")" == "$(prepared_text)" ]] || bad "prepared.source is not exactly the two commits and the helper digests: $(<"$T/root/prepared.source")"
    no_secret_anywhere prepare
}

# G10: what is in the volume must be what is in this checkout, and nothing is recorded otherwise.
t_g10_prepare_verifies_the_harness_in_the_volume() {
    prep_world
    harness_sums | sed 's/^[0-9a-f]\{64\}/ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' >"$W/out_harness_sums"
    run_script -- prepare
    expect_refused 'a helper script in the volume that is not the checkout' 'helper scripts in the source volume are not the ones in this checkout'
    [[ ! -e "$T/root/prepared.source" ]] || bad 'the source was recorded although the volume was not verified'
    prep_world
    harness_sums | head -n 2 >"$W/out_harness_sums"
    run_script -- prepare
    expect_refused 'a volume that holds fewer helper scripts' 'not the ones in this checkout'
    prep_world
    printf '5\n' >"$W/fail_harness_sums"
    run_script -- prepare
    expect_rc 'a digest container that fails' 5
    [[ ! -e "$T/root/prepared.source" ]] || bad 'the source was recorded although the check failed'
}

# WU1: the test-support classes the helpers load are digested inside the volume and compared with the verified commit's own; nothing is recorded otherwise.
t_g10_prepare_verifies_the_support_classes() {
    local got
    prep_world
    support_sums | sed '0,/^[0-9a-f]\{64\}/s//ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' >"$W/out_support_sums"
    run_script -- prepare
    expect_refused 'a support class in the volume that is not the commit'"'"'s' 'test-support classes in the source volume are not the ones the commit'
    [[ ! -e "$T/root/prepared.source" ]] || bad 'the source was recorded although a support class was not verified'
    prep_world
    support_sums | head -n 6 >"$W/out_support_sums"
    run_script -- prepare
    expect_refused 'a volume that holds fewer support classes' 'test-support classes in the source volume are not the ones'
    prep_world
    { support_sums; printf '%s  Extra.php\n' "$(printf 'a%.0s' {1..64})"; } >"$W/out_support_sums"
    run_script -- prepare
    expect_refused 'a volume that holds an extra support class' 'test-support classes in the source volume are not the ones'
    prep_world
    support_sums | tac >"$W/out_support_sums"
    run_script -- prepare
    expect_refused 'support classes in another order' 'test-support classes in the source volume are not the ones'
    prep_world
    printf '5\n' >"$W/fail_support_sums"
    run_script -- prepare
    expect_rc 'a support-class digest container that fails' 5
    [[ ! -e "$T/root/prepared.source" ]] || bad 'the source was recorded although the check failed'
    # The expected digests come from the commit, and a class the commit lacks is a refusal.
    prep_world
    touch "$W/no_support"
    run_script -- prepare
    expect_refused 'a commit without a support class' 'does not hold apps/platform/tests/Support'
    [[ ! -e "$T/root/prepared.source" ]] || bad 'the source was recorded for a commit without its support classes'
    # What is attested is what the helpers load: rehearse.php's and files.php's own lists, and no other.
    got="$({ sed -n '/^function loadSupport/,/^}/p' "$SRC/php/rehearse.php" "$SRC/php/files.php" | grep -o "\['[A-Za-z', ]*'\]" | tr -d "[]' " | tr ',' '\n'; } | LC_ALL=C sort | tr '\n' ' ')"
    [[ "$got" == "$(printf '%s ' "${SUPPORT_EXPECTED[@]}" | tr ' ' '\n' | LC_ALL=C sort | tr '\n' ' ')" ]] || bad "the support classes attested are not the ones the helpers load: loaded [$got]"
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn 'printf "%s\n" "${SUPPORT_FILES[@]}" | LC_ALL=C sort | tr "\n" " "'
    [[ "$OUT" == "$got" ]] || bad "the script's SUPPORT_FILES are not the helpers' loaded classes: [$OUT] against [$got]"

}

t_g10_prepare_uses_only_verified_commits() {
    prep_world
    printf 'HEAD %s\n' "$HEAD_SHA" >"$W/commits"
    run_script -- prepare
    expect_refused 'the historical commit is absent' "$HIST"
    expect_has 'no substitution' "$ERR" 'Nothing was substituted'
    expect_has 'the action is proposed' "$ERR" 'git fetch'
    [[ "$(grep -cE '^(create|volume create)' "$W/calls")" == 0 ]] || bad 'an absent commit led to Docker objects'
    [[ "$(grep -c archive "$W/gitcalls" 2>/dev/null || true)" == 0 ]] || bad 'an absent commit was archived from something else'
    prep_world
    touch "$W/commit_wrong"
    run_script -- prepare
    expect_refused 'a commit that resolves to another ID' 'resolved to'
    prep_world
    touch "$W/no_lockfile"
    run_script -- prepare
    expect_refused 'a commit without a lockfile' 'composer.lock'
    [[ "$(grep -cE '^(create|volume create)' "$W/calls")" == 0 ]] || bad 'a missing lockfile led to Docker objects'
}

t_g10_prepare_never_adopts_an_existing_volume() {
    prep_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source
    run_script -- prepare
    expect_refused 'a prepared volume' 'already exists'
    prep_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" "l_ns="
    run_script -- prepare
    expect_refused 'an unlabelled volume of the same name' 'already exists'
    [[ "$(grep -cE '^(create|volume create)' "$W/calls")" == 0 ]] || bad 'an existing volume was used'
}

t_g10_prepare_verifies_the_volume_it_made() {
    prep_world
    printf 'l_token %s\n' "$OTHER_TOKEN" >"$W/volume_create_override"
    run_script -- prepare
    expect_refused 'a volume with another token' 'source volume is not the rehearsal'
    [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad 'a container was created over an unverified volume'
    expect_has 'recovery' "$ERR" 'cleanup --dry-run'
}

t_g10_prepare_containers_are_verified() {
    prep_world
    printf 'netmode %s\nnets %s,\n' "$NAME_N" "$NAME_N" >"$W/create_override"
    run_script -- prepare
    expect_refused 'a preparation container on the database network' 'was not created as asked'
    [[ ! -e "$W/started" ]] || bad 'a preparation container on the database network was started'
    prep_world
    printf 'readonly_root false\n' >"$W/create_override"
    run_script -- prepare
    expect_refused 'a writable root' 'root filesystem is writable'
}

t_g10_prepare_scrubs_git() {
    prep_world
    run_script GIT_DIR=/elsewhere GIT_WORK_TREE=/elsewhere -- prepare
    expect_rc 'prepare with GIT_DIR set' 0
    expect_lacks 'git environment' "$(sort -u "$W/gitenvnames" | tr '\n' ' ')" 'GIT_DIR'
    expect_lacks 'git environment' "$(sort -u "$W/gitenvnames" | tr '\n' ' ')" 'GIT_WORK_TREE'
}

t_g10_prepare_needs_up() {
    new_world
    run_script -- prepare
    expect_refused 'no state' 'no rehearsal state'
}

# shellcheck disable=SC2016 # grant text contains literal backticks
GRANT_GLOBAL_ALL='GRANT ALL PRIVILEGES ON *.* TO `flc_rehearsal`@`%`'
# shellcheck disable=SC2016 # as above
GRANT_GLOBAL_SELECT='GRANT SELECT ON *.* TO `flc_rehearsal`@`%`'

# --- tests: the application-user SQL path ---------------------------------------------------------------------------------------------

# shellcheck disable=SC2016 # grant text contains literal backticks
t_g12_account_identity_and_grants() {
    acct_case() { # DESC NEEDLE -- the world is prepared by the caller
        run_fn "app_account_problem $CID"
        [[ "$RC" == 1 ]] || bad "$1: expected a reported problem, got $RC"
        expect_has "$1" "$OUT" "$2"
    }
    local db='GRANT ALL PRIVILEGES ON `flc\\_rehearsal\\_app`.* TO `flc_rehearsal`@`%`' usage='GRANT USAGE ON *.* TO `flc_rehearsal`@`%`'
    good_world
    run_fn "app_account_problem $CID"
    expect_rc 'the account as MariaDB creates it' 0
    # An authentication hash in any syntax, and the unescaped spelling of the database name, are not problems.
    printf '%s IDENTIFIED VIA mysql_native_password USING '"'"'*ABC'"'"'\nGRANT ALL PRIVILEGES ON `flc_rehearsal_app`.* TO `flc_rehearsal`@`%%`\n' "$usage" >"$W/app_grants"
    run_fn "app_account_problem $CID"
    expect_rc 'another hash syntax' 0
    good_world; printf '2' >"$W/account_count"; acct_case 'two accounts' 'not exactly one'
    good_world; printf 'root@localhost' >"$W/app_identity"; acct_case 'the client is another account' 'not the application account'
    good_world; touch "$W/app_cannot_connect"; acct_case 'the account cannot connect' 'cannot connect'
    good_world; printf '%s\n%s\nGRANT SELECT ON *.* TO `flc_rehearsal`@`%%`\n' "$usage" "$db" >"$W/app_grants"; acct_case 'a global privilege' 'beyond the rehearsal database'
    good_world; printf 'GRANT ALL PRIVILEGES ON *.* TO `flc_rehearsal`@`%%` WITH GRANT OPTION\n%s\n' "$db" >"$W/app_grants"; acct_case 'global with grant option' 'beyond'
    good_world; printf '%s\n%s\nGRANT SELECT ON `flc_rehearsal_identity`.* TO `flc_rehearsal`@`%%`\n' "$usage" "$db" >"$W/app_grants"; acct_case 'the sentinel database' 'beyond'
    good_world; printf '%s\nGRANT ALL PRIVILEGES ON `flc_rehearsal_%%`.* TO `flc_rehearsal`@`%%`\n' "$usage" >"$W/app_grants"; acct_case 'a wildcard database' 'beyond'
    good_world; printf '%s\n%s WITH GRANT OPTION\n' "$usage" "$db" >"$W/app_grants"; acct_case 'grant option' 'beyond'
    good_world; printf '%s\n%s\nGRANT `admin` TO `flc_rehearsal`@`%%`\n' "$usage" "$db" >"$W/app_grants"; acct_case 'an inherited role' 'beyond'
    good_world; printf '%s\n' "$usage" >"$W/app_grants"; acct_case 'no database privilege at all' 'not exactly USAGE'
    good_world; printf '%s\n%s\n%s\n' "$usage" "$db" "$db" >"$W/app_grants"; acct_case 'a repeated grant' 'not exactly USAGE'
}

# shellcheck disable=SC2016 # the client's own $MARIADB_PASSWORD is text to look for, not an expansion
t_g12_restricted_client_and_guarded_path() {
    local line
    good_world
    run_fn "printf 'SELECT 1;\\n' | guarded_app_sql '$T/root' flc_rehearsal_app"
    expect_rc 'guarded application SQL' 0
    expect_has 'the statement reached the application path' "$(cat "$W/appsql.log")" 'args=[-D flc_rehearsal_app] SELECT 1;'
    line="$(grep -E '^exec -i --user mysql .* sh -c ' "$W/calls" | tail -n 1)"
    [[ -n "$line" ]] || bad 'no restricted client call was made'
    for flag in '--sandbox' '--local-infile=0' '--no-defaults' '--user=flc_rehearsal' '--protocol=tcp' '-u MARIADB_ROOT_PASSWORD' '-u MYSQL_ROOT_PASSWORD' 'EDITOR=/bin/false' 'VISUAL=/bin/false' 'PAGER=cat' 'MYSQL_PAGER=cat' 'MYSQL_PWD="$MARIADB_PASSWORD"'; do
        expect_has 'restricted client' "$line" "$flag"
    done
    for flag in '--force' ' -f ' '--user=root' '-uroot' ' -p' '--password' "$APP_PW" "$ROOT_PW"; do
        expect_lacks 'restricted client' "$line" "$flag"
    done
    [[ "$line" == *'--user mysql'* ]] || bad 'the client does not run as a non-root operating-system user'
    run_fn "printf 'SELECT 1;\\n' | guarded_app_sql '$T/root' flowlife"
    expect_refused 'a development database name' 'not one of the rehearsal'
}

# The grant line exactly as the real client prints it: the entrypoint escapes the underscores (`flc\_rehearsal\_app`) and --batch doubles each
# backslash. This is the regression test for the defect the review found: a comparison against single backslashes never matched.
# shellcheck disable=SC2016 # grant text contains literal backticks
t_g12_grant_escaping_matches_batch_output() {
    local usage='GRANT USAGE ON *.* TO `flc_rehearsal`@`%` IDENTIFIED BY PASSWORD '"'"'*0123456789ABCDEF0123456789ABCDEF01234567'"'"''
    local batch='GRANT ALL PRIVILEGES ON `flc\\_rehearsal\\_app`.* TO `flc_rehearsal`@`%`'
    local single='GRANT ALL PRIVILEGES ON `flc\_rehearsal\_app`.* TO `flc_rehearsal`@`%`'
    good_world
    # The fake's default output is the --batch form: two backslashes before each underscore.
    run_fn "printf 'SELECT 1;\\n' | guarded_app_sql '$T/root' flc_rehearsal_app"
    expect_rc 'the default (--batch) grants' 0
    good_world; printf '%s\n%s\n' "$usage" "$batch" >"$W/app_grants"
    run_fn "app_account_problem $CID"
    expect_rc 'the correctly escaped grant' 0
    expect_lacks 'no problem reported' "$OUT" 'beyond'
    # Anything that is not exactly that line still refuses.
    good_world; printf '%s\n%s\n' "$usage" "$single" >"$W/app_grants"
    run_fn "app_account_problem $CID"
    expect_has 'a single-backslash line is not what --batch prints' "$OUT" 'beyond'
    good_world; printf '%s\n%s WITH GRANT OPTION\n' "$usage" "$batch" >"$W/app_grants"
    run_fn "app_account_problem $CID"
    expect_has 'the escaped grant with grant option' "$OUT" 'beyond'
    good_world; printf '%s\n%s\n%s\n' "$usage" "$batch" 'GRANT SELECT ON `flc\\_rehearsal\\_identity`.* TO `flc_rehearsal`@`%`' >"$W/app_grants"
    run_fn "app_account_problem $CID"
    expect_has 'the escaped grant plus the sentinel database' "$OUT" 'beyond'
    good_world; printf '%s\n%s\n' "$usage" 'GRANT ALL PRIVILEGES ON `flc\\_rehearsal\\_%`.* TO `flc_rehearsal`@`%`' >"$W/app_grants"
    run_fn "app_account_problem $CID"
    expect_has 'an escaped wildcard database' "$OUT" 'beyond'
}

t_g10_cleanup_dry_run_names_each_container_by_its_verified_identity() {
    src_world
    add_ephemeral "$RID" runner
    add_ephemeral "$PID_" prepare
    put "$W/n/$NID" endpoints "$CID,$RID,"
    run_script -- cleanup --dry-run
    expect_rc 'dry run' 0
    expect_has 'the runner' "$OUT" "would remove container ${RID:0:12} ($NAME_R)"
    expect_has 'the preparation container' "$OUT" "would remove container ${PID_:0:12} ($NAME_P)"
    expect_has 'the database' "$OUT" "would remove container ${CID:0:12} ($NAME_C)"
    [[ -d "$W/c/$RID" && -d "$W/c/$PID_" && -d "$W/c/$CID" ]] || bad 'a dry run removed a container'
}

t_g12_there_is_no_root_fallback() {
    good_world
    touch "$W/app_cannot_connect"
    run_fn "printf 'SELECT 1;\\n' | guarded_app_sql '$T/root' flc_rehearsal_app"
    expect_refused 'an account that cannot connect' 'never falls back to root'
    expect_has 'the smallest correction is named' "$ERR" 'GRANT'
    [[ ! -e "$W/appsql.log" ]] || bad 'the statement reached the application path'
    ! grep -q 'SELECT 1' "$W/sql.log" 2>/dev/null || bad 'the statement was sent through the root path instead'
    good_world
    printf '%s\n' "$GRANT_GLOBAL_ALL" >"$W/app_grants"
    run_fn "printf 'DROP DATABASE x;\\n' | guarded_app_sql '$T/root' flc_rehearsal_app"
    expect_refused 'an over-privileged account' 'beyond the rehearsal database'
    [[ ! -e "$W/appsql.log" ]] || bad 'a statement was sent as an over-privileged account'
}

t_g12_run_checks_the_account_first() {
    src_world
    printf '%s\n' "$GRANT_GLOBAL_ALL" >"$W/app_grants"
    run_script -- run current migrate
    expect_refused 'an over-privileged account' 'beyond the rehearsal database'
    [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad 'a runner was created for an over-privileged account'
}

t_g12_self_check_uses_the_application_account() {
    src_world
    run_script -- self-check
    expect_rc 'self-check' 0
    expect_has 'self-check' "$OUT" 'application account answers through the restricted client'
    expect_has 'self-check' "$OUT" 'source volume is a plain local volume'
    assert_read_only 'self-check with a source volume'
    src_world
    printf '%s\n' "$GRANT_GLOBAL_SELECT" >"$W/app_grants"
    run_script -- self-check
    expect_refused 'self-check with an over-privileged account' 'beyond the rehearsal database'
}

t_g10_self_check_verifies_the_source_volume() {
    src_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source l_token="$OTHER_TOKEN"
    run_script -- self-check
    expect_refused 'a source volume of another run' 'source volume is not the rehearsal'
}

t_g10_lookalikes_of_the_new_names() {
    local c
    for c in "$NAME_R" "$NAME_P"; do
        good_world
        add_container "$FOREIGN" "name=/$c" "l_ns="
        run_script -- self-check
        expect_refused "an unlabelled container named $c" 'cannot prove it created'
    done
    good_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" "l_ns="
    run_script -- self-check
    expect_refused 'an unlabelled volume named like the source volume' 'cannot prove it created'
}

# --- tests: the probe ------------------------------------------------------------------------------------------------------------------

probe_output() {
    local line
    for line in php-version user network-external dns-external root-fs source-readonly tmp-writable db-app databases sentinel-denied; do printf 'probe:%s=ok\n' "$line"; done
}

t_g12_probe() {
    src_world
    probe_output >"$W/run_stdout"
    run_script -- probe
    expect_rc probe 0
    expect_has probe "$OUT" 'Probe passed'
    expect_has 'the runner runs the fixed probe in the helper directory' "$(grep -E '^create ' "$W/calls")" '--workdir /var/www/html/harness'
    expect_has 'the fixed probe' "$(grep -E '^create ' "$W/calls")" ' sh php /var/www/html/harness/probe.php'
    only_database_left probe
    assert_read_only_sql_after_probe
}
assert_read_only_sql_after_probe() { # the probe sends no statement that changes anything
    ! grep -qiE 'CREATE|DROP|INSERT|DELETE|ALTER|UPDATE|TRUNCATE|GRANT' "$W/appsql.log" 2>/dev/null || bad 'the probe sent a statement that changes data or schema'
}

t_g12_probe_refuses_what_it_finds() {
    src_world; probe_output >"$W/run_stdout"; touch "$W/app_sees_sentinel"
    run_script -- probe
    expect_refused 'an application account that sees the sentinel' 'can read the sentinel'
    [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad 'the runner was created after the client probe failed'
    src_world; probe_output >"$W/run_stdout"; touch "$W/sandbox_escape"
    run_script -- probe
    expect_refused 'a client sandbox that runs a shell' 'sandbox ran a shell'
    src_world; probe_output | sed 's/network-external=ok/network-external=FAIL/' >"$W/run_stdout"
    run_script -- probe
    expect_refused 'a runner that reaches the network' 'network-external=ok'
    src_world; probe_output | grep -v sentinel-denied >"$W/run_stdout"
    run_script -- probe
    expect_refused 'a probe that did not report a check' 'sentinel-denied=ok'
}

# --- tests: cleanup and the new objects -------------------------------------------------------------------------------------------------

t_g10_cleanup_removes_leftover_ephemerals_and_source() {
    src_world
    add_ephemeral "$RID" runner
    add_ephemeral "$PID_" prepare
    put "$W/n/$NID" endpoints "$CID,$RID,"
    run_script -- cleanup
    expect_rc 'cleanup' 0
    [[ -z "$(ls -A "$W/c")$(ls -A "$W/n")$(ls -A "$W/v")" ]] || bad 'cleanup left a container, network or volume'
    [[ ! -e "$T/root" ]] || bad 'cleanup left the state directory'
    src_world
    add_ephemeral "$PID_" prepare netmode=bridge nets=bridge,
    run_script -- cleanup
    expect_rc 'cleanup of a preparation container that had a route out' 0
    [[ -z "$(ls "$W/c")" ]] || bad 'a preparation container with the bridge network was kept'
}

t_g10_cleanup_keeps_ephemerals_that_do_not_verify() {
    src_world
    add_ephemeral "$RID" runner "mounts=volume:$NAME_SRC:/var/www/html:false,bind::/var/run/docker.sock:true,"
    run_script -- cleanup
    expect_rc 'cleanup with an unverifiable runner' 1
    expect_has 'cleanup' "$ERR" 'remains: container'
    [[ -d "$W/c/$RID" ]] || bad 'a runner that does not verify was removed'
    src_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source l_token="$OTHER_TOKEN"
    run_script -- cleanup
    expect_rc 'cleanup with a source volume of another run' 1
    [[ -d "$W/v/$NAME_SRC" ]] || bad 'a source volume of another run was removed'
    src_world
    add_container "$FOREIGN" "name=/$NAME_R" "l_ns=" "mounts=volume:elsewhere:/x,"
    run_script -- cleanup
    expect_rc 'cleanup beside an unlabelled lookalike' 0
    [[ -d "$W/c/$FOREIGN" ]] || bad 'an unlabelled container named like the runner was removed'
}

# --- tests: slice 2.2A, the first half of the recovery cycle ---------------------------------------------------------------------------------
#
# The harness (php/rehearse.php) runs in the runner, so here it is a fake: out_<key> is what it prints, after_<key> what it changes in the
# world, fail_<key> an exit status (keys: migrate_historical, fixture_load, snapshot_historical, snapshot_current, migrate_current, mark).
# The state files the later stages need are made by the real script's earlier stages, once, and copied (the world's token and keys are fixed).

rows_of() {
    case "$1" in
        account_invitations) echo 4 ;; account_recovery_codes) echo 5 ;; account_totp_factors) echo 3 ;; accounts) echo 11 ;;
        cache | cache_locks | failed_jobs | job_batches | jobs) echo 0 ;; membership_grants) echo 5 ;; migrations) echo 15 ;;
        password_reset_tokens) echo 2 ;; people) echo 13 ;; role_assignments) echo 12 ;; security_events) echo 8 ;; sessions) echo 3 ;;
    esac
}
fake_digest() { printf '%s' "$1" | sha256sum | cut -c1-64; }
table_block() { # KIND SEED: one line per v0.3.0 table, as the harness prints them (a table with no rows has the digest of none)
    local t
    for t in "${HIST_TABLES[@]}"; do
        if [[ "$(rows_of "$t")" == 0 ]]; then printf '%s %s 0 %s\n' "$1" "$t" "$EMPTY_DIGEST"; else printf '%s %s %s %s\n' "$1" "$t" "$(rows_of "$t")" "$(fake_digest "$t$2")"; fi
    done
}
EMPTY_DIGEST="$(printf '[]' | sha256sum | cut -c1-64)" # the harness's digest of no rows
restored_block() { # the snapshot's durable tables as they were, the transient ones empty: what a restore of the two-pass dump leaves
    local t rows
    for t in "${HIST_TABLES[@]}"; do
        rows="$(rows_of "$t")"
        if [[ " ${TRANSIENT[*]} " == *" $t "* ]]; then printf 'table %s 0 %s\n' "$t" "$EMPTY_DIGEST"; else printf 'table %s %s %s\n' "$t" "$rows" "$(fake_digest "${t}before")"; fi
    done
}
reupgraded_block() { # the upgrade's expected lines for a database that was restored first: the durable tables as the upgrade leaves them, the transient ones empty
    local t
    for t in "${HIST_TABLES[@]}"; do
        if [[ " ${TRANSIENT[*]} " == *" $t "* ]]; then printf 'table %s 0 %s\n' "$t" "$EMPTY_DIGEST"; else printf 'table %s %s %s\n' "$t" "$(rows_of "$t")" "$(fake_digest "${t}after")"; fi
    done
}
structure_block() { # KIND (hist: the 16 tables, up: the 34): the structure digest of each, as the harness prints them
    local t
    for t in $(if [[ "$1" == up ]]; then up_names; else hist_names; fi); do printf 'structure %s %s\n' "$t" "$(fake_digest "struct-$t-$1")"; done
}
signin_output() { printf 'signin:enrolment-needs-previous-key=ok\nsignin:password=ok\nsignin:second-factor=ok\nsignin:me=ok\nsignin:rolled-back=ok\n'; restored_block; echo 'signin:complete=ok'; }
fixture_output() { printf 'key:current=%s\nkey:previous=%s\n' "$FP_CUR" "$FP_PREV"; table_block table before; table_block upgrade after; structure_block hist; echo 'fixture:complete=ok'; }
snapshot_output() { table_block table "$1"; structure_block "${2:-hist}"; echo 'snapshot:complete=ok'; } # SEED [hist|up]
hist_names() { printf '%s\n' "${HIST_TABLES[@]}"; }
up_names() { printf '%s\n' "${HIST_TABLES[@]}" "${NEWER_TABLES[@]}"; }
put_dumps() { # [oneline]: the two passes as mariadb-dump writes their parts that the script reads
    local t
    : >"$W/dump_pass1"
    : >"$W/dump_pass2"
    for t in "${HIST_TABLES[@]}"; do
        printf 'DROP TABLE IF EXISTS \x60%s\x60;\nCREATE TABLE \x60%s\x60 (\n  \x60id\x60 int\n) ENGINE=InnoDB;\n' "$t" "$t" >>"$W/dump_pass1"
        [[ " ${TRANSIENT[*]} " == *" $t "* ]] && continue
        # Observed live (mariadb-dump 10.19 of MariaDB 10.11.18, L2, 2026-11-13): the statement's first line ends at VALUES and each row has a line
        # of its own. The single-line form is what the first tests assumed; the script accepts both.
        if [[ "${1:-}" == oneline ]]; then printf 'INSERT INTO \x60%s\x60 VALUES (1),(2);\n' "$t" >>"$W/dump_pass2"; else printf 'INSERT INTO \x60%s\x60 VALUES\n(1),\n(2);\n' "$t" >>"$W/dump_pass2"; fi
    done
}

# The empty rehearsal database with the prepared source, and what the helpers print and change.
stage_world() { src_world; : >"$W/inventory"; }
baseline_world() {
    stage_world
    hist_names >"$W/hist.inv"
    fixture_output >"$W/out_fixture_load"
    snapshot_output before >"$W/out_snapshot_historical"
    printf 'cp "%s/hist.inv" "%s/inventory"\n' "$W" "$W" >"$W/after_migrate_historical"
    put_dumps
}
CACHE_READY=0
ensure_cache() { # runs baseline and take-backup once, and keeps the three state files
    [[ "$CACHE_READY" == 0 ]] || return 0
    baseline_world
    run_script -- baseline
    [[ "$RC" == 0 ]] || bad "preparing the cached baseline: exit $RC: ${ERR:0:300}"
    run_script -- take-backup
    [[ "$RC" == 0 ]] || bad "preparing the cached backup: exit $RC: ${ERR:0:300}"
    rm -rf "${T:?}/cache"
    mkdir -p "$T/cache"
    cp -p "$T/root/historical.snapshot" "$T/root/historical.dump.sql" "$T/root/historical.manifest.json" "$T/cache/"
    CACHE_READY=1
}
install_state() { local f; for f in "$@"; do cp -p "$T/cache/historical.$f" "$T/root/historical.$f"; done; } # snapshot dump.sql manifest.json
install_upgraded_structure() { { echo 'upgraded-structure-v1'; structure_block up; } >"$T/root/upgraded.structure"; chmod 600 "$T/root/upgraded.structure"; }
# A world for each later stage, with the state its predecessors left. Call ensure_cache BEFORE (it builds worlds of its own).
backup_world() { baseline_world; hist_names >"$W/inventory"; install_state snapshot; }
upgrade_world() {
    stage_world
    hist_names >"$W/inventory"
    up_names >"$W/up.inv"
    snapshot_output before >"$W/out_snapshot_historical"
    printf 'cp "%s/up.inv" "%s/inventory"\n' "$W" "$W" >"$W/after_migrate_current"
    snapshot_output after up >"$W/out_snapshot_current"
    echo 'marker:written=ok' >"$W/out_mark"
    install_state snapshot dump.sql manifest.json
}
reproduce_world() {
    stage_world
    up_names >"$W/inventory"
    cp "$W/inventory" "$W/up.inv"
    printf '14\t1\t29\t0\n' >"$W/counts"
    printf 'cp "%s/up.inv" "%s/inventory"\nprintf "13\\t1\\t15\\t1\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/on_import"
    { restored_block; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    install_state snapshot dump.sql manifest.json
}
no_helper_ran() { [[ ! -e "$W/runs" ]] || bad "$1: a helper ran: $(tr '\n' ' ' <"$W/runs")"; }
leftover_dump() { compgen -G "$T/root/.dump.*" >/dev/null; } # a temporary dump file in the state directory
no_dump_or_import() { [[ ! -e "$W/dumpcalls" && ! -e "$W/import.sql" ]] || bad "$1: a dump was taken or a dump was loaded"; }

# --- G14: keys and fingerprints ---

t_g14_vectors_and_forms() {
    local k
    good_world
    run_fn "key_fingerprint '$KEY_CUR'"; [[ "$OUT" == "$FP_CUR" ]] || bad "the bytes 0..31 key fingerprints as '$OUT', not $FP_CUR"
    run_fn "key_fingerprint '$KEY_PREV'"; [[ "$OUT" == "$FP_PREV" ]] || bad "the zero key fingerprints as '$OUT', not $FP_PREV"
    run_fn "key_fingerprint '$KEY_FF'"; [[ "$OUT" == "$FP_FF" ]] || bad "the 0xff key fingerprints as '$OUT', not $FP_FF"
    run_fn "keyring_fingerprint $FP_CUR"; [[ "$OUT" == "$RING_CUR_ONLY" ]] || bad "one-key ring is '$OUT', not $RING_CUR_ONLY"
    run_fn "keyring_fingerprint $FP_CUR $FP_PREV"; [[ "$OUT" == "$RING_CUR_PREV" ]] || bad "two-key ring is '$OUT', not $RING_CUR_PREV"
    run_fn "keyring_fingerprint $FP_PREV $FP_CUR"; [[ "$OUT" == "$RING_CUR_PREV" ]] || bad 'the ring depends on the order of the fingerprints'
    run_fn "keyring_fingerprint $FP_CUR $FP_CUR"; [[ "$OUT" == "$RING_CUR_ONLY" ]] || bad 'a repeated fingerprint changes the ring'
    run_fn "keyring_fingerprint $FP_FF $FP_PREV $FP_CUR"; [[ "$OUT" == "$RING_THREE" ]] || bad "three-key ring is '$OUT', not $RING_THREE"
    for k in "$KEY_CUR" "$KEY_PREV" "$KEY_FF"; do run_fn "key_form_ok '$k'"; expect_rc "valid key form" 0; done
    for k in '' 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=' 'base64:' 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8' "base64:$(printf 'A%.0s' {1..43})" \
        'base64:AAECAwQFBgcICQoLDA0ODw==' 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=extra' 'base64:!AECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8='; do
        run_fn "key_form_ok '$k'"
        [[ "$RC" != 0 ]] || bad "a malformed key was accepted: '${k:0:50}'"
    done
    # Missing required fingerprints are named; a larger live ring is the normal state after a rotation.
    run_fn "keyring_missing '$FP_CUR
$FP_PREV' '$FP_CUR
$FP_FF'"
    [[ "$OUT" == "$FP_PREV" ]] || bad "the missing fingerprint was '$OUT', not $FP_PREV"
    run_fn "keyring_missing '$FP_CUR
$FP_PREV' '$FP_FF
$FP_PREV
$FP_CUR'"
    [[ -z "$OUT" ]] || bad "a live ring that holds every required fingerprint reported '$OUT' missing"
    run_fn "keyring_missing '$FP_CUR
$FP_PREV' ''"
    [[ "$OUT" == "$FP_CUR"$'\n'"$FP_PREV" ]] || bad 'an empty live ring did not report every fingerprint missing'
}

t_g14_key_format() {
    local line
    for line in '' 'REHEARSAL_APP_KEY=' 'REHEARSAL_APP_KEY=AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=' 'REHEARSAL_APP_KEY=base64:AAECAwQFBgcICQoLDA0ODw=='; do
        src_world
        grep -v '^REHEARSAL_APP_KEY=' "$T/root/compose.env" >"$T/root/compose.env.new" || true
        [[ -z "$line" ]] || printf '%s\n' "$line" >>"$T/root/compose.env.new"
        mv "$T/root/compose.env.new" "$T/root/compose.env"
        run_script -- run current about
        expect_refused "key line '${line:0:40}'" 'encryption key REHEARSAL_APP_KEY'
        [[ "$(grep -cE '^create' "$W/calls")" == 0 ]] || bad "key line '${line:0:40}': a runner was created"
    done
    src_world
    sed -i '/^REHEARSAL_APP_PREVIOUS_KEY=/d' "$T/root/compose.env"
    run_script -- run current about
    expect_refused 'no previous key' 'REHEARSAL_APP_PREVIOUS_KEY'
}

t_g14_keys_agree() {
    baseline_world
    sed -i "s/^key:current=.*/key:current=$FP_FF/" "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a different current key' 'not the one this run generated'
    baseline_world
    sed -i "s/^key:previous=.*/key:previous=$FP_FF/" "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a different previous key' 'previous keys'
    [[ ! -e "$T/root/historical.snapshot" ]] || bad 'a snapshot was written for keys that do not agree'
}

# --- G13: baseline ---

t_g13_baseline_happy_path() {
    local creates f
    baseline_world
    run_script -- baseline
    expect_rc baseline 0
    expect_has 'summary' "$OUT" 'Baseline done'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'migrate_historical fixture_load ' ]] || bad "the helpers did not run as migrate then fixture-load in the historical tree: $(tr '\n' ' ' <"$W/runs")"
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c -- '--workdir /var/www/html/historical' <<<"$creates")" == 2 ]] || bad 'both runners were not in the historical tree'
    expect_has 'the historical migration' "$creates" ' php artisan migrate --force --no-interaction'
    expect_has 'the fixture' "$creates" ' php /var/www/html/harness/rehearse.php fixture-load'
    f="$T/root/historical.snapshot"
    [[ "$("$REAL_STAT" -c %a "$f")" == 600 ]] || bad 'the snapshot is not mode 600'
    [[ "$(grep -c '^table ' "$f")" == 16 && "$(grep -c '^upgrade ' "$f")" == 16 && "$(grep -c '^key:' "$f")" == 2 ]] || bad 'the snapshot is not 16 table lines, 16 upgrade lines and two key fingerprints'
    expect_lacks 'the snapshot' "$(<"$f")" "${KEY_CUR#base64:}"
    only_database_left baseline
    no_secret_anywhere baseline
    # The emptiness check came before the first runner.
    [[ "$(grep -n 'information_schema' "$W/appsql.log" | head -n 1 | cut -d: -f1)" == 1 ]] || bad 'the inventory was not read first'
}

t_g13_source_commits() {
    baseline_world
    printf 'HEAD %s\n' "$HEAD_SHA" >"$W/commits"
    run_script -- baseline
    expect_refused 'the historical commit is absent' 'is not in this clone'
    no_helper_ran 'absent commit'
    baseline_world
    touch "$W/commit_wrong"
    run_script -- baseline
    expect_refused 'the historical commit resolves to another' 'resolved to'
    no_helper_ran 'another commit'
    baseline_world
    printf ' M apps/platform/composer.json\n' >"$W/git_status"
    run_script -- baseline
    expect_refused 'an unclean checkout' 'uncommitted or untracked'
    no_helper_ran 'unclean checkout'
}

t_g13_baseline_preconditions() {
    baseline_world
    printf 'sessions\n' >"$W/inventory"
    run_script -- baseline
    expect_refused 'a database that is not empty' 'EMPTY database'
    no_helper_ran 'non-empty database'
    baseline_world
    { hist_names; echo extra_table; } >"$W/hist.inv"
    run_script -- baseline
    expect_refused 'a 17-table result' 'not exactly the 16 tables'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'migrate_historical ' ]] || bad 'the fixture was loaded into a schema that is not the v0.3.0 one'
    baseline_world
    grep -v '^people$' <(hist_names) >"$W/hist.inv"
    run_script -- baseline
    expect_refused 'a 15-table result' 'missing people'
}

t_g13_baseline_fixture() {
    local line
    baseline_world
    sed -i '/^fixture:complete=ok$/d' "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a fixture that does not report completion' 'fixture:complete=ok'
    baseline_world
    sed -i '/^table sessions /d' "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a snapshot without a table' 'not exactly one for each'
    baseline_world
    sed -i 's/^table people 13 /table people 12 /' "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a fixture with 12 people' 'people holds 12'
    baseline_world
    sed -i 's/^table accounts 11 /table accounts 0 /' "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a durable table with no rows' 'durable table accounts is empty'
    baseline_world
    sed -i 's/^\(table accounts 11 \).*/\1nothex/' "$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a malformed digest' 'malformed'
    baseline_world
    line="$(grep -m1 '^table people ' "$W/out_fixture_load")"
    printf '%s\n' "$line" >>"$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a repeated table' 'not exactly one for each'
    [[ ! -e "$T/root/historical.snapshot" ]] || bad 'a snapshot was written from an incomplete fixture'
}

t_g13_previous_stage() {
    local c
    for c in take-backup upgrade reproduce-wp3-01; do
        baseline_world
        run_script -- "$c"
        expect_refused "$c with no snapshot" 'is missing from the state directory'
        no_helper_ran "$c with no snapshot"
        no_dump_or_import "$c with no snapshot"
    done
    ensure_cache
    backup_world
    chmod 644 "$T/root/historical.snapshot"
    run_script -- take-backup
    expect_refused 'a snapshot that is not private' 'not intact'
}

t_g13_backup_required() {
    ensure_cache
    upgrade_world
    rm -f "$T/root/historical.dump.sql" "$T/root/historical.manifest.json"
    run_script -- upgrade
    expect_refused 'upgrade with no backup' 'no complete backup'
    no_helper_ran 'upgrade with no backup'
    upgrade_world
    rm -f "$T/root/historical.manifest.json"
    run_script -- upgrade
    expect_refused 'upgrade with no manifest' 'no complete backup'
    reproduce_world
    rm -f "$T/root/historical.dump.sql"
    run_script -- reproduce-wp3-01
    expect_refused 'reproduce with no dump' 'no complete backup'
    no_dump_or_import 'reproduce with no dump'
}

t_g13_backup_preconditions() {
    ensure_cache
    backup_world
    { hist_names; echo extra_table; } >"$W/inventory"
    run_script -- take-backup
    expect_refused 'a database with an extra table' 'not exactly the 16 tables'
    no_dump_or_import 'extra table'
    backup_world
    snapshot_output changed >"$W/out_snapshot_historical"
    run_script -- take-backup
    expect_refused 'a database that changed since the snapshot' 'no longer equals the snapshot'
    expect_has 'the changed tables are named' "$ERR" 'people'
    no_dump_or_import 'changed database'
}

# --- G15: the backup ---

# shellcheck disable=SC2016 # the client's own $MARIADB_PASSWORD is text to look for, not an expansion
t_g15_take_backup() {
    local f m sha line p1 p2 table
    ensure_cache
    backup_world
    run_script -- take-backup
    expect_rc take-backup 0
    expect_has 'summary' "$OUT" 'Backup done'
    f="$T/root/historical.dump.sql"
    m="$T/root/historical.manifest.json"
    [[ "$("$REAL_STAT" -c %a "$f")" == 600 && "$("$REAL_STAT" -c %a "$m")" == 600 ]] || bad 'the dump or the manifest is not mode 600'
    [[ "$(cat "$W/dump_pass1" "$W/dump_pass2")" == "$(<"$f")" ]] || bad 'the dump is not pass 1 followed by pass 2'
    # The two passes, as the runbook gives them, with the database last and every transient table ignored.
    p1="$(sed -n 1p "$W/dumpcalls")"
    p2="$(sed -n 2p "$W/dumpcalls")"
    [[ "$p1" == 'DUMP args=[--no-data --no-tablespaces flc_rehearsal_app]' ]] || bad "pass 1 was: $p1"
    line='DUMP args=[--no-create-info --single-transaction --quick --no-tablespaces'
    for table in "${TRANSIENT[@]}"; do line+=" --ignore-table=flc_rehearsal_app.$table"; done
    [[ "$p2" == "$line flc_rehearsal_app]" ]] || bad "pass 2 was: $p2"
    [[ "$(grep -c . "$W/dumpcalls")" == 2 ]] || bad 'the dump client was not called exactly twice'
    line="$(grep -E '^exec --user mysql .* sh -c ' "$W/calls" | head -n 1)"
    for sha in 'mariadb-dump' '--no-defaults' '--user=flc_rehearsal' '--protocol=tcp' '-u MARIADB_ROOT_PASSWORD' '-u MYSQL_ROOT_PASSWORD' 'EDITOR=/bin/false' 'PAGER=cat' 'MYSQL_PWD="$MARIADB_PASSWORD"'; do
        expect_has 'dump client' "$line" "$sha"
    done
    for sha in '--user=root' '-uroot' ' -p' '--password' "$APP_PW" "$ROOT_PW"; do expect_lacks 'dump client' "$line" "$sha"; done
    # The manifest: the runbook's, with every value checked against the facts.
    sha="$(sha256sum <"$f" | cut -c1-64)"
    expect_has 'dump checksum' "$(<"$m")" "\"dump_sha256\": \"$sha\","
    expect_has 'dump size' "$(<"$m")" "\"dump_bytes\": $(wc -c <"$f" | tr -d ' '),"
    expect_has 'release' "$(<"$m")" "\"commit\": \"$HIST\""
    expect_has 'release version' "$(<"$m")" '"version": "v0.3.0"'
    expect_has 'database' "$(<"$m")" '"name": "flc_rehearsal_app"'
    expect_has 'server version' "$(<"$m")" '"server_version": "10.11.18-MariaDB-ubu2204"'
    expect_has 'current fingerprint' "$(<"$m")" "\"current_key_fingerprint\": \"$FP_CUR\","
    expect_has 'previous fingerprints' "$(<"$m")" "\"previous_key_fingerprints\": [\"$FP_PREV\"],"
    expect_has 'keyring fingerprint' "$(<"$m")" "\"keyring_fingerprint\": \"$RING_CUR_PREV\","
    expect_has 'the durable tables' "$(<"$m")" '"tables_with_data": ["account_invitations", "account_recovery_codes", "account_totp_factors", "accounts", "membership_grants", "migrations", "people", "role_assignments", "security_events"],'
    expect_has 'the schema-only tables' "$(<"$m")" '"tables_schema_only": ["cache", "cache_locks", "failed_jobs", "job_batches", "jobs", "password_reset_tokens", "sessions"]'
    [[ "$(grep -cE '^  "taken_at": "[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z",$' "$m")" == 1 ]] || bad 'taken_at is not an ISO-8601 UTC time'
    # No secret anywhere: not in the manifest, the output, a docker or git argument. (The dump holds synthetic hashes and ciphertext by nature.)
    for sha in "$KEY_CUR" "$KEY_PREV" "${KEY_CUR#base64:}" "${KEY_PREV#base64:}" "$APP_PW" "$ROOT_PW" "$TOKEN"; do expect_lacks 'the manifest' "$(<"$m")" "$sha"; done
    no_secret_anywhere take-backup
    expect_lacks 'the output' "$OUT" "$TOKEN"
    ! leftover_dump || bad 'a temporary dump file was left behind'
    only_database_left take-backup
}

t_g15_dump_contract() {
    ensure_cache
    # A dump that is not what the contract requires is removed and never becomes a backup.
    check() { # DESC NEEDLE -- the world's dump files have been edited by the caller
        run_script -- take-backup
        expect_refused "$1" "$2"
        [[ ! -e "$T/root/historical.dump.sql" && ! -e "$T/root/historical.manifest.json" ]] || bad "$1: a backup was kept"
        ! leftover_dump || bad "$1: the rejected dump file was left behind"
    }
    backup_world; sed -i '/^CREATE TABLE \x60jobs\x60/,/^) ENGINE/d' "$W/dump_pass1"; check 'a table whose structure is missing' 'does not create exactly'
    backup_world; sed -i '/^DROP TABLE IF EXISTS \x60jobs\x60;/d' "$W/dump_pass1"; check 'a table that is not dropped before it is created' 'does not drop exactly'
    backup_world; printf 'INSERT INTO \x60sessions\x60 VALUES\n(1);\n' >>"$W/dump_pass2"; check 'rows of a transient table' 'rows for other than exactly the durable tables'
    backup_world; printf 'INSERT INTO \x60sessions\x60 VALUES (1);\n' >>"$W/dump_pass2"; check 'rows of a transient table, on one line' 'rows for other than exactly the durable tables'
    backup_world; sed -i '/^INSERT INTO \x60people\x60/,/;$/d' "$W/dump_pass2"; check 'a durable table without rows' 'rows for other than exactly the durable tables'
    # An INSERT in a form the check does not know is refused as such, also when a recognised statement for the same table is present (the
    # statements are counted before the names are made unique) and also when it is the only one (never read as a table without rows).
    backup_world; printf 'INSERT INTO \x60people\x60 (\x60id\x60) VALUES (1);\n' >>"$W/dump_pass2"; check 'an unrecognised INSERT beside a recognised one' 'INSERT statement in a form this check does not recognise'
    backup_world; sed -i '/^INSERT INTO \x60people\x60/,/;$/c\INSERT IGNORE INTO \x60people\x60 VALUES (1);' "$W/dump_pass2"; check 'an unrecognised INSERT alone' 'INSERT statement in a form this check does not recognise'
    backup_world; printf 'USE other_db;\n' >>"$W/dump_pass2"; check 'a dump that names a database' 'names a database'
    backup_world; printf 'CREATE DATABASE other_db;\n' >>"$W/dump_pass1"; check 'a dump that creates a database' 'names a database'
    backup_world; printf 'CREATE TABLE \x60extra\x60 (\n  \x60id\x60 int\n) ENGINE=InnoDB;\n' >>"$W/dump_pass1"; check 'a table beyond the sixteen' 'does not create exactly'
    backup_world; touch "$W/dump_fail"; run_script -- take-backup; expect_rc 'a dump client that fails' 1
    [[ ! -e "$T/root/historical.manifest.json" && ! -e "$T/root/historical.dump.sql" ]] || bad 'a backup was kept for a failed dump'
    ! leftover_dump || bad 'the temporary file of a failed dump was left behind'
}

# Both shapes of mariadb-dump's INSERT are backups: the one the tool prints (rows on the lines after VALUES) and the single-line form.
t_g15_dump_insert_forms() {
    ensure_cache
    backup_world
    run_script -- take-backup
    expect_rc 'a dump with the rows on the lines after VALUES' 0
    grep -q '^INSERT INTO .people. VALUES$' "$T/root/historical.dump.sql" || bad 'the test dump is not in the form mariadb-dump prints'
    rm -f "$T/root/historical.dump.sql" "$T/root/historical.manifest.json"
    backup_world
    put_dumps oneline
    run_script -- take-backup
    expect_rc 'a dump with each statement on one line' 0
    grep -qF 'VALUES (1),(2);' "$T/root/historical.dump.sql" || bad 'the test dump is not in the single-line form'
}

t_g15_no_overwrite() {
    ensure_cache
    backup_world
    install_state dump.sql manifest.json
    run_script -- take-backup
    expect_refused 'a second backup' 'never overwritten'
    no_dump_or_import 'a second backup'
    backup_world
    install_state manifest.json
    run_script -- take-backup
    expect_refused 'a manifest with no dump' 'never overwritten'
}

t_g12_the_dump_runs_the_account_checks() {
    good_world
    printf '%s\n' "$GRANT_GLOBAL_ALL" >"$W/app_grants"
    run_fn "guarded_app_dump '$T/root' flc_rehearsal_app --no-data"
    expect_refused 'an over-privileged account' 'beyond the rehearsal database'
    [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken as an over-privileged account'
    good_world
    run_fn "guarded_app_dump '$T/root' flowlife --no-data"
    expect_refused 'a development database name' 'not one of the rehearsal'
    [[ ! -e "$W/dumpcalls" ]] || bad 'a development database was dumped'
    good_world
    put "$W" sentinel_token "$OTHER_TOKEN"
    run_fn "guarded_app_dump '$T/root' flc_rehearsal_app --no-data"
    expect_refused 'a server that is not this run' "sentinel"
    [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken from a server whose sentinel is not this run'
}

t_g15_manifest_is_checked() {
    local m
    ensure_cache
    m="$T/root/historical.manifest.json"
    manifest_case() { # DESC SED NEEDLE
        upgrade_world
        sed -i "$2" "$m"
        run_script -- upgrade
        expect_refused "$1" "$3"
        no_helper_ran "$1"
    }
    manifest_case 'another manifest version' 's/"manifest_version": 1,/"manifest_version": 2,/' 'manifest_version'
    manifest_case 'another release commit' "s/\"commit\": \"$HIST\"/\"commit\": \"$(printf 'a%.0s' {1..40})\"/" 'not for the historical commit'
    manifest_case 'another run' 's/"run_token_fingerprint": "[0-9a-f]*"/"run_token_fingerprint": "0000000000000000"/' "not this run's"
    manifest_case 'a table missing from the inventory' 's/"cache", //' "not the snapshot's"
    manifest_case 'a table that is not in the snapshot' 's/"jobs", /"jobs", "extra_table", /' "not the snapshot's"
    manifest_case 'a transient table classed as durable' 's/"tables_with_data": \[/"tables_with_data": ["sessions", /; s/, "sessions"\]/]/' "not the contract's"
    manifest_case 'a keyring fingerprint that does not follow' 's/"keyring_fingerprint": "[0-9a-f]*"/"keyring_fingerprint": "0000000000000000"/' 'keyring fingerprint is not'
    manifest_case 'a listed fingerprint that was changed' "s/\"current_key_fingerprint\": \"$FP_CUR\"/\"current_key_fingerprint\": \"$FP_FF\"/" 'keyring fingerprint is not'
    reproduce_world
    sed -i 's/"run_token_fingerprint": "[0-9a-f]*"/"run_token_fingerprint": "0000000000000000"/' "$m"
    run_script -- reproduce-wp3-01
    expect_refused 'reproduce with another run'"'"'s manifest' "not this run's"
    no_dump_or_import 'another run'"'"'s manifest'
}

t_g15_dump_matches_manifest() {
    local f m
    ensure_cache
    f="$T/root/historical.dump.sql"
    m="$T/root/historical.manifest.json"
    upgrade_world
    sed -i '0,/^(1),/s//(2),/' "$f" # the same size, another checksum
    run_script -- upgrade
    expect_refused 'a dump altered in place' "SHA-256 is not the manifest's"
    no_helper_ran 'a dump altered in place'
    upgrade_world
    sed -i 's/"dump_bytes": [0-9]*,/"dump_bytes": 1,/' "$m"
    run_script -- upgrade
    expect_refused 'a manifest with another size' "size is not the manifest's"
    no_helper_ran 'a manifest with another size'
    reproduce_world
    printf '\n' >>"$f"
    run_script -- reproduce-wp3-01
    expect_refused 'a dump that grew' 'dump cannot be relied on'
    no_dump_or_import 'a dump that grew'
}


# --- G13: upgrade ---

t_g13_upgrade_happy_path() {
    local creates
    ensure_cache
    upgrade_world
    run_script -- upgrade
    expect_rc upgrade 0
    expect_has 'summary' "$OUT" 'Upgrade done'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical migrate_current snapshot_current mark ' ]] || bad "the helpers did not run in the expected order: $(tr '\n' ' ' <"$W/runs")"
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c -- '--workdir /var/www/html/historical' <<<"$creates")" == 1 && "$(grep -c -- '--workdir /var/www/html/current' <<<"$creates")" == 3 ]] || bad 'the runners were not one historical (the check before) and three current'
    expect_has 'the migration' "$creates" ' php artisan migrate --force --no-interaction'
    expect_has 'the marker' "$creates" ' php /var/www/html/harness/rehearse.php mark'
    only_database_left upgrade
    no_secret_anywhere upgrade
    # The migration never ran before the check that the database still equals its snapshot.
    [[ "$(head -n 1 "$W/runs")" == snapshot_historical ]] || bad 'the upgrade began with something other than the snapshot check'
}

t_g13_upgrade_preconditions() {
    ensure_cache
    upgrade_world
    up_names >"$W/inventory"
    run_script -- upgrade
    expect_refused 'a database that is already upgraded' 'not exactly the 16 tables'
    no_helper_ran 'an upgraded database'
    upgrade_world
    snapshot_output changed >"$W/out_snapshot_historical"
    run_script -- upgrade
    expect_refused 'a database that changed since the backup' 'no longer equals the snapshot'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical ' ]] || bad 'a migration ran although the database no longer equals the backup'
}

t_g13_upgrade_results() {
    ensure_cache
    upgrade_world
    grep -v '^sourced_role_grants$' "$W/up.inv" >"$W/up.inv.new"; mv "$W/up.inv.new" "$W/up.inv"
    run_script -- upgrade
    expect_refused 'a 33-table result' 'not exactly the 34 tables'
    expect_has 'the missing table is named' "$ERR" 'missing sourced_role_grants'
    upgrade_world
    { up_names; echo surprise; } >"$W/up.inv"
    run_script -- upgrade
    expect_refused 'a 35-table result' 'extra surprise'
    upgrade_world
    snapshot_output after up | sed 's/^\(table role_assignments [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' >"$W/out_snapshot_current"
    run_script -- upgrade
    expect_refused 'a role assignment table that is not as the upgrade should leave it' 'did not leave the data as expected'
    expect_has 'the table is named' "$ERR" 'role_assignments'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical migrate_current snapshot_current ' ]] || bad 'the marker was written although the upgrade result was wrong'
    upgrade_world
    echo 'something else' >"$W/out_mark"
    run_script -- upgrade
    expect_refused 'a marker that was not reported' 'marker:written=ok'
    upgrade_world
    printf '5\n' >"$W/fail_migrate_current"
    run_script -- upgrade
    expect_rc 'a migration that fails' 5
    expect_has 'recovery is named' "$ERR" 'cleanup'
}

# --- G16: the WP3-01 demonstration ---

t_g16_reproduce_happy_path() {
    local f
    ensure_cache
    reproduce_world
    run_script -- reproduce-wp3-01
    expect_rc reproduce 0
    expect_has 'the failed equality' "$OUT" 'inventory equality assertion FAILED'
    expect_has 'the orphan' "$OUT" 'no Person behind it'
    expect_has 'the checksums that match' "$OUT" 'would pass'
    expect_has 'the verdict' "$OUT" 'WP3-01 reproduced'
    expect_has 'it is not a restoration' "$OUT" 'not a restoration'
    f="$T/root/historical.dump.sql"
    [[ "$(<"$f")" == "$(<"$W/import.sql")" ]] || bad 'what was loaded is not exactly the verified dump' # (the fake's capture drops the final newline)
    expect_has 'the load went through the application account, into the one database' "$(<"$W/appsql.log")" 'IMPORT args=[-D flc_rehearsal_app]'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_current ' ]] || bad "the only helper should be the snapshot after the load: $(tr '\n' ' ' <"$W/runs")"
    # No clearing, no recreation, no other mutation: every docker call is a read, an exec, or the runner's create/start/rm.
    ! grep -qE '^(volume rm|network rm|stop|compose)' "$W/calls" || bad 'the demonstration removed or recreated an object'
    only_database_left reproduce
    no_secret_anywhere reproduce
}

t_g16_preconditions() {
    local fp ring
    ensure_cache
    reproduce_world
    hist_names >"$W/inventory"
    run_script -- reproduce-wp3-01
    expect_refused 'a database that is not upgraded' 'not exactly the 34 tables'
    no_dump_or_import 'a database that is not upgraded'
    reproduce_world
    printf '13\t1\t15\t1\n' >"$W/counts"
    run_script -- reproduce-wp3-01
    expect_refused 'a database that is already in the defective state' 'not the post-upgrade state'
    no_dump_or_import 'a database already in the defective state'
    reproduce_world
    printf '14\t0\t29\t0\n' >"$W/counts"
    run_script -- reproduce-wp3-01
    expect_refused 'a database with no marker profile' 'not the post-upgrade state'
    # A live ring that lacks a fingerprint the backup requires: nothing is loaded.
    reproduce_world
    run_fn "keyring_fingerprint $FP_CUR $FP_FF"
    ring="$OUT"
    reproduce_world
    sed -i "s/\"previous_key_fingerprints\": \\[\"$FP_PREV\"\\]/\"previous_key_fingerprints\": [\"$FP_FF\"]/; s/\"keyring_fingerprint\": \"[0-9a-f]*\"/\"keyring_fingerprint\": \"$ring\"/" "$T/root/historical.manifest.json"
    run_script -- reproduce-wp3-01
    expect_refused 'a backup that requires a key the live ring lacks' "lacks a key fingerprint the backup requires ($FP_FF"
    no_dump_or_import 'a missing key'
    fp="$(grep -c 'DROP' "$W/appsql.log" 2>/dev/null || true)"
    [[ "${fp:-0}" == 0 ]] || bad 'a statement that drops was sent'
}

t_g16_import_is_reverified() {
    ensure_cache
    reproduce_world
    # The dump changes, in place and to the same size, after the checks that start the command and before the load.
    printf "sed -i '0,/^(1),/s//(2),/' '%s/root/historical.dump.sql'\n" "$T" >"$W/hook_app_sql"
    run_script -- reproduce-wp3-01
    expect_refused 'a dump changed after it was checked' 'is not the one the manifest describes'
    [[ ! -e "$W/import.sql" ]] || bad 'a dump that no longer matched its manifest was loaded'
}

t_g16_unexpected_outcomes() {
    ensure_cache
    reproduce_world
    hist_names >"$W/after.inv"
    printf 'cp "%s/after.inv" "%s/inventory"\nprintf "13\\t1\\t15\\t1\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/on_import"
    run_script -- reproduce-wp3-01
    expect_rc 'an inventory that equals the historical one after the load' 1
    expect_has 'the defect did not reproduce' "$ERR" 'did not reproduce'
    reproduce_world
    grep -v '^resource_packs$' "$W/up.inv" >"$W/after.inv"
    printf 'cp "%s/after.inv" "%s/inventory"\nprintf "13\\t1\\t15\\t1\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/on_import"
    run_script -- reproduce-wp3-01
    expect_rc 'only 17 of the 18 newer tables remain' 1
    expect_has 'not as described' "$ERR" 'not as WP3-01 describes'
    reproduce_world
    grep -v '^people$' "$W/up.inv" >"$W/after.inv"
    printf 'cp "%s/after.inv" "%s/inventory"\nprintf "13\\t1\\t15\\t1\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/on_import"
    run_script -- reproduce-wp3-01
    expect_rc 'a historical table missing after the load' 1
    expect_has 'a missing table is not the defect' "$ERR" 'not as WP3-01 describes'
    reproduce_world
    printf 'cp "%s/up.inv" "%s/inventory"\nprintf "13\\t1\\t29\\t0\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/on_import"
    run_script -- reproduce-wp3-01
    expect_rc 'a ledger that was not restored' 1
    expect_has 'the state after the load' "$ERR" 'after the load the state is'
    reproduce_world
    { restored_block | sed 's/^\(table people [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    run_script -- reproduce-wp3-01
    expect_rc 'historical tables that the dump did not restore' 1
    expect_has 'the tables are named' "$ERR" 'people'
    reproduce_world
    touch "$W/import_fail"
    run_script -- reproduce-wp3-01
    expect_rc 'a load that fails' 1
    expect_has 'the failure' "$ERR" 'could not be loaded'
}

t_g16_the_load_is_guarded() {
    ensure_cache
    reproduce_world
    put "$W" sentinel_token "$OTHER_TOKEN"
    run_script -- reproduce-wp3-01
    expect_refused 'a server that is not this run' 'sentinel'
    no_dump_or_import 'a server that is not this run'
    reproduce_world
    printf '%s\n' "$GRANT_GLOBAL_ALL" >"$W/app_grants"
    run_script -- reproduce-wp3-01
    expect_refused 'an over-privileged account' 'beyond the rehearsal database'
    no_dump_or_import 'an over-privileged account'
}

# --- slice 2.2B: the corrected restore, the sign-in and the re-upgrade ---

# The database as `reproduce-wp3-01` leaves it (34 tables, 13 Persons, the marker profile without its Person, 15 migrations), with the state the
# earlier stages leave in the state directory. Loading the dump leaves the 16 tables as the ORIGINAL had them with the transient ones empty, and
# the helpers answer as the restored v0.3.0 application.
restore_world() {
    stage_world
    up_names >"$W/inventory"
    up_names >"$W/up.inv"
    hist_names >"$W/hist.inv"
    printf '13\t1\t15\t1\n' >"$W/counts"
    printf 'cp "%s/hist.inv" "%s/inventory"\n' "$W" "$W" >"$W/on_import"
    { restored_block; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    signin_output >"$W/out_signin"
    install_state snapshot dump.sql manifest.json
    install_upgraded_structure
}
# The restored database, for verify-signin and reupgrade, and what the current application's migration and snapshot then show.
restored_world() {
    restore_world
    hist_names >"$W/inventory"
    printf 'cp "%s/up.inv" "%s/inventory"\nprintf "13\\t0\\t29\\t0\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/after_migrate_current"
    { reupgraded_block; structure_block up; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
}
no_clear_or_import() { [[ ! -e "$W/clear.sql" && ! -e "$W/import.sql" ]] || bad "$1: the schema was cleared or a dump was loaded"; }
restore_refused() { # DESC NEEDLE: the restore refuses, and nothing was cleared or loaded
    run_script -- restore-historical
    expect_refused "$1" "$2"
    no_clear_or_import "$1"
}

t_g17_restore_happy_path() {
    local clear names inv clr imp line
    ensure_cache
    restore_world
    run_script -- restore-historical
    expect_rc restore 0
    expect_has 'summary' "$OUT" 'Restored'
    expect_has 'the marker is gone' "$OUT" 'marker Person and profile are gone'
    expect_has 'durable tables' "$OUT" 'every durable table equals the pre-upgrade snapshot'
    expect_has 'transient tables' "$OUT" 'every transient table is present and empty'
    # What was cleared: exactly the 34 tables, by name, in one DROP, foreign keys off for that session, and nothing else.
    clear="$(<"$W/clear.sql")"
    # shellcheck disable=SC2016 # the backticks are the table quoting in the statement
    names="$(grep -o '`[a-z_]*`' <<<"$clear" | tr -d '`' | LC_ALL=C sort)"
    [[ "$names" == "$(up_names | LC_ALL=C sort)" ]] || bad "the cleared tables are not exactly the 34 tables: $(tr '\n' ' ' <<<"$names")"
    [[ "$(grep -c 'DROP' <<<"$clear")" == 1 ]] || bad 'more than one DROP in the clearing statement'
    for line in 'IF EXISTS' 'DATABASE' 'SCHEMA' '*' 'LIKE' 'TRUNCATE' 'DELETE'; do expect_lacks "the clearing statement has no $line" "$clear" "$line"; done
    expect_has 'foreign keys off for the one session' "$clear" 'SET FOREIGN_KEY_CHECKS=0;'
    [[ "$(<"$T/root/historical.dump.sql")" == "$(<"$W/import.sql")" ]] || bad 'what was loaded is not exactly the verified dump'
    # In order: the inventory is read, the schema is cleared (as the application account, in the one database), then the dump is loaded, and no
    # application helper has run before the clearing: nothing started against the inconsistent schema.
    inv="$(grep -n 'information_schema.tables' "$W/appsql.log" | head -n 1 | cut -d: -f1)"
    clr="$(grep -n '^CLEAR' "$W/appsql.log" | cut -d: -f1)"
    imp="$(grep -n '^IMPORT' "$W/appsql.log" | cut -d: -f1)"
    [[ -n "$inv" && -n "$clr" && -n "$imp" && "$inv" -lt "$clr" && "$clr" -lt "$imp" ]] || bad "the inventory, the clear and the load did not happen in that order ($inv, $clr, $imp)"
    expect_has 'the clear was by the application account in the one database' "$(grep '^CLEAR' "$W/appsql.log")" 'CLEAR args=[-D flc_rehearsal_app]'
    expect_has 'the load was by the application account in the one database' "$(grep '^IMPORT' "$W/appsql.log")" 'IMPORT args=[-D flc_rehearsal_app]'
    [[ ! -s "$W/runs_at_clear" ]] || bad "a helper had run before the schema was cleared: $(tr '\n' ' ' <"$W/runs_at_clear")"
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical ' ]] || bad "the only helper should be the historical snapshot after the load: $(tr '\n' ' ' <"$W/runs")"
    # Nothing but the application account changed the database; no root statement, no other docker mutation.
    if [[ -f "$W/sql.log" ]] && grep -qE 'DROP|INSERT|CREATE TABLE' "$W/sql.log"; then bad 'a root statement changed the schema'; fi
    ! grep -qE '^(volume rm|network rm|stop|compose)' "$W/calls" || bad 'the restore removed or recreated an object'
    [[ "$(grep -E '^exec' "$W/calls" | grep -vc '^exec -i ')" == 0 ]] || bad 'an exec that is not the SQL client was made'
    only_database_left restore
    no_secret_anywhere restore
}

t_g17_starts_from_the_upgraded_state_too() {
    ensure_cache
    restore_world
    printf '14\t1\t29\t0\n' >"$W/counts"
    run_script -- restore-historical
    expect_rc 'a restore from the upgraded state' 0
}

# G17: nothing is cleared or loaded unless every precondition holds.
t_g17_preconditions() {
    ensure_cache
    restore_world; hist_names >"$W/inventory"
    restore_refused 'the 16-table historical schema (nothing to restore from)' 'not exactly the 34 tables'
    restore_world; { up_names; echo surprise; } >"$W/inventory"
    restore_refused 'an unexpected table' 'extra surprise'
    restore_world; grep -v '^sourced_role_grants$' "$W/up.inv" >"$W/inventory"
    restore_refused 'a missing table' 'missing sourced_role_grants'
    restore_world; echo 1 >"$W/nonbase"
    restore_refused 'a view among the tables' 'not plain tables'
    restore_world; printf '1\t0\t0\n' >"$W/other_objects"
    restore_refused 'a trigger' 'a trigger, a routine or an event'
    restore_world; printf '0\t0\t1\n' >"$W/other_objects"
    restore_refused 'an event' 'a trigger, a routine or an event'
    restore_world; printf '14\t0\t29\t0\n' >"$W/counts"
    restore_refused 'a state that is neither the upgraded nor the WP3-01 one' 'neither the post-upgrade state'
    restore_world; printf '13\t1\t29\t0\n' >"$W/counts"
    restore_refused 'a state that mixes the two' 'neither the post-upgrade state'
    no_helper_ran 'a refused restore'
    # The server and the account are verified before anything.
    restore_world; put "$W" sentinel_token "$OTHER_TOKEN"
    restore_refused 'a server that is not this run' 'sentinel'
    restore_world; printf '%s\n' "$GRANT_GLOBAL_ALL" >"$W/app_grants"
    restore_refused 'an over-privileged account' 'beyond the rehearsal database'
}

t_g17_backup_and_keys() {
    local ring
    ensure_cache
    restore_world
    run_fn "keyring_fingerprint $FP_CUR $FP_FF"; ring="$OUT"
    restore_world
    sed -i "s/\"previous_key_fingerprints\": \\[\"$FP_PREV\"\\]/\"previous_key_fingerprints\": [\"$FP_FF\"]/; s/\"keyring_fingerprint\": \"[0-9a-f]*\"/\"keyring_fingerprint\": \"$ring\"/" "$T/root/historical.manifest.json"
    restore_refused 'a backup that requires a key the live ring lacks' "lacks a key fingerprint the backup requires ($FP_FF"
    restore_world; printf 'x' >>"$T/root/historical.dump.sql"
    restore_refused 'a corrupted dump' 'the dump cannot be relied on'
    restore_world; sed -i 's/"run_token_fingerprint": "[0-9a-f]*"/"run_token_fingerprint": "0000000000000000"/' "$T/root/historical.manifest.json"
    restore_refused "another run's manifest" "not this run's"
    restore_world; rm -f "$T/root/historical.snapshot"
    restore_refused 'no snapshot' 'stage that writes it has not been run'
    restore_world; rm -f "$T/root/historical.manifest.json"
    restore_refused 'no manifest' 'no complete backup'
    restore_world; sed -i 's/"commit": "[0-9a-f]*"/"commit": "'"$(printf 'a%.0s' {1..40})"'"/' "$T/root/historical.manifest.json"
    restore_refused 'a backup of another release' 'not for the historical commit'
    no_helper_ran 'a refused restore'
}

t_g17_the_dump_is_reverified() {
    ensure_cache
    # Changed (in place, to the same size) after the backup was checked and before the schema is cleared.
    restore_world
    printf "sed -i '0,/^(1),/s//(2),/' '%s/root/historical.dump.sql'\n" "$T" >"$W/hook_app_sql"
    restore_refused 'a dump changed before the schema is cleared' 'is not the one the manifest describes'
    # Changed after the schema was cleared: nothing is loaded, and the cleared state is reported honestly.
    restore_world
    printf 'sed -i "0,/^(1),/s//(2),/" "%s/root/historical.dump.sql"\n: >"%s/inventory"\n' "$T" "$W" >"$W/on_clear"
    run_script -- restore-historical
    expect_refused 'a dump changed after the schema was cleared' 'it is not loaded'
    [[ ! -e "$W/import.sql" ]] || bad 'a dump that no longer matched its manifest was loaded'
    expect_has 'recovery is named' "$ERR" 'cleanup'
}

t_g17_outcomes() {
    ensure_cache
    restore_world; touch "$W/clear_fail"
    run_script -- restore-historical
    expect_rc 'a clear that fails' 1
    expect_has 'what failed' "$ERR" 'could not be cleared'
    expect_has 'recovery is named' "$ERR" 'cleanup'
    [[ ! -e "$W/import.sql" ]] || bad 'a dump was loaded after the clear failed'
    restore_world; printf 'true\n' >"$W/on_clear" # the tables are all still there after the "clear"
    run_script -- restore-historical
    expect_refused 'tables that remain after the clear' 'not exactly empty'
    [[ ! -e "$W/import.sql" ]] || bad 'a dump was loaded over tables that remained'
    restore_world; touch "$W/import_fail"
    run_script -- restore-historical
    expect_rc 'a load that fails' 1
    expect_has 'what failed' "$ERR" 'could not be loaded'
    restore_world; printf 'cp "%s/up.inv" "%s/inventory"\n' "$W" "$W" >"$W/on_import" # the newer tables are still there after the load
    run_script -- restore-historical
    expect_refused 'newer tables that remain after the load' 'not exactly the 16 tables'
    expect_has 'the newer tables are named' "$ERR" 'extra contact_profiles'
    restore_world; { restored_block | sed 's/^\(table people [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a durable table that was not restored' 'differs from the snapshot'
    expect_has 'the table is named' "$ERR" 'people'
    # The ORIGINAL transient rows are not the restored state: a restore that left the sessions in place is refused.
    restore_world; { table_block table before; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'transient tables that still hold their original rows' 'differs from the snapshot'
    expect_has 'the sessions are named' "$ERR" 'sessions'
    expect_has 'the reset tokens are named' "$ERR" 'password_reset_tokens'
    restore_world; { restored_block; structure_block hist | sed 's/^\(structure accounts \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a structure that is not the original' 'structure of the restored tables'
    expect_has 'the table is named' "$ERR" 'accounts'
    restore_world; { restored_block; structure_block hist; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a snapshot that did not say it finished' 'snapshot:complete=ok'
    restore_world; { restored_block; structure_block hist | head -n 15; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a structure line missing' 'structure lines are not exactly'
    restore_world; { restored_block | grep -v '^table accounts '; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a durable table missing from the snapshot' 'not exactly one for each of the 16'
    restore_world; printf '4\n' >"$W/fail_snapshot_historical"
    run_script -- restore-historical
    expect_rc 'a snapshot helper that fails' 4
}

# The explicit semantic checks, which the digests would otherwise be the only protection for: the snapshot is altered to say 16 migrations and 14
# Persons and the restored state to equal it, so only the named checks can refuse.
t_g17_semantic_checks() {
    ensure_cache
    restore_world
    sed -i 's/^table migrations 15 /table migrations 16 /' "$T/root/historical.snapshot" "$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a ledger that is not the 15 migrations of v0.3.0' 'not the 15 migrations'
    restore_world
    sed -i 's/^table people 13 /table people 14 /' "$T/root/historical.snapshot" "$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a Person left over from the upgraded state' 'marker Person is still there'
}

t_g18_signin() {
    local creates line
    ensure_cache
    restored_world
    run_script -- verify-signin
    expect_rc verify-signin 0
    expect_has 'the sign-in' "$OUT" "accepted the administrator's password, then an authenticator code whose secret needs the previous key"
    expect_has 'rolled back' "$OUT" 'rolled back, and the database after it is exactly what it was before'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical signin ' ]] || bad "the helpers were not the check of the restored state and then the sign-in: $(tr '\n' ' ' <"$W/runs")"
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c -- '--workdir /var/www/html/historical' <<<"$creates")" == 2 && "$(grep -c -- '--workdir /var/www/html/current' <<<"$creates")" == 0 ]] || bad 'the sign-in was not run by the HISTORICAL application only'
    expect_has 'the helper' "$creates" ' php /var/www/html/harness/rehearse.php signin'
    no_clear_or_import verify-signin
    only_database_left verify-signin
    no_secret_anywhere verify-signin
    # The database must be the restored one first.
    restored_world; up_names >"$W/inventory"
    run_script -- verify-signin
    expect_refused 'a database that is not restored' 'not exactly the 16 tables'
    no_helper_ran 'a database that is not restored'
    restored_world; { table_block table before; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- verify-signin
    expect_refused 'a database with the original transient rows' 'differs from the snapshot'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical ' ]] || bad 'a sign-in was attempted against a database that was not the restored one'
    for line in signin:enrolment-needs-previous-key=ok signin:password=ok signin:second-factor=ok signin:me=ok signin:rolled-back=ok signin:complete=ok; do
        restored_world
        grep -vxF "$line" "$W/out_signin" >"$W/out_signin.new"; mv "$W/out_signin.new" "$W/out_signin"
        run_script -- verify-signin
        expect_refused "a sign-in that did not report $line" "did not report $line"
    done
    restored_world; signin_output | sed 's/^\(table people [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' >"$W/out_signin"
    run_script -- verify-signin
    expect_refused 'a sign-in that changed the database' 'signing in changed the database'
    expect_has 'the table is named' "$ERR" 'people'
    restored_world; printf '3\n' >"$W/fail_signin"
    run_script -- verify-signin
    expect_rc 'a sign-in the application refused' 3
    expect_has 'what failed' "$ERR" "'helper:sign-in' in the historical tree failed"
}

t_g19_reupgrade() {
    local creates
    ensure_cache
    restored_world
    run_script -- reupgrade
    expect_rc reupgrade 0
    expect_has 'the cycle' "$OUT" 'The cycle is complete'
    expect_has 'no duplicate-table failure' "$OUT" 'duplicate-table failure of WP3-01 did not occur'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical signin migrate_current snapshot_current ' ]] || bad "the helpers did not run in the expected order: $(tr '\n' ' ' <"$W/runs")"
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c -- '--workdir /var/www/html/historical' <<<"$creates")" == 2 && "$(grep -c -- '--workdir /var/www/html/current' <<<"$creates")" == 2 ]] || bad 'the runners were not two historical (the checks before) and two current'
    expect_has 'the migration' "$creates" ' php artisan migrate --force --no-interaction'
    no_clear_or_import reupgrade
    only_database_left reupgrade
    no_secret_anywhere reupgrade
}

t_g19_preconditions() {
    ensure_cache
    restored_world; up_names >"$W/inventory"
    run_script -- reupgrade
    expect_refused 'a database that is not restored (it is the 34-table one)' 'not exactly the 16 tables'
    no_helper_ran 'a database that is not restored'
    restored_world; rm -f "$T/root/upgraded.structure"
    run_script -- reupgrade
    expect_refused 'no record of the first upgrade' 'stage that writes it has not been run'
    no_helper_ran 'no record of the first upgrade'
    restored_world; { echo 'upgraded-structure-v1'; structure_block up | head -n 30; } >"$T/root/upgraded.structure"
    run_script -- reupgrade
    expect_refused 'a record of the first upgrade that lacks tables' 'structure lines are not exactly'
    restored_world; { table_block table before; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- reupgrade
    expect_refused 'a database that was not restored (the original transient rows)' 'differs from the snapshot'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical ' ]] || bad 'a migration ran although the database was not the restored one'
    restored_world; printf '3\n' >"$W/fail_signin"
    run_script -- reupgrade
    expect_rc 'a restored application that cannot sign in' 3
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical signin ' ]] || bad 'a migration ran although the restored application could not sign in'
}

t_g19_results() {
    ensure_cache
    restored_world; grep -v '^sourced_role_grants$' "$W/up.inv" >"$W/up.inv.new"; mv "$W/up.inv.new" "$W/up.inv"
    run_script -- reupgrade
    expect_refused 'a 33-table result' 'not exactly the 34 tables'
    expect_has 'the missing table is named' "$ERR" 'missing sourced_role_grants'
    restored_world; { up_names; echo surprise; } >"$W/up.inv"
    run_script -- reupgrade
    expect_refused 'a 35-table result' 'extra surprise'
    restored_world; { reupgraded_block | sed 's/^\(table role_assignments [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; structure_block up; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    run_script -- reupgrade
    expect_refused 'role assignments that are not as the upgrade should leave them' 'did not leave the data as expected'
    expect_has 'the table is named' "$ERR" 'role_assignments'
    # The restored database had empty transient tables; one that holds the ORIGINAL rows again is not what the re-upgrade should leave.
    restored_world; { table_block upgrade after | sed 's/^upgrade/table/'; structure_block up; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    run_script -- reupgrade
    expect_refused 'transient tables that regained their original rows' 'did not leave the data as expected'
    expect_has 'the sessions are named' "$ERR" 'sessions'
    restored_world; { reupgraded_block; structure_block up | sed 's/^\(structure accounts \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    run_script -- reupgrade
    expect_refused 'a structure that is not the first upgrade'"'"'s' 'not the structure after the first upgrade'
    expect_has 'the table is named' "$ERR" 'accounts'
    restored_world; printf 'cp "%s/up.inv" "%s/inventory"\nprintf "13\\t1\\t29\\t0\\n" >"%s/counts"\n' "$W" "$W" "$W" >"$W/after_migrate_current"
    run_script -- reupgrade
    expect_refused 'a marker that came back' 'marker came back'
    restored_world; printf '5\n' >"$W/fail_migrate_current"
    run_script -- reupgrade
    expect_rc 'a migration that fails' 5
    expect_has 'recovery is named' "$ERR" 'cleanup'
    restored_world; { reupgraded_block; structure_block up; } >"$W/out_snapshot_current"
    run_script -- reupgrade
    expect_refused 'a snapshot that did not say it finished' 'snapshot:complete=ok'
}

# --- slice 2.2C-1: the half-failed upgrade, the third state the restore will clear, and the security reversal -----------------------------------
#
# The harness is fake here, as everywhere: out_half_migrate is what `rehearse.php half-migrate` prints, after_half_migrate what it changes in the
# world; on_stub is what placing the stub changes. What the SERVER says (ledger, stub, row counts, schema, security tables) is canned in files the
# worlds write (canned_partial_answers), so a test plants one deviation by changing one file. The expected values below are written out here, in the
# tests, not read from the script: the lists the script holds are pinned against the repository by t_static_half_failed_lists.

PARTIAL_NEWER=(contact_interactions contact_methods contact_profiles contact_tag_assignments contact_tags discussion_messages discussions person_relationship_status_changes person_relationships resource_assets resource_card_audiences resource_cards resource_categories resource_pack_audiences resource_packs)
STUB_NAME=person_relationship_field_values
STUB_ROW=$'InnoDB\tutf8mb4_unicode_ci\tflc-rehearsal-stub-s22c1\tBASE TABLE\tstub:char(1):NO\t0\t0\t0'
DURABLE_ROWS_EXPECTED=$'4\t5\t3\t11\t5\t13\t12\t8'
partial_names() { printf '%s\n' "${HIST_TABLES[@]}" "${PARTIAL_NEWER[@]}" "$STUB_NAME"; }
migration_files() { find "$ROOT/apps/platform/database/migrations" -name '*_*.php' -printf '%f\n' | sed 's/\.php$//' | LC_ALL=C sort; }
# The ledger as `SELECT migration, batch ...` prints it for a half-failed upgrade: the first 15 migration files (batch 1), then the next 10 (batch 2).
ledger_block() { migration_files | awk 'NR <= 15 { print $0 "\t1"; next } NR <= 25 { print $0 "\t2" }'; }
partial_table_block() { restored_block | sed "s/^table migrations 15 .*/table migrations 25 $(fake_digest migrations-25)/"; }
# The 16 as they were, the 14 committed tables nothing later changes as the first upgrade made them, contact_methods as its CREATING migration left it (which is
# NOT how the first upgrade left it: the 29th migration adds a column the half-failed state never reaches), and the stub as something else.
partial_structure_block() {
    local t
    for t in "${HIST_TABLES[@]}"; do printf 'structure %s %s\n' "$t" "$(fake_digest "struct-$t-hist")"; done
    for t in "${PARTIAL_NEWER[@]}"; do
        if [[ "$t" == contact_methods ]]; then printf 'structure %s %s\n' "$t" "$(fake_digest "struct-$t-partial")"; else printf 'structure %s %s\n' "$t" "$(fake_digest "struct-$t-up")"; fi
    done
    printf 'structure %s %s\n' "$STUB_NAME" "$(fake_digest "struct-$STUB_NAME-stub")"
}
half_output() {
    cat <<'OUT'
half-migrate:first-failure-class=Illuminate\Database\QueryException
half-migrate:first-failure-code=42S01
half-migrate:first-failure-driver-code=1050
half-migrate:first-failure-table=person_relationship_field_values
half-migrate:first-ledger=25
half-migrate:first-tables=32
half-migrate:second-failure-class=Illuminate\Database\QueryException
half-migrate:second-failure-code=42S01
half-migrate:second-failure-driver-code=1050
half-migrate:second-failure-table=person_relationships
half-migrate:second-ledger=25
half-migrate:second-tables=32
half-migrate:contact-methods-structure=ok
half-migrate:complete=ok
OUT
}
# What the server says about a half-failed schema; each answer is one file, so a test changes one.
canned_partial_answers() {
    local n
    ledger_block >"$W/ledger"
    printf '%s' "$STUB_ROW" >"$W/stub_row"
    printf '0' >"$W/partial_rows"
    printf '%s' "$DURABLE_ROWS_EXPECTED" >"$W/durable_rows"
    printf '%s\t%s\t%s' "$(printf 'a%.0s' {1..32})" "$(printf 'b%.0s' {1..32})" "$(printf 'c%.0s' {1..32})" >"$W/security_digest"
    for n in 1 2 3 4 5; do printf 'schema part %s as the stage saw it\n' "$n" >"$W/schema_$n"; done
}
# The restored v0.3.0 database, the verified backup and the first upgrade's structure: what `half-fail-upgrade` starts from.
half_world() {
    stage_world
    hist_names >"$W/inventory"
    hist_names >"$W/hist.inv"
    partial_names >"$W/partial.inv"
    { restored_block; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    { partial_table_block; partial_structure_block; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_output >"$W/out_half_migrate"
    printf '{ cat "%s/hist.inv"; echo %s; } >"%s/inventory"\n' "$W" "$STUB_NAME" "$W" >"$W/on_stub"
    printf 'cp "%s/partial.inv" "%s/inventory"\n' "$W" "$W" >"$W/after_half_migrate"
    canned_partial_answers
    install_state snapshot dump.sql manifest.json
    install_upgraded_structure
}
# Runs `half-fail-upgrade` once, in a world where it passes, and keeps the state file it writes: the record `restore-historical` compares with.
ensure_half_state() {
    [[ ! -e "$T/cache/halfmigrated.state" ]] || return 0
    ensure_cache
    half_world
    run_script -- half-fail-upgrade
    [[ "$RC" == 0 ]] || bad "preparing the cached half-failed state: exit $RC: ${ERR:0:300}"
    cp -p "$T/root/halfmigrated.state" "$T/cache/halfmigrated.state"
}
# The database a half-failed upgrade leaves, with the record the stage wrote, for `restore-historical`.
partial_restore_world() {
    restore_world
    partial_names >"$W/inventory"
    cp "$W/inventory" "$W/partial.inv"
    printf '13\t0\t25\t0\n' >"$W/counts"
    canned_partial_answers
    cp -p "$T/cache/halfmigrated.state" "$T/root/halfmigrated.state"
}
# The upgraded database with a revocation made after the backup, as `revoke-after-backup` leaves it; the restore brings the security tables back.
reversal_world() {
    restore_world
    printf '14\t1\t29\t0\n' >"$W/counts"
    printf '11\t9\t0\t1\t1\n' >"$W/revoke_counts"
    printf 'cp "%s/hist.inv" "%s/inventory"\nprintf "12\\t8\\t1\\t0\\t0\\n" >"%s/revoke_counts"\n' "$W" "$W" "$W" >"$W/on_import"
}
revoke_world() {
    stage_world
    up_names >"$W/inventory"
    printf '14\t1\t29\t0\n' >"$W/counts"
    printf '12\t8\t1\t0\t0\n' >"$W/revoke_counts"
    printf '%s\n' revoke:role-removed=ok revoke:event-recorded=ok revoke:complete=ok >"$W/out_revoke"
    printf 'printf "11\\t9\\t0\\t1\\t1\\n" >"%s/revoke_counts"\n' "$W" >"$W/after_revoke"
    install_state snapshot dump.sql manifest.json
}
no_stub() {
    [[ ! -e "$W/stub.sql" ]] || bad "$1: the stub was placed"
    [[ ! -e "$T/root/halfmigrated.state" ]] || bad "$1: the half-failed state was recorded"
}
half_refused() { # DESC NEEDLE: refused before the stub is placed
    run_script -- half-fail-upgrade
    expect_refused "$1" "$2"
    no_stub "$1"
    no_helper_ran "$1"
}
half_refused_late() { # DESC NEEDLE: refused after the stub is placed and the migrations ran, and nothing is recorded
    run_script -- half-fail-upgrade
    expect_refused "$1" "$2"
    [[ ! -e "$T/root/halfmigrated.state" ]] || bad "$1: the half-failed state was recorded although the stage was refused"
    no_clear_or_import "$1"
}

t_g20_half_fail_happy_path() {
    local creates state
    ensure_cache
    half_world
    run_script -- half-fail-upgrade
    expect_rc half-fail-upgrade 0
    expect_has 'the restored state is checked first' "$OUT" 'exactly the restored historical state'
    expect_has 'the stub is named' "$OUT" 'person_relationship_field_values'
    expect_has 'both runs failed as a duplicate table' "$OUT" 'both runs failed as a duplicate table does: QueryException, SQLSTATE 42S01, error 1050'
    expect_has 'the 32 tables' "$OUT" 'exactly the 32 tables'
    expect_has 'the ledger' "$OUT" 'the ledger is 25 migrations and does not hold the Relationships migration'
    expect_has 'the historical rows' "$OUT" 'only the migration ledger differs'
    expect_has 'the structure' "$OUT" 'the stub is not the real person_relationship_field_values'
    expect_has 'recorded' "$OUT" 'recorded, for restore-historical'
    expect_has 'the summary' "$OUT" 'Half-failed'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical half_migrate snapshot_current ' ]] || bad "the helpers did not run in the expected order: $(tr '\n' ' ' <"$W/runs")"
    [[ "$(<"$W/runs_at_stub")" == snapshot_historical ]] || bad "the stub was placed before the restored state had been verified: $(tr '\n' ' ' <"$W/runs_at_stub")"
    # The stub is exactly the statement the MariaDB test ran, placed by the application account in the one database.
    [[ "$(<"$W/stub.sql")" == "$(<"$ROOT/apps/platform/tests/Fixtures/rehearsal/half-failed-stub.sql")" ]] || bad 'the stub is not the statement of tests/Fixtures/rehearsal/half-failed-stub.sql'
    expect_has 'by the application account' "$(grep '^STUB' "$W/appsql.log")" 'STUB args=[-D flc_rehearsal_app]'
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c -- '--workdir /var/www/html/historical' <<<"$creates")" == 1 && "$(grep -c -- '--workdir /var/www/html/current' <<<"$creates")" == 2 ]] || bad 'the runners were not one historical (the check before) and two current (the migrations, the snapshot)'
    expect_has 'the helper' "$creates" ' php /var/www/html/harness/rehearse.php half-migrate'
    # What it records: private, and exactly the shape restore-historical requires.
    state="$T/root/halfmigrated.state"
    [[ -f "$state" && "$(stat -c %a "$state")" == 600 ]] || bad 'the half-failed state is not a private file'
    [[ "$(sed -n 1p "$state")" == halfmigrated-v1 && "$(grep -c '^schema [0-9a-f]\{64\}$' "$state")" == 1 && "$(grep -c "^security [0-9a-f]\\{32\\}"$'\t'"[0-9a-f]\\{32\\}"$'\t'"[0-9a-f]\\{32\\}\$" "$state")" == 1 && "$(wc -l <"$state")" == 3 ]] || bad "the half-failed state is not the three lines the restore expects: $(cat "$state")"
    if [[ -f "$W/sql.log" ]] && grep -qE 'DROP|INSERT|CREATE TABLE' "$W/sql.log"; then bad 'a root statement changed the schema'; fi
    ! grep -qE '^(volume rm|network rm|stop|compose)' "$W/calls" || bad 'the stage removed or recreated an object'
    only_database_left half-fail-upgrade
    no_secret_anywhere half-fail-upgrade
}

t_g20_half_fail_preconditions() {
    ensure_cache
    half_world
    cp -p "$T/cache/historical.snapshot" "$T/root/halfmigrated.state"
    run_script -- half-fail-upgrade
    expect_refused 'a state already recorded' 'never overwritten'
    [[ ! -e "$W/stub.sql" ]] || bad 'the stub was placed over a recorded state'
    no_helper_ran 'a state already recorded'
    half_world; up_names >"$W/inventory"
    half_refused 'the 34-table upgraded database' 'not exactly the 16 tables'
    half_world; { table_block table before; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- half-fail-upgrade
    expect_refused 'the original database, which was never restored' 'differs from the snapshot'
    no_stub 'the original database'
    half_world; rm -f "$T/root/upgraded.structure"
    half_refused 'no record of the first upgrade' 'stage that writes it has not been run'
    half_world; rm -f "$T/root/historical.manifest.json"
    half_refused 'no backup' 'no complete backup'
    half_world; put "$W" sentinel_token "$OTHER_TOKEN"
    half_refused 'a server that is not this run' 'sentinel'
}

t_g20_half_fail_outcomes() {
    local line attempt field
    ensure_cache
    half_world; printf 'true\n' >"$W/on_stub"
    half_refused_late 'a stub that did not appear' 'the 16 tables of v0.3.0 and the stub'
    half_world; touch "$W/stub_fail"
    run_script -- half-fail-upgrade
    expect_rc 'a stub that cannot be created' 1
    expect_has 'what failed' "$ERR" 'could not be created'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical ' ]] || bad "a migration ran although the stub could not be created: $(tr '\n' ' ' <"$W/runs")"
    # The helper must say everything it is required to say.
    for line in half-migrate:first-ledger=25 half-migrate:first-tables=32 half-migrate:second-ledger=25 half-migrate:second-tables=32 half-migrate:complete=ok; do
        half_world; grep -vxF "$line" "$W/out_half_migrate" >"$W/out_half_migrate.new"; mv "$W/out_half_migrate.new" "$W/out_half_migrate"
        half_refused_late "a helper that did not report $line" "did not report $line"
    done
    # The failure must be a duplicate table, with its class and both codes, on each attempt.
    for attempt in first second; do
        for field in 'failure-class=Illuminate\\Database\\QueryException:failure-class=RuntimeException' 'failure-code=42S01:failure-code=HY000' 'failure-driver-code=1050:failure-driver-code=1062'; do
            half_world; sed -i "s/^half-migrate:$attempt-${field%%:*}\$/half-migrate:$attempt-${field#*:}/" "$W/out_half_migrate"
            if [[ "$(half_output)" == "$(<"$W/out_half_migrate")" ]]; then bad "the test did not change the $attempt ${field%%=*}"; fi
            half_refused_late "a $attempt migration that failed another way ($field)" 'did not fail as a duplicate table does'
        done
    done
    # And on the right table: the stub the first time, person_relationships (the migration's first table) the second.
    half_world; sed -i 's/^half-migrate:first-failure-table=.*/half-migrate:first-failure-table=person_relationships/' "$W/out_half_migrate"
    half_refused_late 'a first failure on another table' 'not the stub person_relationship_field_values'
    half_world; sed -i 's/^half-migrate:first-failure-table=.*/half-migrate:first-failure-table=/' "$W/out_half_migrate"
    half_refused_late 'a first failure that names no table' 'not the stub'
    half_world; sed -i 's/^half-migrate:second-failure-table=.*/half-migrate:second-failure-table=person_relationship_field_values/' "$W/out_half_migrate"
    half_refused_late 'a second run that failed on the stub again' 'did not refuse as an unrepaired partial schema must'
    half_world; printf '3\n' >"$W/fail_half_migrate"
    run_script -- half-fail-upgrade
    expect_rc 'a helper that refuses' 3
    expect_has 'what failed' "$ERR" "'helper:half-migrate' in the current tree failed"
    # The schema afterwards must be exactly the 32 tables, and the SQL must agree with the helper.
    half_world; grep -v '^contact_tags$' "$W/partial.inv" >"$W/partial.inv.new"; mv "$W/partial.inv.new" "$W/partial.inv"
    half_refused_late 'a table fewer than the half-failed schema' 'not exactly the 32 tables'
    half_world; { cat "$W/partial.inv"; echo sourced_role_grants; } >"$W/partial.inv.new"; mv "$W/partial.inv.new" "$W/partial.inv"
    half_refused_late 'a table more than the half-failed schema' 'not exactly the 32 tables'
    # Nothing historical may have moved except the ledger; the structure must be the original's, the first upgrade's, and the stub must not be the real table.
    half_world; partial_table_block | sed 's/^\(table people [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' >"$W/new.tables"
    { cat "$W/new.tables"; partial_structure_block; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a historical row that changed' 'only the migration ledger may differ'
    expect_has 'the table is named' "$ERR" 'people'
    half_world; { restored_block; partial_structure_block; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a ledger that did not change' 'only the migration ledger may differ'
    half_world; { partial_table_block; partial_structure_block | sed 's/^\(structure accounts \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a historical table whose structure changed' 'structure of the historical tables changed'
    expect_has 'the table is named' "$ERR" 'accounts'
    half_world; { partial_table_block; partial_structure_block | sed 's/^\(structure resource_cards \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a committed table that is not what the first upgrade made' 'the 14 tables the completed work made'
    expect_has 'the table is named' "$ERR" 'resource_cards'
    half_world; { partial_table_block; partial_structure_block | sed "s/^\\(structure $STUB_NAME \\).*/\\1$(fake_digest "struct-$STUB_NAME-up")/"; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a stub that is the real table' 'it is not the stub'
    # contact_methods is held to what its creating migration left, and not just excused from the comparison with the first upgrade:
    # (a) the faithful world above passes although its digest differs from the first upgrade's (the old comparison of all 15 would have refused it);
    half_world; [[ "$(grep '^structure contact_methods ' "$W/out_snapshot_current")" != "$(grep '^structure contact_methods ' "$T/root/upgraded.structure")" ]] || bad 'the fake world does not give contact_methods a structure other than the first upgrade'"'"'s'
    # (b) the fully upgraded table (the provenance migration has run) is not the half-failed table;
    half_world; { partial_table_block; partial_structure_block | sed "s/^\\(structure contact_methods \\).*/\\1$(fake_digest "struct-contact_methods-up")/"; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a contact_methods that already has the provenance column' 'the later provenance migration has run'
    # (c) the helper's own judgement of the table must be `ok`: any deviation it names ends the stage, and no report at all does too;
    half_world; sed -i 's/^half-migrate:contact-methods-structure=.*/half-migrate:contact-methods-structure=unexpected column surprise/' "$W/out_half_migrate"
    half_refused_late 'a contact_methods with an unrelated extra column' 'not the structure its creating migration left'
    expect_has 'the deviation is named' "$ERR" 'unexpected column surprise'
    half_world; sed -i 's/^half-migrate:contact-methods-structure=.*/half-migrate:contact-methods-structure=different column label/' "$W/out_half_migrate"
    half_refused_late 'a contact_methods with a changed column' 'not the structure its creating migration left'
    half_world; grep -v '^half-migrate:contact-methods-structure=' "$W/out_half_migrate" >"$W/out_half_migrate.new"; mv "$W/out_half_migrate.new" "$W/out_half_migrate"
    half_refused_late 'a helper that says nothing about contact_methods' 'not the structure its creating migration left'
    # (d) the other 14 are still held strictly to the first upgrade's, contact_profiles included (the table the later migration is NOT about).
    half_world; { partial_table_block; partial_structure_block | sed 's/^\(structure contact_profiles \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_current"
    half_refused_late 'a contact_profiles whose structure changed' 'the 14 tables the completed work made'
    expect_has 'the table is named' "$ERR" 'contact_profiles'
    # The same recogniser the restore uses judges what the stage made: a stub that is not the stub, and a ledger that is not the 25, end the stage.
    half_world; printf 'InnoDB\tutf8mb4_unicode_ci\t\tBASE TABLE\trelationship_id:char(26):NO,field_key:varchar(64):NO\t1\t1\t0' >"$W/stub_row"
    half_refused_late 'a stub that is the real table, by the server' 'is not the stub this script places'
    half_world; ledger_block | sed '$d' >"$W/ledger"
    half_refused_late 'a ledger of 24' 'migration ledger is not exactly'
}

# shellcheck disable=SC2016 # the backticks and the SQL fragments in single quotes are text to find, not expansions
t_g20_restore_from_half_failed() {
    local clear names inv clr imp line table
    ensure_half_state
    partial_restore_world
    run_script -- restore-historical
    expect_rc 'the restore from the half-failed state' 0
    expect_has 'the state is named' "$OUT" 'a half-failed upgrade'
    expect_has 'the count' "$OUT" 'DROP TABLE for exactly the 32 tables'
    expect_has 'the summary' "$OUT" 'Restored'
    expect_lacks 'no reversal is claimed where there was no revocation' "$OUT" 'SECURITY STATE REVERSED'
    # What was cleared: exactly the 32 tables, by name, in one DROP; neither table the migration never reached, and nothing else.
    clear="$(<"$W/clear.sql")"
    # shellcheck disable=SC2016 # the backticks are the table quoting in the statement
    names="$(grep -o '`[a-z_]*`' <<<"$clear" | tr -d '`' | LC_ALL=C sort)"
    [[ "$names" == "$(partial_names | LC_ALL=C sort)" ]] || bad "the cleared tables are not exactly the 32 of the half-failed state: $(tr '\n' ' ' <<<"$names")"
    [[ "$(grep -c . <<<"$names")" == 32 ]] || bad 'the clearing statement does not name 32 tables'
    for table in sourced_role_grants person_relationship_role_provisions; do expect_lacks "the clearing statement does not name $table, which the failed migration never made" "$clear" "$table"; done
    [[ "$(grep -c 'DROP' <<<"$clear")" == 1 ]] || bad 'more than one DROP in the clearing statement'
    for line in 'IF EXISTS' 'DATABASE' 'SCHEMA' '*' 'LIKE' 'TRUNCATE' 'DELETE'; do expect_lacks "the clearing statement has no $line" "$clear" "$line"; done
    expect_has 'foreign keys off for the one session' "$clear" 'SET FOREIGN_KEY_CHECKS=0;'
    [[ "$(<"$T/root/historical.dump.sql")" == "$(<"$W/import.sql")" ]] || bad 'what was loaded is not exactly the verified dump'
    inv="$(grep -n 'information_schema.tables' "$W/appsql.log" | sed -n 1p | cut -d: -f1)"
    clr="$(grep -n '^CLEAR' "$W/appsql.log" | cut -d: -f1)"
    imp="$(grep -n '^IMPORT' "$W/appsql.log" | cut -d: -f1)"
    [[ -n "$inv" && -n "$clr" && -n "$imp" && "$inv" -lt "$clr" && "$clr" -lt "$imp" ]] || bad "the inventory, the clear and the load did not happen in that order ($inv, $clr, $imp)"
    # Every partial-state precondition was read, by SQL, before the clear; and no helper (no application) ran before it.
    for line in 'FROM migrations ORDER BY id' 'GROUP_CONCAT(CONCAT(c.column_name' 'FROM `account_invitations`' 'create_options' 'MD5(IFNULL(GROUP_CONCAT' ') + (SELECT COUNT(*) FROM `'; do
        grep -qF -- "$line" "$W/appsql.log" && [[ "$(grep -n -F -- "$line" "$W/appsql.log" | sed -n 1p | cut -d: -f1)" -lt "$clr" ]] || bad "the precondition '$line' was not read before the clear"
    done
    [[ ! -s "$W/runs_at_clear" ]] || bad "a helper had run before the schema was cleared: $(tr '\n' ' ' <"$W/runs_at_clear")"
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'snapshot_historical ' ]] || bad "the only helper should be the historical snapshot after the load: $(tr '\n' ' ' <"$W/runs")"
    if [[ -f "$W/sql.log" ]] && grep -qE 'DROP|INSERT|CREATE TABLE' "$W/sql.log"; then bad 'a root statement changed the schema'; fi
    ! grep -qE '^(volume rm|network rm|stop|compose)' "$W/calls" || bad 'the restore removed or recreated an object'
    only_database_left restore
    no_secret_anywhere restore
}

# G20: nothing is cleared or loaded unless every precondition of the half-failed state holds; each is read by SQL, before any destructive statement.
t_g20_partial_state_preconditions() {
    ensure_half_state
    # The tables and the objects.
    partial_restore_world; grep -v '^contact_tags$' "$W/partial.inv" >"$W/inventory"
    restore_refused 'a table fewer than the half-failed schema' 'not exactly the 34 tables'
    partial_restore_world; { cat "$W/partial.inv"; echo surprise; } >"$W/inventory"
    restore_refused 'a table more than the half-failed schema' 'extra surprise'
    partial_restore_world; { cat "$W/partial.inv"; echo sourced_role_grants; } >"$W/inventory"
    restore_refused 'the half-failed schema with a table the migration never reached' 'not exactly the 34 tables'
    partial_restore_world; echo 1 >"$W/nonbase"
    restore_refused 'a view among the half-failed tables' 'not plain tables'
    partial_restore_world; printf '1\t0\t0\n' >"$W/other_objects"
    restore_refused 'a trigger on the half-failed schema' 'a trigger, a routine or an event'
    partial_restore_world; printf '0\t1\t0\n' >"$W/other_objects"
    restore_refused 'a routine on the half-failed schema' 'a trigger, a routine or an event'
    partial_restore_world; printf '0\t0\t1\n' >"$W/other_objects"
    restore_refused 'an event on the half-failed schema' 'a trigger, a routine or an event'
    # The counts that go with each table set, and no other pairing.
    partial_restore_world; printf '14\t1\t29\t0\n' >"$W/counts"
    restore_refused 'the post-upgrade counts on the half-failed tables' 'the counts are not'
    partial_restore_world; printf '13\t1\t15\t1\n' >"$W/counts"
    restore_refused 'the WP3-01 counts on the half-failed tables' 'the counts are not'
    partial_restore_world; printf '13\t0\t26\t0\n' >"$W/counts"
    restore_refused 'a ledger count of 26' 'the counts are not'
    partial_restore_world; printf '14\t0\t25\t0\n' >"$W/counts"
    restore_refused 'a Person more than the fixture' 'the counts are not'
    partial_restore_world; up_names >"$W/inventory"; printf '13\t0\t25\t0\n' >"$W/counts"
    restore_refused 'the half-failed counts on the 34 tables' 'neither the post-upgrade state'
    # The ledger: every record, in order, with its batch.
    partial_restore_world; ledger_block | sed '$d' >"$W/ledger"
    restore_refused 'a ledger that lacks its last record' 'migration ledger is not exactly'
    partial_restore_world; ledger_block | awk 'NR == 1 { first = $0; next } NR == 2 { print; print first; next } { print }' >"$W/ledger"
    restore_refused 'a ledger whose first two records are reordered' 'migration ledger is not exactly'
    partial_restore_world; ledger_block | sed '25s/\t2$/\t3/' >"$W/ledger"
    restore_refused 'a record in the wrong batch' 'migration ledger is not exactly'
    partial_restore_world; { ledger_block; printf '2026_10_09_000001_create_person_relationships_tables\t2\n'; } >"$W/ledger"
    restore_refused 'a ledger that records the migration that failed' 'migration ledger is not exactly'
    partial_restore_world; ledger_block | sed '20s/^[^\t]*/2026_10_04_000009_create_something_else/' >"$W/ledger"
    restore_refused 'a record that is not one of the ten' 'migration ledger is not exactly'
    partial_restore_world; printf '' >"$W/ledger"
    restore_refused 'an empty ledger' 'migration ledger is not exactly'
    # The stub as the stub, and every newer table empty.
    partial_restore_world; printf 'InnoDB\tutf8mb4_unicode_ci\t\tBASE TABLE\trelationship_id:char(26):NO,field_key:varchar(64):NO,value:text:NO\t1\t1\t0' >"$W/stub_row"
    restore_refused 'a stub that is the real table' 'is not the stub this script places'
    partial_restore_world; printf 'InnoDB\tutf8mb4_unicode_ci\tflc-rehearsal-stub-s22c1\tBASE TABLE\tstub:char(1):NO\t0\t0\t1' >"$W/stub_row"
    restore_refused 'a stub with a row' 'is not the stub this script places'
    partial_restore_world; printf 'MyISAM\tutf8mb4_unicode_ci\tflc-rehearsal-stub-s22c1\tBASE TABLE\tstub:char(1):NO\t0\t0\t0' >"$W/stub_row"
    restore_refused 'a stub of another engine' 'is not the stub this script places'
    partial_restore_world; printf 'InnoDB\tutf8mb4_unicode_ci\tsomeone else made this\tBASE TABLE\tstub:char(1):NO\t0\t0\t0' >"$W/stub_row"
    restore_refused 'a stub that this script did not place' 'is not the stub this script places'
    partial_restore_world; printf 'InnoDB\tutf8mb4_unicode_ci\tflc-rehearsal-stub-s22c1\tBASE TABLE\tstub:char(1):NO\t0\t1\t0' >"$W/stub_row"
    restore_refused 'a stub with an index' 'is not the stub this script places'
    partial_restore_world; : >"$W/stub_row"
    restore_refused 'a stub the server does not report' 'is not the stub this script places'
    partial_restore_world; printf '1' >"$W/partial_rows"
    restore_refused 'a row in one of the newer tables' 'holds rows (1)'
    # The durable data: the fixture's counts.
    partial_restore_world; printf '4\t5\t3\t11\t5\t13\t11\t8' >"$W/durable_rows"
    restore_refused 'a role assignment fewer' 'do not hold the fixture'
    partial_restore_world; printf '4\t5\t3\t11\t5\t14\t12\t8' >"$W/durable_rows"
    restore_refused 'a Person more' 'do not hold the fixture'
    partial_restore_world; printf '4\t5\t3\t11\t5\t13\t12\t9' >"$W/durable_rows"
    restore_refused 'a security event more' 'do not hold the fixture'
    # What the stage recorded: present, private and exact; and the schema and the security tables as recorded.
    partial_restore_world; rm -f "$T/root/halfmigrated.state"
    restore_refused 'no record that half-fail-upgrade made this schema' 'was not left and checked by this script'
    partial_restore_world; chmod 644 "$T/root/halfmigrated.state"
    restore_refused 'a record that is not private' 'not a private file'
    partial_restore_world; printf 'halfmigrated-v1\nschema nothing\nsecurity nothing\n' >"$T/root/halfmigrated.state"; chmod 600 "$T/root/halfmigrated.state"
    restore_refused 'a record that is not what the stage writes' 'not exactly what half-fail-upgrade writes'
    partial_restore_world; { cat "$T/root/halfmigrated.state"; echo 'extra line'; } >"$T/root/halfmigrated.state.new"; mv "$T/root/halfmigrated.state.new" "$T/root/halfmigrated.state"; chmod 600 "$T/root/halfmigrated.state"
    restore_refused 'a record with a line more' 'not exactly what half-fail-upgrade writes'
    partial_restore_world; printf 'schema part 2 as someone changed it\n' >"$W/schema_2"
    restore_refused 'a column that changed since the stage recorded the schema' 'not the one half-fail-upgrade recorded'
    partial_restore_world; printf 'schema part 5 as someone changed it\n' >"$W/schema_5"
    restore_refused 'a foreign key rule that changed' 'not the one half-fail-upgrade recorded'
    partial_restore_world; printf '%s\t%s\t%s' "$(printf 'a%.0s' {1..32})" "$(printf 'd%.0s' {1..32})" "$(printf 'c%.0s' {1..32})" >"$W/security_digest"
    restore_refused 'an Account that changed since the stage recorded the security tables' 'not as half-fail-upgrade recorded them'
    partial_restore_world; printf 'garbage' >"$W/security_digest"
    restore_refused 'a security digest the server did not give' 'could not be digested'
    # The backup, the keys and the server are verified first, as for the other states.
    partial_restore_world; printf 'x' >>"$T/root/historical.dump.sql"
    restore_refused 'a corrupted dump' 'the dump cannot be relied on'
    partial_restore_world; put "$W" sentinel_token "$OTHER_TOKEN"
    restore_refused 'a server that is not this run' 'sentinel'
    no_helper_ran 'a refused restore from the half-failed state'
}

# Once cleared, the restore of the half-failed state is judged exactly as the other two are.
t_g20_partial_restore_outcomes() {
    ensure_half_state
    partial_restore_world; touch "$W/clear_fail"
    run_script -- restore-historical
    expect_rc 'a clear that fails' 1
    expect_has 'what failed' "$ERR" 'could not be cleared'
    [[ ! -e "$W/import.sql" ]] || bad 'a dump was loaded after the clear failed'
    partial_restore_world; printf 'true\n' >"$W/on_clear"
    run_script -- restore-historical
    expect_refused 'tables that remain after the clear' 'not exactly empty'
    [[ ! -e "$W/import.sql" ]] || bad 'a dump was loaded over tables that remained'
    partial_restore_world; printf 'cp "%s/partial.inv" "%s/inventory"\n' "$W" "$W" >"$W/on_import"
    run_script -- restore-historical
    expect_refused 'the stub and the half-built tables that remain after the load' 'not exactly the 16 tables'
    expect_has 'the stub is named' "$ERR" "extra $STUB_NAME"
    partial_restore_world; { restored_block | sed 's/^\(table role_assignments [0-9]* \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; structure_block hist; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a durable table that was not restored' 'differs from the snapshot'
    expect_has 'the table is named' "$ERR" 'role_assignments'
    partial_restore_world; { restored_block; structure_block hist | sed 's/^\(structure accounts \).*/\1ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/'; echo 'snapshot:complete=ok'; } >"$W/out_snapshot_historical"
    run_script -- restore-historical
    expect_refused 'a structure that is not the original' 'structure of the restored tables'
}

# G21: the security change made after the backup.
t_g21_revoke_happy_path() {
    local creates
    ensure_cache
    revoke_world
    run_script -- revoke-after-backup
    expect_rc revoke-after-backup 0
    expect_has 'the change' "$OUT" "role assignment is gone (11 role assignments) and the role.revoked event 01jbe000000000000000000009 about that Person records it (9 security events)"
    expect_has 'the backup holds neither' "$OUT" 'the backup holds neither change'
    expect_has 'the next step' "$OUT" 'restore-historical'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'revoke ' ]] || bad "the helper did not run once: $(tr '\n' ' ' <"$W/runs")"
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c -- '--workdir /var/www/html/current' <<<"$creates")" == 1 && "$(grep -c -- '--workdir /var/www/html/historical' <<<"$creates")" == 0 ]] || bad 'the revocation was not made by the CURRENT application only'
    expect_has 'the helper' "$creates" ' php /var/www/html/harness/rehearse.php revoke'
    no_clear_or_import revoke-after-backup
    only_database_left revoke-after-backup
    no_secret_anywhere revoke-after-backup
    # The same five numbers, read before and after, with the exact ids.
    [[ "$(grep -c "type = 'role.revoked'" "$W/appsql.log")" == 2 ]] || bad 'the security tables were not read before and after'
    expect_has 'the assignment is named by its id' "$(<"$W/appsql.log")" "id = '01jbr000000000000000000004' AND person_id = '01jbp000000000000000000003'"
}

t_g21_revoke_preconditions() {
    ensure_cache
    revoke_world; hist_names >"$W/inventory"
    run_script -- revoke-after-backup
    expect_refused 'a database that is not upgraded' 'not exactly the 34 tables'
    no_helper_ran 'a database that is not upgraded'
    revoke_world; printf '13\t1\t15\t1\n' >"$W/counts"
    run_script -- revoke-after-backup
    expect_refused 'the WP3-01 state' 'not the post-upgrade state'
    no_helper_ran 'the WP3-01 state'
    revoke_world; printf '14\t0\t29\t0\n' >"$W/counts"
    run_script -- revoke-after-backup
    expect_refused 'a database with no marker profile' 'not the post-upgrade state'
    revoke_world; printf '11\t9\t0\t1\t1\n' >"$W/revoke_counts"
    run_script -- revoke-after-backup
    expect_refused 'a revocation that has already been made' 'not as the upgrade left them'
    no_helper_ran 'a revocation already made'
    revoke_world; printf '12\t8\t0\t0\t0\n' >"$W/revoke_counts"
    run_script -- revoke-after-backup
    expect_refused 'a role assignment that is not there' 'not as the upgrade left them'
    revoke_world; printf '12\t9\t1\t0\t0\n' >"$W/revoke_counts"
    run_script -- revoke-after-backup
    expect_refused "security events that are not the fixture's" 'not as the upgrade left them'
    revoke_world; printf '12\t8\t1\t0\t1\n' >"$W/revoke_counts"
    run_script -- revoke-after-backup
    expect_refused 'the revocation event already there before the revocation' 'not as the upgrade left them'
    no_helper_ran 'the revocation event already there'
    revoke_world; rm -f "$T/root/historical.manifest.json"
    run_script -- revoke-after-backup
    expect_refused 'no backup (there would be nothing to restore to)' 'no complete backup'
    no_helper_ran 'no backup'
}

t_g21_revoke_outcomes() {
    local line
    ensure_cache
    for line in revoke:role-removed=ok revoke:event-recorded=ok revoke:complete=ok; do
        revoke_world; grep -vxF "$line" "$W/out_revoke" >"$W/out_revoke.new"; mv "$W/out_revoke.new" "$W/out_revoke"
        run_script -- revoke-after-backup
        expect_refused "a helper that did not report $line" "did not report $line"
    done
    revoke_world; : >"$W/after_revoke"
    run_script -- revoke-after-backup
    expect_refused 'a revocation the server does not show' 'not what the revocation leaves'
    revoke_world; printf 'printf "11\\t9\\t1\\t1\\t1\\n" >"%s/revoke_counts"\n' "$W" >"$W/after_revoke"
    run_script -- revoke-after-backup
    expect_refused 'an assignment that is still there' 'not what the revocation leaves'
    # An event for ANOTHER Person leaves every overall count as the revocation does (11 role assignments, 9 events, one role.revoked) but is not the
    # event of this Person's revocation: only the fifth reading, the event by its id, type and subject, tells them apart.
    revoke_world; printf 'printf "11\\t9\\t0\\t1\\t0\\n" >"%s/revoke_counts"\n' "$W" >"$W/after_revoke"
    run_script -- revoke-after-backup
    expect_refused 'a role.revoked event that is not the event of this Person' 'not what the revocation leaves'
    revoke_world; printf '3\n' >"$W/fail_revoke"
    run_script -- revoke-after-backup
    expect_rc 'a helper that refuses' 3
    expect_has 'what failed' "$ERR" "'helper:revoke' in the current tree failed"
    expect_has 'recovery is named' "$ERR" 'cleanup'
}

t_g21_the_restore_reverses_it_and_says_so() {
    local out
    ensure_cache
    reversal_world
    run_script -- restore-historical
    expect_rc 'the restore of a database that carries the revocation' 0
    out="$OUT"
    expect_has 'the reversal is reported' "$out" 'SECURITY STATE REVERSED'
    expect_has 'the revocation is named' "$out" "Person 01jbp000000000000000000003's role assignment (01jbr000000000000000000004) was removed"
    expect_has 'both counts' "$out" '(11 role assignments, 9 security events)'
    expect_has 'the return' "$out" '(12 role assignments, 8 security events)'
    expect_has 'the implication' "$out" 'RESURRECTS authority that was revoked after the backup'
    expect_has 'nothing reconciles it' "$out" 'Nothing here reconciles'
    expect_has 'the operator policy' "$out" 'keep the service in maintenance'
    # The security tables are read twice: before the clear (the revocation is there), and after the load (it is gone).
    [[ "$(grep -c "type = 'role.revoked'" "$W/appsql.log")" == 2 ]] || bad 'the security tables were not read before the clear and after the load'
    [[ "$(grep -n "type = 'role.revoked'" "$W/appsql.log" | sed -n 1p | cut -d: -f1)" -lt "$(grep -n '^CLEAR' "$W/appsql.log" | cut -d: -f1)" ]] || bad 'the revocation was looked for after the clear'
    # Where no revocation of this stage was made, nothing is claimed.
    reversal_world; printf '12\t8\t1\t0\t0\n' >"$W/revoke_counts"
    run_script -- restore-historical
    expect_rc 'a restore where no revocation was made' 0
    expect_lacks 'no reversal is claimed' "$OUT" 'SECURITY STATE REVERSED'
    reversal_world; printf '11\t9\t0\t0\t0\n' >"$W/revoke_counts"
    run_script -- restore-historical
    expect_rc 'a security state that is not the revocation of this stage' 0
    expect_lacks 'no reversal is claimed for another security state' "$OUT" 'SECURITY STATE REVERSED'
    # The same four overall counts as the revocation, but the role.revoked event is another Person's: it is not this stage's revocation, so no reversal is claimed.
    reversal_world; printf '11\t9\t0\t1\t0\n' >"$W/revoke_counts"
    run_script -- restore-historical
    expect_rc 'an event of another Person with the same overall counts' 0
    expect_lacks 'no reversal is claimed for an event that is not this Person'"'"'s' "$OUT" 'SECURITY STATE REVERSED'
}

t_g21_a_reversal_that_did_not_happen_is_not_reported() {
    ensure_cache
    reversal_world; printf 'cp "%s/hist.inv" "%s/inventory"\n' "$W" "$W" >"$W/on_import"
    run_script -- restore-historical
    expect_refused 'a restore after which the security tables still show the revocation' 'the restore reported success but the security tables are not what the backup held'
    expect_lacks 'no reversal is claimed' "$OUT" 'SECURITY STATE REVERSED'
    reversal_world; printf 'cp "%s/hist.inv" "%s/inventory"\nprintf "12\\t8\\t0\\t0\\t0\\n" >"%s/revoke_counts"\n' "$W" "$W" "$W" >"$W/on_import"
    run_script -- restore-historical
    expect_refused 'a restore after which the assignment did not come back' 'not what the backup held'
    reversal_world; printf 'cp "%s/hist.inv" "%s/inventory"\nprintf "12\\t8\\t1\\t0\\t1\\n" >"%s/revoke_counts"\n' "$W" "$W" "$W" >"$W/on_import"
    run_script -- restore-historical
    expect_refused 'a restore after which the revocation event is still there (the counts of the assignment and events are the backup'"'"'s)' 'not what the backup held'
}

# The paths that existed before slice 2.2C-1 do not look for the half-failed state, and the half-failed path does not look for the revocation.
# shellcheck disable=SC2016 # the backticks are the table quoting in the statement
t_g20_the_other_paths_are_unchanged() {
    local log line
    ensure_half_state
    restore_world
    run_script -- restore-historical
    expect_rc 'the WP3-01 state still restores' 0
    log="$(<"$W/appsql.log")"
    for line in 'FROM migrations ORDER BY id' 'create_options' 'MD5(IFNULL(GROUP_CONCAT' 'GROUP_CONCAT(CONCAT(c.column_name'; do expect_lacks "the 34-table path never reads '$line'" "$log" "$line"; done
    [[ "$(grep -c 'DROP TABLE' "$W/clear.sql")" == 1 && "$(grep -o '`[a-z_]*`' "$W/clear.sql" | wc -l)" == 34 ]] || bad 'the 34-table path did not clear exactly 34 tables'
    partial_restore_world
    run_script -- restore-historical
    expect_rc 'the half-failed state restores' 0
    expect_lacks 'the half-failed path never looks for the revocation' "$(<"$W/appsql.log")" "type = 'role.revoked'"
}

# G13: every stage runs only against the source `prepare` recorded.
t_g13_prepared_source() {
    local c
    ensure_cache
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade half-fail-upgrade revoke-after-backup; do
        restore_world; rm -f "$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c without a record of the prepared source" 'prepare has not finished'
        no_helper_ran "$c without a record"; no_clear_or_import "$c without a record"
        restore_world; printf 'HEAD %s\n%s %s\n' "$(printf 'c%.0s' {1..40})" "$HIST" "$HIST" >"$W/commits"
        run_script -- "$c"
        expect_refused "$c after HEAD moved" 'HEAD is not the commit the source volume was archived from'
        no_helper_ran "$c after HEAD moved"; no_clear_or_import "$c after HEAD moved"
    done
    restore_world; chmod 644 "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a record that is not private' 'prepare has not finished'
    restore_world; sed -i "s/^historical=.*/historical=$(printf 'a%.0s' {1..40})/" "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a source archived from another historical commit' 'not archived from the historical commit'
    restore_world; sed -i 's/^harness [0-9a-f]\{64\}/harness ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a helper script that is not the one that was copied in' 'helper script in scripts/rehearsal/php'
    restore_world; sed -i '/^harness .*files\.php$/d' "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a record that lacks a helper' 'helper script in scripts/rehearsal/php'
    # WU1: the test-support classes the helpers load are attested one by one: a changed, a missing, an added and a reordered record each refuse, as does a record
    # of the earlier format (which has none: it is stale, not adopted), and a format this script does not know.
    for c in restore-historical seed-files; do
        restore_world; sed -i '0,/^support [0-9a-f]\{64\}/s//support ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff/' "$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c with a support class whose recorded digest is not the commit's" 'test-support class recorded'
        restore_world; sed -i '$d' "$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c with a record that lacks a support class" 'test-support class recorded'
        restore_world; printf 'support %s  Extra.php\n' "$(printf 'd%.0s' {1..64})" >>"$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c with a record that adds a support class" 'test-support class recorded'
        restore_world; { head -n 3 "$T/root/prepared.source"; grep '^harness ' "$T/root/prepared.source"; grep '^support ' "$T/root/prepared.source" | tac; } >"$T/prepared.reordered"; cp "$T/prepared.reordered" "$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c with a record whose support classes are in another order" 'test-support class recorded'
        restore_world; { printf 'prepared-v1\nhistorical=%s\ncurrent=%s\n' "$HIST" "$HEAD_SHA"; harness_sums | sed 's/^/harness /'; } >"$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c with a record of the earlier format" 'earlier version of prepare'
        expect_has "$c names the remedy" "$ERR" 'Run cleanup, then up and prepare again'
    done
    no_helper_ran 'a stale or altered attestation'; no_clear_or_import 'a stale or altered attestation'
    restore_world; sed -i 's/^prepared-v2$/prepared-v0/' "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a record in another format' 'earlier version of prepare'
    restore_world; printf 'extra\n' >>"$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a record with something added' 'not exactly what prepare writes'
    no_clear_or_import 'a stale record'
    # The checkout must also be clean and the historical commit exact: what is recorded is only part of it.
    restore_world; printf ' M apps/platform/composer.json\n' >"$W/git_status"
    run_script -- restore-historical
    expect_refused 'an unclean checkout' 'uncommitted or untracked'
}

# The three states a snapshot can be compared as, and the comparison that cannot overlook a missing record.
t_g13_snapshot_semantics() {
    local original restored name
    original="$(table_block table before | sed 's/^table //')"
    run_fn "emptied_transients '$original'"
    restored="$OUT"
    for name in "${TRANSIENT[@]}"; do expect_has "the transient table $name is emptied" "$restored" "$name 0 $EMPTY_DIGEST"; done
    expect_has 'a durable table is untouched' "$restored" "accounts 11 $(fake_digest accountsbefore)"
    expect_has 'the ledger is untouched' "$restored" "migrations 15 $(fake_digest migrationsbefore)"
    # A restore of the dump is the original with the transient tables emptied, and is judged as such.
    run_fn "restore_state_differences '$original' '$restored'"; [[ -z "$OUT" ]] || bad "a correct restore differs from the original: $OUT"
    # The original itself is NOT the restored state: the two tables that had fixture rows still hold them.
    run_fn "restore_state_differences '$original' '$original'"
    [[ "$OUT" == $'password_reset_tokens\nsessions' ]] || bad "the original snapshot judged as a restore should differ in exactly the sessions and reset tokens, not '$OUT'"
    # A durable table missing from the later one, or present only there, is a difference, never an omission.
    run_fn "digest_differences '$original' '$(grep -v '^accounts ' <<<"$original")'"; [[ "$OUT" == accounts ]] || bad "a durable table missing from the restored snapshot read as '$OUT', not as a difference"
    run_fn "digest_differences '$original' '$original
surprise 1 $(fake_digest x)'"; [[ "$OUT" == surprise ]] || bad "a table present only in the later snapshot read as '$OUT'"
    # shellcheck disable=SC2001 # a line-anchored substitution on a multi-line value
    run_fn "digest_differences '$original' '$(sed 's/^people 13 /people 12 /' <<<"$original")'"; [[ "$OUT" == people ]] || bad "a changed row count read as '$OUT'"
    run_fn "digest_differences '$original' '$original'"; [[ -z "$OUT" ]] || bad "identical snapshots differ: $OUT"
    # Structure lines: exactly the expected tables, each with a digest.
    run_fn "structure_lines '$(structure_block hist)' '$(hist_names | LC_ALL=C sort)'"; expect_rc 'a complete structure' 0
    run_fn "structure_lines '$(structure_block hist | head -n 15)' '$(hist_names | LC_ALL=C sort)'"; expect_refused 'a structure line missing' 'structure lines are not exactly'
    run_fn "structure_lines '$(structure_block up)' '$(hist_names | LC_ALL=C sort)'"; expect_refused 'a structure line too many' 'structure lines are not exactly'
    run_fn "structure_lines 'structure accounts nothex' 'accounts'"; expect_refused 'a malformed digest' 'malformed'
}

# G13: a snapshot helper that did not say it finished is not an empty snapshot.
t_g13_snapshot_must_be_reported() {
    ensure_cache
    backup_world
    { snapshot_output before | grep -v '^snapshot:complete=ok$'; } >"$W/out_snapshot_historical"
    run_script -- take-backup
    expect_refused 'a snapshot that did not say it finished' 'snapshot:complete=ok'
    [[ ! -e "$T/root/historical.dump.sql" ]] || bad 'a backup was taken from a snapshot that did not finish'
    baseline_world
    fixture_output | grep -v '^structure accounts ' >"$W/out_fixture_load"
    run_script -- baseline
    expect_refused 'a fixture whose structure is incomplete' 'structure lines are not exactly'
    [[ ! -e "$T/root/historical.snapshot" ]] || bad 'a snapshot was written for an incomplete structure'
}

# --- the command surface ---

t_g13_commands_take_no_arguments() {
    local c
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade half-fail-upgrade revoke-after-backup; do
        good_world
        run_script -- "$c" /tmp/other.sql
        expect_rc "$c with an argument" 64
        assert_no_docker_call "$c with an argument"
        run_script -- "$c" --database flowlife
        expect_rc "$c with a database" 64
    done
    run_script -- --help
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade half-fail-upgrade revoke-after-backup; do expect_has 'usage' "$OUT" "$c"; done
    expect_has 'usage names the defect' "$OUT" 'not a restoration'
}

t_g2_stage_commands_refuse_inherited_variables() {
    local c
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade half-fail-upgrade revoke-after-backup; do
        src_world
        run_script DB_HOST=elsewhere -- "$c"
        expect_refused "$c with DB_HOST" 'DB_HOST'
        assert_no_docker_call "$c with DB_HOST"
    done
    src_world
    run_script APP_KEY="$KEY_CUR" -- baseline
    expect_refused 'baseline with an inherited APP_KEY' 'APP_KEY'
    assert_no_docker_call 'an inherited APP_KEY'
}

t_cleanup_removes_the_stage_files() {
    ensure_cache
    good_world
    install_state snapshot dump.sql manifest.json
    put_prepared_source; install_upgraded_structure
    printf 'halfmigrated-v1\n' >"$T/root/halfmigrated.state"; chmod 600 "$T/root/halfmigrated.state" # slice 2.2C-1: the half-failed record is a file this script wrote
    run_script -- cleanup --dry-run
    expect_rc 'dry run' 0
    [[ -e "$T/root/historical.dump.sql" ]] || bad 'a dry run removed the dump'
    run_script -- cleanup
    expect_rc 'cleanup' 0
    [[ ! -e "$T/root" ]] || bad 'cleanup left the state directory although it held only files this script wrote (the prepared-source record, the upgrade structure and the half-failed record included)'
    good_world
    install_state snapshot dump.sql manifest.json
    printf 'mine\n' >"$T/root/notes.txt"
    run_script -- cleanup
    expect_rc 'cleanup beside another file' 1
    [[ -e "$T/root/notes.txt" && -e "$T/root/historical.dump.sql" && -e "$T/root/.flc-rehearsal-root" ]] || bad 'cleanup removed files from a directory that holds a file it did not write'
    mkdir "$T/root/historical.snapshot.d" 2>/dev/null || true
}

# --- static: the lists, and the two languages, agree ---

# shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
t_static_lists_match_the_repository() {
    local snap="$ROOT/apps/platform/tests/Support/HistoricalSnapshot.php" fixture="$ROOT/apps/platform/tests/Support/HistoricalFixture.php" runbook="$ROOT/docs/runbooks/deployment.md" got want name
    want="$(printf '%s\n' "${HIST_TABLES[@]}" | sort)"
    got="$(sed -n '/public const array TABLES = \[/,/^    \];/p' "$snap" | grep -o "^ *'[a-z_]*' =>" | tr -d " '=>" | sort)"
    [[ "$got" == "$want" ]] || bad 'the 16 historical tables are not HistoricalSnapshot::TABLES'
    want="$(printf '%s\n' "${NEWER_TABLES[@]}" | sort)"
    got="$(sed -n '/public const array NEW_TABLES = \[/,/^    \];/p' "$fixture" | grep -o "'[a-z_]*'" | tr -d "'" | sort)"
    [[ "$got" == "$want" ]] || bad 'the 18 newer tables are not HistoricalFixture::NEW_TABLES'
    want="$(printf '%s\n' "${TRANSIENT[@]}" | sort)"
    got="$(grep -o -- '--ignore-table=<db>\.[a-z_]*' "$runbook" | sed 's/.*\.//' | sort -u)"
    [[ "$got" == "$want" ]] || bad 'the transient tables are not the deployment runbook'"'"'s --ignore-table list'
    # The script's own lists are the tests' lists.
    run_fn 'printf "%s\n" "${HISTORICAL_TABLES[@]}" | LC_ALL=C sort'; [[ "$OUT" == "$(printf '%s\n' "${HIST_TABLES[@]}" | LC_ALL=C sort)" ]] || bad "the script's HISTORICAL_TABLES differ from the tests'"
    run_fn 'printf "%s\n" "${NEWER_TABLES[@]}" | LC_ALL=C sort'; [[ "$OUT" == "$(printf '%s\n' "${NEWER_TABLES[@]}" | LC_ALL=C sort)" ]] || bad "the script's NEWER_TABLES differ from the tests'"
    run_fn 'printf "%s\n" "${TRANSIENT_TABLES[@]}" | LC_ALL=C sort'; [[ "$OUT" == "$(printf '%s\n' "${TRANSIENT[@]}" | LC_ALL=C sort)" ]] || bad "the script's TRANSIENT_TABLES differ from the tests'"
    [[ "$(( ${#HIST_TABLES[@]} + ${#NEWER_TABLES[@]} ))" == 34 && ${#NEWER_TABLES[@]} == 18 && ${#TRANSIENT[@]} == 7 ]] || bad 'the table counts are not 16 + 18 = 34, with 7 transient'
    # The harness's written-out row counts: the 16 tables, 13 people.
    for name in "${HIST_TABLES[@]}"; do
        grep -qE "'$name' => $(rows_of "$name")[,]" "$SRC/php/rehearse.php" || bad "rehearse.php's FIXTURE_ROWS does not hold $name => $(rows_of "$name")"
    done
    run_fn 'echo "$FIXTURE_PEOPLE"'; [[ "$OUT" == 13 ]] || bad 'FIXTURE_PEOPLE is not 13'
}

# The registry comparison still finds a marker with no entry and an entry with no marker, finds nothing where they agree, answers the same at
# any size (the size at which the pipe it replaced failed every time), and the matrix no longer pipes into an early-exiting reader.
t_guard_registry_comparison() {
    local out i matrix
    [[ "$(registry_differences $'A\nB\nC' $'B\nC\nD' | LC_ALL=C sort)" == $'unmarked D\nunregistered A' ]] || bad 'a marker with no entry and an entry with no marker were not both found'
    [[ -z "$(registry_differences $'A\nB' $'B\nA')" ]] || bad 'markers and entries that agree were reported as different'
    [[ "$(registry_differences 'G13-a' 'G13-ab' | LC_ALL=C sort)" == $'unmarked G13-ab\nunregistered G13-a' ]] || bad 'a name that is a prefix of another was taken for it'
    [[ -n "$(registry_differences 'A' 'a')" ]] || bad 'names that differ only in case were taken for one'
    for i in 1 2 3; do
        out="$(registry_differences "$(seq 1 40000)" "$(seq 2 40000)")"
        [[ "$out" == 'unregistered 1' ]] || bad "a list of 40000 markers gave '${out:0:60}' (round $i)"
    done
    out="$(registry_differences "$(seq 1 40000)" "$(seq 1 40000)")"
    [[ -z "$out" ]] || bad 'two equal lists of 40000 differ'
    matrix="$(sed -n '/^[[:space:]]*printf .\\nMutation matrix (each/,$p' "$ROOT/scripts/tests/rehearsal.sh")"
    [[ -n "$matrix" ]] || bad 'the matrix could not be found in this file'
    [[ "$matrix" != *'grep -q'* && "$matrix" != *'grep -x'* ]] || bad 'the matrix pipes into an early-exiting grep again'
}

# --- WU1: files-volume creation is an explicit permission; the volume is proven again after the create; evidence; what a failed stage may say ----------------

# The marker that says the files volume was established earlier in this run (what the script writes the first time it has created and verified the volume).
established_files_volume() {
    printf 'files-volume-v1\n%s' "$(printf 'flc-run-v1|%s' "$TOKEN" | sha256sum | cut -c1-16)" >"$T/root/files.volume"
    chmod 600 "$T/root/files.volume"
}
# What the world holds that a stage that changed nothing would not have changed: no mutating helper, no write statement, no volume created or removed, nothing
# written to the state directory but what was there (the run's own lock file, which every command makes, aside). (The fake daemon logs each; the state directory is compared with a listing taken before the stage.)
STATE_LISTING=''
snapshot_state() { STATE_LISTING="$(cd "$T/root" && find . -type f ! -name run.lock -exec sha256sum {} + | LC_ALL=C sort)"; }
world_changed() { # prints what changed, if anything
    local k out='' now
    for k in fixture_load migrate_historical migrate_current mark half_migrate revoke files_seed files_aside files_restore; do
        if [[ -f "$W/runs" ]] && grep -q "^$k\$" "$W/runs"; then out+="$k ran; "; fi
    done
    for k in stub.sql clear.sql import.sql assets_insert.sql; do [[ ! -e "$W/$k" ]] || out+="$k was run; "; done
    if grep -qE '^volume (create|rm) ' "$W/calls"; then out+="a volume was created or removed; "; fi
    now="$(cd "$T/root" && find . -type f ! -name run.lock -exec sha256sum {} + | LC_ALL=C sort)"
    [[ "$now" == "$STATE_LISTING" ]] || out+="the state directory changed ($(diff <(printf '%s\n' "$STATE_LISTING") <(printf '%s\n' "$now") | grep '^[<>]' | cut -c1-90 | tr '\n' '|')); "
    printf '%s' "$out"
}
UNCHANGED_SAYS='stopped before it began to change anything'
changed_says='may be partly changed. No database, volume or network was removed'
# trailer_unchanged DESC: the stage refused having begun nothing, and said exactly that; the world agrees. trailer_cautious DESC: it may have begun, and said so.
trailer_unchanged() {
    expect_has "$1 (the trailer)" "$ERR" "$UNCHANGED_SAYS"
    expect_lacks "$1 (the trailer)" "$ERR" 'may be partly changed'
    [[ -z "$(world_changed)" ]] || bad "$1: it said it changed nothing but the world changed: $(world_changed)"
}
trailer_cautious() {
    expect_has "$1 (the trailer)" "$ERR" "$changed_says"
    expect_lacks "$1 (the trailer)" "$ERR" "$UNCHANGED_SAYS"
}

# N-L1: only the first files action of seed-files or backup-files may create the files volume, and it is told so by an argument. Nothing in the environment can.
t_g22_creation_is_an_explicit_permission() {
    local before
    ensure_files_cache
    # A variable in the environment grants nothing: it is refused at the start (G2), and if it were not, the functions do not read it.
    backup_files_world up absent 0
    run_script FILES_CREATE_OK=1 -- backup-files
    expect_refused 'backup-files with FILES_CREATE_OK in the environment' 'FILES_CREATE_OK'
    assert_no_docker_call 'an inherited FILES_CREATE_OK'
    for before in aside-files restore-files seed-files; do
        files_world
        run_script FILES_CREATE_OK=1 -- "$before"
        expect_refused "$before with FILES_CREATE_OK" 'FILES_CREATE_OK'
    done
    restore_files_world novolume
    run_fn "ROOT='$T/root'; files_volume_ready '$TOKEN'" FILES_CREATE_OK=1
    [[ "$RC" == 3 && "$ERR" == *'only the first files action of seed-files or backup-files may create it'* ]] || bad "an inherited FILES_CREATE_OK created, or the refusal was not the rule's: $RC ${ERR:0:300}"
    run_fn "ROOT='$T/root'; files_volume_ready '$TOKEN' 0" FILES_CREATE_OK=1
    [[ "$RC" == 3 && "$ERR" == *'only the first files action'* ]] || bad "files_volume_ready with no permission, and an inherited variable, did not refuse: $RC ${ERR:0:300}"
    [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad 'a files volume was created without permission'
    if want_case G22-f-create-scope; then
        # No other action can acquire it, whatever its caller asks.
        for before in aside restore archive verify-archive; do
            restore_files_world novolume
            run_fn "ROOT='$T/root'; run_files --create $before"
            [[ "$RC" == 3 && "$ERR" == *'may not create the files volume'* ]] || bad "run_files --create $before was not refused as an action that may not create: $RC ${ERR:0:300}"
            [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad "run_files --create $before created a files volume"
        done
    fi
    # The authorized first use: the volume is created, with its labels, once, and recorded.
    restore_files_world novolume
    run_fn "ROOT='$T/root'; files_volume_ready '$TOKEN' 1"
    expect_rc 'the authorized first creation' 0
    [[ "$(grep -c '^volume create ' "$W/calls")" == 1 && -d "$W/v/$NAME_FV" && -f "$T/root/files.volume" ]] || bad 'the authorized creation did not create one volume and record it'
    # An established volume that is missing is never recreated, even when this call is permitted to create.
    restore_files_world novolume; established_files_volume
    run_fn "ROOT='$T/root'; files_volume_ready '$TOKEN' 1"
    [[ "$RC" == 3 && "$ERR" == *'established earlier in this run and is now missing'* ]] || bad "a permitted call recreated an established volume, or was refused for another reason: $RC ${ERR:0:300}"
    [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad 'an established volume was created again'
    # The initial zero-file backup still creates the volume, once.
    backup_files_world hist absent 0
    run_script -- backup-files
    expect_rc 'the initial zero-file backup' 0
    [[ "$(grep -c '^volume create ' "$W/calls")" == 1 ]] || bad 'the zero-file backup did not create exactly one volume'
}

# N-L2: Docker creates a named volume that is missing when it sets up a container's mounts, so the volumes are proven again once the container exists, with that
# container the only consumer allowed. The fake daemon replaces a volume (or adds a consumer, or alters the new container) during the create.
# What it proves: that the script looks again, after the create returns, with the right identity and consumer rules, and acts only on its own container. What it
# cannot prove: that a live daemon behaves so on that race, and what the replacement volume looks like there (the fake makes it empty, unlabelled and local).
replace_volume_hook() { # NAME: the volume is removed and made again as Docker would on its own: the right name, local, no labels, no token
    printf 'rm -rf "%s/v/%s"; mkdir -p "%s/v/%s"; cd "%s/v/%s"; printf %%s "%s" >id; printf %%s "%s" >name; printf local >driver; printf 0 >options\n' "$W" "$1" "$W" "$1" "$W" "$1" "$1" "$1" >"$W/hook_create"
}
files_container_exists() { local d; for d in "$W"/c/*; do [[ -d "$d" && "$(cat "$d/name" 2>/dev/null)" == "/$NAME_F" ]] && return 0; done; return 1; }

# process_alive PID: true while the process exists and is not a zombie that has only to be waited for.
process_alive() { local st=''; [[ -r "/proc/$1/stat" ]] || return 1; read -r _ _ st _ <"/proc/$1/stat" 2>/dev/null || return 1; [[ -n "$st" && "$st" != Z && "$st" != X ]]; }

# interrupt_at_create TMPDIR TRIES -- ARGS...: runs the script as run_script does (the same scrubbed environment, the same fake daemon), but in the background so that
# the PID of THAT process is known from the launch ($!: nothing is looked up, nothing is searched for by name). The fake daemon's create hook says it has been entered,
# and waits to be released; the test waits for that (at most TRIES tenths of a second), checks that the change record already exists, sends SIGTERM to the one PID it
# started and to nothing else, releases the hook so that the stage can run its trap, and waits for that PID. Sets RC, OUT and ERR as run_script does, and INT_WHY:
# empty when it all happened, otherwise what did not (a stage that ended early, a create that was never reached, a record that was not there, a stage that did not
# end). Whatever it started is gone when it returns, whichever way it ended.
interrupt_at_create() {
    local tmp="$1" tries="$2" pid i=0 args
    shift 3
    args="$*"
    INT_WHY='' INT_PID='' RC=0
    rm -f "$W/create_ready" "$W/create_release"
    # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
    printf '{ for f in "%s"/*.changed; do [[ -e "$f" ]] && echo changed-record-present; done; echo entered; } >"%s/create_ready"\nj=0; while [[ ! -e "%s/create_release" && $j -lt 100 ]]; do sleep 0.1; j=$((j + 1)); done\n' \
        "$tmp" "$W" "$W" >"$W/hook_create"
    # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
    [[ "${INT_NO_READY:-0}" == 1 ]] && printf 'j=0; while [[ ! -e "%s/create_release" && $j -lt 100 ]]; do sleep 0.1; j=$((j + 1)); done\n' "$W" >"$W/hook_create"
    env -i PATH="$BIN" HOME="$T/home" FLC_REHEARSAL_ROOT="$T/root" TMPDIR="$tmp" "$BIN/bash" "$SUT/restore-rehearsal.sh" "$@" >"$T/out" 2>"$T/err" &
    pid=$! INT_PID=$!
    until [[ -s "$W/create_ready" ]]; do
        if ! process_alive "$pid"; then INT_WHY='the stage ended before the daemon was asked to create the container'; break; fi
        if ((i >= tries)); then INT_WHY='the stage did not reach the create in time'; break; fi
        sleep 0.1
        i=$((i + 1))
    done
    if [[ -z "$INT_WHY" ]]; then
        compgen -G "$tmp/*.changed" >/dev/null || INT_WHY='the change record did not exist when the create was reached'
        [[ "$(tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null)" == *"$SUT/restore-rehearsal.sh $args "* ]] || INT_WHY="${INT_WHY:-the PID that was started is not the stage}"
    fi
    if [[ -z "$INT_WHY" ]]; then
        kill -TERM "$pid" 2>/dev/null || INT_WHY='the signal could not be delivered to the stage'
    fi
    : >"$W/create_release"
    i=0
    while process_alive "$pid"; do
        if ((i >= 100)); then INT_WHY="${INT_WHY:-the stage did not end after the signal}"; kill -KILL "$pid" 2>/dev/null || true; break; fi
        sleep 0.1
        i=$((i + 1))
    done
    wait "$pid" 2>/dev/null || RC=$?
    OUT="$(<"$T/out")"
    ERR="$(<"$T/err")"
    INT_SEEN="$(cat "$W/create_ready" 2>/dev/null || true)"
    [[ -z "$INT_WHY" ]] || return 1
    return 0
}
t_g22_the_mounts_are_verified_again_after_create() {
    # The files volume is replaced by an unlabelled one while the container is created.
    if want_case G22-f-recheck-files-volume || want_case G22-f-recheck-refuses; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
        replace_volume_hook "$NAME_FV"
        snapshot_state
        run_script -- backup-files
        expect_refused 'a files volume replaced while the container was created' "after the files container was created, the files volume is no longer the rehearsal's: its labels are not this script's"
        expect_has 'the container is said to be removed, not started' "$ERR" 'was removed and not started'
        [[ ! -e "$W/started" ]] || ! grep -q "$NAME_F" "$W/started" || bad 'the files container was started although its volume was replaced'
        ! files_container_exists || bad 'the files container that was created was not removed'
        [[ -d "$W/v/$NAME_FV" ]] || bad 'the replacement volume was removed: it is evidence and is never deleted'
        ! grep -q '^volume rm ' "$W/calls" || bad 'a volume was removed'
        no_files_container 'a replaced files volume'
        [[ ! -e "$T/root/resources.manifest.json" ]] || bad 'a manifest was written for a backup whose volume was replaced'
        [[ "$(grep -c '^create ' "$W/calls")" == 1 ]] || bad 'more than the one files container was created'
    fi
    # The same for the source volume, and for a consumer that appeared.
    if want_case G22-f-recheck-source-volume; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
        replace_volume_hook "$NAME_SRC"
        run_script -- backup-files
        expect_refused 'a source volume replaced while the container was created' "the source volume is no longer the rehearsal's"
        [[ ! -e "$W/started" ]] || ! grep -q "$NAME_F" "$W/started" || bad 'the files container was started although the source volume was replaced'
        ! files_container_exists || bad 'the files container was not removed after the source volume was replaced'
        [[ -d "$W/v/$NAME_SRC" ]] || bad 'the replacement source volume was removed'
    fi
    # A container that is not ours that began using the files volume, or the source volume, in the window.
    backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf 'mkdir -p "%s/c/%s"; cd "%s/c/%s"; printf %%s %s >id; printf /intruder >name; printf %%s "volume:%s:/data," >mounts\n' "$W" "$FOREIGN" "$W" "$FOREIGN" "$FOREIGN" "$NAME_FV" >"$W/hook_create"
    run_script -- backup-files
    expect_refused 'a foreign container that began using the files volume' 'that is not ours uses it'
    ! files_container_exists || bad 'the files container was not removed after a foreign consumer appeared'
    [[ -d "$W/c/$FOREIGN" ]] || bad 'the foreign container was removed'
    backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf 'mkdir -p "%s/c/%s"; cd "%s/c/%s"; printf %%s %s >id; printf /intruder >name; printf %%s "volume:%s:/data," >mounts\n' "$W" "$FOREIGN" "$W" "$FOREIGN" "$FOREIGN" "$NAME_SRC" >"$W/hook_create"
    run_script -- backup-files
    expect_refused 'a foreign container that began using the source volume' 'the source volume is no longer the rehearsal'"'"'s: container'
    # The new container is only removed if it is still what was created: one that changed is left where it is, and the refusal says so.
    if want_case G22-f-recheck-removable; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
        replace_volume_hook "$NAME_FV"
        # (after create_verified has looked, at the next inspect of that container; the hook waits for the files container to exist)
        # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
        printf 'ok=1; for d in "%s"/c/*; do if [[ "$(cat "$d/name" 2>/dev/null)" == "/%s" ]]; then printf %%s someone-else >"$d/l_role"; ok=0; fi; done; exit $ok\n' "$W" "$NAME_F" >"$W/hook_inspect_c"
        run_script -- backup-files
        expect_refused 'a container that changed during the create' 'is no longer what this script created, so it was left where it is'
        files_container_exists || bad 'a container that is no longer the script'"'"'s was removed'
        ! grep -q "^rm " "$W/calls" || bad 'a container was removed although it could not be proven to be the one created'
        [[ ! -e "$W/started" ]] || ! grep -q "$NAME_F" "$W/started" || bad 'a container that changed was started'
        # and one that is fine is removed (the case above), and a removal that fails says so
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
        replace_volume_hook "$NAME_FV"
        inject_failure 'rm *'
        run_script -- backup-files
        expect_refused 'a created container that cannot be removed' 'could not be removed and was not started'
    fi
    # Nothing was weakened: with the volume replaced BEFORE the create, the first check refuses as before and no container is created.
    backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_ns=; established_files_volume
    run_script -- backup-files
    expect_refused 'a volume replaced before the create' "the files volume is not the rehearsal's"
    [[ "$(grep -c '^create ' "$W/calls" || true)" == 0 ]] || bad 'a container was created although the volume was already wrong'
    # And a restore, which reads the same volumes, is held to the same rule.
    ensure_files_cache
    restore_files_world; established_files_volume
    replace_volume_hook "$NAME_FV"
    run_script -- restore-files
    expect_refused 'a restore whose files volume was replaced during the create' 'is no longer the rehearsal'"'"'s'
    ! files_container_exists || bad 'the restore left its files container'
}

# Evidence: only what has passed the existing validation is printed, only keys, sizes and SHA-256 values, in the validated order; a large inventory is reduced to
# a count and one digest. No content, token or row is printed, and no line of a helper's output reaches the report unvalidated.
t_g22_evidence_is_validated_before_it_is_printed() {
    local spec='' i key large=''
    for i in 1 2 3; do spec+="${spec:+,}$(printf '0%025d' "$i"):$((i * 10)):$(printf '%064x' "$i")"; done
    run_fn "say_inventory 'read before' '$spec'"
    expect_rc 'a small inventory' 0
    expect_has 'the count' "$OUT" 'read before: 3 file(s), by key, size and SHA-256:'
    expect_has 'a key, a size and a digest' "$OUT" "$(printf '0%025d' 2)  20 bytes  $(printf '%064x' 2)"
    for i in $(seq 1 17); do large+="${large:+,}$(printf '0%025d' "$i"):$i:$(printf '%064x' "$i")"; done
    run_fn "say_inventory 'read after' '$large'"
    expect_has 'a large inventory is a count' "$OUT" 'read after: 17 files; SHA-256 of the list'
    expect_has 'and one digest' "$OUT" "$(printf '%s' "$large" | sha256sum | cut -c1-64)"
    expect_lacks 'and no key' "$OUT" "$(printf '0%025d' 5)"
    run_fn "say_inventory 'none' 'empty'"
    expect_has 'an empty inventory' "$OUT" 'none: no files'
    if want_case G22-evidence-shape; then
        for key in 'garbage' "$(printf '0%025d' 1):1:xyz" "$(printf '0%025d' 1):-1:$(printf '%064x' 1)" "$(printf '0%025d' 1):1:$(printf '%064x' 1):extra" "$(printf 'U%025d' 1):1:$(printf '%064x' 1)"; do
            run_fn "say_inventory 'x' '$key'"
            [[ "$RC" == 3 && "$OUT" != *'by key'* && "$OUT" != *"${key:0:12}"* ]] || bad "an inventory not in the validated shape ('${key:0:30}') was printed or not refused: $RC ${OUT:0:200}"
        done
    fi
    # In the commands: the archive's accepted entry count, and the aside's inventories, after their own validation; nothing a helper printed beyond that.
    ensure_files_cache
    backup_files_world up present 4
    run_script -- backup-files
    expect_rc 'a backup' 0
    expect_has 'the accepted entries of the verified archive' "$OUT" 'holds 5 entries (the Resources directory and 4 file(s))'
    restore_files_world
    printf 'archive:note=SECRET-NOT-FOR-THE-LOG\n' >>"$W/out_files_verify_archive"
    run_script -- restore-files
    expect_rc 'a restore' 0
    expect_has 'the entries read before anything was written' "$OUT" 'holds 5 entries (the Resources directory and 4 file(s))'
    expect_lacks 'a helper line that is not part of the protocol' "$OUT$ERR" 'SECRET-NOT-FOR-THE-LOG'
    aside_world
    printf 'aside:note=SECRET-NOT-FOR-THE-LOG\n' >>"$W/out_files_aside"
    run_script -- aside-files
    expect_rc 'an aside' 0
    expect_has 'the inventory before the rename' "$OUT" 'read immediately before the rename: 4 file(s), by key, size and SHA-256:'
    expect_has 'the inventory after it' "$OUT" 'read in the renamed directory: 4 file(s), by key, size and SHA-256:'
    expect_has 'a key, size and digest' "$OUT" "${FKEYS[0]}  ${FBYTES[0]} bytes  $(fsha 1)"
    expect_lacks 'a helper line that is not part of the protocol' "$OUT$ERR" 'SECRET-NOT-FOR-THE-LOG'
    # Evidence is printed only after validation: an aside whose inventories differ prints none.
    aside_world; aside_out 3 4 >"$W/out_files_aside"
    run_script -- aside-files
    expect_refused 'an aside that did not preserve the files' 'not what was there'
    expect_lacks 'no inventory after a refusal' "$OUT" 'by key, size and SHA-256'
}

# What a stage that did not finish says. A refusal that came before anything could change the rehearsal database, the files volume or the state directory says
# so, and the world agrees; one after something may have begun, however it failed (inside a function, a command substitution, a pipeline or a subshell), keeps
# the cautious words. The fake daemon proves the tracking sees every one of these; whether a live daemon's failures are always seen the same way is not proven.
t_g23_a_failed_stage_does_not_overstate_or_understate() {
    # --- refusals that came before anything could change (before the files container is asked of Docker): the trailer says so, and the world agrees.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    add_ephemeral "$RID" runner; snapshot_state
    run_script -- backup-files
    expect_refused 'a runner left over from an interrupted run' 'a rehearsal container other than the database exists'
    trailer_unchanged 'a refusal because of a leftover container'
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_token="$OTHER_TOKEN"; established_files_volume; snapshot_state
    run_script -- backup-files
    expect_refused 'a files volume of another run' "the files volume is not the rehearsal's"
    trailer_unchanged 'a refusal because the files volume is not the rehearsal'"'"'s'
    backup_files_world up present 4; established_files_volume; snapshot_state
    run_script -- backup-files
    expect_refused 'an established files volume that is gone' 'was established earlier in this run and is now missing'
    trailer_unchanged 'a refusal because an established volume is missing'
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source l_token="$OTHER_TOKEN"; snapshot_state
    run_script -- backup-files
    expect_refused 'a source volume of another run' "the source volume is not the rehearsal's"
    trailer_unchanged 'a refusal because the source volume is not the rehearsal'"'"'s'
    # --- a refusal AFTER the files container was asked of Docker is cautious even when the action only reads: Docker sets up the mounts at create, and a missing
    # volume is made then. (The helper's output being wrong, the archive not verifying, a zero-file backup refused on a directory: all of them come after.)
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf 'files:state=present\nfiles:count=4\n' >"$W/out_files_check"
    run_script -- backup-files
    expect_refused 'a files check that did not report ok' 'did not report files:check=ok'
    trailer_cautious 'a read-only files check that did not report ok'
    ensure_files_cache
    restore_files_world; established_files_volume
    printf 'archive:entries=5\n' >"$W/out_files_verify_archive"
    run_script -- restore-files
    expect_refused 'an archive that was not verified' 'was not verified against the manifest'
    trailer_cautious 'a read-only archive verification that did not verify'
    restore_files_world zero; established_files_volume
    fout present 4 files:check=ok >"$W/out_files_check"
    run_script -- restore-files
    expect_refused 'a zero-file restore with a directory in place' 'a Resources directory exists'
    trailer_cautious 'a zero-file restore refused on a read-only check'
    # --- refusals after something may have begun: the cautious words, whatever the failure was inside.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
    printf 'files:state=present\nfiles:count=4\n' >"$W/out_files_check"
    run_script -- backup-files
    expect_refused 'a refusal after the record of the files volume was written' 'did not report files:check=ok'
    trailer_cautious 'a refusal after the record of the files volume was written'
    backup_files_world up present 4
    printf 'files:state=present\nfiles:count=4\n' >"$W/out_files_check"
    run_script -- backup-files
    expect_refused 'a refusal after the files volume was created' 'did not report files:check=ok'
    trailer_cautious 'a refusal after the files volume was created'
    seed_world
    printf '5\n' >"$W/fail_files_seed"
    run_script -- seed-files
    expect_rc 'a seed that fails inside a command substitution' 5
    trailer_cautious 'a seed that failed in a command substitution'
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf '5\n' >"$W/fail_files_archive"
    run_script -- backup-files
    trailer_cautious 'an archive that failed after its temporary file was made'
    ensure_files_cache
    restore_files_world; established_files_volume
    printf '5\n' >"$W/fail_files_restore"
    run_script -- restore-files
    trailer_cautious 'an extraction that failed'
    aside_world; established_files_volume
    printf '5\n' >"$W/fail_files_aside"
    run_script -- aside-files
    trailer_cautious 'an aside that failed'
    if want_case G23-helper-mutation; then
        ensure_cache
        restore_world
        stage_world; baseline_world
        printf '5\n' >"$W/fail_migrate_historical"
        run_script -- baseline
        trailer_cautious 'a migration that failed in the runner'
    fi
    if want_case G23-sql-mutation; then
        ensure_half_state
        half_world
        printf '1\n' >"$W/stub_fail"
        run_script -- half-fail-upgrade
        trailer_cautious 'a stub that failed to be created'
    fi
    # app_query runs reads only.
    if want_case G23-query-read-only; then
        good_world
        run_fn "ROOT='$T/root'; app_query 'DROP TABLE people'"
        [[ "$RC" == 3 && "$ERR" == *'app_query runs reads only'* ]] || bad "app_query ran, or did not refuse, a statement that is not a SELECT: $RC ${ERR:0:200}"
        [[ ! -e "$W/appsql.log" ]] || ! grep -q 'DROP TABLE people' "$W/appsql.log" || bad 'a statement that is not a SELECT reached the database'
    fi
    # The record that tracks all this is the script's own, outside the state directory, and is gone when the stage ends.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf 'files:state=present\nfiles:count=4\n' >"$W/out_files_check"
    mkdir -p "$T/trailer-tmp"
    run_script TMPDIR="$T/trailer-tmp" -- backup-files
    [[ -z "$(find "$T/trailer-tmp" -mindepth 1)" ]] || bad "a stage left its change record behind: $(find "$T/trailer-tmp" -mindepth 1 | head -n 2 | tr '\n' ' ')"
    # When the record cannot be made at all, the trailer is the cautious one: no claim is made without the proof.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf 'files:state=present\nfiles:count=4\n' >"$W/out_files_check"
    run_script TMPDIR="$T/no-such-directory" -- backup-files
    expect_refused 'a refusal with no change record' 'did not report files:check=ok'
    trailer_cautious 'a refusal when the change record could not be made'
}

# --- WU1 review remediation: the files container's create is a recorded change; an unwritable change record stops the stage; the read-only list is pinned ----

# F1. Docker sets up a container's mounts when it is created, and makes a volume that is missing then, so even a read-only files action may change the files
# volume the moment the create is attempted. The change record is made BEFORE Docker is asked, and every way that create can end afterwards (the daemon
# refuses, the container is not what was asked for, a volume was replaced, the stage is interrupted) is told in the cautious words.
t_g23_creating_the_files_container_is_a_recorded_change() {
    local seen
    # The record is there before the create is attempted: the fake daemon looks for it at the create, for an action that only reads, and with the volume
    # established so that nothing earlier could have made it.
    mkdir -p "$T/marker-tmp"
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
    printf 'for f in "%s"/*.changed; do [[ -e "$f" ]] && echo "$f"; done >"%s/marker_at_create"\n' "$T/marker-tmp" "$W" >"$W/hook_create"
    run_script TMPDIR="$T/marker-tmp" -- backup-files
    seen="$(cat "$W/marker_at_create" 2>/dev/null || true)"
    [[ "$seen" == "$T/marker-tmp/"*.changed ]] || bad "the files container was created before the change record was made (the fake daemon saw: ${seen:0:200})"
    # The volume replaced while a READ-ONLY action's container was created: the recheck refuses, and the trailer is the cautious one.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    replace_volume_hook "$NAME_FV"
    run_script -- backup-files
    expect_refused 'a files volume replaced during a read-only check' 'the files volume is no longer the rehearsal'"'"'s'
    trailer_cautious 'a volume replaced during the create of a read-only check'
    [[ -d "$W/v/$NAME_FV" ]] || bad 'the replacement volume was removed'
    ! grep -q '^volume rm ' "$W/calls" || bad 'a volume was removed'
    [[ ! -e "$W/started" ]] || ! grep -q "$NAME_F" "$W/started" || bad 'a container was started after its volume was replaced'
    ensure_files_cache
    restore_files_world; established_files_volume
    replace_volume_hook "$NAME_FV"
    run_script -- restore-files
    expect_refused 'a files volume replaced during a read-only archive verification' 'is no longer the rehearsal'"'"'s'
    trailer_cautious 'a volume replaced during the create of a read-only verification'
    # The container that was created is not what was asked for: refused and removed by create_verified, and the trailer is cautious.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    printf 'l_role someone-else\n' >"$W/create_override"
    run_script -- backup-files
    expect_refused 'a created container that fails its identity check' 'was removed and not started'
    trailer_cautious 'a created container that failed its identity check'
    [[ ! -e "$W/started" ]] || ! grep -q "$NAME_F" "$W/started" || bad 'a container that failed its identity check was started'
    ! files_container_exists || bad 'a container that failed its identity check was left'
    [[ -d "$W/v/$NAME_FV" ]] || bad 'a volume was removed after a container failed its identity check'
    # The daemon refuses the create altogether.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    : >"$W/create_fail"
    run_script -- backup-files
    expect_rc 'a create that Docker refused' 1
    expect_has 'a create that Docker refused' "$ERR" 'docker could not create the files container'
    trailer_cautious 'a create that Docker refused'
    no_files_container 'a create that Docker refused'
    # The stage is interrupted once the create has begun: the exit trap still runs, and says it may have changed. The signal goes to the one process the test
    # started (see interrupt_at_create), at a point the fake daemon has reported and the change record exists.
    local bystander
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    rm -rf "$T/int-tmp"; mkdir -p "$T/int-tmp"
    # A process whose command line has the words of the stage's (a search by name would have found it) and that the test owns: it must be left alone.
    mkfifo "$T/bystander.fifo"
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    "$BIN/bash" -c 'exec 3<>"$2"; read -r -t 30 -u 3 _' restore-rehearsal.sh backup-files "$T/bystander.fifo" &
    bystander=$!
    if interrupt_at_create "$T/int-tmp" 100 -- backup-files; then
        [[ "$INT_SEEN" == *changed-record-present* ]] || bad 'the daemon was asked to create the container before the change record existed'
        [[ "$RC" == 143 ]] || bad "a stage that was terminated did not exit 143: $RC"
        trailer_cautious 'a stage interrupted after the create had begun'
        [[ -z "$(find "$T/int-tmp" -mindepth 1)" ]] || bad 'an interrupted stage left its change record behind'
        # Bash runs a trap after the foreground command that is running has finished, so the `check` whose create the signal arrived in may run to its end (start,
        # then removal) before the stage exits. What matters is what does not follow: no files container is left behind, the stage does not go on to its next files
        # action (`archive`), and exactly one files container was ever created.
        ! files_container_exists || bad 'an interrupted stage left a files container behind'
        [[ "$(grep -c "^create --name $NAME_F " "$W/calls")" == 1 ]] || bad 'an interrupted stage created more than one files container'
        ! grep -q "files.php archive" "$W/calls" || bad 'an interrupted stage went on to the archive action'
        [[ "$(grep -c "^rm " "$W/calls")" == "$(grep -c "^start " "$W/calls")" ]] || bad 'a container that an interrupted stage started was not removed'
    else
        bad "the interruption could not be carried out: $INT_WHY"
    fi
    process_alive "$bystander" || bad 'a process that only looks like the stage was signalled'
    kill -KILL "$bystander" 2>/dev/null || true
    wait "$bystander" 2>/dev/null || true
    rm -f "$T/bystander.fifo"
    # The synchronisation itself fails, and does not hang, when its conditions are not met: a daemon that never says it has been entered, and a stage that ends before
    # it could be.
    backup_files_world up present 4; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data; established_files_volume
    rm -rf "$T/int-tmp"; mkdir -p "$T/int-tmp"
    INT_NO_READY=1 interrupt_at_create "$T/int-tmp" 5 -- backup-files && bad 'an interruption that never saw the daemon pass for one that was carried out'
    [[ "$INT_WHY" == 'the stage did not reach the create in time' ]] || bad "a daemon that never reported was not told as such: $INT_WHY"
    ! process_alive "$INT_PID" || bad 'a stage was left running by an interruption that failed'
    rm -rf "$T/int-tmp"; mkdir -p "$T/int-tmp"
    interrupt_at_create "$T/int-tmp" 20 -- no-such-stage && bad 'a stage that ended early passed for an interruption'
    [[ "$INT_WHY" == 'the stage ended before the daemon was asked to create the container' ]] || bad "a stage that ended early was not told as such: $INT_WHY"
    # Everything before the create is still a pre-mutation refusal (see the unchanged-state cases of t_g23_a_failed_stage_does_not_overstate_or_understate), and a
    # writing action keeps its own earlier record: a seed that fails at the create is cautious as before.
    seed_world
    : >"$W/create_fail"
    run_script -- seed-files
    trailer_cautious 'a seed whose create Docker refused'
}

# F2. If the change record cannot be written, nothing that it records is begun. The destination is made unwritable with ENOTDIR (a path below a regular file),
# which fails for root too. Each place that records first is tried: the function itself, a state file, an SQL write, a helper that may write, a files action
# that writes and one that only reads (whose create is recorded just before Docker is asked). `fail` exits, so it ends a command substitution's subshell and
# a pipeline's, and the callers that need the status (capture) carry it out: tried through capture as well.
t_g23_a_change_record_that_cannot_be_written_stops_the_stage() {
    local bad_mark="$T/not-a-directory/marker" code
    : >"$T/not-a-directory"
    good_world
    run_fn "MUTATION_MARK='$bad_mark'; mutation_begins; echo SURVIVED"
    [[ "$RC" == 1 && "$ERR" == *'the record of what this stage may change could not be written, so nothing was begun'* && "$OUT" != *SURVIVED* ]] || bad "mutation_begins went on after it could not write the record, or did not say so: $RC ${ERR:0:200} $OUT"
    # A record that exists is not written again, and no record at all (a stage that was not armed) records nothing and stops nothing.
    run_fn "MUTATION_MARK=''; mutation_begins; echo SURVIVED"
    [[ "$RC" == 0 && "$OUT" == *SURVIVED* ]] || bad "an unarmed stage was stopped by mutation_begins: $RC ${ERR:0:200}"
    # A state file: nothing is written, not even its temporary file.
    src_world; snapshot_state
    run_fn "ROOT='$T/root'; MUTATION_MARK='$bad_mark'; write_state_file recorded.test content; echo SURVIVED"
    [[ "$RC" == 1 && "$OUT" != *SURVIVED* && ! -e "$T/root/recorded.test" ]] || bad "a state file was written although its change record could not be: $RC ${ERR:0:200}"
    [[ -z "$(find "$T/root" -name '.recorded.test.*')" ]] || bad 'a temporary state file was made although its change record could not be'
    [[ -z "$(world_changed)" ]] || bad "the state directory changed: $(world_changed)"
    # An SQL write reaches no container: the first thing it does is record.
    src_world
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn "ROOT='$T/root'; MUTATION_MARK='$bad_mark'; printf 'DROP TABLE x;\n' | guarded_app_sql '$T/root' \"\$APP_DB\"; echo SURVIVED"
    [[ "$RC" != 0 && "$OUT" != *SURVIVED* ]] || bad "an SQL write went on although its change record could not be written: $RC ${ERR:0:200}"
    ! grep -qE '^(exec|inspect) ' "$W/calls" || bad "an SQL write reached Docker although its change record could not be written: $(grep -E '^(exec|inspect) ' "$W/calls" | head -n 2 | tr '\n' '|')"
    # A helper that may write: no runner is created.
    src_world
    run_fn "ROOT='$T/root'; MUTATION_MARK='$bad_mark'; run_helper historical helper:fixture-load out; echo SURVIVED"
    [[ "$RC" != 0 && "$OUT" != *SURVIVED* ]] || bad "a helper that may write was run although its change record could not be written: $RC ${ERR:0:200}"
    ! grep -q '^create ' "$W/calls" || bad 'a runner was created although the change record could not be written'
    [[ ! -e "$W/runs" ]] || bad "a helper ran: $(tr '\n' ' ' <"$W/runs")"
    # The same through capture, the way the stages call it.
    src_world
    run_fn "ROOT='$T/root'; MUTATION_MARK='$bad_mark'; capture out 'running the helper' run_helper historical helper:fixture-load out; echo SURVIVED"
    [[ "$RC" != 0 && "$OUT" != *SURVIVED* && "$ERR" == *'could not be written, so nothing was begun'* ]] || bad "capture did not carry the failure out: $RC ${ERR:0:300}"
    # A files action that writes, and one that only reads.
    for code in "run_files seed" "run_files check empty" "run_files --create check empty"; do
        restore_files_world; established_files_volume
        run_fn "ROOT='$T/root'; MUTATION_MARK='$bad_mark'; capture out 'a files action' $code; echo SURVIVED"
        [[ "$RC" != 0 && "$OUT" != *SURVIVED* ]] || bad "'$code' went on although its change record could not be written: $RC ${ERR:0:200}"
        ! grep -q '^create ' "$W/calls" || bad "'$code' asked Docker for a container although its change record could not be written"
        ! grep -q '^volume create ' "$W/calls" || bad "'$code' created a volume although its change record could not be written"
        [[ ! -e "$W/runs" ]] || bad "'$code': the files helper ran: $(tr '\n' ' ' <"$W/runs")"
    done
    # A volume that is to be created is recorded first too.
    restore_files_world novolume
    run_fn "ROOT='$T/root'; MUTATION_MARK='$bad_mark'; files_volume_ready '$TOKEN' 1; echo SURVIVED"
    [[ "$RC" != 0 && "$OUT" != *SURVIVED* && "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad "a files volume was created although the change record could not be written: $RC ${ERR:0:200}"
}

# F3. The approved commands that are read-only, for the purpose of what a failed stage may say, are exactly six. Any other approved name, and anything that is not
# an approved name, is classified as one that may change something. The test knows every approved name (read from runner_command), so a new one, or a writing
# helper added to the six, cannot pass without this list being changed on purpose.
t_g23_the_read_only_helpers_are_exactly_these() {
    local -a read_only=(helper:db-ping helper:snapshot helper:snapshot-all migrate:status about release:show)
    local -a approved=() other=()
    local name got
    mapfile -t approved < <(sed -n '/^runner_command() {/,/^}/p' "$SRC/restore-rehearsal.sh" | grep -E '^        [a-z:| -]+\) RUN_CMD=' | sed 's/).*//' | tr '|' '\n' | tr -d ' ')
    ((${#approved[@]} == 14)) || bad "runner_command approves ${#approved[@]} names, not the 14 this test knows: ${approved[*]}"
    for name in "${read_only[@]}"; do
        [[ " ${approved[*]} " == *" $name "* ]] || bad "$name is not an approved command any more, so it cannot be a read-only one"
    done
    for name in "${approved[@]}" bogus '' 'helper:snapshot ' 'helper:' 'about:x' 'HELPER:SNAPSHOT'; do
        run_fn "helper_is_read_only '$name' && echo read-only || echo may-change"
        got="$OUT"
        if [[ " ${read_only[*]} " == *" $name "* && -n "$name" ]]; then
            [[ "$got" == read-only ]] || bad "'$name' is one of the six read-only commands but is classified as one that may change something"
        else
            [[ "$got" == may-change ]] || bad "'$name' is classified as read-only: a command that is not one of the six must be one that may change something"
            other+=("$name")
        fi
    done
    # The writing helpers in particular, by name.
    for name in helper:fixture-load helper:post-upgrade-mark helper:half-migrate helper:revoke helper:sign-in migrate relationships:check resources:assets:prune; do
        [[ " ${other[*]} " == *" $name "* ]] || bad "$name is not classified as a command that may change something"
    done
    run_fn 'declare -f helper_is_read_only | grep -c "return 0"'
    [[ "$OUT" == 1 ]] || bad 'helper_is_read_only has more than one place that says a command is read-only'
}

# --- WU2: the runner's read-only access to the Resources volume, for one operation, and the post-create verification of every runner ----------------------------

# A rehearsal with the prepared source and an established files volume (the state seed-files or backup-files leaves).
prune_world() {
    src_world
    add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
    established_files_volume
}
# Nothing was created, started or removed: a refusal before the create is a refusal before Docker is asked for anything of the runner.
no_runner_created() { # DESC
    ! grep -qE '^(create|volume create|volume rm|rm|start) ' "$W/calls" || bad "$1: a container or volume was created, started or removed: $(grep -E '^(create|volume create|volume rm|rm|start) ' "$W/calls" | head -n 2 | cut -c1-120 | tr '\n' ';')"
    [[ ! -e "$W/started" ]] || bad "$1: a container was started"
}
# The representative output of the dry-run (PruneAssetsCommand's own wording): kept, recent, ignored, orphaned, failed and missing, and the warning that goes with it.
PRUNE_OUTPUT='Resources file store: 5 file(s) found (dry run: nothing is removed).
  referenced by an asset, kept: 2
  unreferenced but younger than 24 hour(s), left alone: 1
    01jc0000000000000000000003
  not a Resources storage key, left alone: 1
    notes.txt
  orphaned, would be removed: 1
    01jb0000000000000000000002
  orphaned, could NOT be removed (tried again next run): 0
Asset rows whose file is missing from the store (asset_unavailable): 1
    01jd0000000000000000000004
Restore those files from the backup taken with the database; they cannot be served until then.'

t_g24_the_prune_dry_run_reads_the_files_volume_read_only() {
    local create flag
    prune_world
    printf '%s\n' "$PRUNE_OUTPUT" >"$W/out_prune"
    run_script -- run current resources:assets:prune
    expect_rc 'the prune dry-run' 0
    [[ "$OUT" == "$PRUNE_OUTPUT" ]] || bad "the dry-run's output was not relayed exactly: ${OUT:0:200}"
    only_database_left 'the prune dry-run'
    create="$(grep -E '^create ' "$W/calls" || true)"
    [[ "$(grep -c . <<<"$create")" == 1 ]] || bad "the prune dry-run: expected exactly one create, got: $create"
    # Exactly two volumes, both read-only, the files volume at a path of its own; nothing from the host.
    expect_has 'the files volume, read-only' "$create" "--mount type=volume,source=$NAME_FV,target=/rehearsal/files,readonly "
    expect_has 'the source volume, read-only' "$create" "--mount type=volume,source=$NAME_SRC,target=/var/www/html,readonly "
    [[ "$(grep -o 'type=volume' <<<"$create" | wc -l)" == 2 ]] || bad 'the prune dry-run runner has other than the two volumes'
    expect_lacks 'a host mount' "$create" 'type=bind'
    expect_lacks 'a volume option' "$create" ' -v '
    expect_has 'the command and its link setup' "$(<"$W/calls")" ' sh /rehearsal/files php artisan resources:assets:prune --dry-run' # (the setup text has newlines: the log has more than one line for the create)
    for flag in '--read-only' '--cap-drop ALL' '--security-opt no-new-privileges' '--user 33:33' '--pids-limit 256' '--memory 1g' '--pull never' "--network $NAME_N" \
        '--workdir /var/www/html/current' '-e DB_PASSWORD '; do
        expect_has 'the prune dry-run runner is as isolated as any' "$create" "$flag"
    done
    ! grep -qE '^volume (create|rm) ' "$W/calls" || bad 'the prune dry-run created or removed a volume'
    [[ "$(cat "$W/started")" == "/$NAME_R" ]] || bad "the runner was not started exactly once: $(cat "$W/started" 2>/dev/null)"
    no_secret_anywhere 'the prune dry-run'
    # The command's own status and output are the answer, whatever they are: a failure is relayed, and so is a store with no file.
    prune_world
    printf '1' >"$W/fail_prune"
    run_script -- run current resources:assets:prune
    expect_rc 'a failing dry-run' 1
    only_database_left 'a failing dry-run'
    prune_world
    printf 'Resources file store: 0 file(s) found (dry run: nothing is removed).\n  referenced by an asset, kept: 0\n' >"$W/out_prune"
    run_script -- run current resources:assets:prune
    expect_rc 'a store with no file' 0
    expect_has 'a store with no file' "$OUT" '0 file(s) found'
    # A daemon that made the files volume writable, or left it out, or put it elsewhere, is refused like any other deviation (the recheck of create_verified).
    for flag in "volume:$NAME_FV:/rehearsal/files:true,volume:$NAME_SRC:/var/www/html:false," "volume:$NAME_SRC:/var/www/html:false," "volume:$NAME_FV:/var/www/html:false,volume:$NAME_SRC:/var/www/html:false," \
        "volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:true,"; do
        prune_world
        printf 'mounts %s\n' "$flag" >"$W/create_override"
        run_script -- run current resources:assets:prune
        expect_refused "mounts $flag" 'its mounts are'
        [[ ! -e "$W/started" ]] || bad "mounts $flag: the runner was started"
    done
}

# runner_may_read_files is a decision about the vector the runner is about to run, not about a name. The table is the complete argument for "exactly one".
t_g24_the_files_volume_is_only_for_the_prune_dry_run() {
    local row ok want many
    may_read() { # WORKDIR CMD...: prints yes or no
        run_fn "runner_may_read_files $(printf '%q ' "$@")"
        if [[ "$RC" == 0 ]]; then echo yes; else echo no; fi
    }
    [[ "$(may_read current php artisan resources:assets:prune --dry-run)" == yes ]] || bad 'the prune dry-run in the current tree was not allowed the files volume'
    for row in 'historical php artisan resources:assets:prune --dry-run' 'harness php artisan resources:assets:prune --dry-run' 'main php artisan resources:assets:prune --dry-run' \
        'current php artisan resources:assets:prune' 'current php artisan resources:assets:prune --dry-run --force' 'current php artisan resources:assets:prune --dry-run extra' \
        'current php artisan resources:assets:prune --dry-run=1' 'current php artisan --dry-run resources:assets:prune' 'current php artisan about' \
        'current php artisan schedule:run' 'current php artisan res:ass:pru --dry-run' 'current php artisan resources:assets:prun --dry-run' \
        'current /usr/bin/php artisan resources:assets:prune --dry-run' 'current php /var/www/html/harness/rehearse.php snapshot' 'current php artisan migrate --force --no-interaction' \
        'current php artisan resources:assets:prune dry-run' 'current php artisan' 'current php' 'current'; do
        # shellcheck disable=SC2086 # the words are the point
        [[ "$(may_read $row)" == no ]] || bad "the files volume was allowed for: $row"
    done
    many=(current php artisan 'resources:assets:prune --dry-run')
    [[ "$(may_read "${many[@]}")" == no ]] || bad 'a single word holding the whole operation was allowed the files volume'
    many=(current php artisan resources:assets:prune ' --dry-run')
    [[ "$(may_read "${many[@]}")" == no ]] || bad 'an option with a leading space was allowed the files volume'
    # And from the outside: no other approved command, in either tree, has the volume, or so much as asks Docker about it.
    for want in 'current about' 'current migrate:status' 'current helper:db-ping' 'current migrate' 'historical about'; do
        prune_world
        # shellcheck disable=SC2086
        run_script -- run $want
        expect_rc "run $want" 0
        ok="$(grep -E '^create ' "$W/calls" || true)"
        expect_lacks "run $want" "$ok" "$NAME_FV"
        expect_lacks "run $want" "$ok" '/rehearsal/files'
        ! grep -q "$NAME_FV" "$W/calls" || bad "run $want: the files volume was looked at for a command that does not read it"
    done
}

t_g24_the_prune_runner_needs_an_established_valid_files_volume() {
    local fp case_name field value needle
    fp="$(printf 'flc-run-v1|%s' "$OTHER_TOKEN" | sha256sum | cut -c1-16)"
    # No files volume has ever been established, and none exists: this operation does not make one.
    if want_case G24-files-record; then
        src_world
        run_script -- run current resources:assets:prune
        expect_refused 'no files volume at all' 'no files volume has been established'
        no_runner_created 'no files volume at all'
        # A volume of the right name with no record of it is not adopted either.
        src_world; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
        run_script -- run current resources:assets:prune
        expect_refused 'a volume with no record' 'no files volume has been established'
        no_runner_created 'a volume with no record'
        [[ ! -e "$T/root/files.volume" ]] || bad 'a record of the files volume was written by a runner'
    fi
    if want_case G24-files-ready; then
        # Established and then gone: never replaced by an empty one, never read as an empty store, never recreated.
        src_world; established_files_volume
        run_script -- run current resources:assets:prune
        expect_refused 'an established volume that is gone' 'is now missing'
        no_runner_created 'an established volume that is gone'
        # Not the rehearsal's volume, in each of the ways it can differ.
        for case_name in 'token|l_token|'"$OTHER_TOKEN"'|run token differs' 'role|l_role|source|labels are not' 'label|l_ns|other|labels are not' 'options|options|1|plain local volume' \
            'driver|driver|nfs|plain local volume' 'name|name|someone-else|its name is'; do
            IFS='|' read -r _ field value needle <<<"$case_name"
            prune_world
            printf '%s' "$value" >"$W/v/$NAME_FV/$field"
            run_script -- run current resources:assets:prune
            expect_refused "a files volume whose $field differs" "$needle"
            no_runner_created "a files volume whose $field differs"
        done
        # Used by a container that is not ours.
        prune_world
        mkdir -p "$W/c/$FOREIGN"
        put "$W/c/$FOREIGN" id "$FOREIGN"; put "$W/c/$FOREIGN" name /intruder; put "$W/c/$FOREIGN" mounts "volume:$NAME_FV:/data,"
        run_script -- run current resources:assets:prune
        expect_refused 'a files volume with a foreign user' 'is not ours'
        no_runner_created 'a files volume with a foreign user'
        # A record that is not this run's.
        prune_world
        printf 'files-volume-v1\n%s' "$fp" >"$T/root/files.volume"
        run_script -- run current resources:assets:prune
        expect_refused 'a record of another run' "the record of the files volume in the state directory is not this run's"
        no_runner_created 'a record of another run'
    fi
}

t_g24_every_runner_rechecks_the_source_volume_after_create() {
    local cmd
    # The source volume is replaced by an unlabelled one while the runner is created: the recheck refuses, the runner is not started, and only the container just
    # created is removed. The volume that is there now is evidence.
    for cmd in 'current about' 'historical migrate:status'; do
        src_world
        replace_volume_hook "$NAME_SRC"
        # shellcheck disable=SC2086 # the words are the point
        run_script -- run $cmd
        expect_refused "a source volume replaced while the runner for '$cmd' was created" "after the runner container was created, the source volume is no longer the rehearsal's: its labels are not this script's"
        expect_has "'$cmd'" "$ERR" 'was removed and not started'
        [[ ! -e "$W/started" ]] || bad "'$cmd': the runner was started although its source volume was replaced"
        only_database_left "'$cmd'"
        [[ -d "$W/v/$NAME_SRC" ]] || bad "'$cmd': the replacement volume was removed: it is evidence and is never deleted"
        ! grep -q '^volume rm ' "$W/calls" || bad "'$cmd': a volume was removed"
        [[ "$(grep -c '^create ' "$W/calls")" == 1 ]] || bad "'$cmd': more than the one runner was created"
    done
    # A container that is not ours that began using the source volume in the window.
    src_world
    printf 'mkdir -p "%s/c/%s"; cd "%s/c/%s"; printf %%s %s >id; printf /intruder >name; printf %%s "volume:%s:/data," >mounts\n' "$W" "$FOREIGN" "$W" "$FOREIGN" "$FOREIGN" "$NAME_SRC" >"$W/hook_create"
    run_script -- run current about
    expect_refused 'a foreign container that began using the source volume' "the source volume is no longer the rehearsal's: container"
    [[ ! -e "$W/started" ]] || bad 'the runner was started although a foreign container used its source volume'
    [[ -d "$W/c/$FOREIGN" ]] || bad 'the foreign container was removed'
    # The runner is only removed if it is still what was created; one that changed is left where it is, and the refusal says so.
    if want_case G24-r-recheck-refuses; then
        src_world
        replace_volume_hook "$NAME_SRC"
        # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
        printf 'ok=1; for d in "%s"/c/*; do if [[ "$(cat "$d/name" 2>/dev/null)" == "/%s" ]]; then printf %%s someone-else >"$d/l_role"; ok=0; fi; done; exit $ok\n' "$W" "$NAME_R" >"$W/hook_inspect_c"
        run_script -- run current about
        expect_refused 'a runner that changed during the create' 'is no longer what this script created, so it was left where it is'
        ! grep -q '^rm ' "$W/calls" || bad 'a container was removed although it could not be proven to be the one created'
        [[ ! -e "$W/started" ]] || bad 'a runner that changed was started'
        # A removal that fails says so.
        src_world
        replace_volume_hook "$NAME_SRC"
        inject_failure 'rm *'
        run_script -- run current about
        expect_refused 'a created runner that cannot be removed' 'could not be removed and was not started'
    fi
    # Nothing was weakened: a source volume that is wrong BEFORE the create is refused before it, as before.
    src_world
    printf 'other' >"$W/v/$NAME_SRC/l_ns"
    run_script -- run current about
    expect_refused 'a source volume that is wrong before the create' 'the source volume is not the rehearsal'"'"'s'
    [[ "$(grep -c '^create ' "$W/calls" || true)" == 0 ]] || bad 'a runner was created although the source volume was already wrong'
}

t_g24_the_prune_runner_rechecks_the_files_volume_too() {
    # The files volume is replaced while the runner that is to read it is created (as Docker would make a missing one: empty, unlabelled).
    prune_world
    replace_volume_hook "$NAME_FV"
    run_script -- run current resources:assets:prune
    expect_refused 'a files volume replaced while the runner was created' "after the runner container was created, the files volume is no longer the rehearsal's: its labels are not this script's"
    expect_has 'the runner is said to be removed, not started' "$ERR" 'was removed and not started'
    [[ ! -e "$W/started" ]] || bad 'the runner was started although the files volume it was to read had been replaced'
    only_database_left 'a replaced files volume'
    [[ -d "$W/v/$NAME_FV" ]] || bad 'the replacement files volume was removed: it is evidence and is never deleted'
    ! grep -q '^volume rm ' "$W/calls" || bad 'a volume was removed'
    # A container that is not ours that began using it.
    prune_world
    printf 'mkdir -p "%s/c/%s"; cd "%s/c/%s"; printf %%s %s >id; printf /intruder >name; printf %%s "volume:%s:/data," >mounts\n' "$W" "$FOREIGN" "$W" "$FOREIGN" "$FOREIGN" "$NAME_FV" >"$W/hook_create"
    run_script -- run current resources:assets:prune
    expect_refused 'a foreign container that began using the files volume' "the files volume is no longer the rehearsal's: container"
    [[ ! -e "$W/started" ]] || bad 'the runner was started although a foreign container used the files volume'
    # And the source volume, for this runner as for every other.
    prune_world
    replace_volume_hook "$NAME_SRC"
    run_script -- run current resources:assets:prune
    expect_refused 'a source volume replaced while the prune runner was created' "the source volume is no longer the rehearsal's"
    [[ ! -e "$W/started" ]] || bad 'the prune runner was started although the source volume was replaced'
}

# The create of a runner is a recorded change: Docker may make a missing volume when it sets up the mounts, so an armed stage says "may be partly changed" from the
# moment the create is attempted, whatever the command is, and says it changed nothing only for a refusal that came before. (cmd_run and cmd_probe arm nothing: a
# stage is armed by the stages that call run_in_runner, so the function is run here under an armed stage of its own.)
t_g24_a_runner_create_is_a_recorded_change() {
    local seen
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    local code='preflight; arm_stage probe-stage on_stage_exit; run_in_runner "$ROOT" current php artisan about'
    mkdir -p "$T/marker-tmp"
    prune_world
    # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
    printf 'for f in "%s"/*.changed; do [[ -e "$f" ]] && echo "$f"; done >"%s/marker_at_create"\n' "$T/marker-tmp" "$W" >"$W/hook_create"
    run_fn "$code" TMPDIR="$T/marker-tmp"
    expect_rc 'a runner under an armed stage' 0
    seen="$(cat "$W/marker_at_create" 2>/dev/null || true)"
    [[ "$seen" == "$T/marker-tmp/"*.changed ]] || bad "the runner was created before the change record was made (the fake daemon saw: ${seen:0:200})"
    # The create refused by Docker: cautious. A refusal before the create: it changed nothing.
    prune_world
    : >"$W/create_fail"
    run_fn "$code" TMPDIR="$T/marker-tmp"
    [[ "$RC" != 0 ]] || bad 'a runner whose create Docker refused did not fail'
    trailer_cautious 'a create that Docker refused'
    prune_world
    printf other >"$W/v/$NAME_SRC/l_ns"
    snapshot_state
    run_fn "$code" TMPDIR="$T/marker-tmp"
    expect_rc 'a refusal before the create' 3
    trailer_unchanged 'a refusal before the runner was created'
    [[ -z "$(find "$T/marker-tmp" -mindepth 1)" ]] || bad 'a change record was left behind'
}

# run_helper records a change for every command that is not one of the six read-only ones, BEFORE it asks for the runner. Since every runner's create is recorded as
# well (above), this is the earlier of two records, and is tested on its own: run_in_runner is replaced by a stand-in that only says whether the record existed.
t_g24_run_helper_records_a_writing_helper_before_the_runner() {
    local name got
    for name in helper:fixture-load helper:post-upgrade-mark helper:half-migrate helper:revoke helper:sign-in migrate relationships:check resources:assets:prune \
        helper:db-ping helper:snapshot helper:snapshot-all migrate:status about release:show; do
        mkdir -p "$T/marker-tmp"
        run_fn "ROOT=/stand-in; run_in_runner() { if [[ -e \"\$MUTATION_MARK.changed\" ]]; then echo recorded; else echo unrecorded; fi; }; arm_stage stand-in on_stage_exit; run_helper current $name got; echo \"\$got\"" TMPDIR="$T/marker-tmp"
        expect_rc "run_helper $name" 0
        got="$OUT"
        if [[ "$name" == helper:db-ping || "$name" == helper:snapshot || "$name" == helper:snapshot-all || "$name" == migrate:status || "$name" == about || "$name" == release:show ]]; then
            [[ "$got" == unrecorded ]] || bad "$name is read-only, and a change was recorded before its runner: $got"
        else
            [[ "$got" == recorded ]] || bad "$name may change something, and no change was recorded before its runner: $got"
        fi
    done
}

# The directory the runner links to is the one the files helper (and the archive reader behind it) keeps the Resources files in: the volume is the application's
# `storage/app/private`, mounted at FILES_MOUNT, and the files are `<volume>/<STORE>/<storage key>`. That layout is described once, by ResourceFileArchive::STORE and the
# files helper's root; the script's FILES_STORE_DIR, FILES_MOUNT and the runner's setup must agree with them, and this is where they are held to it (a disagreement
# once made a populated volume look empty). The mapping test below takes its expected directory from the script's constant, so it cannot drift from this.
t_g24_the_runner_mapping_agrees_with_the_files_helper_layout() {
    local archive="$ROOT/apps/platform/tests/Support/ResourceFileArchive.php" helper="$SRC/php/files.php" php_store php_root roots calls store mount
    [[ -r "$archive" && -r "$helper" ]] || { bad "the files helper or ResourceFileArchive cannot be read ($archive, $helper)"; return 0; }
    php_store="$(sed -n "s/^[[:space:]]*public const string STORE = '\([^']*\)';.*/\1/p" "$archive")"
    php_root="$(sed -n "s/^const FILES_ROOT = '\([^']*\)';.*/\1/p" "$helper")"
    [[ -n "$php_store" && -n "$php_root" ]] || { bad "ResourceFileArchive::STORE ('$php_store') or the helper's FILES_ROOT ('$php_root') was not found"; return 0; }
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn 'printf "%s|%s" "$FILES_STORE_DIR" "$FILES_MOUNT"'
    store="${OUT%%|*}" mount="${OUT#*|}"
    [[ "$store" == "$php_store" ]] || bad "the script's FILES_STORE_DIR is '$store' and ResourceFileArchive::STORE is '$php_store'"
    [[ "$mount" == "$php_root" ]] || bad "the script mounts the files volume at '$mount' and the files helper's root is '$php_root'"
    # The helper works on its root only (every call that takes a root is given FILES_ROOT, none another path), and the script's setup looks where STORE is.
    roots="$(grep -cE 'ResourceFileArchive::(inventory|seed|createArchive|setAside|extract)\(' "$helper" || true)"
    calls="$(grep -cE 'ResourceFileArchive::(inventory|seed|createArchive|setAside|extract)\(([a-zA-Z]+\(\), )?FILES_ROOT' "$helper" || true)"
    [[ "$roots" -ge 5 && "$roots" == "$calls" ]] || bad "the files helper does not give FILES_ROOT as the root of every archive call ($calls of $roots)"
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn 'printf "%s" "$RUNNER_FILES_SETUP"'
    # shellcheck disable=SC2016 # the text looked for is the setup's own, with its `$store` in it
    [[ "$OUT" == *'dir="$store/'"$php_store"'"'* ]] || bad "the runner's setup does not look for the directory $php_store in the volume"
    # shellcheck disable=SC2016 # as above
    [[ "$OUT" != *'$store/storage'* ]] || bad "the runner's setup looks for the directory under the volume's storage/, which is not in it"
    # The container's link name is the application's own path of the disk (config/filesystems.php), which is what makes the link the application's Resources disk.
    grep -qE "'root' => storage_path\('app/private/$php_store'\)" "$ROOT/apps/platform/config/filesystems.php" || bad "the application's resources disk is not rooted at storage/app/private/$php_store"
    # shellcheck disable=SC2016 # as above
    [[ "$OUT" == *'ln -s "$dir" storage/app/private/'"$php_store"* ]] || bad "the runner's setup does not link the application's storage/app/private/$php_store"
}

# The link the prune dry-run's runner makes is made by RUNNER_FILES_SETUP, a shell text that runs INSIDE the runner. It is run here, for real, by the host's `sh`,
# against a directory standing in for the files volume's mount (laid out as the files helper lays it out: `<volume>/resources/<key>`) and another for the runner's
# working directory, with a stand-in for the command: what it makes, what it refuses, what it leaves alone. (That Docker mounts the volume where this says, and that
# the application's disk follows the link, only a real run can show.)
t_g24_the_files_mapping_is_made_inside_the_runner() {
    local sb="$T/mapping" setup dir before after kind out want store stamp
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn 'printf "%s" "$RUNNER_FILES_SETUP"'
    setup="$OUT"
    [[ -n "$setup" ]] || { bad 'RUNNER_FILES_SETUP is empty'; return 0; }
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn 'printf "%s" "$FILES_STORE_DIR"'
    store="$OUT"
    [[ -n "$store" ]] || { bad 'FILES_STORE_DIR is empty'; return 0; }
    PRE=''
    mapped() { # runs the setup with the sandbox's mount and prints what the stand-in command saw; sets RC and out
        rm -rf "$sb/work"; mkdir -p "$sb/work/storage"
        if [[ -n "$PRE" ]]; then mkdir -p "$sb/work/storage/app/private/resources"; fi
        RC=0
        out="$(cd "$sb/work" && sh -c "$setup" sh "$sb/files" sh -c '(cd storage/app/private/resources 2>/dev/null && pwd -P); ls storage/app/private/resources 2>/dev/null | tr "\n" " "; echo "args=$#:$1:$2"; exit 7' x 'a b' c 2>&1)" || RC=$?
    }
    volume_listing() { (cd "$sb/files" && find . -exec ls -ldn --time-style=+ {} + | LC_ALL=C sort | tr '\n' ' '); }
    dir="$sb/files/$store"
    # Files in the volume's Resources directory: the application's path is the volume's directory, which is listed, and the command's status and arguments come through.
    rm -rf "$sb"; mkdir -p "$dir"; : >"$dir/01jb0000000000000000000001"; : >"$dir/01jb0000000000000000000002"
    before="$(volume_listing)"
    mapped
    [[ "$RC" == 7 ]] || bad "the command's own status did not come through: $RC ($out)"
    [[ "$out" == *"$dir"* && "$out" == *'01jb0000000000000000000001 01jb0000000000000000000002'* && "$out" == *'args=2:a b:c'* ]] || bad "the application's path was not the volume's directory, or the arguments changed: $out"
    [[ "$(readlink "$sb/work/storage/app/private/resources")" == "$dir" ]] || bad 'the link is not exactly to the Resources directory in the volume'
    after="$(volume_listing)"
    [[ "$before" == "$after" ]] || bad 'the setup changed the files volume'
    # A Resources directory with no file in it.
    rm -f "$dir/01jb0000000000000000000001" "$dir/01jb0000000000000000000002"
    before="$(volume_listing)"
    mapped
    [[ "$out" == *"$dir"* && "$RC" == 7 && "$out" == *'args=2:a b:c'* ]] || bad "an empty Resources directory was not mapped: $out"
    [[ "$(readlink "$sb/work/storage/app/private/resources")" == "$dir" && "$before" == "$(volume_listing)" ]] || bad 'an empty Resources directory was not linked, or the setup changed the volume'
    # No Resources directory at all (the zero-file state): no link, the command runs, and finds what a new installation finds.
    rm -rf "$sb/files"; mkdir -p "$sb/files"
    before="$(volume_listing)"
    mapped
    [[ ! -e "$sb/work/storage/app/private/resources" && ! -L "$sb/work/storage/app/private/resources" && "$RC" == 7 && "$out" == *'args=2:a b:c'* ]] || bad "a volume with no Resources directory was not left as the empty store: rc=$RC $out"
    [[ "$before" == "$(volume_listing)" ]] || bad 'the setup changed a volume with no Resources directory'
    # A volume whose Resources directory was set aside (`resources.before-restore-<stamp>`) has no active one: that directory is not linked, and its files are not seen.
    stamp=20261118T101500Z
    rm -rf "$sb/files"; mkdir -p "$sb/files/$store.before-restore-$stamp"; : >"$sb/files/$store.before-restore-$stamp/01jb0000000000000000000009"
    before="$(volume_listing)"
    mapped
    [[ ! -e "$sb/work/storage/app/private/resources" && ! -L "$sb/work/storage/app/private/resources" && "$RC" == 7 && "$out" == *'args=2:a b:c'* ]] || bad "a volume holding only a set-aside Resources directory was given an active one: rc=$RC $out"
    [[ "$out" != *01jb0000000000000000000009* && "$before" == "$(volume_listing)" ]] || bad 'the set-aside directory was exposed, or the setup changed the volume'
    # Anything else that is there is refused, status 70, and the command is not run: a link (to somewhere in the container or in the volume), a dangling link, a file,
    # and a directory of that name that is already in the runner's own storage. And nothing is changed in the volume.
    for kind in link dangling file preexisting; do
        rm -rf "$sb/files"; mkdir -p "$sb/files" "$sb/elsewhere"
        PRE=''
        case "$kind" in
            link) ln -s "$sb/elsewhere" "$dir" ;;
            dangling) ln -s "$sb/nowhere" "$dir" ;;
            file) : >"$dir" ;;
            preexisting) mkdir -p "$dir"; PRE=1 ;;
        esac
        before="$(volume_listing)"
        mapped
        [[ "$RC" == 70 ]] || bad "a Resources directory that is a $kind was not refused with status 70: rc=$RC ($out)"
        [[ "$out" != *'args='* ]] || bad "the command was run although the Resources directory was a $kind"
        want="is not a plain directory"
        [[ "$kind" != preexisting ]] || want="could not be made visible"
        [[ "$out" == *"$want"* && "$out" == *'nothing was run'* ]] || bad "a Resources directory that is a $kind was refused without saying why ('$want'): $out"
        [[ "$before" == "$(volume_listing)" ]] || bad "the setup changed the volume when the Resources directory was a $kind"
    done
    PRE=''
}

# Cleanup knows the runner's two layouts and no third: the ordinary one (the read-only source volume) and the prune dry-run's (that and the files volume, read-only, at the
# path WU2 gives it). Each is removed when it is the rehearsal's, and what is not exactly one of them is kept, with the cleanup reporting itself incomplete.
t_g24_cleanup_recognises_both_runner_layouts() {
    local plain="volume:$NAME_SRC:/var/www/html:false," prune="volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:false," second row
    second="$(printf '3%.0s' {1..64})"
    # Each layout on its own: listed by --dry-run (changing nothing), then removed, with the volumes it used.
    for row in "$plain" "$prune"; do
        prune_world
        add_ephemeral "$RID" runner "mounts=$row"
        put "$W/n/$NID" endpoints "$CID,$RID,"
        run_script -- cleanup --dry-run
        expect_rc "cleanup --dry-run with the runner mounting $row" 0
        expect_has "the runner mounting $row" "$OUT" "would remove container ${RID:0:12} ($NAME_R)"
        expect_has "the volumes beside the runner mounting $row" "$OUT" "would remove volume $NAME_FV"
        expect_has "the volumes beside the runner mounting $row" "$OUT" "would remove volume $NAME_SRC"
        [[ -d "$W/c/$RID" && -d "$W/v/$NAME_FV" && -d "$W/v/$NAME_SRC" ]] || bad "a dry run removed something ($row)"
        [[ -z "$(mutating_calls)" ]] || bad "a dry run called: $(mutating_calls)"
        run_script -- cleanup
        expect_rc "cleanup with the runner mounting $row" 0
        [[ -z "$(ls -A "$W/c")$(ls -A "$W/n")$(ls -A "$W/v")" ]] || bad "cleanup left something behind, with the runner mounting $row: $(find "$W/c" "$W/n" "$W/v" -mindepth 1 | tr '\n' ' ')"
        [[ ! -e "$T/root" ]] || bad "cleanup left the state directory, with the runner mounting $row"
    done
    # Both layouts together (two runners, as an interrupted run and its successor could leave).
    prune_world
    add_ephemeral "$RID" runner "mounts=$plain"
    add_ephemeral "$second" runner "mounts=$prune"
    put "$W/n/$NID" endpoints "$CID,$RID,$second,"
    run_script -- cleanup --dry-run
    expect_rc 'a dry run with both layouts' 0
    expect_has 'the ordinary runner' "$OUT" "would remove container ${RID:0:12} ($NAME_R)"
    expect_has 'the prune runner' "$OUT" "would remove container ${second:0:12} ($NAME_R)"
    run_script -- cleanup
    expect_rc 'cleanup with both layouts' 0
    [[ -z "$(ls -A "$W/c")$(ls -A "$W/n")$(ls -A "$W/v")" && ! -e "$T/root" ]] || bad 'cleanup of both layouts left something behind'
    # Not exactly one of the two layouts: kept, whatever else about the container is right. The files volume's mount writable; at another path; a third mount; the
    # files mount alone; the source volume writable beside the files volume; the files volume under the source's path; the other layout's mounts with a bind mount.
    for row in "volume:$NAME_FV:/rehearsal/files:true,volume:$NAME_SRC:/var/www/html:false," \
        "volume:$NAME_FV:/data:false,volume:$NAME_SRC:/var/www/html:false," \
        "volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:false,volume:elsewhere:/x:false," \
        "volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:false,bind::/var/run/docker.sock:false," \
        "volume:$NAME_FV:/rehearsal/files:false," \
        "volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:true," \
        "volume:$NAME_FV:/var/www/html:false,volume:$NAME_SRC:/rehearsal/files:false," \
        "volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_FV:/var/www/html:false," \
        "volume:$NAME_SRC:/var/www/html:false,volume:$NAME_SRC:/rehearsal/files:false,"; do
        prune_world
        add_ephemeral "$RID" runner "mounts=$row"
        put "$W/n/$NID" endpoints "$CID,$RID,"
        run_script -- cleanup --dry-run
        expect_lacks "a runner mounting $row" "$OUT" "would remove container ${RID:0:12}"
        expect_has "a runner mounting $row" "$OUT" "keep    container ${RID:0:12}: "
        run_script -- cleanup
        expect_rc "cleanup with a runner mounting $row" 1
        [[ -d "$W/c/$RID" ]] || bad "a runner mounting $row was removed"
        [[ -f "$T/root/compose.env" ]] || bad "the state was discarded although a runner mounting $row remains"
        expect_has "the way on" "$ERR" 'cleanup is NOT complete'
        ! grep -qE "^(stop|rm) $RID" "$W/calls" || bad "stop or rm was called on a runner mounting $row"
    done
    # A runner that is not the rehearsal's in some other way is kept in either layout (another run's token, a writable root filesystem, a published port, or user 0).
    for row in "$plain" "$prune"; do
        for kind in 'l_token=other' 'readonly_root=false' 'ports=1' 'user=0:0'; do
            prune_world
            add_ephemeral "$RID" runner "mounts=$row" "$kind"
            put "$W/n/$NID" endpoints "$CID,$RID,"
            run_script -- cleanup
            expect_rc "cleanup with a runner ($kind) mounting $row" 1
            [[ -d "$W/c/$RID" ]] || bad "a runner ($kind) mounting $row was removed"
        done
    done
    # The state a cleanup that could not finish keeps is what a later cleanup finishes from: the runner (prune layout) is removed once it is what it should be.
    prune_world
    add_ephemeral "$RID" runner "mounts=volume:$NAME_FV:/rehearsal/files:true,volume:$NAME_SRC:/var/www/html:false,"
    put "$W/n/$NID" endpoints "$CID,$RID,"
    run_script -- cleanup
    expect_rc 'cleanup with a writable files mount' 1
    put "$W/c/$RID" mounts "$prune"
    run_script -- cleanup
    expect_rc 'cleanup once the runner is the rehearsal prune runner' 0
    [[ -z "$(ls -A "$W/c")$(ls -A "$W/n")$(ls -A "$W/v")" && ! -e "$T/root" ]] || bad 'the retry did not finish the cleanup'
}

# A runner that stops being the rehearsal's between its classification and its removal is not removed: the check just before the removal is the same strict one, so a prune
# runner whose files mount became writable (or a plain one that gained the files volume) is kept.
t_g24_cleanup_rechecks_the_runner_layout_before_removing_it() {
    local row change need
    # (the hook runs after each full identity inspect and waits, by exiting non-zero, until the classification is over: after the first check for the ordinary layout,
    # after the second for the prune layout, whose first check is the ordinary one that it does not satisfy)
    for row in "1|volume:$NAME_SRC:/var/www/html:false,|volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:false,volume:elsewhere:/x:false," \
        "2|volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:false,|volume:$NAME_FV:/rehearsal/files:true,volume:$NAME_SRC:/var/www/html:false," \
        "2|volume:$NAME_FV:/rehearsal/files:false,volume:$NAME_SRC:/var/www/html:false,|volume:$NAME_FV:/data:false,volume:$NAME_SRC:/var/www/html:false,"; do
        need="${row%%|*}" row="${row#*|}"
        change="${row#*|}" row="${row%%|*}"
        prune_world
        add_ephemeral "$RID" runner "mounts=$row"
        put "$W/n/$NID" endpoints "$CID,$RID,"
        # shellcheck disable=SC2016 # the script written here is run by the fake daemon, which expands it
        printf 'n=$(( $(cat "%s/hook_count" 2>/dev/null || echo 0) + 1 )); echo $n >"%s/hook_count"; ((n >= %s)) || exit 1; printf %%s "%s" >"%s/c/%s/mounts"\n' "$W" "$W" "$need" "$change" "$W" "$RID" >"$W/hook_inspect_c"
        run_script -- cleanup
        expect_rc "a runner that changed from $row to $change after it was classified" 1
        [[ -d "$W/c/$RID" ]] || bad "a runner that changed to $change after it was classified was removed"
        expect_has 'the change' "$OUT" 'changed since it was checked'
        ! grep -qE "^(stop|rm) $RID" "$W/calls" || bad "stop or rm was called on a runner that no longer verified ($change)"
        [[ -f "$T/root/compose.env" ]] || bad 'the state was discarded although a runner remains'
    done
}

# `run historical resources:assets:prune` would not be given the files volume: it would see an empty store whatever the volume holds. It is refused, before any rehearsal object is inspected or
# created; the same command in the current tree is the one that runs, and no other command's policy changes.
t_g24_the_historical_prune_is_refused() {
    local name
    prune_world
    run_script -- run historical resources:assets:prune
    expect_refused 'the prune dry-run in the historical tree' 'is only run in the current tree'
    expect_has 'the way on' "$ERR" 'run current resources:assets:prune'
    no_runner_created 'the historical prune'
    ! grep -q "$NAME_FV" "$W/calls" || bad 'the files volume was looked at for the historical prune'
    # Nothing else changes: the others still run in the historical tree (the vectors are the ones the policy test holds), and the current one runs.
    for name in about migrate:status helper:db-ping; do
        prune_world
        run_script -- run historical "$name"
        expect_rc "run historical $name" 0
    done
    prune_world
    run_script -- run current resources:assets:prune
    expect_rc 'run current resources:assets:prune' 0
    # The refusal is the runner's own: it holds for any caller of it (a helper stage), not only for the `run` command.
    prune_world
    # shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
    run_fn 'ROOT=/stand-in; run_in_runner "$ROOT" historical php artisan resources:assets:prune --dry-run'
    [[ "$RC" != 0 && "$ERR" == *'is only run in the current tree'* ]] || bad "run_in_runner ran the prune dry-run in the historical tree: $RC ${ERR:0:200}"
    no_runner_created 'the historical prune through run_in_runner'
}

# --- the matrix runner itself (WU0): sharing the mutants among workers must not change which mutants run or what is concluded ---------------------------
# These tests are about the machinery that runs the matrix, not about the rehearsal script, so no GUARD_TESTS entry points at them.

# The shares: for any number of workers they are disjoint, together they are every mutant, they differ in size by at most one, and the same inputs always
# give the same shares. Over the real mutant list and over lists of other sizes (none, one, fewer than the workers, many).
t_matrix_shares_are_a_partition() {
    local -a ids=() share=() again=() all=()
    local n i size min max list sizes
    local -a real=()
    mapfile -t ids < <(matrix_mutants)
    ((${#ids[@]} >= 280)) || bad "the matrix found only ${#ids[@]} mutants to share; the rehearsal has well over 280 guards"
    for list in real 0 1 2 5 17 289; do
        if [[ "$list" == real ]]; then real=("${ids[@]}"); else real=() && mapfile -t real < <(seq 1 "$list" | sed 's/^/G-/' | { grep . || true; }); fi
        for n in 1 2 3 4 5 7 16; do
            all=() min=999999 max=0 sizes=''
            for ((i = 0; i < n; i++)); do
                mapfile -t share < <(shard_of "$n" "$i" ${real[@]+"${real[@]}"})
                mapfile -t again < <(shard_of "$n" "$i" ${real[@]+"${real[@]}"})
                [[ "${share[*]}" == "${again[*]}" ]] || bad "the share $i of $n changed between two calls (list: $list)"
                size=${#share[@]}
                ((size >= min)) || min=$size
                ((size <= max)) || max=$size
                sizes+="$size "
                all+=(${share[@]+"${share[@]}"})
            done
            ((${#all[@]} == ${#real[@]})) || bad "$n workers were given ${#all[@]} mutants between them, not ${#real[@]} (list: $list; shares: $sizes)"
            [[ "$(printf '%s\n' ${all[@]+"${all[@]}"} | LC_ALL=C sort | { grep . || true; } | uniq -d | wc -l)" == 0 ]] || bad "a mutant was given to two of $n workers (list: $list)"
            [[ "$(printf '%s\n' ${all[@]+"${all[@]}"} | LC_ALL=C sort)" == "$(printf '%s\n' ${real[@]+"${real[@]}"} | LC_ALL=C sort)" ]] || bad "the shares of $n workers are not the whole list (list: $list)"
            ((${#real[@]} == 0 || max - min <= 1)) || bad "the shares of $n workers differ by more than one mutant ($sizes; list: $list)"
        done
    done
}

# Every marker in the script has tests, so every marker is a mutant, and each of them is in exactly one worker's share for the worker counts that matter.
t_matrix_every_marker_is_a_mutant_exactly_once() {
    local -a markers=() ids=() all=() share=()
    local id n i
    mapfile -t markers < <(matrix_markers)
    mapfile -t ids < <(matrix_mutants)
    ((${#markers[@]} > 0)) || bad 'the script has no guard markers'
    [[ "${markers[*]}" == "${ids[*]}" ]] || bad "the mutants are not exactly the script's markers, in order (markers ${#markers[@]}, mutants ${#ids[@]})"
    for id in "${markers[@]}"; do
        [[ -n "${GUARD_TESTS[$id]:-}" ]] || bad "$id is a marker with no tests, so it would not be mutated"
    done
    for n in 1 2 3 4 8 16; do
        all=()
        for ((i = 0; i < n; i++)); do
            mapfile -t share < <(shard_of "$n" "$i" "${ids[@]}")
            all+=("${share[@]}")
        done
        [[ "$(printf '%s\n' "${all[@]}" | LC_ALL=C sort)" == "$(printf '%s\n' "${markers[@]}" | LC_ALL=C sort)" ]] || bad "with $n workers the mutants are not exactly the markers, each once"
    done
}

# How many workers: bounded by the CPUs (one is kept for the ordinary tests), by 4, and by memory; never negative; and REHEARSAL_WORKERS is refused unless it
# is a whole number from 0 to 16.
t_matrix_worker_count() {
    local cpus mem want got value c
    local -a cases=('1 16000 0' '2 16000 1' '3 16000 2' '4 16000 3' '5 16000 4' '16 16000 4' '64 16000 4' '16 1024 2' '16 511 0' '8 1000 1' '4 100000 3' '0 16000 0')
    for c in "${cases[@]}"; do
        read -r cpus mem want <<<"$c"
        got="$(default_workers "$cpus" "$mem")"
        [[ "$got" == "$want" ]] || bad "default_workers $cpus $mem gave $got, not $want"
    done
    got="$(available_cpus)"
    if ! [[ "$got" =~ ^[1-9][0-9]*$ ]] || ((got > $(nproc))); then bad "available_cpus gave '$got'"; fi
    got="$(available_memory_mb)"
    [[ "$got" =~ ^[0-9]+$ ]] || bad "available_memory_mb gave '$got'"
    for value in 0 1 4 16; do
        got="$(REHEARSAL_WORKERS=$value matrix_workers)" || bad "REHEARSAL_WORKERS=$value was refused"
        [[ "$got" == "$value" ]] || bad "REHEARSAL_WORKERS=$value gave $got workers"
    done
    for value in '' 17 -1 abc 1.5 ' 2' '2 ' '01' '1;2' 100; do
        got="$(REHEARSAL_WORKERS="$value" matrix_workers 2>&1)" && bad "REHEARSAL_WORKERS='$value' was accepted ($got)"
        [[ "$got" == *'REHEARSAL_WORKERS must be a whole number'* ]] || bad "REHEARSAL_WORKERS='$value' was refused without saying why ($got)"
    done
}

# What the parent concludes from a worker's output: only a worker that printed every assigned mutant once as caught, closed with matching counts, and exited
# with the status those imply, is accepted. Each kind of incomplete, duplicated, extra or crashed worker is a problem of its own.
t_matrix_worker_results_are_checked() {
    local ok_a='  ok    A                          caught by g1_a: it did' ok_b='  ok    B                          caught by g1_b: it did'
    local fail_b='  FAIL  B                          survived: none of [t_x] failed without the guard' trailer='worker 1/3: assigned=2 caught=2 failed=0'
    matrix_case() { # DESC STATUS OUTPUT EXPECTED_SUBSTRING ('' = right)
        worker_problems 1 3 'A B' "$2" "$3"
        if [[ -z "$4" ]]; then
            [[ -z "$WORKER_PROBLEMS" ]] || bad "$1: a right worker was reported as wrong: ${WORKER_PROBLEMS:0:300}"
        else
            [[ "$WORKER_PROBLEMS" == *"$4"* ]] || bad "$1: expected a problem mentioning '$4', got: ${WORKER_PROBLEMS:0:300}"
        fi
    }
    MUTANT_LINE=()
    matrix_case 'a right worker' 0 "$ok_a"$'\n'"$ok_b"$'\n'"$trailer" ''
    [[ "${MUTANT_LINE[A]:-}" == "$ok_a" && "${MUTANT_LINE[B]:-}" == "$ok_b" ]] || bad 'the results of a right worker were not kept'
    matrix_case 'noise around the results' 0 $'some warning\n'"$ok_a"$'\nok, but not a result line\n'"$ok_b"$'\n'"$trailer"$'\n' ''
    matrix_case 'a mutant not run (and a closing line that says so)' 1 "$ok_a"$'\n''worker 1/3: assigned=2 caught=1 failed=0' 'produced no result for: B'
    matrix_case 'a mutant not run (and a closing line that hides it)' 0 "$ok_a"$'\n'"$trailer" 'produced no result for: B'
    matrix_case 'a mutant reported twice' 0 "$ok_a"$'\n'"$ok_a"$'\n'"$ok_b"$'\n'"$trailer" 'A more than once'
    matrix_case 'a mutant nobody assigned' 0 "$ok_a"$'\n'"$ok_b"$'\n''  ok    C                          caught by x: y'$'\n'"$trailer" 'C, which was not assigned'
    matrix_case 'a worker that crashed (no closing line)' 137 "$ok_a" 'did not finish'
    matrix_case 'a worker that printed nothing' 0 '' 'produced no result for: A B'
    matrix_case 'a closing line printed twice' 0 "$ok_a"$'\n'"$ok_b"$'\n'"$trailer"$'\n'"$trailer" 'did not finish'
    matrix_case 'counts that do not match the results' 0 "$ok_a"$'\n'"$ok_b"$'\n''worker 1/3: assigned=2 caught=1 failed=1' 'closed with'
    matrix_case 'a closing line for another worker' 0 "$ok_a"$'\n'"$ok_b"$'\n''worker 0/3: assigned=2 caught=2 failed=0' 'closed with'
    matrix_case 'a mutant that survived, reported as success' 0 "$ok_a"$'\n'"$fail_b"$'\n''worker 1/3: assigned=2 caught=1 failed=1' 'exited 0 though it reported 1 failed'
    matrix_case 'a mutant that survived, reported honestly' 1 "$ok_a"$'\n'"$fail_b"$'\n''worker 1/3: assigned=2 caught=1 failed=1' ''
    matrix_case 'a worker that exits badly after succeeding' 2 "$ok_a"$'\n'"$ok_b"$'\n'"$trailer" 'exited with status 2'
    unset -f matrix_case
    MUTANT_LINE=()
}

# Workers started as the parent starts them, on real mutants: each has a temporary directory of its own and removes it, they reach the same verdicts as one
# worker running the same mutants alone, a mutant that survives is reported by both, REHEARSAL_SRC is honoured, and a worker killed part-way is found out.
t_matrix_workers_are_isolated_and_equivalent() {
    local -a ids=(G1-context-env G1-host G1-tls G2-env G1-context G1-endpoint) first=() second=()
    local copy="$T/matrix-src" tmp="$T/matrix-tmp" id i out_all out_a out_b rc_all=0 rc_a=0 rc_b=0 pid_a pid_b seen=0 dir_a dir_b
    mkdir -p "$tmp"
    cp -r "$SRC" "$copy"
    sed -i 's/ # guard:G1-tls$//' "$copy/restore-rehearsal.sh" # its mutation then changes nothing: a mutant that cannot be caught
    for id in "${ids[@]}"; do [[ -n "${GUARD_TESTS[$id]:-}" ]] || bad "the guard $id used by this test is not registered"; done
    mapfile -t first < <(shard_of 2 0 "${ids[@]}")
    mapfile -t second < <(shard_of 2 1 "${ids[@]}")

    out_all="$(TMPDIR="$tmp" REHEARSAL_SRC="$copy" bash "$THIS_SCRIPT" --worker 0 1 "${ids[@]}" 2>&1)" || rc_all=$?
    [[ -z "$(find "$tmp" -mindepth 1 -maxdepth 1)" ]] || bad "a worker left its temporary directory behind: $(find "$tmp" -mindepth 1 -maxdepth 1 | head -n 3 | tr '\n' ' ')"
    TMPDIR="$tmp" REHEARSAL_SRC="$copy" bash "$THIS_SCRIPT" --worker 0 2 "${first[@]}" >"$T/matrix-a.out" 2>&1 &
    pid_a=$!
    TMPDIR="$tmp" REHEARSAL_SRC="$copy" bash "$THIS_SCRIPT" --worker 1 2 "${second[@]}" >"$T/matrix-b.out" 2>&1 &
    pid_b=$!
    for _ in {1..100}; do # both are running at once, each in a temporary directory of its own: a worker's working directory is inside its own
        dir_a="$(readlink "/proc/$pid_a/cwd" 2>/dev/null || true)" dir_b="$(readlink "/proc/$pid_b/cwd" 2>/dev/null || true)"
        if [[ "$dir_a" == "$tmp"/*/cwd && "$dir_b" == "$tmp"/*/cwd && "$dir_a" != "$dir_b" ]]; then seen=2; break; fi
        sleep 0.1
    done
    { wait "$pid_a" || rc_a=$?; } 2>/dev/null
    { wait "$pid_b" || rc_b=$?; } 2>/dev/null
    ((seen >= 2)) || bad 'the two workers were never seen running at once in two different temporary directories of their own'
    [[ -z "$(find "$tmp" -mindepth 1 -maxdepth 1)" ]] || bad "a worker left its temporary directory behind: $(find "$tmp" -mindepth 1 -maxdepth 1 | head -n 3 | tr '\n' ' ')"
    out_a="$(<"$T/matrix-a.out")" out_b="$(<"$T/matrix-b.out")"

    # One worker alone and two together: the same verdicts, for the same tests. (What follows the colon is the first failed assertion's text.)
    local verdicts_all verdicts_two
    verdicts_all="$(grep -E '^  (ok|FAIL) ' <<<"$out_all" | sed 's/:.*//; s/ \{2,\}/ /g' | LC_ALL=C sort)"
    verdicts_two="$(grep -E '^  (ok|FAIL) ' <<<"$out_a"$'\n'"$out_b" | sed 's/:.*//; s/ \{2,\}/ /g' | LC_ALL=C sort)"
    [[ "$verdicts_all" == "$verdicts_two" ]] || bad "one worker and two workers did not reach the same verdicts: [$verdicts_all] against [$verdicts_two]"
    [[ "$(grep -c '^ ok ' <<<"$verdicts_all" || true)" == 5 ]] || bad "expected five caught mutants of the six: $verdicts_all"
    [[ "$verdicts_all" == *' FAIL G1-tls'* ]] || bad 'the mutant that cannot be caught was not reported as failing'
    [[ "$out_all" == *'G1-tls'*'the mutation changed nothing'* ]] || bad 'the surviving mutant did not say why'
    # A worker runs mutants and nothing else: none of the ordinary tests (they are run once, by the parent) is among what it prints.
    ! grep -qE '^  (ok|FAIL) +[a-z]' <<<"$out_all"$'\n'"$out_a"$'\n'"$out_b" || bad 'a worker ran an ordinary test, which only the parent runs'
    ((rc_all == 1)) || bad "a worker with a failing mutant exited $rc_all, not 1"
    ((rc_a + rc_b == 1 || rc_a + rc_b == 2)) || bad "the two workers exited $rc_a and $rc_b"
    # Judged as the parent judges them.
    MUTANT_LINE=()
    worker_problems 0 1 "${ids[*]}" "$rc_all" "$out_all"
    [[ -z "$WORKER_PROBLEMS" ]] || bad "one worker, honestly reporting its failing mutant, was found wrong: ${WORKER_PROBLEMS:0:300}"
    worker_problems 0 2 "${first[*]}" "$rc_a" "$out_a"
    [[ -z "$WORKER_PROBLEMS" ]] || bad "worker 0 of 2 was found wrong: ${WORKER_PROBLEMS:0:300}"
    worker_problems 1 2 "${second[*]}" "$rc_b" "$out_b"
    [[ -z "$WORKER_PROBLEMS" ]] || bad "worker 1 of 2 was found wrong: ${WORKER_PROBLEMS:0:300}"
    MUTANT_LINE=()

    # A worker killed part-way is not accepted, whatever it had printed by then.
    TMPDIR="$tmp" REHEARSAL_SRC="$SRC" bash "$THIS_SCRIPT" --worker 0 1 G1-context-env G1-host G1-tls G2-env G1-context G1-endpoint >"$T/matrix-killed.out" 2>&1 &
    pid_a=$!
    sleep 1
    kill -KILL "$pid_a" 2>/dev/null || true
    rc_a=0
    { wait "$pid_a" || rc_a=$?; } 2>/dev/null
    ((rc_a != 0)) || bad 'the killed worker exited 0'
    worker_problems 0 1 "${ids[*]}" "$rc_a" "$(<"$T/matrix-killed.out")"
    [[ "$WORKER_PROBLEMS" == *'did not finish'* ]] || bad "a worker killed part-way was accepted: ${WORKER_PROBLEMS:0:300}"
    MUTANT_LINE=()
    rm -rf "$tmp" || true
}

# The sequential run and a worker give the same answer, with the same reason, for guards whose tests depend on the fixture caches (the manifest's and the
# half-failed state's): in-process, with the caches made from the unmutated script, and in a worker, which makes them itself before its first mutant. If a worker
# made a cache under a mutation (or let one mutant's cache reach the next) its reasons would differ here, though the verdicts would not. The reasons are compared
# whole: only the temporary directory's name is normalised, and the worker's has the length of this process's, so the 100-character cut of a reason falls alike.
t_matrix_sequential_and_worker_give_the_same_reasons() {
    local -a ids=(G22-manifest-run G22-manifest-state G22-manifest-taken G20-partial-counts)
    local id line sequential='' worker='' rc=0 saved_digest="$CACHE_DIGEST" shown
    for id in "${ids[@]}"; do [[ -n "${GUARD_TESTS[$id]:-}" ]] || bad "the guard $id used by this test is not registered"; done
    prepare_caches fresh || bad 'the caches could not be prepared from the unmutated script'
    for id in "${ids[@]}"; do sequential+="$(run_mutant "$id" 2>&1)"$'\n'; done
    worker="$(REHEARSAL_SRC="$SRC" TMPDIR="$(dirname "$T")" bash "$THIS_SCRIPT" --worker 0 1 "${ids[@]}" 2>&1)" || rc=$?
    CACHE_DIGEST="$saved_digest"
    [[ "$rc" == 0 ]] || bad "a worker given these mutants exited $rc: ${worker:0:300}"
    sequential="$(grep -E '^  (ok|FAIL) ' <<<"$sequential" | sed "s#$T#<T>#g")"
    worker="$(grep -E '^  (ok|FAIL) ' <<<"$worker" | sed -E "s#$(dirname "$T")/tmp\.[A-Za-z0-9]+#<T>#g")"
    [[ "$(grep -c . <<<"$sequential")" == "${#ids[@]}" ]] || bad "the sequential run did not give one result for each of the ${#ids[@]} mutants: $sequential"
    while IFS= read -r line; do
        [[ "$line" =~ ^\ \ ok\ +[A-Za-z0-9-]+\ +caught\ by\ [a-z0-9_]+:\ .+ ]] || bad "a mutant was not caught, or not for a stated reason: $line"
    done <<<"$sequential"
    if [[ "$sequential" != "$worker" ]]; then
        shown="$(diff <(printf '%s\n' "$sequential") <(printf '%s\n' "$worker") | head -n 6 | cut -c1-220 | tr '\n' '|' || true)"
        bad "the sequential run and a worker gave different results (< sequential, > worker): $shown"
    fi
}

# The caches are made before the first mutant and are kept as they were: a worker that cannot make them stops without a closing line (so the parent finds it
# unfinished) and runs no mutant, an incomplete cache is not accepted, and a mutant whose tests changed a cache fails.
t_matrix_the_caches_are_made_before_the_mutants_and_kept() {
    local copy="$T/cache-src" out rc=0 saved_digest="$CACHE_DIGEST" file="$T/cache/historical.manifest.json"
    rm -rf "$copy"
    cp -r "$SRC" "$copy"
    sed -i '1a exit 7' "$copy/restore-rehearsal.sh" # every stage now fails, so no cache can be made
    out="$(REHEARSAL_SRC="$copy" TMPDIR="$T" timeout 300 bash "$THIS_SCRIPT" --worker 0 1 G1-host 2>&1)" || rc=$?
    ((rc != 0)) || bad 'a worker that could not make its caches exited 0'
    [[ "$out" == *'could not be prepared'* ]] || bad "a worker that could not make its caches did not say so: ${out:0:300}"
    [[ "$out" != *'worker 0/1:'* && "$out" != *'  ok '* ]] || bad "a worker that could not make its caches printed a result or a closing line: ${out:0:300}"
    worker_problems 0 1 G1-host "$rc" "$out"
    [[ "$WORKER_PROBLEMS" == *'did not finish'* ]] || bad "the parent would accept a worker that could not make its caches: ${WORKER_PROBLEMS:0:300}"
    # Whole caches are made from the unmutated script, and a mutant that changes one is a failure. The caches are this process's own and the ordinary tests use
    # them, so they are put back exactly as they were (a copy of the directories, with their times and modes) after the damage this test does to them.
    prepare_caches fresh || bad 'the caches could not be prepared'
    [[ -n "$CACHE_DIGEST" ]] || bad 'no digest of the caches was kept'
    rm -rf "${T:?}/cache.keep" "${T:?}/fcache.keep"
    cp -a "$T/cache" "$T/cache.keep"
    cp -a "$T/fcache" "$T/fcache.keep"
    out="$(run_mutant G1-host 2>&1)" || bad "a mutant with the caches untouched failed: ${out:0:300}"
    printf 'changed\n' >>"$file"
    rc=0
    out="$(run_mutant G1-host 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'changed the fixture caches'* ]] || bad "a mutant whose tests changed a cache was not failed (exit $rc): ${out:0:300}"
    rm -rf "${T:?}/cache" "${T:?}/fcache"
    cp -a "$T/cache.keep" "$T/cache"
    cp -a "$T/fcache.keep" "$T/fcache"
    [[ "$(cache_digest)" == "$CACHE_DIGEST" ]] || bad 'the caches were not put back as they were'
    rm -f "$file"
    if prepare_caches >/dev/null 2>&1; then bad 'an incomplete cache (a state file missing) was accepted'; fi
    rm -rf "${T:?}/cache" "${T:?}/fcache"
    cp -a "$T/cache.keep" "$T/cache"
    cp -a "$T/fcache.keep" "$T/fcache"
    rm -rf "${T:?}/cache.keep" "${T:?}/fcache.keep"
    [[ "$(cache_digest)" == "$CACHE_DIGEST" ]] || bad 'the caches were not put back as they were'
    CACHE_DIGEST="$saved_digest"
}

# What the parent makes of the workers' reports: the failures it counts. Synthetic reports go through the same two steps the parent takes (account_worker,
# report_mutants), with the failure count local to this test. Complete, honest reports of caught mutants add no failure; an honestly reported survivor, or a
# worker's missing result, does; and complete reports cannot hide a failure the run already had.
t_matrix_parent_counts_failures() {
    local FAILURES=0 TALLY='' MUTANTS_RAN=-1 MUTANTS_CAUGHT=-1
    local -a MUTANT_IDS=(A B C)
    local -A MUTANT_LINE=()
    local ok_a='  ok    A                          caught by g1_a: it did' ok_b='  ok    B                          caught by g1_b: it did' ok_c='  ok    C                          caught by g1_c: it did'
    local fail_b='  FAIL  B                          survived: none of [t_x] failed without the guard'
    tally() { # BEFORE STATUS0 TEXT0 STATUS1 TEXT1: worker 0 of 2 has A and C, worker 1 has B; the failure count starts at BEFORE
        FAILURES="$1" MUTANT_LINE=()
        printf '%s\n' "$3" >"$T/tally-0.out"
        printf '%s\n' "$5" >"$T/tally-1.out"
        {
            account_worker 0 2 'A C' "$2" "$T/tally-0.out"
            account_worker 1 2 'B' "$4" "$T/tally-1.out"
            report_mutants 2
        } >"$T/tally.out" 2>&1
        TALLY="$(<"$T/tally.out")"
    }
    local trailer0='worker 0/2: assigned=2 caught=2 failed=0' trailer1='worker 1/2: assigned=1 caught=1 failed=0'
    tally 0 0 "$ok_a"$'\n'"$ok_c"$'\n'"$trailer0" 0 "$ok_b"$'\n'"$trailer1"
    ((FAILURES == 0)) || bad "complete, honest reports of caught mutants were counted as $FAILURES failure(s): ${TALLY:0:300}"
    [[ "$TALLY" == *'3 mutant(s) run by 2 worker(s), each exactly once'* ]] || bad "a clean run did not say so: ${TALLY:0:300}"
    ((MUTANTS_RAN == 3 && MUTANTS_CAUGHT == 3)) || bad "the summary's mutant counts for a clean run were ran=$MUTANTS_RAN caught=$MUTANTS_CAUGHT, not 3 and 3"
    tally 0 0 "$ok_a"$'\n'"$ok_c"$'\n'"$trailer0" 1 "$fail_b"$'\n''worker 1/2: assigned=1 caught=0 failed=1'
    ((FAILURES > 0)) || bad "a surviving mutant, honestly reported, did not fail the run: ${TALLY:0:300}"
    [[ "$TALLY" == *'FAIL  B'*'survived'* && "$TALLY" != *'each exactly once'* ]] || bad "a surviving mutant was not reported, or the run was called clean: ${TALLY:0:300}"
    ((MUTANTS_RAN == 3 && MUTANTS_CAUGHT == 2)) || bad "the summary's mutant counts with a survivor were ran=$MUTANTS_RAN caught=$MUTANTS_CAUGHT, not 3 and 2"
    tally 1 0 "$ok_a"$'\n'"$ok_c"$'\n'"$trailer0" 0 "$ok_b"$'\n'"$trailer1"
    ((FAILURES == 1)) || bad "complete reports changed a failure the run already had: FAILURES=$FAILURES"
    [[ "$TALLY" != *'each exactly once'* ]] || bad "complete reports hid a failure the run already had: ${TALLY:0:300}"
    tally 0 0 "$ok_a"$'\n'"$ok_c"$'\n'"$trailer0" 0 "$trailer1"
    ((FAILURES > 0)) || bad "a worker with a mutant missing, and an exit status of 0, did not fail the run: ${TALLY:0:300}"
    [[ "$TALLY" == *'did not run'* ]] || bad "a mutant that was never reported was not said to have not run: ${TALLY:0:300}"
    ((MUTANTS_RAN == 2 && MUTANTS_CAUGHT == 2)) || bad "the summary's mutant counts with a missing result were ran=$MUTANTS_RAN caught=$MUTANTS_CAUGHT, not 2 and 2"
    unset -f tally
}

# A worker is chosen by arguments and by nothing else, a malformed or repeated share is refused before anything runs, and the other ways of running the
# file keep their meaning: REHEARSAL_TESTS runs just those tests (no matrix), whatever the environment says about workers; a bad REHEARSAL_WORKERS stops the
# run at once, before any test.
t_matrix_modes_keep_their_meaning() {
    local out rc args
    for args in '2 2 G1-host' 'x 2 G1-host' '0 0 G1-host' '0 1 NOT-A-GUARD' '0 1 G1-host G1-host' '0 1 ../x' ''; do
        rc=0
        # shellcheck disable=SC2086 # the words are the point
        out="$(timeout 120 bash "$THIS_SCRIPT" --worker $args 2>&1)" || rc=$?
        [[ "$rc" == 2 ]] || bad "a worker given '$args' exited $rc, not 2 (it printed: ${out:0:200})"
        [[ "$out" != *'  ok '* ]] || bad "a worker given '$args' ran a mutant"
    done
    rc=0
    out="$(REHEARSAL_WORKER=1 REHEARSAL_WORKERS=3 REHEARSAL_TESTS=t_guard_registry_comparison timeout 300 bash "$THIS_SCRIPT" 2>&1)" || rc=$?
    [[ "$rc" == 0 ]] || bad "REHEARSAL_TESTS with worker variables in the environment exited $rc: ${out:0:300}"
    [[ "$out" == *'ok    guard_registry_comparison'* && "$out" != *'Mutation matrix'* && "$out" != *'worker'* && "$out" != *REHEARSAL_SUMMARY* ]] || bad "REHEARSAL_TESTS did not run just that test, started workers, or printed a verification summary: ${out:0:300}"
    rc=0
    out="$(env -u REHEARSAL_TESTS REHEARSAL_WORKERS=17 timeout 60 bash "$THIS_SCRIPT" 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" == *'REHEARSAL_WORKERS must be a whole number'* && "$out" != *'  ok '* ]] || bad "REHEARSAL_WORKERS=17 did not stop the run at once (exit $rc): ${out:0:300}"
}

# --- the modes of the file itself (CI-1): which verification a run is, and that a weaker one is only ever chosen on the command line ---------------------------
# The modes are driven by functions that take their tests from ORDINARY_TESTS, so they are tested here with a short list of stand-ins and the REAL registry and mutant
# list, in a subshell of their own (nothing they change reaches this test); `rehearsal.sh --routine` itself is only started here with arguments that are refused.

# Anything but nothing or exactly --routine is refused before a single test runs, and a weaker run cannot be chosen by the environment.
t_modes_a_malformed_selection_is_refused() {
    local out rc args
    for args in '--bogus' '--routine --routine' '--routine --worker 0 1 G1-host' '--full' '--Routine' '--routine=1' '-r' 'routine' '--routine extra' "''"; do
        rc=0
        # shellcheck disable=SC2086 # the words are the point
        out="$(eval "timeout 60 bash \"\$THIS_SCRIPT\" $args" 2>&1)" || rc=$?
        [[ "$rc" == 2 ]] || bad "rehearsal.sh $args exited $rc, not 2 (it printed: ${out:0:200})"
        [[ "$out" != *'  ok '* && "$out" != *REHEARSAL_SUMMARY* && "$out" != *'passed'* ]] || bad "rehearsal.sh $args ran something or reported a result: ${out:0:200}"
    done
    # The environment does not choose a mode, and does not combine with one.
    rc=0
    out="$(REHEARSAL_TESTS=t_surface_no_restore_command timeout 60 bash "$THIS_SCRIPT" --routine 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" == *'cannot be combined'* && "$out" != *'  ok '* ]] || bad "--routine with REHEARSAL_TESTS was not refused at once (exit $rc): ${out:0:200}"
    rc=0
    out="$(env -u REHEARSAL_TESTS REHEARSAL_WORKERS=17 timeout 60 bash "$THIS_SCRIPT" --routine 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" == *'REHEARSAL_WORKERS must be a whole number'* && "$out" != *'  ok '* ]] || bad "--routine with a malformed REHEARSAL_WORKERS was not refused at once (exit $rc): ${out:0:200}"
    # A weaker mode needs its argument: the same environment and no argument is the full run, which a malformed value stops at once as before.
    rc=0
    out="$(env -u REHEARSAL_TESTS REHEARSAL_ROUTINE=1 REHEARSAL_MODE=routine REHEARSAL_WORKERS=17 timeout 60 bash "$THIS_SCRIPT" 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" != *'Routine'* && "$out" != *'NOT RUN'* ]] || bad "an environment variable chose a weaker mode (exit $rc): ${out:0:200}"
}

# Running named tests directly (REHEARSAL_TESTS) is for working on a test, and must never look like a pass it is not: a name that is not a test, a selection of nothing
# (empty, blank or only whitespace) and a variable that is merely set are all refused before anything runs (exit 2), a selected test that fails fails the run (exit 1),
# and no run of this kind prints the verification summary. (The valid case, with the real file, is held by t_matrix_modes_keep_their_meaning.)
# shellcheck disable=SC2329 # the stand-in functions are defined in subshells and called by the code under test
t_modes_targeted_runs_refuse_what_tests_nothing() {
    local out rc sel
    for sel in '' ' ' $'\t' $'\n' $' \n ' 't_no_such_test' 'no_such_test' 'bad' 'run_test' 't_guard_registry_comparison t_no_such_test' 't_no_such_test t_guard_registry_comparison'; do
        rc=0
        out="$(REHEARSAL_TESTS="$sel" timeout 60 bash "$THIS_SCRIPT" 2>&1)" || rc=$?
        [[ "$rc" == 2 ]] || bad "REHEARSAL_TESTS='${sel//$'\n'/\\n}' exited $rc, not 2 (it printed: ${out:0:200})"
        [[ "$out" != *'  ok '* && "$out" != *REHEARSAL_SUMMARY* && "$out" != *'passed'* ]] || bad "REHEARSAL_TESTS='${sel//$'\n'/\\n}' ran a test or reported a pass: ${out:0:200}"
    done
    rc=0
    out="$(REHEARSAL_TESTS='' timeout 60 bash "$THIS_SCRIPT" --routine 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" != *'  ok '* && "$out" != *REHEARSAL_SUMMARY* ]] || bad "--routine with an empty REHEARSAL_TESTS was not refused (exit $rc): ${out:0:200}"
    # A failing selected test fails the run, the others still run, and neither a pass nor a failure prints the summary.
    local log="$T/targeted.log"
    rm -f "$log"
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_a() { echo a >>"$log"; }; t_fake_bad() { echo bad >>"$log"; TEST_FAILS=1; }; t_fake_c() { echo c >>"$log"; }
        ORDINARY_TESTS=(t_fake_a t_fake_bad t_fake_c)
        run_targeted 't_fake_a t_fake_bad'
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 ]] || bad "a failing selected test did not fail the targeted run (exit $rc): ${out:0:300}"
    [[ "$(sort "$log" | tr '\n' ' ')" == 'a bad ' ]] || bad "the targeted run ran something other than what was selected: $(tr '\n' ' ' <"$log")"
    [[ "$out" != *REHEARSAL_SUMMARY* && "$out" != *'selected test(s) passed'* ]] || bad "a failing targeted run printed a summary or a pass: ${out:0:300}"
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (t_fake_a() { :; }; ORDINARY_TESTS=(t_fake_a); run_targeted ' t_fake_a ') 2>&1)" || rc=$?
    [[ "$rc" == 0 && "$out" == *'1 selected test(s) passed'* && "$out" != *REHEARSAL_SUMMARY* && "$out" == *'not a verification'* ]] || bad "a passing targeted run (exit $rc) did not say it is not a verification, or printed a summary: ${out:0:300}"
}

# Routine: every ordinary test once, the registry, no mutation experiment, and a report that cannot be taken for the full run's.
# shellcheck disable=SC2329 # the stand-in functions are defined in subshells and called by the code under test
t_modes_routine_runs_every_ordinary_test_once_and_no_mutant() {
    local out rc=0 log="$T/modes.log" markers
    markers="$(matrix_markers | wc -l)"
    rm -f "$log" "$T/modes.mutants"
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell, which expands them
    out="$( (
        t_fake_a() { echo a >>"$log"; }; t_fake_b() { echo b >>"$log"; }; t_fake_c() { echo c >>"$log"; }
        run_mutant() { echo "mutant $1" >>"$T/modes.mutants"; }
        prepare_caches() { echo caches >>"$T/modes.mutants"; }
        ORDINARY_TESTS=(t_fake_a t_fake_b t_fake_c)
        rehearsal_routine
    ) 2>&1)" || rc=$?
    [[ "$rc" == 0 ]] || bad "a routine run of passing tests exited $rc: ${out:0:300}"
    [[ "$(sort "$log" | tr '\n' ' ')" == 'a b c ' ]] || bad "the ordinary tests were not each run exactly once: $(tr '\n' ' ' <"$log")"
    [[ ! -e "$T/modes.mutants" ]] || bad "a routine run executed a mutation experiment or prepared the matrix's caches: $(tr '\n' ' ' <"$T/modes.mutants")"
    expect_has 'the summary line' "$out" "REHEARSAL_SUMMARY mode=routine result=passed ordinary_run=3 ordinary_failed=0 registry=consistent guards_registered=$markers mutants_executed=0 mutants_caught=0 matrix=not-run"
    expect_has 'the matrix is said not to have run' "$out" 'Mutation matrix: NOT RUN'
    expect_has 'the closing line says so too' "$out" 'The mutation matrix was NOT RUN.'
    expect_lacks 'the full run closing line' "$out" 'All rehearsal guard tests passed'
    expect_lacks 'the full run mutant line' "$out" 'mutant(s) run by'
    expect_lacks 'the full run matrix header' "$out" 'each safeguard line replaced'
    [[ "$markers" -ge 300 ]] || bad "the registry holds only $markers guards: the test is not using the real one"
}

# Routine still fails for a failing ordinary test (and runs the others), for no tests, and for a test listed twice.
# shellcheck disable=SC2329 # the stand-in functions are defined in subshells and called by the code under test
t_modes_routine_fails_closed() {
    local out rc log="$T/modes-fail.log"
    rm -f "$log"
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_a() { echo a >>"$log"; }; t_fake_bad() { echo bad >>"$log"; TEST_FAILS=1; }; t_fake_c() { echo c >>"$log"; }
        ORDINARY_TESTS=(t_fake_a t_fake_bad t_fake_c)
        rehearsal_routine
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 ]] || bad "a failing ordinary test did not fail the routine run (exit $rc): ${out:0:300}"
    [[ "$(sort "$log" | tr '\n' ' ')" == 'a bad c ' ]] || bad "the routine run did not go on past a failing test: $(tr '\n' ' ' <"$log")"
    expect_has 'the failure is in the summary' "$out" 'result=failed ordinary_run=3 ordinary_failed=1'
    expect_lacks 'no success line' "$out" 'Routine rehearsal checks passed'
    rc=0
    out="$( (ORDINARY_TESTS=(); rehearsal_routine) 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'there are no ordinary tests to run'* && "$out" != *'Routine rehearsal checks passed'* ]] || bad "a routine run of no tests succeeded (exit $rc): ${out:0:300}"
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (t_fake_a() { :; }; ORDINARY_TESTS=(t_fake_a t_fake_a); rehearsal_routine) 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'appears twice'* && "$out" != *'Routine rehearsal checks passed'* ]] || bad "a test listed twice was not refused (exit $rc): ${out:0:300}"
}

# Routine checks the registry in both directions, as the full run does: a marker with no entry, and an entry with no marker, each fail it.
# shellcheck disable=SC2329 # the stand-in functions are defined in subshells and called by the code under test
t_modes_routine_checks_the_registry_both_ways() {
    local out rc real
    real="$(matrix_markers)"
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_a() { :; }
        matrix_markers() { { printf '%s\n' "$real"; echo G99-a-marker-with-no-entry; } | sort -u; }
        ORDINARY_TESTS=(t_fake_a)
        rehearsal_routine
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 ]] || bad "a marker with no registry entry did not fail the routine run (exit $rc): ${out:0:300}"
    expect_has 'the unregistered marker is named' "$out" 'G99-a-marker-with-no-entry has no tests in GUARD_TESTS'
    expect_has 'the summary' "$out" 'result=failed'
    expect_has 'the summary says inconsistent' "$out" 'registry=inconsistent'
    expect_lacks 'no success line (marker)' "$out" 'Routine rehearsal checks passed'
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_a() { :; }
        matrix_markers() { printf '%s\n' "$real" | grep -vx 'G1-host'; }
        ORDINARY_TESTS=(t_fake_a)
        rehearsal_routine
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 ]] || bad "a registry entry with no marker did not fail the routine run (exit $rc): ${out:0:300}"
    expect_has 'the unmarked entry is named' "$out" 'GUARD_TESTS lists G1-host, which has no marker in the script'
    expect_lacks 'no success line (entry)' "$out" 'Routine rehearsal checks passed'
}

# Full (no argument) keeps every registered mutant, runs each exactly once and counts each as caught; one that is not caught, or an ordinary test that fails, fails it.
# (The workers' own accounting is held by the matrix_* tests; this runs the sequential path, with the mutant itself replaced by a stand-in that records the call.)
# shellcheck disable=SC2329 # the stand-in functions are defined in subshells and called by the code under test
t_modes_full_runs_every_registered_mutant_exactly_once() {
    local out rc=0 log="$T/modes-full.log" markers mutants
    markers="$(matrix_markers | wc -l)"
    mutants="$(matrix_mutants | wc -l)"
    [[ "$mutants" == "$markers" && "$markers" -ge 300 ]] || bad "the matrix would not mutate every marker ($mutants of $markers)"
    rm -f "$log"
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_a() { :; }
        prepare_caches() { :; }
        run_mutant() { echo "$1" >>"$log"; printf '  ok    %s caught by a stand-in\n' "$1"; }
        ORDINARY_TESTS=(t_fake_a)
        REHEARSAL_WORKERS=0 rehearsal_full
    ) 2>&1)" || rc=$?
    [[ "$rc" == 0 ]] || bad "a full run in which every mutant is caught exited $rc: ${out:0:300}"
    [[ "$(wc -l <"$log")" == "$markers" && "$(sort "$log" | uniq -d | wc -l)" == 0 && "$(sort "$log")" == "$(matrix_mutants | sort)" ]] || bad "the full run did not run each of the $markers registered mutants exactly once"
    expect_has 'the summary' "$out" "REHEARSAL_SUMMARY mode=full result=passed ordinary_run=1 ordinary_failed=0 registry=consistent guards_registered=$markers mutants_executed=$markers mutants_caught=$markers matrix=run workers=0"
    expect_has 'the closing line' "$out" 'All rehearsal guard tests passed'
    expect_lacks 'a full run is not said to be routine' "$out" 'NOT RUN'
    # A guard whose mutant is not caught fails the run.
    rm -f "$log"
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_a() { :; }
        prepare_caches() { :; }
        run_mutant() { if [[ "$1" == G1-host ]]; then printf '  FAIL  %s survived\n' "$1" >&2; return 1; fi; printf '  ok    %s caught\n' "$1"; }
        ORDINARY_TESTS=(t_fake_a)
        REHEARSAL_WORKERS=0 rehearsal_full
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 ]] || bad "a full run with a surviving mutant exited $rc: ${out:0:300}"
    expect_has 'the survivor is in the summary' "$out" "result=failed ordinary_run=1 ordinary_failed=0 registry=consistent guards_registered=$markers mutants_executed=$markers mutants_caught=$((markers - 1))"
    expect_lacks 'no success line (survivor)' "$out" 'All rehearsal guard tests passed'
    # An ordinary test that fails fails the full run, whatever the mutants say.
    rc=0
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell
    out="$( (
        t_fake_bad() { TEST_FAILS=1; }
        prepare_caches() { :; }
        run_mutant() { printf '  ok    %s caught\n' "$1"; }
        ORDINARY_TESTS=(t_fake_bad)
        REHEARSAL_WORKERS=0 rehearsal_full
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'result=failed ordinary_run=1 ordinary_failed=1'* && "$out" != *'All rehearsal guard tests passed'* ]] || bad "a failing ordinary test did not fail the full run (exit $rc): ${out:0:300}"
    # And the full run refuses a malformed REHEARSAL_WORKERS before any test, as before.
    rc=0
    out="$( (t_fake_a() { echo RAN; }; ORDINARY_TESTS=(t_fake_a); REHEARSAL_WORKERS=17 rehearsal_full) 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" != *RAN* ]] || bad "a malformed REHEARSAL_WORKERS did not stop the full run before its tests (exit $rc): ${out:0:200}"
}

# --- routine mode: the ordinary tests shared among workers (CI-2) ----------------------------------------------------------------------------------------
# These tests are about the machinery that shares and judges the ordinary tests, not about the rehearsal script, so no GUARD_TESTS entry points at them. The workers
# the parent starts are real where the test is about what a real worker does, and stand-ins (which write what a faulty worker would, to a record file) where it is
# about what the parent concludes from one.

# The shares: for any number of workers up to 16 they are, over the real list of tests, disjoint, together the whole list, within one of each other in size, the same
# every time, and the parent's own check (plan_ordinary_shards) finds them right; and it finds a faulty shard_of wrong.
# shellcheck disable=SC2329 # the faulty shard_of is defined in subshells and called by the code under test
t_ordinary_shares_are_a_partition() {
    local -a all=() joined=() share=()
    local n i min max sizes out rc
    local -a ORDINARY_TESTS=()
    mapfile -t all < <(all_tests)
    ((${#all[@]} >= 284)) || bad "only ${#all[@]} ordinary tests were found; there are at least 284"
    ORDINARY_TESTS=("${all[@]}")
    for ((n = 1; n <= 16; n++)); do
        joined=() min=999999 max=0 sizes=''
        plan_ordinary_shards "$n" 2>"$T/plan.err" || bad "$n workers: the shares were refused: $(head -n 3 "$T/plan.err" | tr '\n' ' ')"
        for ((i = 0; i < n; i++)); do
            read -ra share <<<"${SHARD_TESTS[i]}"
            ((${#share[@]} >= min)) || min=${#share[@]}
            ((${#share[@]} <= max)) || max=${#share[@]}
            sizes+="${#share[@]} "
            joined+=("${share[@]}")
        done
        [[ "$(printf '%s\n' "${joined[@]}" | LC_ALL=C sort)" == "$(printf '%s\n' "${all[@]}" | LC_ALL=C sort)" ]] || bad "the $n shares are not the whole list of ordinary tests, each once (sizes: $sizes)"
        ((max - min <= 1)) || bad "the $n shares differ by more than one test ($sizes)"
        [[ "${SHARD_TESTS[0]%% *}" == "${all[0]}" ]] || bad "share 0 of $n does not start with the first test"
        mapfile -t share < <(shard_of "$n" 0 "${all[@]}")
        [[ "${share[*]}" == "${SHARD_TESTS[0]}" ]] || bad "share 0 of $n is not what shard_of gives for it"
    done
    # A shard_of that loses a test, that gives one to two workers, or that gives one worker everything, is found out.
    rc=0
    out="$( (
        shard_of() { local c="$1" i="$2" id n=0; shift 2; for id in "$@"; do if ((n % c == i && n > 0)); then printf '%s\n' "$id"; fi; n=$((n + 1)); done; }
        plan_ordinary_shards 3
    ) 2>&1)" || rc=$?
    [[ "$rc" != 0 && "$out" == *'is assigned to no ordinary worker'* ]] || bad "a shard_of that loses a test was accepted (exit $rc): ${out:0:200}"
    rc=0
    out="$( (
        shard_of() { local c="$1" i="$2" id n=0; shift 2; if ((i > 0)); then printf '%s\n' "$1"; fi; for id in "$@"; do if ((n % c == i)); then printf '%s\n' "$id"; fi; n=$((n + 1)); done; }
        plan_ordinary_shards 3
    ) 2>&1)" || rc=$?
    [[ "$rc" != 0 && "$out" == *'is assigned to ordinary workers'* ]] || bad "a shard_of that gives a test to two workers was accepted (exit $rc): ${out:0:200}"
    rc=0
    out="$( (
        shard_of() { local c="$1" i="$2"; shift 2; if ((i == 0)); then printf '%s\n' "$@"; fi; }
        plan_ordinary_shards 3
    ) 2>&1)" || rc=$?
    [[ "$rc" != 0 && "$out" == *'would be given no test'* ]] || bad "a shard_of that leaves a worker with nothing was accepted (exit $rc): ${out:0:200}"
}

# An ordinary-test worker is chosen by arguments and by nothing else, and a malformed assignment is refused (exit 2) before anything runs: no result, no record.
t_ordinary_worker_arguments_are_checked() {
    local out rc args records="$T/ordinary-args.records"
    for args in '2 2 t_g1_host' 'x 2 t_g1_host' '-1 2 t_g1_host' '0 0 t_g1_host' '0 1' '0' '' '0 1 t_no_such_test' '0 1 g1_host' '0 1 t_g1_host t_g1_host' '0 1 ../x' '0 1 run_test' '0 1 bad' '0 1 G1-host' '0 1 t_g1_host;touch'; do
        rc=0
        : >"$records"
        # shellcheck disable=SC2086 # the words are the point
        out="$(timeout 120 bash "$THIS_SCRIPT" --tests-worker $args 3>"$records" 2>&1)" || rc=$?
        [[ "$rc" == 2 ]] || bad "a tests worker given '$args' exited $rc, not 2 (it printed: ${out:0:200})"
        [[ "$out" != *'  ok '* && "$out" != *REHEARSAL_SUMMARY* && ! -s "$records" ]] || bad "a tests worker given '$args' ran a test or wrote a record: ${out:0:200}"
    done
    # Without a record descriptor it is not being run by a routine run, and runs nothing.
    rc=0
    out="$(timeout 120 bash "$THIS_SCRIPT" --tests-worker 0 1 t_g1_host 3>&- 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" == *'no record file on descriptor 3'* && "$out" != *'  ok '* ]] || bad "a tests worker with no record descriptor ran, or was not refused (exit $rc): ${out:0:200}"
    # It is not a second way into the matrix's worker, nor the matrix's worker a way into it; and an option beside it is refused with the rest.
    rc=0
    out="$(timeout 120 bash "$THIS_SCRIPT" --worker 0 1 t_g1_host 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" != *'  ok '* ]] || bad "the mutation worker accepted a test name (exit $rc): ${out:0:200}"
    rc=0
    out="$(timeout 60 bash "$THIS_SCRIPT" --routine --tests-worker 0 1 t_g1_host 3>"$records" 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" == *'more than one option'* && "$out" != *'  ok '* && ! -s "$records" ]] || bad "--routine beside --tests-worker was not refused (exit $rc): ${out:0:200}"
    # Nothing in the environment makes a run a worker or a different kind of run.
    rc=0
    out="$(env -u REHEARSAL_TESTS REHEARSAL_TESTS_WORKER=1 REHEARSAL_WORKER=1 REHEARSAL_WORKERS=17 timeout 60 bash "$THIS_SCRIPT" --routine 2>&1)" || rc=$?
    [[ "$rc" == 2 && "$out" == *'REHEARSAL_WORKERS must be a whole number'* && "$out" != *'  ok '* ]] || bad "an inherited variable changed what --routine did (exit $rc): ${out:0:200}"
}

# What the parent concludes from one worker's records: right only when every line is a record, each assigned test is reported once and nothing else, and the
# closing record is there once, last, with the counts of the results, and the exit status agrees. Each way of being wrong is a problem of its own.
t_ordinary_worker_records_are_checked() {
    local ok_a='RESULT t_fake_a ok' ok_b='RESULT t_fake_b ok' fail_b='RESULT t_fake_b fail' done_ok='DONE 1 3 2 2 0'
    local -A ORDINARY_VERDICT=()
    ordinary_case() { # DESC STATUS RECORDS EXPECTED_SUBSTRING ('' = right) [STOPPED]
        ORDINARY_VERDICT=()
        ordinary_worker_problems 1 3 't_fake_a t_fake_b' "$2" "$3" "${5:-}"
        if [[ -z "$4" ]]; then
            [[ -z "$WORKER_PROBLEMS" ]] || bad "$1: a right worker was reported as wrong: ${WORKER_PROBLEMS:0:300}"
        else
            [[ "$WORKER_PROBLEMS" == *"$4"* ]] || bad "$1: expected a problem mentioning '$4', got: ${WORKER_PROBLEMS:0:300}"
        fi
    }
    ordinary_case 'a right worker' 0 "$ok_a"$'\n'"$ok_b"$'\n'"$done_ok" ''
    [[ "${ORDINARY_VERDICT[t_fake_a]:-}" == ok && "${ORDINARY_VERDICT[t_fake_b]:-}" == ok ]] || bad 'the results of a right worker were not kept'
    ordinary_case 'a failure, honestly reported' 1 "$ok_a"$'\n'"$fail_b"$'\n''DONE 1 3 2 1 1' ''
    [[ "${ORDINARY_VERDICT[t_fake_b]:-}" == fail && "$WORKER_TEST_FAILURES" == 1 ]] || bad 'an honestly reported failure was not kept as one'
    ordinary_case 'a failure reported with exit 0' 0 "$ok_a"$'\n'"$fail_b"$'\n''DONE 1 3 2 1 1' 'exited 0 though it reported 1 failed'
    ordinary_case 'a bad exit after a clean report' 2 "$ok_a"$'\n'"$ok_b"$'\n'"$done_ok" 'exited with status 2 though it reported no failed'
    ordinary_case 'a test missing, closing record that says so' 1 "$ok_a"$'\n''DONE 1 3 2 1 0' 'produced no result for: fake_b'
    ordinary_case 'a test missing, closing record that hides it' 0 "$ok_a"$'\n'"$done_ok" 'produced no result for: fake_b'
    ordinary_case 'a test reported twice' 0 "$ok_a"$'\n'"$ok_a"$'\n'"$ok_b"$'\n'"$done_ok" 'fake_a more than once'
    ordinary_case 'a test nobody assigned' 0 "$ok_a"$'\n'"$ok_b"$'\n''RESULT t_fake_c ok'$'\n'"$done_ok" 'fake_c, which was not assigned'
    ordinary_case 'no closing record' 137 "$ok_a" 'did not finish'
    ordinary_case 'no record at all' 0 '' 'produced no result for: fake_a fake_b'
    ordinary_case 'a closing record twice' 0 "$ok_a"$'\n'"$ok_b"$'\n'"$done_ok"$'\n'"$done_ok" 'closing record 2 times'
    ordinary_case 'counts that do not match the results' 0 "$ok_a"$'\n'"$ok_b"$'\n''DONE 1 3 2 1 1' 'closed with'
    ordinary_case 'a closing record for another worker' 0 "$ok_a"$'\n'"$ok_b"$'\n''DONE 0 3 2 2 0' 'closed with 0/3'
    ordinary_case 'a result after the closing record' 0 "$ok_a"$'\n'"$ok_b"$'\n'"$done_ok"$'\n'"$ok_b" 'after its closing record'
    ordinary_case 'a line that is not a record' 0 "$ok_a"$'\n''  ok    fake_b'$'\n'"$ok_b"$'\n'"$done_ok" 'wrote a record that is not one'
    ordinary_case 'a record with the right words and the wrong shape' 0 "$ok_a"$'\n''RESULT t_fake_b maybe'$'\n'"$ok_b"$'\n'"$done_ok" 'wrote a record that is not one'
    ordinary_case 'a worker that was stopped' 143 "$ok_a" 'was stopped after 7 seconds' 7
    ordinary_case 'a worker that was stopped, whatever it wrote' 143 "$ok_a"$'\n'"$ok_b"$'\n'"$done_ok" 'was stopped after 7 seconds' 7
    unset -f ordinary_case
}

# A launcher that starts stand-in workers (call it in a subshell): worker FAULTY of the run does SCENARIO, written to its record file as a faulty worker would, and
# the others are right. The stand-in leads a process group of its own, as the real one does. FAKE_PIDS (a file) collects the pids a hanging stand-in starts.
# shellcheck disable=SC2016 # the text is a script for the stand-in worker's own bash, which expands it
FAKE_WORKER='
scenario=$1; shift
all=("$@"); n=${#all[@]}
rec() { printf "%s\n" "$*" >&3; }
results() { local t; for t in "${all[@]}"; do rec "RESULT $t ok"; done; }
case "$scenario" in
    ok) results; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    honest-failure) rec "RESULT ${all[0]} fail"; rec "RESULT ${all[1]} ok"; rec "DONE $FAKE_INDEX $FAKE_COUNT $n 1 1"; exit 1 ;;
    exit-nonzero) results; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0"; exit 1 ;;
    fail-exit-zero) rec "RESULT ${all[0]} fail"; rec "RESULT ${all[1]} ok"; rec "DONE $FAKE_INDEX $FAKE_COUNT $n 1 1" ;;
    missing) rec "RESULT ${all[0]} ok"; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    duplicate) rec "RESULT ${all[0]} ok"; results; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    unassigned) results; rec "RESULT t_fake_a ok"; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    other-names) rec "RESULT t_fake_x ok"; rec "RESULT t_fake_y ok"; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    no-closing-record) results ;;
    killed) rec "RESULT ${all[0]} ok"; kill -KILL $$ ;;
    forged-output) printf "  ok    fake_b\n  ok    fake_d\nRESULT t_fake_b ok\nRESULT t_fake_d ok\nDONE $FAKE_INDEX $FAKE_COUNT $n $n 0\n" ;;
    garbage) results; echo "hello" >&3; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    result-after-done) results; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0"; rec "RESULT ${all[0]} ok" ;;
    done-twice) results; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0"; rec "DONE $FAKE_INDEX $FAKE_COUNT $n $n 0" ;;
    bad-counts) results; rec "DONE $FAKE_INDEX $FAKE_COUNT $n 1 1" ;;
    wrong-index) results; rec "DONE 0 $FAKE_COUNT $n $n 0" ;;
    never-ran) exit 2 ;;
    hang) rec "RESULT ${all[0]} ok"; sleep 300 & echo $! >>"$FAKE_PIDS"; echo $$ >>"$FAKE_PIDS"; sleep 300 ;;
    stubborn) trap "" TERM; rec "RESULT ${all[0]} ok"; sleep 300 & echo $! >>"$FAKE_PIDS"; echo $$ >>"$FAKE_PIDS"; while :; do sleep 1; done ;;
    orphan) rec "RESULT ${all[0]} ok"; ( trap "" TERM; sleep 300 ) & echo $! >>"$FAKE_PIDS"; echo $$ >>"$FAKE_PIDS"; sleep 300 ;;
esac
'
FAKE_FAULTY=1 FAKE_SCENARIO=ok FAKE_PIDS=/dev/null
stand_in_ordinary_workers() { # FAULTY SCENARIO
    FAKE_FAULTY="$1" FAKE_SCENARIO="$2"
    start_ordinary_worker() { # INDEX COUNT RECORDS OUTPUT NAME...
        local index="$1" count="$2" records="$3" output="$4" scenario=ok
        shift 4
        [[ "$index" != "$FAKE_FAULTY" ]] || scenario="$FAKE_SCENARIO"
        FAKE_INDEX="$index" FAKE_COUNT="$count" FAKE_PIDS="$FAKE_PIDS" setsid bash -c "$FAKE_WORKER" fake "$scenario" "$@" 3>"$records" >"$output" 2>&1 </dev/null &
        STARTED_PID=$!
    }
}
# stand_in_routine OUTFILE FAULTY SCENARIO: a routine run of four stand-in tests in two stand-in workers, as the harness would run it, written to OUTFILE; its status.
stand_in_routine() {
    local rc=0
    (
        stand_in_ordinary_workers "$2" "$3"
        ORDINARY_TESTS=(t_fake_a t_fake_b t_fake_c t_fake_d)
        rehearsal_routine 2
    ) >"$1" 2>&1 || rc=$?
    return "$rc"
}

# The parent against workers that go wrong. Each fault of worker 1 of 2 fails the run, and is said to, whatever its status was; a right worker beside it does not hide it;
# and an honest failure is a failure of that test and not of the worker. Four stand-in tests, in two stand-in workers: (fake_a fake_c) and (fake_b fake_d).
# shellcheck disable=SC2329 # the faulty shard_of is defined in subshells and called by the code under test
t_ordinary_a_worker_that_goes_wrong_fails_the_run() {
    local out rc c scenario want out_summary
    local -a cases=(
        'honest-failure|FAIL  fake_b|result=failed ordinary_run=4 ordinary_failed=1'
        'exit-nonzero|exited with status 1 though it reported no failed test|result=failed ordinary_run=4 ordinary_failed=0'
        'fail-exit-zero|exited 0 though it reported 1 failed test(s)|result=failed ordinary_run=4 ordinary_failed=1'
        'missing|produced no result for: fake_d|result=failed ordinary_run=3 ordinary_failed=0'
        'duplicate|reported fake_b more than once|result=failed ordinary_run=4 ordinary_failed=0'
        'unassigned|reported fake_a, which was not assigned to it|result=failed ordinary_run=4 ordinary_failed=0'
        'other-names|reported fake_x, which was not assigned to it|result=failed ordinary_run=2 ordinary_failed=0'
        'no-closing-record|did not finish|result=failed ordinary_run=4 ordinary_failed=0'
        'killed|did not finish|result=failed ordinary_run=3 ordinary_failed=0'
        'forged-output|produced no result for: fake_b fake_d|result=failed ordinary_run=2 ordinary_failed=0'
        'garbage|wrote a record that is not one|result=failed ordinary_run=4 ordinary_failed=0'
        'result-after-done|after its closing record|result=failed ordinary_run=4 ordinary_failed=0'
        'done-twice|closing record 2 times|result=failed ordinary_run=4 ordinary_failed=0'
        'bad-counts|closed with 1/2 assigned=2 passed=1 failed=1|result=failed ordinary_run=4 ordinary_failed=0'
        'wrong-index|closed with 0/2|result=failed ordinary_run=4 ordinary_failed=0'
        'never-ran|produced no result for: fake_b fake_d|result=failed ordinary_run=2 ordinary_failed=0'
    )
    # The control: the same stand-ins, all right, are accepted, so that the failures below are the scenarios' and not the stand-ins'.
    rc=0
    stand_in_routine "$T/ordinary-fake.out" 1 ok || rc=$?
    out="$(<"$T/ordinary-fake.out")"
    [[ "$rc" == 0 ]] || bad "four stand-in tests in two right stand-in workers did not pass (exit $rc): ${out:0:400}"
    expect_has 'the control run' "$out" 'result=passed ordinary_run=4 ordinary_failed=0'
    expect_has 'the control says how it ran' "$out" 'Starting 2 ordinary-test worker(s) for 4 test(s)'
    expect_has 'the control reports each test, in order' "$out" $'  ok    fake_a\n  ok    fake_b\n  ok    fake_c\n  ok    fake_d'
    for c in "${cases[@]}"; do
        IFS='|' read -r scenario want out_summary <<<"$c"
        rc=0
        stand_in_routine "$T/ordinary-fake.out" 1 "$scenario" || rc=$?
        out="$(<"$T/ordinary-fake.out")"
        [[ "$rc" == 1 ]] || bad "$scenario: the run exited $rc, not 1: ${out:0:300}"
        expect_has "$scenario" "$out" "$want"
        expect_has "$scenario (summary)" "$out" "$out_summary"
        expect_lacks "$scenario (no success line)" "$out" 'Routine rehearsal checks passed'
        [[ "$(grep -c '^REHEARSAL_SUMMARY' <<<"$out")" == 1 ]] || bad "$scenario: the run did not print exactly one summary line"
    done
    # A fault of the other worker is found as well (the first is not the only one looked at).
    rc=0
    stand_in_routine "$T/ordinary-fake.out" 0 killed || rc=$?
    out="$(<"$T/ordinary-fake.out")"
    [[ "$rc" == 1 && "$out" == *'ordinary worker 0/2 did not finish'* ]] || bad "a fault of worker 0 was not found (exit $rc): ${out:0:300}"
    # The list of tests is checked before any worker is started: shares that are not the whole list, a list with nothing in it or with a test twice, are failures that
    # start nothing; and fewer tests than workers means fewer workers, not an idle one.
    rc=0
    out="$( (
        stand_in_ordinary_workers 1 ok
        shard_of() { local c="$1" i="$2" id n=0; shift 2; for id in "$@"; do if ((n % c == i && n > 0)); then printf '%s\n' "$id"; fi; n=$((n + 1)); done; }
        ORDINARY_TESTS=(t_fake_a t_fake_b t_fake_c t_fake_d)
        rehearsal_routine 2
    ) 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'is assigned to no ordinary worker'* && "$out" != *'Starting'* && "$out" == *'result=failed ordinary_run=0'* ]] || bad "shares that lose a test did not fail the run before it started (exit $rc): ${out:0:300}"
    rc=0
    out="$( (stand_in_ordinary_workers 1 ok; ORDINARY_TESTS=(t_fake_a t_fake_a); rehearsal_routine 2) 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'appears twice'* && "$out" != *'Starting'* && "$out" != *'Routine rehearsal checks passed'* ]] || bad "a test listed twice was not refused before the workers started (exit $rc): ${out:0:300}"
    rc=0
    out="$( (stand_in_ordinary_workers 1 ok; ORDINARY_TESTS=(); rehearsal_routine 2) 2>&1)" || rc=$?
    [[ "$rc" == 1 && "$out" == *'there are no ordinary tests to run'* && "$out" != *'Starting'* && "$out" != *'Routine rehearsal checks passed'* ]] || bad "a run of no tests succeeded with workers (exit $rc): ${out:0:300}"
    rc=0
    out="$( (stand_in_ordinary_workers 1 ok; ORDINARY_TESTS=(t_fake_a t_fake_b); rehearsal_routine 4) 2>&1)" || rc=$?
    [[ "$rc" == 0 && "$out" == *'Starting 2 ordinary-test worker(s) for 2 test(s)'* && "$out" == *'result=passed ordinary_run=2 ordinary_failed=0'* ]] || bad "four workers for two tests did not become two (exit $rc): ${out:0:300}"
    # What a failing worker printed is shown to the reader, marked so that it cannot be taken for a line of the run's own.
    rc=0
    stand_in_routine "$T/ordinary-fake.out" 1 forged-output || rc=$?
    out="$(<"$T/ordinary-fake.out")"
    [[ "$out" != *$'\n  ok    fake_b'* ]] || bad "a worker's printed '  ok' line was reported as a result of the run: ${out:0:300}"
}

# A worker that hangs is stopped after the longest a routine run waits for one, with whatever it started, and the run fails; so is one that ignores TERM (it is killed
# after the grace period), and one that ends at TERM but leaves a process behind that does not. Nothing is left running, and the run says which worker and how far it got.
t_ordinary_a_worker_that_hangs_is_stopped_with_what_it_started() {
    local out rc scenario pid pids gone
    for scenario in hang stubborn orphan; do
        : >"$T/ordinary-hang.pids"
        rc=0
        out="$( (
            ORDINARY_TIMEOUT=3 ORDINARY_GRACE=2 FAKE_PIDS="$T/ordinary-hang.pids"
            stand_in_ordinary_workers 1 "$scenario"
            ORDINARY_TESTS=(t_fake_a t_fake_b t_fake_c t_fake_d)
            rehearsal_routine 2
        ) 2>&1)" || rc=$?
        [[ "$rc" == 1 ]] || bad "$scenario: a run with a hung worker exited $rc, not 1: ${out:0:300}"
        expect_has "$scenario" "$out" 'ordinary worker 1/2 was stopped after 3 seconds'
        expect_has "$scenario (how far it got)" "$out" 'with 1 of its 2 tests reported'
        expect_has "$scenario (its summary)" "$out" 'result=failed ordinary_run=3 ordinary_failed=0'
        expect_lacks "$scenario (no success line)" "$out" 'Routine rehearsal checks passed'
        pids="$(<"$T/ordinary-hang.pids")"
        [[ "$(grep -c . <<<"$pids")" == 2 ]] || bad "$scenario: the stand-in did not start what this test looks for: $pids"
        for pid in $pids; do
            gone=0
            for _ in {1..30}; do
                if ! kill -0 "$pid" 2>/dev/null; then gone=1; break; fi
                sleep 0.1
            done
            ((gone == 1)) || { bad "$scenario: process $pid (the worker, or what it started) was left running"; kill -KILL "$pid" 2>/dev/null || true; }
        done
    done
}

# Workers started as the parent starts them, on real tests: each leads a process group, has a temporary directory of its own inside the parent's scratch directory (and
# removes it), runs only what it was given, writes exactly the records that say so, and cannot be made to write another by anything a test starts.
t_ordinary_workers_are_isolated() {
    local -a ids=(G1-context-env G1-host G1-tls G2-env G1-context G1-endpoint) tests=() first=() second=() names
    local id t copy="$T/ordinary-iso-src" pid_a pid_b dir_a dir_b seen=0 pg_a pg_b rc_a=0 rc_b=0 out_a out_b rec_a rec_b
    local -A ORDINARY_VERDICT=()
    for id in "${ids[@]}"; do
        [[ -n "${GUARD_TESTS[$id]:-}" ]] || bad "the guard $id used by this test is not registered"
        for t in ${GUARD_TESTS[$id]}; do [[ " ${tests[*]} " == *" $t "* ]] || tests+=("$t"); done
    done
    mapfile -t first < <(shard_of 2 0 "${tests[@]}")
    mapfile -t second < <(shard_of 2 1 "${tests[@]}")
    rm -rf "$T/scratch-0" "$T/scratch-1"
    start_ordinary_worker 0 2 "$T/iso-0.records" "$T/iso-0.out" "${first[@]}"
    pid_a="$STARTED_PID"
    start_ordinary_worker 1 2 "$T/iso-1.records" "$T/iso-1.out" "${second[@]}"
    pid_b="$STARTED_PID"
    for _ in {1..200}; do
        dir_a="$(readlink "/proc/$pid_a/cwd" 2>/dev/null || true)" dir_b="$(readlink "/proc/$pid_b/cwd" 2>/dev/null || true)"
        pg_a="$(pgid_of "$pid_a" || true)" pg_b="$(pgid_of "$pid_b" || true)"
        if [[ "$dir_a" == "$T/scratch-0"/*/cwd && "$dir_b" == "$T/scratch-1"/*/cwd && "$dir_a" != "$dir_b" ]]; then seen=2; break; fi
        sleep 0.05
    done
    ((seen == 2)) || bad "the two workers were never seen running at once, each in a temporary directory of its own inside the parent's scratch directory ($dir_a, $dir_b)"
    [[ "$pg_a" == "$pid_a" && "$pg_b" == "$pid_b" ]] || bad "a worker does not lead a process group of its own (pids $pid_a $pid_b, groups $pg_a $pg_b)"
    { wait "$pid_a" || rc_a=$?; } 2>/dev/null
    { wait "$pid_b" || rc_b=$?; } 2>/dev/null
    [[ "$rc_a" == 0 && "$rc_b" == 0 ]] || bad "right workers on right tests exited $rc_a and $rc_b"
    out_a="$(<"$T/iso-0.out")" out_b="$(<"$T/iso-1.out")" rec_a="$(<"$T/iso-0.records")" rec_b="$(<"$T/iso-1.records")"
    [[ -z "$(find "$T/scratch-0" "$T/scratch-1" -mindepth 1 2>/dev/null)" ]] || bad "a worker left its temporary directory behind: $(find "$T/scratch-0" "$T/scratch-1" -mindepth 1 | head -n 3 | tr '\n' ' ')"
    ordinary_worker_problems 0 2 "${first[*]}" "$rc_a" "$rec_a"
    [[ -z "$WORKER_PROBLEMS" ]] || bad "worker 0 of 2 was found wrong: ${WORKER_PROBLEMS:0:300}"
    ordinary_worker_problems 1 2 "${second[*]}" "$rc_b" "$rec_b"
    [[ -z "$WORKER_PROBLEMS" ]] || bad "worker 1 of 2 was found wrong: ${WORKER_PROBLEMS:0:300}"
    # Each ran its own tests and no other, and what it printed says the same as what it recorded; and it never prints the summary line.
    for t in "${first[@]}"; do
        if ! grep -qxF "  ok    ${t#t_}" <<<"$out_a" || grep -qxF "  ok    ${t#t_}" <<<"$out_b"; then bad "$t was not run by worker 0 alone"; fi
    done
    for t in "${second[@]}"; do
        if ! grep -qxF "  ok    ${t#t_}" <<<"$out_b" || grep -qxF "  ok    ${t#t_}" <<<"$out_a"; then bad "$t was not run by worker 1 alone"; fi
    done
    [[ "$out_a$out_b" != *REHEARSAL_SUMMARY* ]] || bad 'a tests worker printed the summary line'
    [[ "$(grep -c '^RESULT ' <<<"$rec_a")" == "${#first[@]}" && "$(grep -c '^DONE ' <<<"$rec_a")" == 1 && "$(grep -vc '^RESULT \|^DONE ' <<<"$rec_a" || true)" == 0 ]] || bad "worker 0's records are not its results and one closing record: $rec_a"
    # A worker has every function the tests call, those that run the modes included (it once stopped short of them, and these tests failed in it, not in the parent).
    rc_a=0
    mkdir -p "$T/scratch-0"
    start_ordinary_worker 0 1 "$T/iso-modes.records" "$T/iso-modes.out" t_modes_routine_fails_closed t_ordinary_worker_count t_ordinary_shares_are_a_partition
    { wait "$STARTED_PID" || rc_a=$?; } 2>/dev/null
    [[ "$rc_a" == 0 && "$(<"$T/iso-modes.records")" == *'DONE 0 1 3 3 0' ]] || bad "a worker could not run the tests that call the functions of the modes (exit $rc_a): $(grep -v '^  ok ' "$T/iso-modes.out" | head -n 4 | cut -c1-200 | tr '\n' '|')"
    # The records are the worker's alone: a test that tries to write them (the script a test runs does, from a copy made here) leaves them as they were.
    rm -rf "$copy" "$T/scratch-0"
    cp -r "$SRC" "$copy"
    sed -i '1a { echo "RESULT t_g1_tls ok" >\&9; echo "RESULT t_g1_tls ok" >\&3; echo "DONE 0 1 1 1 0" >\&3; } 2>/dev/null || true' "$copy/restore-rehearsal.sh"
    rc_a=0
    mkdir -p "$T/scratch-0"
    SRC="$copy" start_ordinary_worker 0 1 "$T/iso-forged.records" "$T/iso-forged.out" t_g1_host
    { wait "$STARTED_PID" || rc_a=$?; } 2>/dev/null
    [[ "$(<"$T/iso-forged.records")" == $'RESULT t_g1_host ok\nDONE 0 1 1 1 0' ]] || bad "something a test ran changed the worker's records: $(tr '\n' '|' <"$T/iso-forged.records")"
}

# routine_run_against DIR WORKERS: rehearsal_routine, with DIR as the script under test (for the ordinary tests, the registry and the workers it starts).
routine_run_against() {
    local SRC="$1" SUT="$1"
    rehearsal_routine "$2"
}

# The run's meaning does not depend on how it is carried out: the same tests, one of them broken by a mutation of the script under test, give the same verdicts, the same
# summary and the same exit status in this process (0 workers) and in 2, 3 and 4 workers; and so does an unbroken run. The tests are real, and the mutation is a guard
# removed from a copy, as the matrix removes it (the marker stays, so the registry still agrees with itself).
t_ordinary_sequential_and_sharded_runs_agree() {
    local -a ids=(G1-context-env G1-host G1-tls G1-context G1-endpoint) tests=()
    local id t n rc variant broken want_failed copy="$T/ordinary-eq-src" out seq_verdicts seq_summary seq_rc verdicts summary
    for id in "${ids[@]}"; do
        [[ -n "${GUARD_TESTS[$id]:-}" ]] || bad "the guard $id used by this test is not registered"
        for t in ${GUARD_TESTS[$id]}; do [[ " ${tests[*]} " == *" $t "* ]] || tests+=("$t"); done
    done
    ((${#tests[@]} >= 5)) || bad "too few tests (${#tests[@]}) to share among four workers"
    rm -rf "$copy"
    cp -r "$SRC" "$copy"
    sed -i 's/^.*# guard:G1-host$/: # guard:G1-host/; s/^.*# guard:G1-tls$/: # guard:G1-tls/' "$copy/restore-rehearsal.sh"
    cmp -s "$SRC/restore-rehearsal.sh" "$copy/restore-rehearsal.sh" && bad 'the mutation changed nothing'
    # The tests the two removed guards are tested by are the ones that must fail (the registry says which; both of them are in the list above). (G2-env's would
    # do as well, but its mutant takes twenty seconds to run, and this one is run seven times.)
    want_failed="$(for t in ${GUARD_TESTS[G1-host]} ${GUARD_TESTS[G1-tls]}; do printf '  FAIL  %s\n' "${t#t_}"; done | LC_ALL=C sort)"
    broken="$(grep -c . <<<"$want_failed")"
    for variant in mutated intact; do
        seq_rc='' seq_verdicts='' seq_summary=''
        for n in 0 2 3 4; do
            [[ "$variant" == intact && "$n" != 0 && "$n" != 2 ]] && continue
            rc=0
            out="$( (
                ORDINARY_TESTS=("${tests[@]}")
                if [[ "$variant" == mutated ]]; then routine_run_against "$copy" "$n"; else rehearsal_routine "$n"; fi
            ) 2>&1)" || rc=$?
            verdicts="$(grep -E '^  (ok|FAIL) ' <<<"$out" | LC_ALL=C sort)"
            summary="$(grep '^REHEARSAL_SUMMARY' <<<"$out" || true)"
            if [[ "$variant" == mutated ]]; then
                [[ "$rc" == 1 ]] || bad "$variant, $n workers: exit $rc, not 1: ${out:0:300}"
                [[ "$summary" == *"ordinary_run=${#tests[@]} ordinary_failed=$broken "* ]] || bad "$variant, $n workers: the summary does not say $broken of ${#tests[@]} failed: $summary"
                [[ "$(grep '^  FAIL' <<<"$verdicts")" == "$want_failed" ]] || bad "$variant, $n workers: the failing tests are not exactly those of the removed guards: $(grep '^  FAIL' <<<"$verdicts" | tr '\n' ' ')"
            else
                [[ "$rc" == 0 && "$summary" == *"result=passed ordinary_run=${#tests[@]} ordinary_failed=0 "* ]] || bad "$variant, $n workers: an unbroken run did not pass (exit $rc): ${out:0:300}"
            fi
            [[ "$summary" != *workers=* && "$(grep -c '^REHEARSAL_SUMMARY' <<<"$out")" == 1 ]] || bad "$variant, $n workers: the summary is not the single line of the sequential run's form: $summary"
            if ((n == 0)); then
                seq_rc="$rc" seq_verdicts="$verdicts" seq_summary="$summary"
                [[ "$out" != *'ordinary-test worker'* ]] || bad "$variant: a run of 0 workers started workers"
            else
                [[ "$rc" == "$seq_rc" && "$verdicts" == "$seq_verdicts" && "$summary" == "$seq_summary" ]] || bad "$variant: $n workers and 0 workers did not agree: exit $rc/$seq_rc, [$verdicts] / [$seq_verdicts], [$summary] / [$seq_summary]"
                [[ "$out" == *"Starting $n ordinary-test worker(s)"* || "$n" -gt "${#tests[@]}" ]] || bad "$variant, $n workers: the run does not say it started them"
            fi
        done
    done
}

# An interrupted routine run is not a success and leaves nothing behind: TERM (and INT, which a terminal sends) to the parent, while its workers are at work on real
# tests, ends it with a nonzero status and no summary line, and every worker, everything a worker started and every temporary directory is gone.
t_ordinary_an_interrupted_run_leaves_nothing() {
    local sig tmp="$T/ordinary-int" parent out="$T/ordinary-int.out" rc pids pid group left
    for sig in TERM INT; do
        rm -rf "$tmp"
        mkdir -p "$tmp"
        rc=0
        TMPDIR="$tmp" REHEARSAL_WORKERS=2 env --default-signal=INT,TERM -u REHEARSAL_TESTS bash "$THIS_SCRIPT" --routine >"$out" 2>&1 &
        parent=$!
        pids=''
        for _ in {1..200}; do
            pids="$(for pid in $(pgrep -P "$parent" 2>/dev/null || true); do tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -q -- '--tests-worker' && echo "$pid"; done)"
            [[ "$(grep -c . <<<"$pids")" == 2 ]] && break
            sleep 0.1
        done
        [[ "$(grep -c . <<<"$pids")" == 2 ]] || { bad "$sig: the run did not start two workers (found: $pids)"; kill -KILL "$parent" 2>/dev/null || true; continue; }
        sleep 2 # until they are running tests, and what the tests start
        group=''
        for pid in $pids; do group+=" $(pgrep -g "$pid" | tr '\n' ' ')"; done
        kill "-$sig" "$parent"
        { wait "$parent" || rc=$?; } 2>/dev/null
        ((rc != 0)) || bad "$sig: an interrupted run exited 0"
        [[ "$(<"$out")" != *REHEARSAL_SUMMARY* && "$(<"$out")" != *'Routine rehearsal checks passed'* ]] || bad "$sig: an interrupted run printed a summary or a success line"
        left=''
        for pid in $group; do ! kill -0 "$pid" 2>/dev/null || left+=" $pid"; done
        [[ -z "$left" ]] || { bad "$sig: processes of the run were left running:$left"; for pid in $left; do kill -KILL "$pid" 2>/dev/null || true; done; }
        [[ -z "$(find "$tmp" -mindepth 1 2>/dev/null)" ]] || bad "$sig: temporary files were left behind: $(find "$tmp" -mindepth 1 | head -n 3 | tr '\n' ' ')"
    done
    rm -rf "$tmp"
}

# The summary of a sharded routine run is the line of every routine run: the outer verification (check.sh's rehearsal_verified, unchanged) accepts a passing one, and
# refuses a failing one, one of a worker that went wrong, and a run that printed no summary at all.
t_ordinary_the_outer_verification_accepts_a_sharded_summary() {
    local out rc=0 file="$T/ordinary-outer.out" case_
    stand_in_routine "$file" 1 ok || rc=$?
    [[ "$rc" == 0 ]] || bad "the stand-in routine run failed: $(<"$file")"
    [[ "$(grep -c '^REHEARSAL_SUMMARY' "$file")" == 1 && "$(grep '^REHEARSAL_SUMMARY' "$file")" != *workers=* ]] || bad "the sharded summary is not the routine run's single line: $(grep '^REHEARSAL_SUMMARY' "$file")"
    verified() { FLOW_ROOT="$ROOT" bash -c 'source "$FLOW_ROOT/scripts/lib/common.sh"; source "$FLOW_ROOT/scripts/commands/check.sh"; rehearsal_verified "$@"' x routine cat "$1" 2>&1; }
    rc=0
    out="$(verified "$file")" || rc=$?
    [[ "$rc" == 0 && "$out" == *'Rehearsal summary verified (routine): 4 ordinary test(s) passed'* ]] || bad "the outer verification did not accept a passing sharded run (exit $rc): ${out:0:300}"
    for case_ in honest-failure killed missing forged-output; do
        stand_in_routine "$file" 1 "$case_" || true
        rc=0
        out="$(verified "$file")" || rc=$?
        [[ "$rc" != 0 && "$out" != *'Rehearsal summary verified'* ]] || bad "the outer verification accepted a sharded run with a worker that was $case_ (exit $rc): ${out:0:300}"
    done
    # Exactly one summary: a worker's output, replayed, cannot add another.
    stand_in_routine "$file" 1 forged-output || true
    [[ "$(grep -c '^REHEARSAL_SUMMARY' "$file")" == 1 ]] || bad 'a failing worker could add a summary line'
    unset -f verified
}

# How many workers: the default (CPUs, at most four, at most one per 512 MiB, and none when that leaves fewer than two), REHEARSAL_WORKERS in routine mode (0 is
# sequential, 1 to 16 explicit, anything else refused) and the routine run's choice between this process and workers, which the full run never makes.
# shellcheck disable=SC2329 # the stand-in functions are defined in subshells and called by the code under test
t_ordinary_worker_count() {
    local cpus mem want got value c out rc log="$T/ordinary-count.log"
    local -a cases=('1 16000 0' '2 16000 2' '3 16000 3' '4 16000 4' '5 16000 4' '16 16000 4' '64 16000 4' '16 1024 2' '16 1023 0' '4 1536 3' '4 100000 4' '0 16000 0' '1 100000 0')
    for c in "${cases[@]}"; do
        read -r cpus mem want <<<"$c"
        got="$(default_ordinary_workers "$cpus" "$mem")"
        [[ "$got" == "$want" ]] || bad "default_ordinary_workers $cpus $mem gave $got, not $want"
    done
    for value in 0 1 4 16; do
        got="$(REHEARSAL_WORKERS=$value ordinary_workers)" || bad "REHEARSAL_WORKERS=$value was refused"
        [[ "$got" == "$value" ]] || bad "REHEARSAL_WORKERS=$value gave $got workers"
    done
    for value in '' 17 -1 abc 1.5 ' 2' '01' '1;2'; do
        rc=0
        got="$(REHEARSAL_WORKERS="$value" ordinary_workers 2>&1)" || rc=$?
        [[ "$rc" == 2 && "$got" == *'REHEARSAL_WORKERS must be a whole number'* ]] || bad "REHEARSAL_WORKERS='$value' was not refused (exit $rc): $got"
    done
    got="$( (unset REHEARSAL_WORKERS; ordinary_workers) )"
    [[ "$got" =~ ^[0-4]$ && "$got" != 1 ]] || bad "the default number of ordinary workers is '$got'"
    # The routine run shares the tests among workers only when asked to; 0 (and the full run, always) keep them in this process.
    rm -f "$log"
    # shellcheck disable=SC2016 # the stand-ins are defined in the subshell, which expands them
    out="$( (
        t_fake_a() { echo local >>"$log"; }
        run_ordinary_sharded() { echo "sharded $1" >>"$log"; ORDINARY_RAN=1 ORDINARY_FAILED=0; }
        ORDINARY_TESTS=(t_fake_a)
        rehearsal_routine 0 >/dev/null
        rehearsal_routine >/dev/null
        rehearsal_routine 3 >/dev/null
        prepare_caches() { :; }
        run_mutant() { printf '  ok    %s caught\n' "$1"; }
        REHEARSAL_WORKERS=0 rehearsal_full >/dev/null
    ) 2>&1)" || rc=$?
    [[ "$(tr '\n' ' ' <"$log")" == 'local local sharded 3 local ' ]] || bad "the routine and full runs did not choose between this process and workers as they should: $(tr '\n' ' ' <"$log") $out"
}

# The lists the half-failed rehearsal holds are the repository's: the tables, the migrations, the stub's statement, the fixture's counts, the fixed ids.
# shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
t_static_half_failed_lists() {
    local fixture="$ROOT/apps/platform/tests/Fixtures/rehearsal/half-failed-stub.sql" pest="$ROOT/apps/platform/tests/Upgrade/HalfFailedMigrationTest.php" t counts='' want got shape
    run_fn 'printf "%s\n" "${PARTIAL_NEWER_TABLES[@]}" | LC_ALL=C sort'; [[ "$OUT" == "$(printf '%s\n' "${PARTIAL_NEWER[@]}" | LC_ALL=C sort)" ]] || bad "the script's PARTIAL_NEWER_TABLES differ from the tests'"
    # The 15 + the stub + the two tables the failed migration never made are the 18 newer tables, with no overlap.
    want="$(printf '%s\n' "${NEWER_TABLES[@]}" | LC_ALL=C sort)"
    got="$(printf '%s\n' "${PARTIAL_NEWER[@]}" "$STUB_NAME" person_relationship_role_provisions sourced_role_grants | LC_ALL=C sort)"
    [[ "$got" == "$want" ]] || bad 'the 15 committed tables, the stub and the two never reached are not the 18 newer tables'
    [[ ${#PARTIAL_NEWER[@]} == 15 ]] || bad 'the half-failed state does not hold 15 committed newer tables'
    # The migrations: v0.3.0's 15, then the ten before Relationships, as the repository's files; Relationships is the 26th and is not in the ledger.
    run_fn 'printf "%s\n" "${HISTORICAL_MIGRATIONS[@]}"'; [[ "$OUT" == "$(migration_files | awk 'NR <= 15')" ]] || bad "the script's HISTORICAL_MIGRATIONS are not the first 15 migrations of the repository"
    run_fn 'printf "%s\n" "${PARTIAL_MIGRATIONS[@]}"'; [[ "$OUT" == "$(migration_files | awk 'NR > 15 && NR <= 25')" ]] || bad "the script's PARTIAL_MIGRATIONS are not migrations 16 to 25 of the repository"
    [[ "$(migration_files | awk 'NR == 26')" == 2026_10_09_000001_create_person_relationships_tables ]] || bad 'the 26th migration is not the Relationships migration: the failure point has moved'
    [[ "$(migration_files | wc -l)" == 29 ]] || bad 'the repository no longer has the 29 migrations the half-failed state was measured against'
    # The stub: one statement, the one the MariaDB test runs, for the table that migration creates third.
    run_fn 'printf "%s\n" "$STUB_SQL"'; [[ "$OUT" == "$(<"$fixture")" ]] || bad "the script's STUB_SQL is not tests/Fixtures/rehearsal/half-failed-stub.sql"
    run_fn 'printf "%s" "$STUB_TABLE"'; [[ "$OUT" == "$STUB_NAME" ]] || bad "the script's STUB_TABLE is not $STUB_NAME"
    [[ "$(grep -c . "$fixture")" == 1 && "$(grep -o ';' "$fixture" | wc -l)" == 1 ]] || bad 'the stub fixture is not exactly one statement'
    grep -qF "'flc-rehearsal-stub-s22c1'" "$fixture" || bad 'the stub fixture does not carry the marker comment the script requires'
    run_fn 'printf "%s" "$STUB_EXPECTED"'; [[ "$OUT" == "$STUB_ROW" ]] || bad "the script's STUB_EXPECTED is not the tests'"
    # The 14 the first upgrade's structure is compared with, and the one the later provenance migration also changes: together the 15, no overlap.
    run_fn 'printf "%s\n" "${PARTIAL_UNCHANGED_TABLES[@]}" "$PROVENANCE_TABLE" | LC_ALL=C sort'; [[ "$OUT" == "$(printf '%s\n' "${PARTIAL_NEWER[@]}" | LC_ALL=C sort)" ]] || bad 'the 14 tables compared with the first upgrade and the provenance table are not the 15 committed tables'
    run_fn 'printf "%s" "${#PARTIAL_UNCHANGED_TABLES[@]}"'; [[ "$OUT" == 14 ]] || bad 'the script does not compare 14 committed tables with the first upgrade'"'"'s structure'
    # The one that is different is the table the 29th migration adds a column to; the column is the one HalfFailedShape says the half-failed table lacks.
    shape="$ROOT/apps/platform/tests/Support/HalfFailedShape.php"
    run_fn 'printf "%s" "$PROVENANCE_TABLE"'; [[ "$OUT" == contact_methods ]] || bad 'the script'"'"'s PROVENANCE_TABLE is not contact_methods'
    grep -qF "public const TABLE = 'contact_methods';" "$shape" || bad "HalfFailedShape's table is not the script's PROVENANCE_TABLE"
    grep -qF "public const LATER_COLUMN = 'updated_by_account_id';" "$shape" || bad "HalfFailedShape's later column is not updated_by_account_id"
    [[ "$(migration_files | awk 'NR == 29')" == 2026_10_11_000001_add_provenance_to_contact_methods ]] || bad 'the 29th migration is not the one that adds the provenance column to contact_methods'
    grep -qF "\$table->char('updated_by_account_id', 26)->nullable();" "$ROOT/apps/platform/database/migrations/2026_10_11_000001_add_provenance_to_contact_methods.php" || bad 'the provenance migration no longer adds updated_by_account_id as HalfFailedShape assumes'
    # The Pest test measured the same 15.
    got="$(sed -n '/^const HALF_FAILED_NEW_TABLES = \[/,/^\];/p' "$pest" | grep -o "'[a-z_]*'" | tr -d "'" | LC_ALL=C sort)"
    [[ "$got" == "$(printf '%s\n' "${PARTIAL_NEWER[@]}" | LC_ALL=C sort)" ]] || bad "tests/Upgrade/HalfFailedMigrationTest.php's HALF_FAILED_NEW_TABLES are not the script's 15 committed tables"
    # The durable rows, in the order the script counts them (sorted by name), are the fixture's.
    for t in account_invitations account_recovery_codes account_totp_factors accounts membership_grants people role_assignments security_events; do counts+="${counts:+$'\t'}$(rows_of "$t")"; done
    [[ "$counts" == "$DURABLE_ROWS_EXPECTED" ]] || bad "the tests' DURABLE_ROWS_EXPECTED are not the fixture's row counts"
    run_fn 'printf "%s" "$DURABLE_ROWS"'; [[ "$OUT" == "$DURABLE_ROWS_EXPECTED" ]] || bad "the script's DURABLE_ROWS are not the fixture's row counts"
    run_fn 'durable_data_tables | tr "\n" " "'; [[ "$OUT" == 'account_invitations account_recovery_codes account_totp_factors accounts membership_grants people role_assignments security_events ' ]] || bad "the durable tables the script counts are not the eight it should: $OUT"
    # The revoked assignment and its Person are HistoricalFixture::assignment(4) and ::person(3): '01jb', a kind letter, the number padded to 21.
    run_fn 'printf "%s %s" "$REVOKE_ASSIGNMENT" "$REVOKE_PERSON"'; [[ "$OUT" == "$(printf '01jb%s%021d' r 4) $(printf '01jb%s%021d' p 3)" ]] || bad "the revoked ids are not the fixture's assignment 4 and Person 3: $OUT"
    grep -qF "'id' => self::id('E', \$number)" "$ROOT/apps/platform/tests/Support/HistoricalFixture.php" || bad 'the fixture no longer makes event ids as the revoke helper assumes'
    grep -qF "4 => [3, 'guardian']" "$ROOT/apps/platform/tests/Support/HistoricalFixture.php" || bad 'assignment 4 is no longer the guardian role of Person 3'
    # The revocation event is HistoricalFixture::id('E', 9), and the reading that ties it to Person 3 asks for that id, the type AND the subject.
    run_fn 'printf "%s" "$REVOKE_EVENT"'; [[ "$OUT" == "$(printf '01jb%s%021d' e 9)" ]] || bad "the script's REVOKE_EVENT is not HistoricalFixture::id('E', 9): $OUT"
    run_fn 'printf "%s" "$REVOKE_SQL"'
    expect_has 'the event is asked for by id, type and subject' "$OUT" "id = '01jbe000000000000000000009' AND type = 'role.revoked' AND subject_person_id = '01jbp000000000000000000003'"
    run_fn 'printf "%s|%s" "$REVOKE_BEFORE" "$REVOKE_AFTER"'; [[ "$OUT" == "$(printf '12\t8\t1\t0\t0|11\t9\t0\t1\t1')" ]] || bad 'the five readings before and after are not 12 8 1 0 0 and 11 9 0 1 1'
    grep -qF "'id' => HistoricalFixture::id('E', 9)" "$ROOT/scripts/rehearsal/php/rehearse.php" || bad 'the revoke helper no longer records the event with the id the script reads'
    grep -qF "'type' => 'role.revoked'" "$ROOT/scripts/rehearsal/php/rehearse.php" || bad 'the revoke helper no longer records a role.revoked event'
    grep -qF "'subject_person_id' => \$person" "$ROOT/scripts/rehearsal/php/rehearse.php" || bad 'the revoke helper no longer records the event about the Person the script reads'
}

# shellcheck disable=SC2016 # `$attempt` is text to find in the PHP source, and the code in single quotes is run by the inner bash
t_static_the_new_helpers_agree() {
    local php_tables line
    # The harness prints every line the script requires of it, and dispatches the two new actions.
    for line in 'half-migrate:complete=ok' 'revoke:role-removed=ok' 'revoke:event-recorded=ok' 'revoke:complete=ok'; do
        grep -qF "echo \"$line\\n\"" "$SRC/php/rehearse.php" || bad "the script requires $line, which rehearse.php never prints"
    done
    for line in 'half-migrate:$attempt-failure-class=' 'half-migrate:$attempt-failure-code=' 'half-migrate:$attempt-failure-driver-code=' 'half-migrate:$attempt-failure-table=' 'half-migrate:$attempt-ledger=' 'half-migrate:$attempt-tables='; do
        grep -qF "$line" "$SRC/php/rehearse.php" || bad "the script reads $line, which rehearse.php never prints"
    done
    grep -qF "half-migrate:contact-methods-structure=" "$SRC/php/rehearse.php" || bad 'the script requires the helper'"'"'s judgement of contact_methods, which rehearse.php never prints'
    grep -qF "'HalfFailedShape'" "$SRC/php/rehearse.php" || bad 'rehearse.php does not load HalfFailedShape from the prepared source'
    # The helper has no namespace of its own, so the bare name in `HalfFailedShape::check` resolves only through this import.
    grep -qxF 'use Tests\Support\HalfFailedShape;' "$SRC/php/rehearse.php" || bad 'rehearse.php does not import Tests\Support\HalfFailedShape, so its HalfFailedShape::check resolves in the global namespace'
    grep -qxF 'namespace Tests\Support;' "$ROOT/apps/platform/tests/Support/HalfFailedShape.php" || bad 'HalfFailedShape.php is not in the Tests\Support namespace the helper imports'
    [[ -f "$ROOT/apps/platform/tests/Support/HalfFailedShape.php" ]] || bad 'HalfFailedShape.php, which the helper loads, is not in the tests support directory'
    grep -qF "'half-migrate' => 'actionHalfMigrate'" "$SRC/php/rehearse.php" || bad 'rehearse.php has no half-migrate action'
    grep -qF "'revoke' => 'actionRevoke'" "$SRC/php/rehearse.php" || bad 'rehearse.php has no revoke action'
    run_fn 'runner_command helper:half-migrate; printf "%s " "${RUN_CMD[@]}"'
    expect_has 'the half-migrate vector' "$OUT" 'php /var/www/html/harness/rehearse.php half-migrate'
    run_fn 'runner_command helper:revoke; printf "%s " "${RUN_CMD[@]}"'
    expect_has 'the revoke vector' "$OUT" 'php /var/www/html/harness/rehearse.php revoke'
    # The harness's own list of the 15 tables is the script's, and its stub is the script's.
    if command -v php >/dev/null 2>&1; then
        php_tables="$(php -r "require '$SRC/php/rehearse.php'; echo implode(\"\\n\", HALF_MIGRATED_TABLES), \"\\n\", STUB_TABLE;" | LC_ALL=C sort)"
        [[ "$php_tables" == "$(printf '%s\n' "${PARTIAL_NEWER[@]}" "$STUB_NAME" | LC_ALL=C sort)" ]] || bad "rehearse.php's HALF_MIGRATED_TABLES and STUB_TABLE are not the script's 15 tables and the stub"
    fi
}

t_static_the_harness_and_the_script_agree() {
    local php_lines
    if ! command -v php >/dev/null 2>&1; then return 0; fi
    # The harness's own functions, with no database: the fingerprint vectors, and the lines it prints parsing as the script reads them.
    [[ "$(php -r "require '$SRC/php/rehearse.php'; echo keyFingerprint('$KEY_CUR'), keyFingerprint('$KEY_PREV'), keyFingerprint('$KEY_FF');")" == "$FP_CUR$FP_PREV$FP_FF" ]] || bad 'the harness fingerprints the three vectors differently from the script'
    php -r "require '$SRC/php/rehearse.php';
        try { keyFingerprint('base64:AAAA'); echo 'accepted'; } catch (HarnessRefusal) { echo 'refused'; }" | grep -qx refused || bad 'the harness accepted a key that is not 32 bytes'
    php_lines="$(php -r "require '$SRC/php/rehearse.php'; require '$ROOT/apps/platform/tests/Support/HistoricalSnapshot.php';
        \$rows = []; foreach (array_keys(\\Tests\\Support\\HistoricalSnapshot::TABLES) as \$t) { \$rows[\$t] = []; }
        echo tableLines('table', \$rows);")"
    [[ "$(grep -c . <<<"$php_lines")" == 16 ]] || bad 'the harness does not print one line for each of the 16 tables'
    run_fn "table_lines table '$php_lines'"
    expect_rc 'the harness lines parse as the script reads them' 0
    [[ "$(grep -c . <<<"$OUT")" == 16 ]] || bad 'the script did not read 16 lines from the harness output'
    expect_has 'the digest of no rows' "$php_lines" "$(printf '[]' | sha256sum | cut -c1-64)"
    # Slice 2.2B. The digest is of the values as text, so a driver that gives 3 or '3' does not make two application trees disagree, while NULL,
    # a changed value and a lost row still do. The base32 encoder against the RFC 4648 vector (the string 12345678901234567890 is the RFC 6238 secret).
    [[ "$(php -r "require '$SRC/php/rehearse.php'; echo rowsDigest([['a' => 3, 'b' => null]]) === rowsDigest([['a' => '3', 'b' => null]]) ? 'same' : 'differ', ' ', rowsDigest([['a' => 3]]) === rowsDigest([['a' => null]]) ? 'same' : 'differ', ' ', rowsDigest([['a' => 3]]) === rowsDigest([['a' => 4]]) ? 'same' : 'differ', ' ', rowsDigest([['a' => 3]]) === rowsDigest([]) ? 'same' : 'differ';")" == 'same differ differ differ' ]] || bad 'the row digest does not treat 3 and '"'"'3'"'"' alike and NULL, another value and a lost row differently'
    [[ "$(php -r "require '$SRC/php/rehearse.php'; echo base32('12345678901234567890'), ' ', base32(str_repeat(chr(0), 20));")" == 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' ]] || bad 'the base32 encoder does not match the RFC 4648 vector'
    [[ "$(php -r "require '$SRC/php/rehearse.php'; echo strlen(keyBytes('$KEY_CUR'));")" == 32 ]] || bad 'keyBytes does not return 32 bytes'
    # Every line the script requires of the sign-in is one the harness prints, and the helper is the one the script runs.
    run_fn 'declare -f verify_signin'
    while IFS= read -r line; do
        grep -qF "echo \"$line\\n\"" "$SRC/php/rehearse.php" || bad "the script requires $line of the sign-in, which rehearse.php never prints"
    done < <(grep -o 'signin:[a-z-]*=ok' <<<"$OUT" | sort -u)
    grep -q "'signin' => 'actionSignin'" "$SRC/php/rehearse.php" || bad 'rehearse.php has no signin action'
}


# --- tests: static -------------------------------------------------------------------------------------------------------------------

t_static_substrate() {
    local script="$SRC/restore-rehearsal.sh" f
    # The application password is exported in exactly one place, the launcher, and is never a value on a command line.
    [[ "$(grep -c 'DB_PASSWORD=' "$script")" == 1 ]] || bad 'DB_PASSWORD= appears other than in the launcher'
    grep -q 'export DB_PASSWORD=' "$script" || bad 'the launcher does not export DB_PASSWORD'
    # The same for the encryption keys: exported in the launcher alone (REHEARSAL_APP_KEY= is the state file's name for it, not an export).
    [[ "$(grep -c '[^_]APP_KEY=' "$script")" == 1 && "$(grep -c 'APP_PREVIOUS_KEYS=' "$script")" == 1 ]] || bad 'APP_KEY= or APP_PREVIOUS_KEYS= appears other than in the launcher'
    { grep -q 'export APP_KEY=' "$script" && grep -q 'export APP_PREVIOUS_KEYS=' "$script"; } || bad 'the launcher does not export the keys'
    grep -q -e '-e APP_KEY -e APP_PREVIOUS_KEYS' "$script" || bad 'the runner is not given the keys by name'
    if grep -nE '(--force|--privileged|--publish|--env-file|--volumes-from|--network host|-p [0-9])' "$script" | grep -vE '^[0-9]+:[[:space:]]*#|artisan|migrate|refuse|no --force|never|dkr compose' | grep -q .; then bad 'a forbidden docker or client option appears in the script'; fi
    if grep -n 'compose.*-e \|docker.*-v ' "$script" | grep -q .; then bad 'a bind-style docker option appears in the script'; fi
    for f in "$SRC"/php/*.php; do
        [[ " ${HARNESS_FILES_EXPECTED[*]} " == *" ${f##*/} "* ]] || bad "an unexpected helper script ${f##*/} sits in scripts/rehearsal/php"
    done
    [[ "$(grep -c 'HARNESS_FILES=' "$script")" == 1 ]] || bad 'the harness file list is not stated once'
    grep -q "HARNESS_FILES=(${HARNESS_FILES_EXPECTED[*]})" "$script" || bad 'the harness file list is not the expected one'
    if command -v php >/dev/null 2>&1; then
        for f in "$SRC"/php/*.php; do php -l "$f" >/dev/null 2>&1 || bad "${f##*/} does not parse"; done
    fi
    # compose.yaml gains nothing for this slice: still one service, one network, one volume.
    grep -q 'runner\|prepare' "$SRC/compose.yaml" && bad 'compose.yaml mentions the runner or preparation container'
}
# --- tests: slice 2.2C-2, the Resource files ---------------------------------------------------------------------------------------------------
#
# The files container (no network, no credential, the prepared source read-only and the files volume), its volume, the files manifest and the four
# commands, against the fake daemon. What the PHP side refuses is tests/Unit/Rehearsal/ResourceFileArchiveTest.php's (a real directory, the real tar).
# The fake prints what the files helper is told to print (out_files_<action>, out_files_<action>.<n> for the nth run, fail_files_<action>), so each
# test states what a well-behaved helper reports and then what a lying or failing one does to the script.

FKEYS=(01jcf000000000000000000001 01jcf000000000000000000002 01jcf000000000000000000003 01jcf000000000000000000004)
FBYTES=(64 1500 9000 33333)
FP_RUN="$(printf 'flc-run-v1|%s' "$TOKEN" | sha256sum | cut -c1-16)"
fsha() { fake_digest "file-$1"; }
# want_case GUARD: in the mutation matrix, only the case written for the guard that was removed; in an ordinary run, every case.
want_case() { [[ -z "$CURRENT_GUARD" || "$CURRENT_GUARD" == "$1" ]]; }
fspec() { # N: the first N files as the helper's file list
    local i out=''
    for ((i = 1; i <= $1; i++)); do out+="${out:+,}${FKEYS[i - 1]}:${FBYTES[i - 1]}:$(fsha "$i")"; done
    printf '%s' "${out:-empty}"
}
fout() { # STATE N [LINE...]: what the files helper prints for the first N files
    local state="$1" n="$2" i line
    shift 2
    printf 'files:state=%s\nfiles:count=%s\n' "$state" "$n"
    for ((i = 1; i <= n; i++)); do printf 'file %s %s %s\n' "${FKEYS[i - 1]}" "${FBYTES[i - 1]}" "$(fsha "$i")"; done
    for line; do printf '%s\n' "$line"; done
}
frows() { # N: the first N asset rows as the SQL client prints them
    local i
    for ((i = 1; i <= $1; i++)); do printf '%s\t%s\t%s\n' "${FKEYS[i - 1]}" "${FBYTES[i - 1]}" "$(fsha "$i")"; done
}
files_creates() { grep -E "^create .*--name $NAME_F " "$W/calls" || true; }
no_files_container() { [[ ! -e "$W/runs" ]] || ! grep -q '^files_' "$W/runs" || bad "$1: the files helper ran: $(tr '\n' ' ' <"$W/runs")"; }
no_files_leftovers() { if compgen -G "$T/root/.files-archive.*" >/dev/null; then bad "$1: a temporary archive file was left in the state directory"; fi; }

# The upgraded database (34 tables, the post-upgrade state), the prepared source, a resource_assets table with no rows, and no files volume yet.
files_world() {
    stage_world
    up_names >"$W/inventory"
    printf '14\t1\t29\t0\n' >"$W/counts"
    printf '1\n' >"$W/assets_table"
    : >"$W/asset_rows"
}
seed_world() {
    files_world
    fout present 4 files:seed=ok >"$W/out_files_seed"
    fout present 4 files:check=ok >"$W/out_files_check"
    frows 4 >"$W/rows_after"
    printf 'cp "%s/rows_after" "%s/asset_rows"\n' "$W" "$W" >"$W/on_insert"
}
# backup_files_world DBSTATE(up|hist) FILESSTATE(absent|present) N: the files helper reports N files and the database holds N asset rows (or no table, at v0.3.0).
backup_files_world() {
    files_world
    if [[ "$1" == hist ]]; then
        hist_names >"$W/inventory"
        printf '0\n' >"$W/assets_table"
    else
        frows "$3" >"$W/asset_rows"
    fi
    fout "$2" "$3" files:check=ok >"$W/out_files_check"
    if [[ "$2" == present ]]; then
        printf 'ARCHIVE\x00BYTES\xff' >"$W/out_files_archive"
        printf 'archive:entries=%s\narchive:verified=ok\n' "$(($3 + 1))" >"$W/out_files_verify_archive"
    fi
    printf '1700000000' >"$T/fixed-epoch"
}
expected_files_manifest() { # STATE ARCHIVE_SHA ARCHIVE_BYTES ROWS N: written out here, not by the script's own function
    local state="$1" sha="$2" bytes="$3" rows="$4" n="$5" i archive=null ash=null abytes=null list=''
    if [[ "$state" == present ]]; then archive='"resources.archive.tar.gz"' ash="\"$sha\"" abytes="$bytes"; fi
    [[ "$rows" != none ]] || rows=null
    for ((i = 1; i <= n; i++)); do
        list+="    { \"key\": \"${FKEYS[i - 1]}\", \"bytes\": ${FBYTES[i - 1]}, \"sha256\": \"$(fsha "$i")\" }"
        [[ $i == "$n" ]] || list+=','
        list+=$'\n'
    done
    printf '{\n  "files_manifest_version": 1,\n  "taken_at": "2023-11-14T22:13:20Z",\n  "run_token_fingerprint": "%s",\n  "resources_directory": "%s",\n  "resource_files_archive": %s,\n  "resource_files_sha256": %s,\n  "resource_files_bytes": %s,\n  "resource_files_count": %d,\n  "resource_assets_rows": %s,\n  "files": [\n%s  ]\n}' "$FP_RUN" "$state" "$archive" "$ash" "$abytes" "$n" "$rows" "$list"
}

# --- seed-files ---

t_g22_seed_happy_path() {
    local creates first second i values='' expected c f
    seed_world
    run_script -- seed-files
    expect_rc seed-files 0
    expect_has 'the agreement' "$OUT" "4 files in the volume's resources directory and 4 resource_assets rows agree on every key, size and SHA-256"
    expect_has 'the next step' "$OUT" 'backup-files'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_seed files_check ' ]] || bad "the files helper did not run as seed then check: $(tr '\n' ' ' <"$W/runs")"
    [[ "$(<"$W/runs_at_insert")" == files_seed ]] || bad 'the asset rows were inserted before the files were written'
    for ((i = 1; i <= 4; i++)); do
        values+="${values:+,}('${FKEYS[i - 1]}','${FKEYS[i - 1]}','synthetic-$i.bin','application/octet-stream',${FBYTES[i - 1]},'$(fsha "$i")','01jbp000000000000000000001','2026-01-01 00:00:00')"
    done
    expected="START TRANSACTION;"$'\n'"INSERT INTO resource_assets (id, storage_key, original_filename, media_type, byte_size, sha256, uploaded_by_person_id, created_at) VALUES $values;"$'\n'"COMMIT;"
    [[ "$(<"$W/assets_insert.sql")" == "$expected" ]] || bad "the INSERT is not the one transaction with one row per seeded file: $(<"$W/assets_insert.sql")"
    creates="$(files_creates)"
    [[ "$(grep -c . <<<"$creates")" == 2 ]] || bad "expected two files containers (seed, check), got: $creates"
    first="$(sed -n 1p <<<"$creates")" second="$(sed -n 2p <<<"$creates")"
    expect_has 'seed writes the files volume' "$first" "--mount type=volume,source=$NAME_FV,target=/var/www/html --tmpfs"
    expect_has 'check reads the files volume read-only' "$second" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"
    expect_has 'seed is fixed' "$first" ' php /rehearsal/src/harness/files.php seed'
    expect_has 'check is given the asset rows' "$second" " php /rehearsal/src/harness/files.php check $(fspec 4)"
    for c in "$first" "$second"; do
        expect_has 'no network' "$c" '--network none'
        expect_has 'the source is read-only' "$c" "--mount type=volume,source=$NAME_SRC,target=/rehearsal/src,readonly"
        expect_has 'dropped capabilities' "$c" '--cap-drop ALL'
        expect_has 'read-only root' "$c" '--read-only'
        expect_lacks 'no environment at all' "$c" ' -e '
        expect_lacks 'no env file' "$c" '--env-file'
        expect_lacks 'no bind mount' "$c" 'type=bind'
        expect_lacks 'no docker socket' "$c" 'docker.sock'
    done
    # The files container is not given the database password, the encryption keys or anything of the caller's.
    for f in "$W"/create_password_seen.* "$W"/create_appkey_seen.* "$W"/create_previouskeys_seen.*; do [[ ! -s "$f" ]] || bad "a secret reached the docker client that created a files container ($f)"; done
    for f in "$W"/create_env_names.*; do
        [[ "$(grep -cE '^(DB_PASSWORD|APP_KEY|APP_PREVIOUS_KEYS)$' "$f" || true)" == 0 ]] || bad "a credential name was in the environment of the client that created a files container ($f)"
    done
    [[ "$(grep -c '^volume create ' "$W/calls")" == 1 ]] || bad 'the files volume was not created exactly once'
    expect_has 'the volume is labelled' "$(grep '^volume create ' "$W/calls")" "--label io.flowlife.rehearsal=restore-v1 --label io.flowlife.rehearsal.role=files-data --label io.flowlife.rehearsal.run-token=$TOKEN $NAME_FV"
    if [[ -f "$W/sql.log" ]] && grep -qE 'CREATE|DROP|INSERT|DELETE|ALTER|UPDATE|TRUNCATE|GRANT' "$W/sql.log"; then bad 'a root statement changed data or schema'; fi
    [[ "$(grep -cE 'DROP|ALTER|DELETE|UPDATE|TRUNCATE' "$W/appsql.log" || true)" == 0 ]] || bad 'the application account ran a statement other than the two reads and the one insert'
    only_database_left seed-files
    no_secret_anywhere seed-files
}

t_g22_seed_preconditions() {
    if want_case G22-seed-inventory; then
        files_world; hist_names >"$W/inventory"
        run_script -- seed-files
        expect_refused 'a database that is not upgraded' 'not exactly the 34 tables'
        no_files_container 'a database that is not upgraded'
        [[ ! -e "$W/assets_insert.sql" ]] || bad 'rows were inserted into a database that is not upgraded'
    fi
    if want_case G22-seed-state; then
        files_world; printf '13\t1\t15\t1\n' >"$W/counts"
        run_script -- seed-files
        expect_refused 'the WP3-01 state' 'not the post-upgrade state'
        no_files_container 'the WP3-01 state'
    fi
    if want_case G22-seed-assets-empty; then
        files_world; frows 1 >"$W/asset_rows"
        run_script -- seed-files
        expect_refused 'asset rows already there' 'not an existing, empty table'
        no_files_container 'asset rows already there'
        files_world; printf '0\n' >"$W/assets_table"
        run_script -- seed-files
        expect_refused 'no resource_assets table' 'not an existing, empty table'
        no_files_container 'no resource_assets table'
    fi
    if want_case G22-rows-table-answer; then
        files_world; printf '7\n' >"$W/assets_table"
        run_script -- seed-files
        expect_refused 'a table answer that is neither 0 nor 1' 'did not say whether resource_assets exists'
        no_files_container 'a table answer that is neither 0 nor 1'
    fi
}

t_g22_seed_outcomes() {
    if want_case G22-seed-reported; then
        seed_world; fout present 4 >"$W/out_files_seed"
        run_script -- seed-files
        expect_refused 'a seed the helper did not report' 'files:seed=ok'
        [[ ! -e "$W/assets_insert.sql" ]] || bad 'rows were inserted for a seed that was not reported'
    fi
    if want_case G22-seed-count; then
        seed_world; fout present 3 files:seed=ok >"$W/out_files_seed"
        run_script -- seed-files
        expect_refused 'a seed of three files' 'did not seed exactly 4 files'
        [[ ! -e "$W/assets_insert.sql" ]] || bad 'rows were inserted for a seed of the wrong size'
    fi
    if want_case G22-seed-rows-match; then
        seed_world; : >"$W/on_insert"
        run_script -- seed-files
        expect_refused 'rows that did not arrive' 'not the files just written'
    fi
    if want_case G22-seed-agrees; then
        seed_world; fout present 4 >"$W/out_files_check"
        run_script -- seed-files
        expect_refused 'a final check that does not agree' 'files:check=ok'
    fi
    if want_case G22-seed-sql-values; then
        run_fn 'files_insert_sql "01jcf000000000000000000001:64:'"$(fsha 1)"',01jcf000000000000000000002'"'"'); DROP TABLE people; --:1:'"$(fsha 2)"'"'
        expect_refused 'a seeded file whose key is not a key' 'nothing was inserted'
        run_fn 'files_insert_sql "01jcf000000000000000000001:6x:'"$(fsha 1)"'"'
        expect_refused 'a size that is not a number' 'nothing was inserted'
        run_fn 'files_insert_sql "01jcf000000000000000000001:64:'"${FKEYS[0]}"'"'
        expect_refused 'a checksum that is not a SHA-256' 'nothing was inserted'
    fi
    if [[ -z "$CURRENT_GUARD" ]]; then
        # A failing helper ends the stage with its own status, and nothing is inserted.
        seed_world; printf '3\n' >"$W/fail_files_seed"
        run_script -- seed-files
        expect_rc 'a seed the helper refuses (an existing directory)' 3
        [[ ! -e "$W/assets_insert.sql" ]] || bad 'rows were inserted although the seed failed'
        seed_world; touch "$W/insert_fail"
        run_script -- seed-files
        expect_rc 'an insert that fails' 1
        expect_has 'what failed' "$ERR" 'could not be inserted'
    fi
}

t_g22_database_rows_are_validated() {
    if want_case G22-rows-shape; then
        files_world; printf 'NOTAKEY\t64\t%s\n' "$(fsha 1)" >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'a row whose key is not a storage key' 'not a Resources storage key, a size and a SHA-256'
        no_files_container 'a bad key'
        files_world; printf '%s\t64\tnothex\n' "${FKEYS[0]}" >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'a row whose checksum is not a SHA-256' 'not a Resources storage key, a size and a SHA-256'
        files_world; printf '%s\t64\t%s\textra\n' "${FKEYS[0]}" "$(fsha 1)" >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'a row with a fourth column' 'not a Resources storage key, a size and a SHA-256'
        files_world; printf '%s\t-5\t%s\n' "${FKEYS[0]}" "$(fsha 1)" >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'a negative size' 'not a Resources storage key, a size and a SHA-256'
    fi
    if want_case G22-rows-order; then
        files_world; { frows 2 | tac; } >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'rows out of key order' 'not in key order, or repeat a key'
        files_world; { frows 1; frows 1; } >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'a repeated row' 'not in key order, or repeat a key'
        no_files_container 'bad row order'
    fi
}

# --- backup-files ---

t_g22_backup_zero_file_states() {
    local manifest
    # v0.3.0: no resource_assets table, no directory: the zero-file backup. No archive is made and no archive container is run.
    backup_files_world hist absent 0
    run_script -- backup-files
    expect_rc 'a zero-file backup at v0.3.0' 0
    expect_has 'zero-file' "$OUT" 'zero-file backup'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check ' ]] || bad "a zero-file backup ran more than the one read-only check: $(tr '\n' ' ' <"$W/runs")"
    expect_has 'checked against no table' "$(files_creates)" ' php /rehearsal/src/harness/files.php check none'
    [[ ! -e "$T/root/resources.archive.tar.gz" ]] || bad 'a zero-file backup made an archive'
    manifest="$T/root/resources.manifest.json"
    [[ -f "$manifest" && "$("$REAL_STAT" -c %a "$manifest")" == 600 ]] || bad 'the files manifest is not a private file'
    [[ "$(<"$manifest")" == "$(expected_files_manifest absent '' '' none 0)" ]] || bad "the zero-file manifest is not as the runbook describes it (count 0, no checksum, rows null): $(<"$manifest")"
    only_database_left 'a zero-file backup'
    # The upgraded database with the table and no rows: still zero-file, the rows recorded as 0.
    backup_files_world up absent 0
    run_script -- backup-files
    expect_rc 'a zero-file backup with an empty table' 0
    expect_has 'checked against an empty table' "$(files_creates)" ' php /rehearsal/src/harness/files.php check empty'
    [[ "$(<"$manifest")" == "$(expected_files_manifest absent '' '' 0 0)" ]] || bad "the zero-file manifest for an empty table is not rows 0: $(<"$manifest")"
}

t_g22_backup_an_existing_empty_directory() {
    local sha
    backup_files_world up present 0
    run_script -- backup-files
    expect_rc 'an existing empty directory with no rows' 0
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check files_archive files_verify_archive ' ]] || bad "an empty directory is archived (the runbook's 2a): $(tr '\n' ' ' <"$W/runs")"
    sha="$(sha256sum <"$T/root/resources.archive.tar.gz" | cut -c1-64)"
    [[ "$(<"$T/root/resources.manifest.json")" == "$(expected_files_manifest present "$sha" "$(wc -c <"$T/root/resources.archive.tar.gz" | tr -d ' ')" 0 0)" ]] || bad "the manifest for an empty directory is not count 0 with an archive checksum: $(<"$T/root/resources.manifest.json")"
    expect_has 'the verification is of an empty list' "$(grep -E '^create ' "$W/calls" | tail -n 1)" ' php /rehearsal/src/harness/files.php verify-archive empty'
}

t_g22_backup_happy_path() {
    local creates sha bytes archive manifest i
    backup_files_world up present 4
    run_script -- backup-files
    expect_rc backup-files 0
    expect_has 'the files and the rows agree' "$OUT" 'every file in it has the size and SHA-256 the database and the directory agree on'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check files_archive files_verify_archive ' ]] || bad "the helper did not run as check, archive, verify: $(tr '\n' ' ' <"$W/runs")"
    archive="$T/root/resources.archive.tar.gz" manifest="$T/root/resources.manifest.json"
    for i in "$archive" "$manifest"; do [[ -f "$i" && "$("$REAL_STAT" -c %a "$i")" == 600 ]] || bad "$i is not a private file"; done
    printf 'ARCHIVE\x00BYTES\xff' | cmp -s - "$archive" || bad 'the archive in the state directory is not the bytes the files container wrote to its standard output'
    sha="$(sha256sum <"$archive" | cut -c1-64)" bytes="$(wc -c <"$archive" | tr -d ' ')"
    [[ "$(<"$manifest")" == "$(expected_files_manifest present "$sha" "$bytes" 4 4)" ]] || bad "the manifest is not the runbook's Resource fields and the four files' facts: $(<"$manifest")"
    creates="$(files_creates)"
    [[ "$(grep -c . <<<"$creates")" == 3 ]] || bad "expected three files containers: $creates"
    for i in 1 2 3; do expect_has "container $i reads the volume read-only" "$(sed -n "${i}p" <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"; done
    expect_has 'the archive action is given the rows' "$(sed -n 2p <<<"$creates")" " php /rehearsal/src/harness/files.php archive $(fspec 4)"
    expect_has 'the verification reads standard input' "$(sed -n 3p <<<"$creates")" ' -i '
    expect_lacks 'only the verification reads standard input' "$(sed -n 2p <<<"$creates")" ' -i '
    expect_has 'the verification is of the listed files' "$(sed -n 3p <<<"$creates")" " php /rehearsal/src/harness/files.php verify-archive $(fspec 4)"
    printf 'ARCHIVE\x00BYTES\xff' | cmp -s - "$W/stdin.3" || bad 'the verification was not given the archive on its standard input'
    expect_lacks 'a database dump is not part of this' "$(<"$W/appsql.log")" 'INSERT'
    no_files_leftovers 'a backup'
    only_database_left backup-files
    no_secret_anywhere backup-files
}

t_g22_backup_is_never_overwritten() {
    backup_files_world up present 4
    run_script -- backup-files
    expect_rc 'the first backup' 0
    cp -p "$T/root/resources.manifest.json" "$T/before.manifest"
    run_script -- backup-files
    expect_refused 'a second backup' 'never overwritten'
    cmp -s "$T/before.manifest" "$T/root/resources.manifest.json" || bad 'a second backup changed the manifest'
    # Either file alone is enough to refuse.
    backup_files_world up present 4; printf 'x' >"$T/root/resources.archive.tar.gz"; chmod 600 "$T/root/resources.archive.tar.gz"
    run_script -- backup-files
    expect_refused 'a leftover archive' 'never overwritten'
    no_files_container 'a leftover archive'
}

t_g22_backup_needs_a_known_database_state() {
    backup_files_world up present 4
    { hist_names; printf '%s\n' "${NEWER_TABLES[@]:0:5}"; } | LC_ALL=C sort >"$W/inventory"
    run_script -- backup-files
    expect_refused 'a half-migrated database' 'neither the 16 tables of v0.3.0 nor the 34 of the upgraded schema'
    no_files_container 'a half-migrated database'
}

t_g22_backup_refusals() {
    if want_case G22-backup-check-reported; then
        backup_files_world up present 4; fout present 4 >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a check the helper did not report' 'files:check=ok'
        [[ ! -e "$T/root/resources.manifest.json" ]] || bad 'a manifest was written after a check that was not reported'
    fi
    if want_case G22-backup-agrees; then
        # The helper says it agrees, but what it listed is not what the database holds (the second file's checksum differs): the script compares them itself.
        backup_files_world up present 4
        fout present 4 files:check=ok | sed "s/ $(fsha 2)\$/ $(fsha 9)/" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a listing that is not the rows' 'not the asset rows the database holds'
        ! grep -q '^files_archive' "$W/runs" || bad 'an archive was made from a listing that does not agree with the rows'
        backup_files_world up absent 0; frows 1 >"$W/asset_rows"
        run_script -- backup-files
        expect_refused 'an absent directory while a row exists' 'not the asset rows the database holds'
        backup_files_world hist present 1
        run_script -- backup-files
        expect_refused 'files with no table to belong to' 'not the asset rows the database holds'
    fi
    if want_case G22-backup-archive-nonempty; then
        backup_files_world up present 4; : >"$W/out_files_archive"
        run_script -- backup-files
        expect_refused 'an archive of no bytes' 'the archive is empty'
        [[ ! -e "$T/root/resources.manifest.json" && ! -e "$T/root/resources.archive.tar.gz" ]] || bad 'a backup was recorded from an empty archive'
        no_files_leftovers 'an empty archive'
    fi
    if want_case G22-backup-archive-verified; then
        backup_files_world up present 4; printf 'archive:entries=5\n' >"$W/out_files_verify_archive"
        run_script -- backup-files
        expect_refused 'an archive that was not reported verified' 'not verified against the files it was made from'
        [[ ! -e "$T/root/resources.manifest.json" && ! -e "$T/root/resources.archive.tar.gz" ]] || bad 'a backup was recorded from an unverified archive'
        no_files_leftovers 'an unverified archive'
        backup_files_world up present 4; printf 'archive:entries=4\narchive:verified=ok\n' >"$W/out_files_verify_archive"
        run_script -- backup-files
        expect_refused 'an archive with the wrong number of entries' 'not verified against the files it was made from'
    fi
    if [[ -z "$CURRENT_GUARD" ]]; then
        # An unsafe state is the helper's refusal, and it ends the backup with it: no archive is attempted.
        backup_files_world up present 4; printf '3\n' >"$W/fail_files_check"
        run_script -- backup-files
        expect_rc 'a directory the helper refuses' 3
        [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check ' ]] || bad "an archive was attempted after the check refused: $(tr '\n' ' ' <"$W/runs")"
        [[ ! -e "$T/root/resources.manifest.json" ]] || bad 'a manifest was written after the check refused'
        # A failed archive command (tar exits non-zero in the container, the helper refuses): no backup, and no temporary file.
        backup_files_world up present 4; printf '3\n' >"$W/fail_files_archive"
        run_script -- backup-files
        expect_rc 'an archive the container could not make' 3
        [[ ! -e "$T/root/resources.manifest.json" && ! -e "$T/root/resources.archive.tar.gz" ]] || bad 'a backup was recorded although the archive command failed'
        no_files_leftovers 'a failed archive'
        expect_has 'recovery is named' "$ERR" 'cleanup'
        only_database_left 'a failed archive'
    fi
}

# shellcheck disable=SC2001 # line-anchored patterns on a listing, not a substitution of a variable's text
t_g22_the_helper_output_is_validated() {
    local good
    good="$(fout present 4 files:check=ok)"
    if want_case G22-f-protocol-line; then
        backup_files_world up present 4; sed "s/^file ${FKEYS[1]} /file ${FKEYS[1]}x /" <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a file line with a bad key' 'file line of the files helper is malformed'
        backup_files_world up present 4; sed "s/^\(file ${FKEYS[1]} [0-9]*\) .*/\1 nothex/" <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a file line with a bad checksum' 'file line of the files helper is malformed'
        backup_files_world up present 4; sed "s/^\(file ${FKEYS[1]} .*\)/\1 extra/" <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a file line with an extra field' 'file line of the files helper is malformed'
        ! grep -q '^files_archive' "$W/runs" || bad 'an archive was attempted after a malformed listing'
    fi
    if want_case G22-f-protocol-order; then
        backup_files_world up present 4
        fout present 4 files:check=ok | awk 'NR==3 { held = $0; next } NR==4 { print; print held; next } { print }' >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'files listed out of order' 'out of order or twice'
        backup_files_world up present 4
        fout present 4 files:check=ok | awk 'NR==3 { print; print; next } { print }' >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a file listed twice' 'out of order or twice'
    fi
    if want_case G22-f-protocol-state; then
        backup_files_world up present 4; grep -v '^files:state=' <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'no state' 'exactly one state'
        backup_files_world up present 4; { echo files:state=present; echo "$good"; } >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'two states' 'exactly one state'
        backup_files_world up present 4; sed 's/^files:state=present/files:state=elsewhere/' <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a state that is neither' 'exactly one state'
    fi
    if want_case G22-f-protocol-count; then
        backup_files_world up present 4; sed 's/^files:count=4/files:count=5/' <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'a count that is not the number of lines' 'count is not the number of files it listed'
        backup_files_world up present 4; grep -v '^files:count=' <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'no count' 'count is not the number of files it listed'
    fi
    if want_case G22-f-protocol-absent; then
        backup_files_world up present 4; sed 's/^files:state=present/files:state=absent/' <<<"$good" >"$W/out_files_check"
        run_script -- backup-files
        expect_refused 'files in an absent directory' 'listed files in a directory it reported absent'
    fi
}

# --- the files manifest and the archive, as a restore finds them ---

FILES_CACHE=0
# ensure_files_cache: one backup of four files and one zero-file backup, made by the script itself, kept for the tests of the manifest and the restore.
ensure_files_cache() {
    [[ "$FILES_CACHE" == 0 ]] || return 0
    backup_files_world up present 4
    run_script -- backup-files
    [[ "$RC" == 0 ]] || bad "preparing the cached files backup: exit $RC: ${ERR:0:300}"
    rm -rf "${T:?}/fcache"
    mkdir -p "$T/fcache/zero"
    cp -p "$T/root/resources.manifest.json" "$T/root/resources.archive.tar.gz" "$T/fcache/"
    backup_files_world hist absent 0
    run_script -- backup-files
    [[ "$RC" == 0 ]] || bad "preparing the cached zero-file backup: exit $RC: ${ERR:0:300}"
    cp -p "$T/root/resources.manifest.json" "$T/fcache/zero/"
    FILES_CACHE=1
}
# restore_files_world [zero] [novolume]: the prepared source, the files volume (established: a restore never creates it), the files helper told what a restore
# reports, and the cached backup installed in the state directory.
restore_files_world() {
    local flag zero=0 volume=1
    for flag in "$@"; do
        case "$flag" in zero) zero=1 ;; novolume) volume=0 ;; *) ;; esac
    done
    files_world
    [[ $volume == 0 ]] || add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
    fout present 4 restore:extracted=ok >"$W/out_files_restore"
    fout present 4 files:check=ok >"$W/out_files_check"
    printf 'archive:entries=5\narchive:verified=ok\n' >"$W/out_files_verify_archive"
    if [[ $zero == 1 ]]; then
        cp -p "$T/fcache/zero/resources.manifest.json" "$T/root/"
        fout absent 0 files:check=ok >"$W/out_files_check"
    else
        cp -p "$T/fcache/resources.manifest.json" "$T/fcache/resources.archive.tar.gz" "$T/root/"
    fi
}
corrupt_manifest() { # SED_EXPRESSION: the cached manifest with one change, installed
    restore_files_world
    sed -i "$1" "$T/root/resources.manifest.json"
}
manifest_refused() { # NEEDLE
    run_script -- restore-files
    expect_refused "a manifest changed by: $CASE" "$1"
    no_files_container "a manifest changed by: $CASE"
    [[ ! -e "$W/started" ]] || bad "$CASE: a container was started"
}

t_g22_the_manifest_is_validated() {
    ensure_files_cache
    CASE='nothing: the cached manifest as it is'
    if [[ -z "$CURRENT_GUARD" ]]; then
        restore_files_world; run_script -- restore-files; expect_rc 'the unchanged manifest' 0
    fi
    if want_case G22-manifest-version; then CASE='version'; corrupt_manifest 's/"files_manifest_version": 1/"files_manifest_version": 2/'; manifest_refused 'files_manifest_version is not 1'; fi
    if want_case G22-manifest-run; then CASE='run'; corrupt_manifest 's/"run_token_fingerprint": "[0-9a-f]*"/"run_token_fingerprint": "0000000000000000"/'; manifest_refused "not this run's"; fi
    if want_case G22-manifest-taken; then CASE='taken'; corrupt_manifest 's/"taken_at": "[^"]*"/"taken_at": "yesterday"/'; manifest_refused 'not a UTC time'; fi
    if want_case G22-manifest-state; then CASE='state'; corrupt_manifest 's/"resources_directory": "present"/"resources_directory": "maybe"/'; manifest_refused 'neither absent nor present'; fi
    if want_case G22-manifest-count; then CASE='count'; corrupt_manifest 's/"resource_files_count": 4/"resource_files_count": 5/'; manifest_refused 'not the number of files it lists'; fi
    if want_case G22-manifest-zero; then CASE='zero'; corrupt_manifest 's/"resources_directory": "present"/"resources_directory": "absent"/'; manifest_refused 'an absent directory records an archive'; fi
    if want_case G22-manifest-archive-name; then CASE='archive-name'; corrupt_manifest 's/"resource_files_archive": "resources.archive.tar.gz"/"resource_files_archive": "other.tar.gz"/'; manifest_refused 'its archive is not resources.archive.tar.gz'; fi
    if want_case G22-manifest-archive-facts; then CASE='archive-facts'; corrupt_manifest 's/"resource_files_sha256": "[0-9a-f]*"/"resource_files_sha256": "abc"/'; manifest_refused 'not a SHA-256 and a positive byte count'; fi
    if want_case G22-manifest-rows; then CASE='rows'; corrupt_manifest 's/"resource_assets_rows": 4/"resource_assets_rows": 3/'; manifest_refused 'resource_assets_rows is not its file count'; fi
    if want_case G22-manifest-no-table; then CASE='no-table'; corrupt_manifest 's/"resource_assets_rows": 4/"resource_assets_rows": null/'; manifest_refused 'records files but no resource_assets table'; fi
    if want_case G22-manifest-exact; then CASE='exact'; corrupt_manifest 's/^  "files": \[/  "note": "an extra line",\n  "files": [/'; manifest_refused 'not exactly what this script writes'; fi
    if want_case G22-archive-sha; then
        CASE='archive-sha'; restore_files_world; printf 'x' >>"$T/root/resources.archive.tar.gz"
        manifest_refused "SHA-256 is not the manifest's"
    fi
    if want_case G22-archive-bytes; then
        CASE='archive-bytes'; corrupt_manifest 's/"resource_files_bytes": \([0-9]*\)/"resource_files_bytes": 9\1/'
        manifest_refused "size is not the manifest's"
    fi
    if want_case G22-restore-backup-required; then
        CASE='no manifest'; restore_files_world; rm -f "$T/root/resources.manifest.json"
        manifest_refused 'no Resource-files backup'
        CASE='a manifest that is world-readable'; restore_files_world; chmod 644 "$T/root/resources.manifest.json"
        manifest_refused 'no Resource-files backup'
    fi
    if want_case G22-restore-archive-required; then
        CASE='no archive'; restore_files_world; rm -f "$T/root/resources.archive.tar.gz"
        manifest_refused 'is missing from the state directory'
    fi
    if want_case G22-restore-zero-no-archive; then
        CASE='a zero-file backup with an archive beside it'; restore_files_world zero; printf 'stray' >"$T/root/resources.archive.tar.gz"
        manifest_refused 'a zero-file backup, but an archive exists'
    fi
    if want_case G22-manifest-archive-facts; then
        CASE='a size of zero'; corrupt_manifest 's/"resource_files_bytes": [0-9]*/"resource_files_bytes": 0/'; manifest_refused 'not a SHA-256 and a positive byte count'
    fi
}

# --- restore-files ---

t_g22_restore_happy_path() {
    local creates i
    ensure_files_cache
    restore_files_world
    run_script -- restore-files
    expect_rc restore-files 0
    expect_has 'the files are read back' "$OUT" '4 file(s) restored to the Resources directory and read back'
    expect_has 'the database restore is not part of it' "$OUT" "next slice's"
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_verify_archive files_restore files_check ' ]] || bad "the restore did not verify first, then extract, then read back: $(tr '\n' ' ' <"$W/runs")"
    creates="$(files_creates)"
    [[ "$(grep -c . <<<"$creates")" == 3 ]] || bad "expected three files containers: $creates"
    expect_has 'the verification is read-only' "$(sed -n 1p <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"
    expect_has 'the extraction writes the volume' "$(sed -n 2p <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html --tmpfs"
    expect_has 'the read-back is read-only' "$(sed -n 3p <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"
    for i in 1 2; do expect_has "container $i reads the archive from standard input" "$(sed -n "${i}p" <<<"$creates")" ' -i '; done
    expect_has 'the verification is of the manifest' "$(sed -n 1p <<<"$creates")" " php /rehearsal/src/harness/files.php verify-archive $(fspec 4)"
    expect_has 'the extraction is of the manifest' "$(sed -n 2p <<<"$creates")" " php /rehearsal/src/harness/files.php restore $(fspec 4)"
    for i in 1 2; do cmp -s "$T/fcache/resources.archive.tar.gz" "$W/stdin.$i" || bad "container $i was not given the archive the manifest describes on standard input"; done
    [[ ! -e "$W/assets_insert.sql" && ! -e "$W/import.sql" && ! -e "$W/clear.sql" ]] || bad 'the file restore touched the database'
    only_database_left restore-files
    no_secret_anywhere restore-files
}

t_g22_restore_refusals() {
    if want_case G22-restore-verified-first; then
        ensure_files_cache
        restore_files_world; printf 'archive:entries=5\n' >"$W/out_files_verify_archive"
        run_script -- restore-files
        expect_refused 'an archive that was not verified' 'Nothing was extracted'
        ! grep -q '^files_restore' "$W/runs" || bad 'an archive that was not verified was extracted'
        restore_files_world; printf 'archive:entries=3\narchive:verified=ok\n' >"$W/out_files_verify_archive"
        run_script -- restore-files
        expect_refused 'an archive with the wrong number of entries' 'Nothing was extracted'
        ! grep -q '^files_restore' "$W/runs" || bad 'an archive with the wrong number of entries was extracted'
        restore_files_world; printf '3\n' >"$W/fail_files_verify_archive"
        run_script -- restore-files
        expect_rc 'an archive the helper refuses' 3
        ! grep -q '^files_restore' "$W/runs" || bad 'an archive the helper refused was extracted'
    fi
    if want_case G22-restore-reported; then
        ensure_files_cache
        restore_files_world; fout present 4 >"$W/out_files_restore"
        run_script -- restore-files
        expect_refused 'an extraction that was not reported' 'restore:extracted=ok'
    fi
    if want_case G22-restore-result; then
        ensure_files_cache
        restore_files_world; fout present 4 restore:extracted=ok | sed "s/ $(fsha 3)\$/ $(fsha 9)/" >"$W/out_files_restore"
        run_script -- restore-files
        expect_refused 'a restored file that is not the manifest' 'not what the manifest records'
        restore_files_world; fout present 3 restore:extracted=ok >"$W/out_files_restore"
        run_script -- restore-files
        expect_refused 'a restore that lost a file' 'not what the manifest records'
        restore_files_world; fout absent 0 restore:extracted=ok >"$W/out_files_restore"
        run_script -- restore-files
        expect_refused 'a restore that created nothing' 'not what the manifest records'
    fi
    if want_case G22-restore-recheck; then
        ensure_files_cache
        restore_files_world; fout present 4 files:check=ok | sed "s/ $(fsha 4)\$/ $(fsha 9)/" >"$W/out_files_check"
        run_script -- restore-files
        expect_refused 'a second look that differs' 'second, read-only look'
    fi
    if [[ -z "$CURRENT_GUARD" ]]; then
        ensure_files_cache
        # A refused extraction (the Resources directory exists) ends the restore with the helper's status; nothing is read back.
        restore_files_world; printf '3\n' >"$W/fail_files_restore"
        run_script -- restore-files
        expect_rc 'an extraction the helper refuses' 3
        ! grep -q '^files_check' "$W/runs" || bad 'a refused extraction was read back as if it had happened'
    fi
}

t_g22_a_zero_file_restore_extracts_nothing() {
    ensure_files_cache
    if want_case G22-restore-zero-absent || [[ -z "$CURRENT_GUARD" ]]; then
        restore_files_world zero
        run_script -- restore-files
        expect_rc 'a zero-file restore with the directory absent' 0
        expect_has 'nothing to extract' "$OUT" 'nothing to extract, and the Resources directory is absent'
        [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check ' ]] || bad "a zero-file restore ran more than the one read-only check: $(tr '\n' ' ' <"$W/runs")"
        expect_has 'read-only' "$(files_creates)" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"
        restore_files_world zero; fout present 2 files:check=ok >"$W/out_files_check"
        run_script -- restore-files
        expect_refused 'a zero-file restore with a directory present' 'a Resources directory exists. It is not removed'
        [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check ' ]] || bad "a directory was touched by a zero-file restore: $(tr '\n' ' ' <"$W/runs")"
    fi
}

# --- aside-files ---

# aside_out BEFORE_N AFTER_N [NAME]: what the helper prints for an aside: the inventory read before the rename, the one read after it, the name, completion.
aside_out() {
    fout present "$1" | sed 's/^/before:/'
    fout present "$2" | sed 's/^/after:/'
    printf 'aside:name=%s\naside:complete=ok\n' "${3:-resources.before-restore-20231114T221320Z}"
}
# aside_world: a verified files backup (installed from the cache) and an established files volume holding four files; the helper reports an exact aside.
aside_world() {
    ensure_files_cache
    restore_files_world
    printf '1700000000' >"$T/fixed-epoch"
    aside_out 4 4 >"$W/out_files_aside"
    fout absent 0 files:check=ok >"$W/out_files_check"
}

t_g22_aside_happy_path() {
    local creates
    aside_world
    run_script -- aside-files
    expect_rc aside-files 0
    expect_has 'the new name' "$OUT" 'resources moved to resources.before-restore-20231114T221320Z (renamed; nothing deleted)'
    expect_has 'the measurement' "$OUT" '4 file(s), read before the rename and again after it, identical in every key, size and SHA-256'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_aside files_check ' ]] || bad "the aside did not run as aside then check: $(tr '\n' ' ' <"$W/runs")"
    creates="$(files_creates)"
    expect_has 'the aside is given the stamp' "$(sed -n 1p <<<"$creates")" ' php /rehearsal/src/harness/files.php aside 20231114T221320Z'
    expect_has 'the aside writes the volume' "$(sed -n 1p <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html --tmpfs"
    expect_has 'the check is read-only' "$(sed -n 2p <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"
    [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad 'an aside created a volume'
    only_database_left aside-files
}

t_g22_aside_refusals() {
    if want_case G22-aside-backup-required; then
        # No verified backup, no aside: refused before the files helper runs, and nothing is synthesized to satisfy the precondition.
        aside_world; rm -f "$T/root/resources.manifest.json"
        run_script -- aside-files
        expect_refused 'no files backup' 'no Resource-files backup'
        no_files_container 'no files backup'
        [[ ! -e "$T/root/resources.manifest.json" ]] || bad 'a manifest was made for an aside that had none'
        aside_world; sed -i 's/"resource_files_count": 4/"resource_files_count": 5/' "$T/root/resources.manifest.json"
        run_script -- aside-files
        expect_refused 'a manifest that is not consistent' 'cannot be relied on'
        no_files_container 'a bad manifest'
        aside_world; printf 'x' >>"$T/root/resources.archive.tar.gz"
        run_script -- aside-files
        expect_refused 'an archive that is not the manifest'"'"'s' 'cannot be relied on'
        no_files_container 'a bad archive'
    fi
    if want_case G22-aside-stamp; then
        aside_world; printf 'not-a-time\n' >"$T/date-raw"
        run_script -- aside-files
        expect_refused 'a time that is not a UTC stamp' 'did not come out as a UTC stamp'
        no_files_container 'a bad stamp'
    fi
    if want_case G22-aside-reported; then
        aside_world; aside_out 4 4 resources.before-restore-20200101T000000Z >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'an aside under another name' 'did not report moving the directory to resources.before-restore-20231114T221320Z'
        aside_world; printf 'aside:name=resources.before-restore-20231114T221320Z\n' >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'an aside that did not complete' 'did not report moving the directory'
    fi
    if [[ -z "$CURRENT_GUARD" ]]; then
        aside_world; { fout present 4 | sed 's/^before:*//; s/^/before:/'; printf 'aside:name=resources.before-restore-20231114T221320Z\naside:complete=ok\n'; } >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'an aside with no inventory of the renamed directory' 'exactly one state'
        aside_world; { fout present 4 | sed 's/^/after:/'; printf 'aside:name=resources.before-restore-20231114T221320Z\naside:complete=ok\n'; } >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'an aside with no inventory of the original' 'exactly one state'
        aside_world; aside_out 4 4 | sed "s/^after:file ${FKEYS[1]} \([0-9]*\) .*/after:file ${FKEYS[1]} \1 nothex/" >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'a malformed inventory of the renamed directory' 'file line of the files helper is malformed'
        aside_world; printf '3\n' >"$W/fail_files_aside"
        run_script -- aside-files
        expect_rc 'an aside the helper refuses (no directory, the name taken, or a directory the inventory refuses)' 3
        ! grep -q '^files_check' "$W/runs" || bad 'a refused aside was read back as if it had happened'
    fi
    if want_case G22-aside-preserved; then
        # What was moved is not what was there: refused, and the script says the moved directory was left exactly where it is.
        aside_world; aside_out 4 3 >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'a file lost in the move' 'not what was there'
        expect_has 'nothing is renamed back or removed' "$ERR" 'nothing was renamed back or removed'
        ! grep -q '^files_check' "$W/runs" || bad 'an aside that did not preserve was read back as a success'
        aside_world; { aside_out 4 4 | sed "s/^after:file ${FKEYS[2]} \([0-9]*\) .*/after:file ${FKEYS[2]} \1 $(fsha 9)/"; } >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'a file whose content changed in the move' 'not what was there'
        aside_world; aside_out 3 4 >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'a file that was not there before' 'not what was there'
        aside_world; { fout absent 0 | sed 's/^/before:/'; fout present 4 | sed 's/^/after:/'; printf 'aside:name=resources.before-restore-20231114T221320Z\naside:complete=ok\n'; } >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'an aside of a directory that was reported absent' 'not what was there'
        aside_world; { fout present 4 | sed 's/^/before:/'; fout absent 0 | sed 's/^/after:/'; printf 'aside:name=resources.before-restore-20231114T221320Z\naside:complete=ok\n'; } >"$W/out_files_aside"
        run_script -- aside-files
        expect_refused 'a renamed directory that was reported absent' 'not what was there'
    fi
    if want_case G22-aside-result; then
        aside_world; fout present 4 files:check=ok >"$W/out_files_check"
        run_script -- aside-files
        expect_refused 'a directory still in place' 'still where it was'
    fi
}

# --- the files volume is established once and never silently replaced ---

t_g22_a_missing_volume_is_never_recreated() {
    local creates runs_before
    if want_case G22-f-volume-gone; then
        # seed-files establishes the volume (and records it); the volume is then removed behind the script's back.
        seed_world
        run_script -- seed-files
        expect_rc 'seed-files, which establishes the volume' 0
        [[ -f "$T/root/files.volume" && "$("$REAL_STAT" -c %a "$T/root/files.volume")" == 600 ]] || bad 'the establishment of the files volume was not recorded in a private file'
        rm -rf "${W:?}/v/$NAME_FV"
        creates="$(grep -c '^volume create ' "$W/calls")"
        runs_before="$(wc -l <"$W/runs")"
        rm -f "$T/root/resources.archive.tar.gz" "$T/root/resources.manifest.json"
        run_script -- backup-files
        expect_refused 'a backup after the volume vanished (a first action that may create)' 'was established earlier in this run and is now missing'
        [[ "$(grep -c '^volume create ' "$W/calls")" == "$creates" ]] || bad 'a files volume was created again after it had been removed'
        [[ "$(wc -l <"$W/runs")" == "$runs_before" ]] || bad 'the files helper ran although the volume had vanished'
        # The same for the commands that never create: a restore and an aside, from a backup that exists.
        ensure_files_cache
        restore_files_world; rm -rf "${W:?}/v/$NAME_FV"; printf 'files-volume-v1\n%s' "$(printf 'flc-run-v1|%s' "$TOKEN" | sha256sum | cut -c1-16)" >"$T/root/files.volume"; chmod 600 "$T/root/files.volume"
        run_script -- restore-files
        expect_refused 'a restore after the volume vanished' 'was established earlier in this run and is now missing'
        aside_world; rm -rf "${W:?}/v/$NAME_FV"; printf 'files-volume-v1\n%s' "$(printf 'flc-run-v1|%s' "$TOKEN" | sha256sum | cut -c1-16)" >"$T/root/files.volume"; chmod 600 "$T/root/files.volume"
        run_script -- aside-files
        expect_refused 'an aside after the volume vanished' 'was established earlier in this run and is now missing'
        [[ "$(grep -c '^volume create ' "$W/calls")" == 0 ]] || bad 'a restore or an aside created a files volume'
        no_files_container 'a restore or an aside after the volume vanished'
    fi
    if want_case G22-f-volume-not-established; then
        ensure_files_cache
        restore_files_world novolume
        run_script -- restore-files
        expect_refused 'a restore with no files volume at all' 'only the first files action of seed-files or backup-files may create it'
        no_files_container 'a restore with no volume'
        [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad 'a restore created the files volume'
        aside_world; rm -rf "${W:?}/v/$NAME_FV"; rm -f "$T/root/files.volume"
        run_script -- aside-files
        expect_refused 'an aside with no files volume at all' 'only the first files action of seed-files or backup-files may create it'
        [[ ! -e "$T/root/files.volume" ]] || bad 'a record of the files volume was written for a volume that does not exist'
        # Only the FIRST files action of a command may create: a volume that disappears between its check and its archive is refused, not recreated.
        backup_files_world up present 4
        printf 'rm -rf "%s/v/%s"\n' "$W" "$NAME_FV" >"$W/after_files_check"
        run_script -- backup-files
        expect_refused 'a volume that vanished between the check and the archive' 'was established earlier in this run and is now missing'
        [[ "$(grep -c '^volume create ' "$W/calls")" == 1 ]] || bad 'the volume was created again between the check and the archive'
        [[ ! -e "$T/root/resources.manifest.json" ]] || bad 'a manifest was written for a backup whose volume vanished'
    fi
    if want_case G22-f-marker; then
        backup_files_world up absent 0; printf 'garbage\n' >"$T/root/files.volume"; chmod 600 "$T/root/files.volume"
        run_script -- backup-files
        expect_refused 'a record of another run' "record of the files volume in the state directory is not this run's"
        no_files_container 'a bad record'
        backup_files_world up absent 0; printf 'files-volume-v1\n%s' "$(printf 'flc-run-v1|%s' "$TOKEN" | sha256sum | cut -c1-16)" >"$T/root/files.volume"; chmod 644 "$T/root/files.volume"
        run_script -- backup-files
        expect_refused 'a record that is not private' "record of the files volume in the state directory is not this run's"
        backup_files_world up absent 0; ln -s /etc/hostname "$T/root/files.volume"
        run_script -- backup-files
        expect_refused 'a record that is a link' "record of the files volume in the state directory is not this run's"
    fi
}

# --- the files container and its volume ---

t_g22_the_files_container_is_verified_before_it_starts() {
    local i
    local -a overrides=() names=() whys=()
    # Each deviation is applied to the container exactly as `docker create` made it, and the script must refuse before it starts: no network (not
    # even the database's), the two mounts and nothing else, read-only exactly when the action is.
    deviation() { overrides+=("$1"); names+=("$2"); whys+=("$3"); }
    deviation $'netmode bridge\nnets bridge,' 'a files container on the default bridge' 'its network is'
    deviation "netmode $NAME_N"$'\n'"nets $NAME_N," 'a files container on the database network' 'its network is'
    deviation "mounts volume:$NAME_SRC:/rehearsal/src:false,volume:$NAME_FV:/var/www/html:true," 'a writable files volume for a read-only action' 'its mounts are'
    deviation "mounts volume:$NAME_FV:/var/www/html:false," 'no source volume' 'its mounts are'
    deviation "mounts volume:$NAME_SRC:/rehearsal/src:true,volume:$NAME_FV:/var/www/html:false," 'a writable source volume' 'its mounts are'
    deviation "mounts volume:$NAME_SRC:/rehearsal/src:false,volume:$NAME_FV:/var/www/html:false,bind::/var/run/docker.sock:true," 'a Docker socket' 'its mounts are'
    deviation "mounts volume:$NAME_SRC:/rehearsal/src:false,volume:$NAME_V:/var/www/html:false," "the database's volume instead of the files volume" 'its mounts are'
    deviation 'tmpfs /tmp,/var/www/html/current/storage,' 'an extra temporary filesystem' 'its temporary filesystems are'
    deviation 'user 0:0' 'root' 'runs as'
    deviation 'l_role runner' "the runner's role" 'its labels are'
    deviation "name /$NAME_R" "the runner's name" 'its name is'
    for i in "${!overrides[@]}"; do
        backup_files_world up absent 0
        printf '%s\n' "${overrides[i]}" >"$W/create_override"
        run_script -- backup-files
        expect_refused "${names[i]}" 'was not created as asked'
        expect_has "${names[i]}" "$ERR" "${whys[i]}"
        [[ ! -e "$W/started" ]] || bad "${names[i]}: the container was started"
        only_database_left "${names[i]}"
    done
}

t_g22_only_actions_that_write_may_write() {
    local action rw stdin
    for spec in 'check|false|0' 'archive|false|0' 'verify-archive|false|1' 'seed|true|0' 'aside|true|0' 'restore|true|1'; do
        IFS='|' read -r action rw stdin <<<"$spec"
        run_fn "files_action_policy $action; echo \"\$FILES_RW \$FILES_STDIN\""
        expect_rc "action $action" 0
        [[ "$OUT" == "$rw $stdin" ]] || bad "action $action: expected writable=$rw stdin=$stdin, got '$OUT'"
    done
    if want_case G22-f-allowlist || [[ -z "$CURRENT_GUARD" ]]; then
        for action in format delete prune '' 'check;id' restore-historical helper:revoke; do
            run_fn "files_action_policy '$action'"
            expect_refused "action '$action'" 'is not a files action the rehearsal runs'
        done
    fi
}

t_g22_the_files_volume_is_proven_before_use() {
    # Each is a volume of the files volume's name that is not the rehearsal's, found by the identity check, not overwritten by the create.
    if want_case G22-v-name; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=other" l_role=files-data
        run_script -- backup-files
        expect_refused 'a volume under another name' "the files volume is not the rehearsal's: its name is 'other'"
    fi
    if want_case G22-v-options; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data options=1
        run_script -- backup-files
        expect_refused 'a volume with driver options (a host path)' 'not a plain local volume'
        no_files_container 'a volume with driver options'
    fi
    if want_case G22-v-label; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=database-data
        run_script -- backup-files
        expect_refused 'a volume with another role' 'its labels are not this script'
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_ns=
        run_script -- backup-files
        expect_refused 'an unlabelled volume of the same name' 'its labels are not this script'
    fi
    if want_case G22-v-token; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_token="$OTHER_TOKEN"
        run_script -- backup-files
        expect_refused 'a volume of another run' 'run token differs'
        no_files_container 'a volume of another run'
    fi
    if want_case G22-v-users-list; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
        inject_failure "ps -aq --no-trunc --filter volume=$NAME_FV" # the files volume's own listing; the source volume's is the source check's and must not answer for it
        run_script -- backup-files
        expect_refused 'a listing of the volume users that fails' "the files volume is not the rehearsal's: the containers using it cannot be listed"
    fi
    if want_case G22-v-in-use; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
        add_container "$FOREIGN" "name=/intruder" "l_ns=" "mounts=volume:$NAME_FV:/data,"
        run_script -- backup-files
        expect_refused 'a volume a foreign container uses' 'that is not ours uses it'
        no_files_container 'a volume a foreign container uses'
    fi
    if want_case G22-f-volume; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_token="$OTHER_TOKEN"
        run_script -- backup-files
        expect_refused 'a volume that is not the rehearsal'"'"'s' "the files volume is not the rehearsal's"
        # The same volume, as the script would have made it, is accepted and is not created again.
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
        run_script -- backup-files
        expect_rc 'the files volume the rehearsal made' 0
        [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad 'a volume that already existed and verified was created again'
    fi
}

t_g22_files_containers_run_only_beside_the_database() {
    if want_case G22-f-no-leftover; then
        backup_files_world up absent 0
        add_ephemeral "$RID" runner
        run_script -- backup-files
        expect_refused 'a runner left over from an interrupted run' 'a rehearsal container other than the database exists'
        no_files_container 'a leftover runner'
        backup_files_world up absent 0
        add_ephemeral "$RID" files
        run_script -- backup-files
        expect_refused 'a files container left over from an interrupted run' 'a rehearsal container other than the database exists'
    fi
    if want_case G22-f-src-volume; then
        backup_files_world up absent 0; rm -rf "${W:?}/v/$NAME_SRC"
        run_script -- backup-files
        expect_refused 'no source volume (prepare has not been run)' "the source volume is not the rehearsal's (has 'prepare' been run?)"
        no_files_container 'no source volume'
        backup_files_world up absent 0; add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source l_token="$OTHER_TOKEN"
        run_script -- backup-files
        expect_refused 'a source volume of another run' "the source volume is not the rehearsal's"
    fi
}

# --- cleanup and self-check ---

t_g22_cleanup_removes_the_files_objects_and_only_those() {
    backup_files_world up absent 0
    add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
    add_ephemeral "$RID" files
    printf 'x' >"$T/root/resources.archive.tar.gz"; printf 'x' >"$T/root/resources.manifest.json"; printf 'x' >"$T/root/files.volume"
    run_script -- cleanup --dry-run
    expect_rc 'a dry cleanup' 0
    expect_has 'the files container is named' "$OUT" "would remove container ${RID:0:12} ($NAME_F)"
    expect_has 'the files volume is named' "$OUT" "would remove volume $NAME_FV"
    [[ -d "$W/c/$RID" && -d "$W/v/$NAME_FV" ]] || bad 'a dry run removed a files object'
    run_script -- cleanup
    expect_rc 'cleanup of the files objects' 0
    [[ ! -d "$W/c/$RID" ]] || bad 'the files container was not removed'
    if want_case G22-cleanup-dispatch || [[ -z "$CURRENT_GUARD" ]]; then [[ ! -d "$W/v/$NAME_FV" ]] || bad 'the files volume was not removed'; fi
    [[ ! -e "$T/root" ]] || bad "the state directory was kept: $(find "$T/root" -mindepth 1 -maxdepth 1 2>/dev/null | tr '\n' ' ')"
    expect_has 'the volume is reported removed' "$OUT" "removed volume $NAME_FV"
}

t_g22_cleanup_keeps_files_objects_that_do_not_verify() {
    backup_files_world up absent 0
    add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_token="$OTHER_TOKEN"
    run_script -- cleanup
    expect_rc 'cleanup with a files volume of another run' 1
    [[ -d "$W/v/$NAME_FV" ]] || bad 'a files volume of another run was removed'
    backup_files_world up absent 0
    add_ephemeral "$RID" files "mounts=volume:$NAME_SRC:/rehearsal/src:false,volume:$NAME_FV:/var/www/html:true,bind::/var/run/docker.sock:true,"
    run_script -- cleanup
    expect_rc 'cleanup with a files container that does not verify' 1
    [[ -d "$W/c/$RID" ]] || bad 'a files container that does not verify was removed'
    backup_files_world up absent 0
    add_ephemeral "$RID" files netmode=bridge nets=bridge,
    run_script -- cleanup
    expect_rc 'cleanup with a files container that had a network' 1
    [[ -d "$W/c/$RID" ]] || bad 'a files container with a network was removed'
    backup_files_world up absent 0
    add_container "$FOREIGN" "name=/$NAME_F" "l_ns=" "mounts=volume:elsewhere:/x,"
    run_script -- cleanup
    expect_rc 'cleanup beside an unlabelled container named like the files container' 0
    [[ -d "$W/c/$FOREIGN" ]] || bad 'an unlabelled container named like the files container was removed'
    backup_files_world up absent 0
    mkdir -p "$W/v/$NAME_FV"; put "$W/v/$NAME_FV" name "$NAME_FV"; put "$W/v/$NAME_FV" l_ns ''
    run_script -- self-check
    expect_refused 'an unlabelled volume named like the files volume' 'cannot prove it created'
}

t_g22_self_check_verifies_the_files_volume() {
    backup_files_world up absent 0
    add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
    run_script -- self-check
    expect_rc 'a self-check with the files volume' 0
    expect_has 'the files volume is verified' "$OUT" 'files volume is a plain local volume used by no container'
    if want_case G22-selfcheck-files || [[ -z "$CURRENT_GUARD" ]]; then
        backup_files_world up absent 0; add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data l_token="$OTHER_TOKEN"
        run_script -- self-check
        expect_refused 'a files volume of another run' "the files volume is not the rehearsal's"
    fi
}

# --- what cannot be asked of it ---

t_g22_surface() {
    local c
    for c in seed-files backup-files aside-files restore-files; do
        new_world
        run_script -- "$c" an-argument
        expect_rc "$c with an argument" 64
        assert_no_docker_call "$c with an argument"
        run_script -- --help
        expect_has "$c is in the usage" "$OUT" "$c"
    done
    # The files helper is not reachable through `run`, and `run` still allows exactly what it allowed.
    src_world
    for c in helper:files-check helper:files helper:files-seed files.php 'files.php check none'; do
        run_script -- run current "$c"
        expect_refused "run current $c" "is not a command the rehearsal runs"
    done
    no_helper_ran 'run of a files helper'
    new_world
    run_script -- restore-files
    expect_refused 'a restore with no state directory at all' 'there is no rehearsal state'
    assert_read_only_calls_only 'a restore with no state directory at all'
}
assert_read_only_calls_only() { # DESC: nothing but listing and inspecting happened
    local line
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        case "$line" in
            "context show" | "context inspect "* | "info "* | "ps "* | "inspect --type container --format "* | "network ls "* | "network inspect --format "* | "volume ls "* | "volume inspect --format "*) ;;
            *) bad "$1: a mutating or unexpected docker call was made: $line" ;;
        esac
    done <"$W/calls"
}

# shellcheck disable=SC2016 # the patterns are for grep, and their $ are literal
t_g22_static_the_files_slice_is_what_the_contract_says() {
    local script="$SRC/restore-rehearsal.sh" helper="$SRC/php/files.php" support="$ROOT/apps/platform/tests/Support/ResourceFileArchive.php" disk
    # The volume's root is the application's storage/app/private, so `resources` in it is the private Resources disk's root.
    disk="$(grep -A3 "'resources' => \[" "$ROOT/apps/platform/config/filesystems.php" | grep "'root'" || true)"
    [[ "$disk" == *"storage_path('app/private/resources')"* ]] || bad "the application's Resources disk is not rooted at storage/app/private/resources: $disk"
    grep -q "^readonly FILES_STORE_DIR='resources' " "$script" || bad "FILES_STORE_DIR is not the last component of the application's Resources disk root"
    grep -q "public const string STORE = 'resources';" "$support" || bad "the support class's STORE is not 'resources'"
    grep -q "^const FILES_ROOT = '/var/www/html';" "$helper" || bad 'the helper has no fixed root'
    grep -q "^readonly FILES_MOUNT='/var/www/html'" "$script" || bad 'the files volume is not mounted at the helper'"'"'s fixed root'
    [[ "$(grep -c '/var/www/html' "$helper")" == 1 ]] || bad "the helper names its root other than in its one constant: $(grep -n '/var/www/html' "$helper")"
    # No caller chooses a directory, an archive or a statement: the helper takes only the action and its list or stamp, and the root is a constant.
    if grep -nE '\$argv\[[2-9]\]|getenv|\$_ENV|\$_SERVER\[.(argv|PATH)' "$helper" | grep -v '^[0-9]*:[[:space:]]*//' | grep -q .; then bad 'the helper reads an argument beyond the action and its one value, or the environment'; fi
    if grep -nE '\b(curl_|fsockopen|stream_socket_client|socket_create|gethostbyname|file_get_contents\(.https?:|mysqli|new PDO|shell_exec|passthru|system\(|exec\(|popen|proc_open|unlink\()' "$helper" | grep -v '^[0-9]*:[[:space:]]*//' | grep -v "@unlink(\$archive)" | grep -q .; then bad 'the helper has a network, database, shell or delete call'; fi
    if grep -nE '\b(unlink|rmdir|rename|exec|shell_exec|system|passthru|popen)\(' "$support" | grep -v '^[0-9]*:[[:space:]]*\*' | grep -v '@unlink(\$temporary)' | grep -v 'rename(\$from' | grep -q .; then bad 'the support class deletes, renames or runs something other than its one temporary file, its one rename and its tar'; fi
    # What the script reads from the helper is what the helper prints (the lesson of N1: a script and a helper that agree only because each was written to
    # match the other's description). Every protocol line the script greps for or parses is a literal the helper prints.
    local token
    for token in 'files:state=' 'files:count=' 'files:check=ok' 'files:seed=ok' 'archive:verified=ok' 'archive:entries=' 'aside:name=' 'aside:complete=ok' 'restore:extracted=ok'; do
        grep -qF "$token" "$helper" || bad "the helper never prints '$token', which the script reads"
        grep -qF "$token" "$script" || bad "the script never reads '$token', which the helper prints"
    done
    grep -qF 'file %s %d %s' "$helper" || bad 'the helper does not print file lines as "file <key> <bytes> <sha256>"'
    # The entry count the helper prints is the one the reader measured, not one derived from the list it was asked to verify (L3).
    { grep -qF '$accepted = ResourceFileArchive::verifyArchive(' "$helper" && grep -qF "'archive:entries='.\$accepted" "$helper" && ! grep -qF 'count(archiveRows(' "$helper"; } || bad 'the helper prints an archive entry count that is not the reader'"'"'s'
    # The aside is measured: both inventories are printed, with their own prefixes, and the class returns them.
    { grep -qF "printInventory(\$aside['before'], 'before:')" "$helper" && grep -qF "printInventory(\$aside['after'], 'after:')" "$helper"; } || bad 'the helper does not print the inventories before and after the aside'
    # Standard output is protocol and, for `archive`, the archive: nothing else may reach it, so diagnostics go to standard error and a warning is an error.
    { grep -q "ini_set('display_errors', 'stderr')" "$helper" && grep -q 'set_error_handler' "$helper"; } || bad 'the helper does not keep warnings off standard output'
    # The files container is created only by run_files, from fixed arguments, and has no environment, no env file and no published port.
    [[ "$(grep -c 'create_verified "\$ROOT" "\$token" files' "$script")" == 1 ]] || bad 'the files container is created other than by run_files'
    [[ "$(grep -c 'harness/files.php' "$script")" == 1 ]] || bad 'the files helper is named other than where the files container is created'
    sed -n '/^run_files() {/,/^}/p' "$script" >"$T/run_files.txt"
    if grep -nE '(^|[[:space:]])-e[[:space:]]|--env|-p[[:space:]]|--publish|--privileged|--network host|docker\.sock|type=bind|--volumes-from' "$T/run_files.txt" | grep -q .; then bad 'run_files gives the container an environment, a port, privilege, a bind mount or the socket'; fi
    grep -q -- '--network none' "$T/run_files.txt" || bad 'run_files does not give the container no network'
    grep -q 'plain_docker' "$T/run_files.txt" || bad 'the files container is not created by the credential-free launcher'
    ! grep -q 'launch_docker' "$T/run_files.txt" || bad 'the files container is created by the launcher that carries the database password and keys'
    # The two new state files are the only ones added, and cleanup lists them.
    grep -q 'STATE_FILES=.*"\$FILES_ARCHIVE_FILE" "\$FILES_MANIFEST_FILE" "\$FILES_VOLUME_MARKER"' "$script" || bad 'the state files do not include the archive, its manifest and the record of the files volume'
    # The compose file gains nothing: the files volume is not Compose's, and nothing in it names a files object.
    ! grep -qi 'files' "$SRC/compose.yaml" || bad 'compose.yaml mentions the files volume or container'
    # `php -l` where there is a php.
    if command -v php >/dev/null 2>&1; then php -l "$helper" >/dev/null 2>&1 || bad 'files.php does not parse'; fi
}

# --- tests: slice 2.2C-3, WU4: the current profile, snapshot-all, backup-paired and the recovery unit ----------------------------------------------------------------
#
# The harness (php/rehearse.php, php/files.php) runs in containers, so here it is a fake, as everywhere: out_snapshot_all (out_snapshot_all.<n> for the nth run) is what
# `rehearse.php snapshot-all` prints, out_files_<action> what the files helper prints, dump_pass1 and dump_pass2 the two passes of the dump client. What the PHP side
# does with a real database is apps/platform/tests/Unit/Rehearsal/CurrentSnapshotTest.php and tests/Feature/Rehearsal/CurrentSnapshotDatabaseTest.php.
# Nothing in the historical profile's tests is changed by this slice: the tests above still run, as they did, against the same functions.

LEDGER_LAST='2026_10_11_000001_add_provenance_to_contact_methods'
sorted_up() { up_names | LC_ALL=C sort; }
crows_of() { # TABLE: the rows of each of the 34 tables in the post-upgrade, post-seed state: the fixture's, the upgrade's marker profile and Person, the four seeded assets
    case "$1" in
        account_invitations) echo 4 ;; account_recovery_codes) echo 5 ;; account_totp_factors) echo 3 ;; accounts) echo 11 ;; membership_grants) echo 5 ;;
        migrations) echo 29 ;; password_reset_tokens) echo 2 ;; people) echo 14 ;; role_assignments) echo 12 ;; security_events) echo 8 ;; sessions) echo 3 ;;
        contact_profiles) echo 1 ;; resource_assets) echo 4 ;;
        *) echo 0 ;;
    esac
}
all_block() { # SEED: the `all-table` lines of the 34 tables, by name (a table with no rows has the digest of none)
    local t
    for t in $(sorted_up); do
        if [[ "$(crows_of "$t")" == 0 ]]; then printf 'all-table %s 0 %s\n' "$t" "$EMPTY_DIGEST"; else printf 'all-table %s %s %s\n' "$t" "$(crows_of "$t")" "$(fake_digest "$t$1")"; fi
    done
}
snapshot_all_output() { # SEED [LEDGER_DIGEST]: what `snapshot-all` prints
    printf 'key:current=%s\nkey:previous=%s\n' "$FP_CUR" "$FP_PREV"
    all_block "$1"
    structure_block up
    printf 'ledger:count=29\nledger:last=%s\nledger:digest=%s\nsnapshot-all:complete=ok\n' "$LEDGER_LAST" "${2:-$(fake_digest ledger)}"
}
# The two passes of the 34-table dump in the form mariadb-dump 10.19 of MariaDB 10.11.18 writes them (rows on the lines after VALUES; a durable table with no rows has only its
# LOCK and KEYS lines; a transient table is ignored by the second pass and has structure only). Composed from the tool's documented output and the one observed live (L2):
# NOT taken from a live 34-table dump, which is the evidence gap the development note names.
put_current_dumps() {
    local t n i
    {
        printf '/*M!999999\\- enable the sandbox mode */\n-- MariaDB dump 10.19  Distrib 10.11.18-MariaDB, for debian-linux-gnu (x86_64)\n--\n-- Host: 127.0.0.1    Database: flc_rehearsal_app\n-- ------------------------------------------------------\n\n'
        printf '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!50503 SET NAMES utf8mb4 */;\n/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;\n\n'
    } | tee "$W/dump_pass1" >"$W/dump_pass2"
    for t in $(sorted_up); do
        printf -- '--\n-- Table structure for table \x60%s\x60\n--\n\nDROP TABLE IF EXISTS \x60%s\x60;\n/*!40101 SET @saved_cs_client     = @@character_set_client */;\n/*!40101 SET character_set_client = utf8 */;\nCREATE TABLE \x60%s\x60 (\n  \x60id\x60 int(11) NOT NULL,\n  PRIMARY KEY (\x60id\x60)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n/*!40101 SET character_set_client = @saved_cs_client */;\n\n' "$t" "$t" "$t" >>"$W/dump_pass1"
        [[ " ${TRANSIENT[*]} " != *" $t "* ]] || continue
        n="$(crows_of "$t")"
        printf -- '--\n-- Dumping data for table \x60%s\x60\n--\n\nLOCK TABLES \x60%s\x60 WRITE;\n/*!40000 ALTER TABLE \x60%s\x60 DISABLE KEYS */;\n' "$t" "$t" "$t" >>"$W/dump_pass2"
        if ((n > 0)); then
            printf 'INSERT INTO \x60%s\x60 VALUES\n' "$t" >>"$W/dump_pass2"
            for ((i = 1; i <= n; i++)); do printf '(%d)%s\n' "$i" "$( ((i < n)) && echo , || echo ';')" >>"$W/dump_pass2"; done
        fi
        printf '/*!40000 ALTER TABLE \x60%s\x60 ENABLE KEYS */;\nUNLOCK TABLES;\n\n' "$t" >>"$W/dump_pass2"
    done
    printf '/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;\n-- Dump completed on 2026-11-20 12:00:00\n' | tee -a "$W/dump_pass1" >>"$W/dump_pass2"
}

# The upgraded database (34 tables, the post-upgrade state), the four seeded Resource files and their asset rows, the files volume, the prepared source, and what each helper
# reports: the same measurement both times unless a test says otherwise. Not a thing the script is told: a world.
paired_world() {
    backup_files_world up present 4
    add_volume "$NAME_FV" "name=$NAME_FV" l_role=files-data
    snapshot_all_output one >"$W/out_snapshot_all"
    put_current_dumps
}
PAIRED_FILES=(current.dump.sql resources.archive.tar.gz resources.manifest.json recovery.manifest.json)
paired_nothing_in_place() { # DESC: no part of the backup, and no temporary file, is in the state directory
    local f
    for f in "${PAIRED_FILES[@]}"; do [[ ! -e "$T/root/$f" ]] || bad "$1: $f was put in place"; done
    if compgen -G "$T/root/.paired.*" >/dev/null; then bad "$1: a temporary file was left in the state directory"; fi
}
no_snapshot_all_ran() { [[ ! -e "$W/runs" ]] || ! grep -q '^snapshot_all$' "$W/runs" || bad "$1: the snapshot helper ran"; }
# ensure_paired_cache: one paired backup, made by the UNMUTATED script (SUT=$SRC, as prepare_caches does for the other fixtures) and kept for the tests of the recovery unit.
# It builds a world of its own, so a test calls it BEFORE its own world. A file-based cache, so that it survives the subshell each mutant's tests run in.
ensure_paired_cache() {
    local sut="$SUT" f
    [[ ! -s "$T/pcache/recovery.manifest.json" || ! -s "$T/pcache/current.dump.sql" || ! -s "$T/pcache/resources.manifest.json" || ! -s "$T/pcache/resources.archive.tar.gz" ]] || return 0
    SUT="$SRC"
    paired_world
    run_script -- backup-paired
    SUT="$sut"
    [[ "$RC" == 0 ]] || bad "preparing the cached paired backup: exit $RC: ${ERR:0:300}"
    rm -rf "${T:?}/pcache"
    mkdir -p "$T/pcache"
    for f in "${PAIRED_FILES[@]}"; do cp -p "$T/root/$f" "$T/pcache/"; done
}
install_paired() { local f; for f in "${PAIRED_FILES[@]}"; do cp -p "$T/pcache/$f" "$T/root/"; done; } # into the world's state directory
expected_recovery_manifest() { # DUMP_SHA DUMP_BYTES FM_SHA FM_BYTES AR_SHA AR_BYTES: written out here, not by the script's own function
    local t rows rsha class list='' with_data='' schema_only='' structure=''
    for t in $(sorted_up); do
        rows="$(crows_of "$t")"
        if [[ "$rows" == 0 ]]; then rsha="$EMPTY_DIGEST"; else rsha="$(fake_digest "${t}one")"; fi
        if [[ " ${TRANSIENT[*]} " == *" $t "* ]]; then class=transient schema_only+="${schema_only:+, }\"$t\""; else class=durable with_data+="${with_data:+, }\"$t\""; fi
        list+="    { \"name\": \"$t\", \"class\": \"$class\", \"rows\": $rows, \"row_sha256\": \"$rsha\", \"structure_sha256\": \"$(fake_digest "struct-$t-up")\" },"$'\n'
        structure+="$t $(fake_digest "struct-$t-up")"$'\n'
    done
    list="${list%,$'\n'}"
    printf '{\n  "recovery_manifest_version": 1,\n  "backup_id": "20231114T221320Z",\n  "taken_at": "2023-11-14T22:13:20Z",\n  "reason": "rehearsal",\n  "run_token_fingerprint": "%s",\n  "release": {\n    "version": "unreleased",\n    "commit": "%s"\n  },\n  "database": {\n    "connection": "mariadb",\n    "name": "flc_rehearsal_app",\n    "server_version": "10.11.18-MariaDB-ubu2204"\n  },\n  "keyring": {\n    "current_key_fingerprint": "%s",\n    "previous_key_fingerprints": ["%s"],\n    "keyring_fingerprint": "%s",\n    "previous_keys_configured": true\n  },\n  "database_dump_file": "current.dump.sql",\n  "database_dump_sha256": "%s",\n  "database_dump_bytes": %s,\n  "migrations_count": 29,\n  "migrations_last": "%s",\n  "migrations_sha256": "%s",\n  "structure_sha256": "%s",\n  "tables_with_data": [%s],\n  "tables_schema_only": [%s],\n  "resources_manifest_file": "resources.manifest.json",\n  "resources_manifest_sha256": "%s",\n  "resources_manifest_bytes": %s,\n  "resources_archive_file": "resources.archive.tar.gz",\n  "resources_archive_sha256": "%s",\n  "resources_archive_bytes": %s,\n  "resources_files_count": 4,\n  "resources_asset_rows": 4,\n  "resources_inventory_sha256": "%s",\n  "tables": [\n%s\n  ]\n}' \
        "$FP_RUN" "$HEAD_SHA" "$FP_CUR" "$FP_PREV" "$RING_CUR_PREV" "$1" "$2" "$LEDGER_LAST" "$(fake_digest ledger)" "$(printf '%s' "$structure" | sha256sum | cut -c1-64)" "$with_data" "$schema_only" \
        "$3" "$4" "$5" "$6" "$(fspec 4 | tr -d '\n' | sha256sum | cut -c1-64)" "$list"
}

# --- the happy path ---

# shellcheck disable=SC2016 # the client's own $MARIADB_PASSWORD is text to look for, not an expansion
t_g25_paired_happy_path() {
    local f m dump creates i line table
    paired_world
    run_script -- backup-paired
    expect_rc backup-paired 0
    expect_has 'the summary' "$OUT" 'Paired backup done'
    expect_has 'no claim of atomicity' "$OUT" 'This is not an atomic snapshot'
    expect_has 'measured twice' "$OUT" 'measured again afterwards'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check snapshot_all files_archive files_verify_archive files_check snapshot_all ' ]] || bad "the helpers did not run as check, snapshot, archive, verify, check, snapshot: $(tr '\n' ' ' <"$W/runs")"
    for f in "${PAIRED_FILES[@]}"; do
        [[ -f "$T/root/$f" && ! -L "$T/root/$f" && "$("$REAL_STAT" -c %a "$T/root/$f")" == 600 ]] || bad "$f is not a private regular file in the state directory"
    done
    if compgen -G "$T/root/.paired.*" >/dev/null; then bad 'a temporary file was left in the state directory'; fi
    dump="$T/root/current.dump.sql"
    [[ "$(cat "$W/dump_pass1" "$W/dump_pass2")" == "$(<"$dump")" ]] || bad 'the dump is not pass 1 followed by pass 2'
    [[ "$(grep -c . "$W/dumpcalls")" == 2 ]] || bad 'the dump client was not called exactly twice'
    [[ "$(sed -n 1p "$W/dumpcalls")" == 'DUMP args=[--no-data --no-tablespaces flc_rehearsal_app]' ]] || bad "pass 1 was: $(sed -n 1p "$W/dumpcalls")"
    line='DUMP args=[--no-create-info --single-transaction --quick --no-tablespaces'
    for table in "${TRANSIENT[@]}"; do line+=" --ignore-table=flc_rehearsal_app.$table"; done
    [[ "$(sed -n 2p "$W/dumpcalls")" == "$line flc_rehearsal_app]" ]] || bad "pass 2 was: $(sed -n 2p "$W/dumpcalls")"
    [[ "$(<"$T/root/recovery.manifest.json")" == "$(expected_recovery_manifest "$(sha256sum <"$dump" | cut -c1-64)" "$(wc -c <"$dump" | tr -d ' ')" \
        "$(sha256sum <"$T/root/resources.manifest.json" | cut -c1-64)" "$(wc -c <"$T/root/resources.manifest.json" | tr -d ' ')" \
        "$(sha256sum <"$T/root/resources.archive.tar.gz" | cut -c1-64)" "$(wc -c <"$T/root/resources.archive.tar.gz" | tr -d ' ')")" ]] || bad "the recovery manifest is not the one written out independently in this test: $(<"$T/root/recovery.manifest.json")"
    printf 'ARCHIVE\x00BYTES\xff' | cmp -s - "$T/root/resources.archive.tar.gz" || bad 'the archive in the state directory is not the bytes the files container wrote to its standard output'
    [[ "$(<"$T/root/resources.manifest.json")" == "$(expected_files_manifest present "$(sha256sum <"$T/root/resources.archive.tar.gz" | cut -c1-64)" "$(wc -c <"$T/root/resources.archive.tar.gz" | tr -d ' ')" 4 4)" ]] || bad 'the Resource-files manifest is not the proven format of L3b'
    # The two runners of the snapshot: the CURRENT tree, the fixed command, and nothing the caller chose.
    creates="$(grep -E "^create .*--name $NAME_R " "$W/calls")"
    [[ "$(grep -c . <<<"$creates")" == 2 ]] || bad "expected two runners (the two measurements), got: $creates"
    for i in 1 2; do
        expect_has "runner $i is fixed" "$(sed -n "${i}p" <<<"$creates")" ' php /var/www/html/harness/rehearse.php snapshot-all'
        expect_has "runner $i is in the current tree" "$(sed -n "${i}p" <<<"$creates")" '--workdir /var/www/html/current'
    done
    # The files containers: check, archive, verify, check. The first two and the last read the volume read-only.
    creates="$(grep -E "^create .*--name $NAME_F " "$W/calls")"
    [[ "$(grep -c . <<<"$creates")" == 4 ]] || bad "expected four files containers, got: $creates"
    for i in 1 2 3 4; do expect_has "files container $i reads the volume read-only" "$(sed -n "${i}p" <<<"$creates")" "--mount type=volume,source=$NAME_FV,target=/var/www/html,readonly"; done
    [[ "$(grep -c '^volume create ' "$W/calls" || true)" == 0 ]] || bad 'a paired backup created a volume'
    # It changes nothing in the database: the only statements are reads.
    if [[ -f "$W/sql.log" ]] && grep -qE 'CREATE|DROP|INSERT|DELETE|ALTER|UPDATE|TRUNCATE|GRANT' "$W/sql.log"; then bad 'a root statement changed data or schema'; fi
    [[ "$(grep -cE 'DROP|ALTER|DELETE|UPDATE|TRUNCATE|INSERT|CREATE' "$W/appsql.log" || true)" == 0 ]] || bad 'the application account ran a statement other than a read'
    # No historical artifact was made or read.
    for f in historical.dump.sql historical.manifest.json historical.snapshot; do [[ ! -e "$T/root/$f" ]] || bad "$f appeared: the historical profile's files are not part of this stage"; done
    for f in "$KEY_CUR" "$KEY_PREV" "${KEY_CUR#base64:}" "${KEY_PREV#base64:}" "$APP_PW" "$ROOT_PW" "$TOKEN"; do expect_lacks 'the recovery manifest' "$(<"$T/root/recovery.manifest.json")" "$f"; done
    only_database_left backup-paired
    no_secret_anywhere backup-paired
    # And the unit is one: the verifier that a restore will rely on accepts what the stage just put in place.
    run_fn "ROOT='$T/root'; require_recovery_unit"
    expect_rc 'the recovery unit as put in place' 0
}

t_g25_paired_takes_no_arguments() {
    paired_world
    run_script -- backup-paired anything
    [[ "$RC" == 64 ]] || bad "backup-paired with an argument exited $RC, not 64"
    expect_has 'usage' "$ERR" 'backup-paired'
    no_helper_ran 'an argument'
    paired_nothing_in_place 'an argument'
}

# --- what it refuses before anything is taken ---

t_g25_paired_preconditions() {
    local f
    if want_case G25-paired-no-overwrite; then
        for f in "${PAIRED_FILES[@]}"; do
            paired_world
            printf 'x' >"$T/root/$f"
            chmod 600 "$T/root/$f"
            run_script -- backup-paired
            expect_refused "an existing $f" 'never overwritten'
            expect_has "the file is named" "$ERR" "'$f'"
            no_helper_ran "an existing $f"
            [[ "$(<"$T/root/$f")" == x ]] || bad "an existing $f was changed"
            [[ ! -e "$W/dumpcalls" ]] || bad "a dump was taken although $f exists"
        done
    fi
    if want_case G25-paired-inventory; then
        paired_world; hist_names >"$W/inventory"
        run_script -- backup-paired
        expect_refused 'the v0.3.0 database' 'not exactly the 34 tables of the current schema'
        no_helper_ran 'the v0.3.0 database'
        paired_world; { up_names; echo extra_table; } >"$W/inventory"
        run_script -- backup-paired
        expect_refused 'a database with an extra table' 'not exactly the 34 tables of the current schema'
        paired_world; up_names | grep -vx sourced_role_grants >"$W/inventory"
        run_script -- backup-paired
        expect_refused 'a database with a table missing' 'not exactly the 34 tables of the current schema'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken from a database that is not the current schema'
    fi
    if want_case G25-paired-state; then
        paired_world; printf '13\t1\t15\t1\n' >"$W/counts"
        run_script -- backup-paired
        expect_refused 'the WP3-01 state' 'not the post-upgrade state'
        no_helper_ran 'the WP3-01 state'
        paired_world; printf '14\t0\t29\t0\n' >"$W/counts"
        run_script -- backup-paired
        expect_refused 'an upgraded database without the marker' 'not the post-upgrade state'
    fi
    if want_case G25-paired-assets; then
        paired_world; : >"$W/asset_rows"
        run_script -- backup-paired
        expect_refused 'no asset rows' 'does not hold the 4 synthetic asset rows'
        no_files_container 'no asset rows'
        paired_world; frows 3 >"$W/asset_rows"
        run_script -- backup-paired
        expect_refused 'three asset rows' 'does not hold the 4 synthetic asset rows'
        paired_world; printf '0\n' >"$W/assets_table"
        run_script -- backup-paired
        expect_refused 'no resource_assets table' 'does not hold the 4 synthetic asset rows'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken with no Resource-file records to bind'
    fi
    if want_case G25-paired-check-reported; then
        paired_world; fout present 4 >"$W/out_files_check"
        run_script -- backup-paired
        expect_refused 'a check the helper did not report' 'files:check=ok'
        paired_nothing_in_place 'a check the helper did not report'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken after a check that was not reported'
    fi
    if want_case G25-paired-agree; then
        paired_world; fout present 4 files:check=ok | sed "s/ $(fsha 2)\$/ $(fsha 9)/" >"$W/out_files_check"
        run_script -- backup-paired
        expect_refused 'a listing that is not the rows' 'do not agree on every key, size and SHA-256'
        paired_world; fout absent 0 files:check=ok >"$W/out_files_check"
        run_script -- backup-paired
        expect_refused 'an absent directory while rows exist' 'do not agree on every key, size and SHA-256'
        paired_nothing_in_place 'a directory that does not agree'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken from a directory that does not agree with the rows'
        no_snapshot_all_ran 'a directory that does not agree'
    fi
}

# --- what it refuses when the helper's measurement is not complete, or not one the other readers agree with ---

t_g25_paired_the_measurement_is_validated() {
    local good
    good() { snapshot_all_output one; }
    if want_case G25-snapshot-all-reported; then
        paired_world; good | grep -v '^snapshot-all:complete=ok$' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a snapshot that did not say it finished' 'did not report snapshot-all:complete=ok'
        paired_nothing_in_place 'a snapshot that did not say it finished'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken after an incomplete snapshot'
    fi
    if want_case G25-all-line-shape; then
        paired_world; good | sed 's/^all-table people \([0-9]*\) [0-9a-f]*$/all-table people \1 nothex/' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a digest that is not a SHA-256' "an 'all-table' line of the snapshot is malformed"
        paired_world; good | sed 's/^all-table people \([0-9]*\) /all-table people -\1 /' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a row count that is not a number' "an 'all-table' line of the snapshot is malformed"
        paired_world; good | sed 's/^all-table people \(.*\)$/all-table people \1 extra/' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a line with a field too many' "an 'all-table' line of the snapshot is malformed"
    fi
    if want_case G25-all-complete; then
        paired_world; good | grep -v '^all-table sourced_role_grants ' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a table missing from the snapshot' 'not exactly one for each of the 34 tables'
        paired_world; { good; all_block one | grep '^all-table people '; } >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a table repeated in the snapshot' 'not exactly one for each of the 34 tables'
        paired_world; { good; echo "all-table surprise 0 $EMPTY_DIGEST"; } >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a table the schema does not have' 'not exactly one for each of the 34 tables'
        paired_world; { printf 'key:current=%s\nkey:previous=%s\n' "$FP_CUR" "$FP_PREV"; table_block table one; structure_block up; printf 'ledger:count=29\nledger:last=%s\nledger:digest=%s\nsnapshot-all:complete=ok\n' "$LEDGER_LAST" "$(fake_digest ledger)"; } >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'the 16 table lines of the historical snapshot instead' 'not exactly one for each of the 34 tables'
        paired_nothing_in_place 'a snapshot that is not the 34 tables'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken after a snapshot that is not the 34 tables'
    fi
    if want_case G25-ledger-evidence; then
        paired_world; good | sed 's/^ledger:count=29$/ledger:count=28/' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a ledger of 28' 'migration ledger is not the 29 migrations'
        paired_world; good | sed 's/^ledger:last=.*$/ledger:last=2026_10_09_000003_create_sourced_role_grants_table/' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a ledger that ends elsewhere' 'migration ledger is not the 29 migrations'
        paired_world; good | sed 's/^ledger:digest=.*$/ledger:digest=short/' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a ledger digest that is not a SHA-256' 'migration ledger is not the 29 migrations'
        paired_world; good | grep -v '^ledger:digest=' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a ledger with no digest' 'migration ledger is not the 29 migrations'
        paired_world; { good; echo "ledger:count=29"; } >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a ledger count said twice' 'migration ledger is not the 29 migrations'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken after an unreadable ledger'
    fi
    if want_case G25-assets-two-readers; then
        paired_world; good | sed 's/^all-table resource_assets 4 /all-table resource_assets 3 /' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'the application and the query disagree about the asset rows' 'counts 3 resource_assets rows and the query for the asset rows 4'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken while two readers of the asset rows disagreed'
    fi
    if [[ -z "$CURRENT_GUARD" ]]; then
        # Not new guards, but new uses of two older ones: the structure lines (G13-structure-complete) and the keys the application used (G14-keys-agree).
        paired_world; good | grep -v '^structure sourced_role_grants ' >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'a structure line missing' 'structure lines are not exactly one for each expected table'
        paired_world; good | sed "s/^key:current=.*/key:current=$FP_FF/" >"$W/out_snapshot_all"
        run_script -- backup-paired
        expect_refused 'keys the application used that are not this run' 'not the one this run generated'
        paired_nothing_in_place 'a measurement that is not valid'
    fi
}

# --- the second measurement: anything that changed while the artifacts were taken ---

t_g25_paired_a_change_during_the_backup_is_refused() {
    local why
    if want_case G25-paired-quiescent; then
        change() { # DESC NEEDLE: the second measurement is the one written to out_snapshot_all.2 (and whatever the caller did to the world)
            run_script -- backup-paired
            expect_refused "$1" 'changed while the backup was being taken'
            expect_has "$1: what changed" "$ERR" "$2"
            expect_has "$1: nothing in place" "$ERR" 'Nothing was put in place'
            paired_nothing_in_place "$1"
            [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check snapshot_all files_archive files_verify_archive files_check snapshot_all ' ]] || bad "$1: the second measurement did not run after both artifacts: $(tr '\n' ' ' <"$W/runs")"
            only_database_left "$1"
        }
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output two >"$W/out_snapshot_all.2"
        change 'every durable table with rows changed' 'people'
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output one | sed "s/^all-table security_events \([0-9]*\) [0-9a-f]*\$/all-table security_events \1 $(fake_digest altered)/" >"$W/out_snapshot_all.2"
        change 'one durable table changed in content, its row count the same' 'security_events'
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output one | sed 's/^all-table people 14 /all-table people 15 /' >"$W/out_snapshot_all.2"
        change 'a Person added' 'people'
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output one | sed "s/^all-table sessions \([0-9]*\) [0-9a-f]*\$/all-table sessions 4 $(fake_digest session)/" >"$W/out_snapshot_all.2"
        change 'a session appeared (a transient table is measured too)' 'sessions'
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output one | sed "s/^structure people .*\$/structure people $(fake_digest altered-structure)/" >"$W/out_snapshot_all.2"
        change 'a table whose structure changed' 'structure'
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output one "$(fake_digest other-ledger)" >"$W/out_snapshot_all.2"
        change 'the ledger changed' 'ledger'
        # The Resource files: a file and its asset row both change after the archive was verified; the database and the directory still agree with each other,
        # but not with what was measured the first time.
        paired_world
        frows 4 | sed "2s/\\t$(fsha 2)\$/\\t$(fsha 9)/" >"$W/rows_changed"
        printf 'cp "%s/rows_changed" "%s/asset_rows"\n' "$W" "$W" >"$W/after_files_verify_archive"
        fout present 4 files:check=ok >"$W/out_files_check.1"
        fout present 4 files:check=ok | sed "s/ $(fsha 2)\$/ $(fsha 9)/" >"$W/out_files_check.2"
        change 'a Resource file and its row changed' 'resource-files'
    fi
    if [[ -z "$CURRENT_GUARD" ]]; then
        # The same refusal leaves the earlier evidence honest: it says the stage did not finish.
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; snapshot_all_output two >"$W/out_snapshot_all.2"
        run_script -- backup-paired
        expect_has 'the trailer' "$ERR" 'backup-paired did not finish'
        expect_has 'the recovery' "$ERR" 'cleanup'
        expect_has 'nothing of this backup was put in place' "$ERR" 'Nothing of this backup was put in place'
        why="$(grep -c 'no change was observed' <<<"$OUT" || true)"
        [[ "$why" == 0 ]] || bad 'a backup that was refused said no change was observed'
    fi
}

# --- the dump of the current schema, as a stage and as a validator ---

# fresh_dump: the two passes of a world, joined as the stage joins them, and what the first measurement counted, in files the validator is run against. The world is built once
# for the test process (building it is most of what a case costs) and copied for each case.
fresh_dump() {
    if [[ ! -s "$T/fresh.sql" ]]; then
        paired_world
        cat "$W/dump_pass1" "$W/dump_pass2" >"$T/fresh.sql"
        all_block one | awk '{ print $2 " " $3 " " $4 }' >"$T/fresh.measured"
    fi
    cp "$T/fresh.sql" "$T/dump.sql"
    cp "$T/fresh.measured" "$T/measured.txt"
}
dump_check() { # DESC NEEDLE: the dump in $T/dump.sql judged against $T/measured.txt; no NEEDLE means it must be accepted
    run_fn "current_dump_problem '$T/dump.sql' \"\$(<'$T/measured.txt')\""
    if [[ -z "$2" ]]; then
        [[ "$RC" == 0 ]] || bad "$1: refused: ${OUT:0:200}"
    else
        [[ "$RC" == 1 && "$OUT" == *"$2"* ]] || bad "$1: expected the refusal '$2', got exit $RC: ${OUT:0:200}"
    fi
}
# The edits the cases make to a fresh dump, each one a plain text change that does not depend on the validator.
dump_append() { printf '%b\n' "$1" >>"$T/dump.sql"; }                          # TEXT: lines at the end (\x60 is a backtick, \n a line break)
dump_after() { # LINE TEXT: TEXT after the first line of the dump that is exactly LINE
    DUMP_TEXT="$(printf '%b' "$2")" awk -v key="$(printf '%b' "$1")" '{ print } !done && $0 == key { print ENVIRON["DUMP_TEXT"]; done = 1 }' "$T/dump.sql" >"$T/dump.new" && mv "$T/dump.new" "$T/dump.sql"
}
dump_before() { # LINE TEXT: TEXT before the first line of the dump that is exactly LINE
    DUMP_TEXT="$(printf '%b' "$2")" awk -v key="$(printf '%b' "$1")" '!done && $0 == key { print ENVIRON["DUMP_TEXT"]; done = 1 } { print }' "$T/dump.sql" >"$T/dump.new" && mv "$T/dump.new" "$T/dump.sql"
}
dump_replace() { # LINE TEXT: the first line of the dump that is exactly LINE, replaced by TEXT (no text: removed)
    DUMP_TEXT="$(printf '%b' "$2")" awk -v key="$(printf '%b' "$1")" '!done && $0 == key { if (ENVIRON["DUMP_TEXT"] != "") print ENVIRON["DUMP_TEXT"]; done = 1; next } { print }' "$T/dump.sql" >"$T/dump.new" && mv "$T/dump.new" "$T/dump.sql"
}
CREATE_JOBS='CREATE TABLE \x60jobs\x60 ('
DISABLE_PEOPLE='/*!40000 ALTER TABLE \x60people\x60 DISABLE KEYS */;'
ENABLE_PEOPLE='/*!40000 ALTER TABLE \x60people\x60 ENABLE KEYS */;'
END_OF_TABLE=') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
extra_table() { printf 'CREATE TABLE \x60%s\x60 (\n  \x60id\x60 int(11) NOT NULL\n%s\n' "$1" "$END_OF_TABLE"; }
# fresh_dump with the structure of every table as the real schema's own SHOW CREATE TABLE writes it (the fixture a Pest test holds to the migrated schema).
real_structures() {
    local fixture="$ROOT/apps/platform/tests/Fixtures/rehearsal/current-create-tables.sql"
    [[ -s "$fixture" ]] || { bad 'the fixture of the real CREATE TABLE statements is missing'; return 1; }
    awk 'NR == FNR { if ($0 ~ /^CREATE TABLE /) { name = $3; skipping = 1 } text[name] = text[name] $0 "\n"; next }
         /^CREATE TABLE / { name = $3; printf "%s", text[name]; skip = 1; next }
         skip { if ($0 ~ /^\) ENGINE/) skip = 0; next }
         { print }' "$fixture" "$T/dump.sql" >"$T/dump.new" && mv "$T/dump.new" "$T/dump.sql"
}

t_g25_current_dump_contract() {
    local stmt line table
    if [[ -z "$CURRENT_GUARD" ]]; then
        fresh_dump; dump_check 'the dump of the current schema as mariadb-dump writes it' ''
        [[ "$(grep -c '^INSERT INTO' "$T/dump.sql")" == 11 ]] || bad 'the test dump does not have an INSERT for each of the 11 durable tables that hold rows'
        [[ "$(grep -c '^LOCK TABLES' "$T/dump.sql")" == 27 ]] || bad 'the test dump does not have a LOCK for each of the 27 durable tables, empty or not'
        # An empty durable table has no INSERT and is not "missing".
        ! grep -q 'INSERT INTO .contact_tags.' "$T/dump.sql" || bad 'the test dump has an INSERT for an empty table'
        # The profiles are separate: a v0.3.0 dump is not a current dump, and a current dump is not a v0.3.0 one.
        paired_world; put_dumps; cat "$W/dump_pass1" "$W/dump_pass2" >"$T/dump.sql"; all_block one | awk '{ print $2 " " $3 " " $4 }' >"$T/measured.txt"
        dump_check 'a v0.3.0 dump taken as a current one' 'of the dump'
        fresh_dump; run_fn "dump_problem '$T/dump.sql'"
        [[ "$RC" == 1 && "$OUT" == *'does not create exactly the 16'* ]] || bad "the historical validator did not refuse the 34-table dump: $RC ${OUT:0:200}"
        # The single-line form of INSERT, which the historical validator accepts, is not one the current profile has seen from the real tool.
        fresh_dump; sed -i '/^INSERT INTO \x60people\x60 VALUES$/,/;$/d' "$T/dump.sql"; printf 'INSERT INTO \x60people\x60 VALUES (1),(2);\n' >>"$T/dump.sql"
        dump_check 'the single-line INSERT' 'does not recognise'
        # What is accepted besides the minimal test dump: the real schema's own structures (every table of it), an AUTO_INCREMENT counter in a closing line, the header of a
        # real dump, and rows of the literal forms mariadb-dump writes, a string holding a `;`, a `)`, a `--` or a statement being data.
        fresh_dump; real_structures; dump_check 'the CREATE TABLE statements of the real migrated schema' ''
        [[ "$(grep -c '^CREATE TABLE' "$T/dump.sql")" == 34 && "$(grep -c 'FOREIGN KEY' "$T/dump.sql")" -ge 20 ]] || bad 'the real structures were not put in the dump'
        fresh_dump; dump_replace ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;' ') ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        dump_check 'a closing line with an AUTO_INCREMENT counter' ''
        for line in "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;" "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;" "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;" \
            "/*!40103 SET TIME_ZONE='+00:00' */;" "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;" "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;" \
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;" "/*M!100001 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;" "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;" \
            "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;" "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;" "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;" \
            "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;" "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;" "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;" \
            "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;" "-- Server version	10.11.18-MariaDB" "-- Dump completed on 2026-11-20 12:00:00"; do
            fresh_dump; dump_append "$line"
            dump_check "the line '$line'" ''
        done
        fresh_dump; dump_replace '(1),' "(1,'O\\\\'Brien; DROP TABLE people; -- (x)',NULL,-3.5,1e-7,0x41424344,_binary 'a\\\\0b\\\\nc','',''),"
        dump_check 'a row of every literal form, with SQL inside its strings' ''
    fi
    if want_case G25-dump-unrecognised; then
        for stmt in 'USE other_db;' 'CREATE DATABASE other_db;' 'DELETE FROM people;' 'TRUNCATE TABLE people;' 'GRANT ALL ON *.* TO x;' 'SET @x = 1;' $'INSERT IGNORE INTO \x60people\x60 VALUES' \
            $'INSERT INTO \x60people\x60 (\x60id\x60) VALUES' $'ALTER TABLE \x60people\x60 ADD x int;' $'REPLACE INTO \x60people\x60 VALUES' '/*!50001 DROP DATABASE x */;' \
            '/*!40101 SET @x=1 */; DROP DATABASE y;' $'LOCK TABLES \x60people\x60 READ;' 'select 1;' 'DELIMITER ;;' '\! ls' 'source /etc/passwd' 'LOAD DATA INFILE "/etc/passwd" INTO TABLE people;' \
            $'drop table if exists \x60people\x60;' '--DROP DATABASE y;' $'-- a comment with a backslash \\ in it' $'-- a comment with a carriage return\r' $'DROP TABLE IF EXISTS \x60people\x60; DROP DATABASE y;' \
            $'DROP TABLE IF EXISTS \x60people\x60;\r' '/* DROP DATABASE y */;' '/*M!999999\- enable the sandbox mode */ DROP DATABASE y;' $'/*!40000 ALTER TABLE \x60people\x60 DISABLE KEYS */; DROP DATABASE y;' \
            $'/*!40101 SET @a=1 */ DROP TABLE people /* */;' '/*!40101 SET GLOBAL max_connections=1 */;' "/*!40101 SET sql_mode='NO_BACKSLASH_ESCAPES' */;" '/*!40101 SET @m=@@sql_mode, sql_mode=@m */;' \
            "/*!40101 SET @x='NO_BACKSLASH_ESCAPES' */;" '/*!40101 SET character_set_client = gbk */;' '/*!40101 SET NAMES big5 */;' '/*!40101 SET @a=1, DROP DATABASE y */;' \
            $'-- a comment with a vertical tab \v'; do
            fresh_dump; dump_append "$stmt"
            dump_check "the statement '$stmt'" 'does not recognise'
        done
    fi
    if want_case G25-dump-nul; then
        fresh_dump; printf -- '-- a comment\0 with a NUL byte\n' >>"$T/dump.sql"
        dump_check 'a NUL byte, which the line reader would drop' 'NUL byte'
    fi
    if want_case G25-dump-create-body; then
        for line in 'DELETE FROM people;' '  \x60x\x60 int(11)); DROP DATABASE d; --' '  \x60x\x60 int(11) NOT NULL); DROP TABLE people; -- ,' '  \x60x\x60 int(11) NOT NULL, DROP TABLE y' '  DROP TABLE people;' \
            '  KEY \x60k\x60 (\x60id\x60)); DROP TABLE people;' '  CONSTRAINT \x60c\x60 CHECK (1),' '  \x60x\x60 json NOT NULL,' '  \x60x\x60 int NOT NULL,' '  \x60x\x60 varchar(10) DEFAULT '"'"'a;b'"'"',' \
            '  \x60x\x60 int(11) NOT NULL COMMENT '"'"'x'"'"',' '  \x60x\x60 int(11) NOT NULL DEFAULT (select 1),' '  \x60x\x60 int(11) GENERATED ALWAYS AS (1) VIRTUAL,' '  ' '' '   \x60x\x60 int(11) NOT NULL,' \
            '  \x60x\x60 int(11) NOT NULL; DROP TABLE people;' '  PRIMARY KEY (\x60id\x60) USING BTREE,' '  \x60X\x60 int(11) NOT NULL,' '  FULLTEXT KEY \x60f\x60 (\x60id\x60),' \
            '  CONSTRAINT \x60c\x60 FOREIGN KEY (\x60id\x60) REFERENCES \x60people\x60 (\x60id\x60) ON DELETE CASCADE; DROP TABLE y,' \
            "$END_OF_TABLE"$' DROP DATABASE y;' ') ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;' ') ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;' \
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='x';" ') ENGINE=InnoDB;' ')'; do
            fresh_dump; dump_after "$CREATE_JOBS" "$line"
            dump_check "a CREATE TABLE body holding '${line//\\x60/\`}'" 'not a column or key of the forms the schema uses'
        done
    fi
    if want_case G25-dump-create-last; then
        fresh_dump; dump_replace '  PRIMARY KEY (\x60id\x60)' '  PRIMARY KEY (\x60id\x60),'
        dump_check 'a CREATE TABLE whose last line has a comma' 'no column, or its last line has a comma'
        fresh_dump; sed -i '/^CREATE TABLE \x60jobs\x60 (/,/^) ENGINE/{/^  /d}' "$T/dump.sql"
        dump_check 'a CREATE TABLE with no column' 'no column, or its last line has a comma'
    fi
    if want_case G25-dump-create-commas; then
        fresh_dump; sed -i '/^CREATE TABLE \x60jobs\x60 (/,/^) ENGINE/s/^  \x60id\x60 int(11) NOT NULL,$/  \x60id\x60 int(11) NOT NULL/' "$T/dump.sql"
        dump_check 'two lines of a body with no comma between them' 'a line after one that has no comma'
    fi
    if want_case G25-dump-create-reference; then
        fresh_dump; dump_after "$CREATE_JOBS" '  \x60x\x60 int(11) NOT NULL,\n  CONSTRAINT \x60jobs_x_foreign\x60 FOREIGN KEY (\x60x\x60) REFERENCES \x60other\x60 (\x60id\x60),'
        dump_check 'a foreign key to a table that is not one of the 34' 'references a table that is not one of the 34'
        fresh_dump; dump_after "$CREATE_JOBS" '  \x60x\x60 int(11) NOT NULL,\n  CONSTRAINT \x60jobs_x_foreign\x60 FOREIGN KEY (\x60x\x60) REFERENCES \x60people\x60 (\x60id\x60) ON DELETE CASCADE ON UPDATE NO ACTION,'
        dump_check 'a foreign key to a table of the schema' ''
    fi
    if want_case G25-dump-row-form; then
        fresh_dump; sed -i '0,/^INSERT INTO \x60people\x60 VALUES$/s//&\nSELECT 1;/' "$T/dump.sql"
        dump_check 'an INSERT with a statement among its rows' 'not a row of literal values'
        fresh_dump; sed -i '0,/^INSERT INTO \x60people\x60 VALUES$/s//&\n/' "$T/dump.sql"
        dump_check 'an INSERT with a blank line among its rows' 'not a row of literal values'
        for line in '(1); DROP TABLE people;' '(1);DELETE FROM people;' '(1)); DROP DATABASE x; --' "(1,'a'); INSERT INTO other VALUES (2);" '(1),(2);' '(1),(2),' '(1) , (2),' "(1,'a" "(1,'a')x," '(1,foo),' '(1,@x),' '(1,(SELECT 1)),' '(1,now()),' \
            "(1,'a''b')'c')," "(1,'a'" "(1,'a');  " "(1,'a') ;" ' (1),' $'(1)\r,' '(),' '(1,),' "(1,'a'  ,2)," "(1,'a'/*x*/)," '(1,0xZZ),' '(1,-),' '(1,1.),' "(1,_utf8 'a')," "(1,N'a'),"; do
            fresh_dump; dump_replace '(1),' "$line"
            dump_check "the row line '$line'" 'not a row of literal values'
        done
    fi
    if want_case G25-dump-complete; then
        fresh_dump; printf 'CREATE TABLE \x60people\x60 (\n  \x60id\x60 int(11) NOT NULL,\n' >>"$T/dump.sql"
        dump_check 'a dump that ends inside a CREATE TABLE' 'ends inside a statement'
        fresh_dump; table="$(grep -n '^(1),$' "$T/dump.sql" | head -1 | cut -d: -f1)"; head -n "$table" "$T/dump.sql" >"$T/dump.new" && mv "$T/dump.new" "$T/dump.sql"
        dump_check 'a dump that ends inside an INSERT' 'ends inside a statement'
        fresh_dump; table="$(grep -n '^UNLOCK TABLES;$' "$T/dump.sql" | tail -1 | cut -d: -f1)"; head -n "$((table - 1))" "$T/dump.sql" >"$T/dump.new" && mv "$T/dump.new" "$T/dump.sql"
        dump_check 'a dump that ends between a LOCK TABLES and its UNLOCK' 'ends inside a statement'
    fi
    if want_case G25-dump-creates; then
        fresh_dump; sed -i '/^CREATE TABLE \x60jobs\x60 (/,/^) ENGINE/d' "$T/dump.sql"
        dump_check 'a table whose structure is missing' 'does not create exactly the 34 tables'
        fresh_dump; extra_table jobs >>"$T/dump.sql"
        dump_check 'a table created twice' 'does not create exactly the 34 tables'
        fresh_dump; extra_table extra >>"$T/dump.sql"
        dump_check 'a 35th table' 'does not create exactly the 34 tables'
    fi
    if want_case G25-dump-drops; then
        fresh_dump; sed -i '/^DROP TABLE IF EXISTS \x60jobs\x60;$/d' "$T/dump.sql"
        dump_check 'a table that is not dropped before it is created' 'does not drop exactly the 34 tables'
        fresh_dump; printf 'DROP TABLE IF EXISTS \x60jobs\x60;\n' >>"$T/dump.sql"
        dump_check 'a table dropped twice' 'does not drop exactly the 34 tables'
        fresh_dump; printf 'DROP TABLE IF EXISTS \x60other\x60;\n' >>"$T/dump.sql"
        dump_check 'a table that is not one of the 34, dropped' 'does not drop exactly the 34 tables'
    fi
    if want_case G25-dump-create-order; then
        fresh_dump; sed -i '/^DROP TABLE IF EXISTS \x60jobs\x60;$/d' "$T/dump.sql"; printf 'DROP TABLE IF EXISTS \x60jobs\x60;\n' >>"$T/dump.sql"
        dump_check 'a table dropped after it is created' 'creates the table jobs before it drops it'
    fi
    if want_case G25-dump-unknown-table; then
        fresh_dump; printf 'INSERT INTO \x60other\x60 VALUES\n(1);\n' >>"$T/dump.sql"
        dump_check 'rows for a table that is not one of the 34' 'rows for a table that is not one of the 34'
    fi
    if want_case G25-dump-transient-empty; then
        fresh_dump; printf 'INSERT INTO \x60sessions\x60 VALUES\n(1);\n' >>"$T/dump.sql"
        dump_check 'rows of a transient table' 'rows for the transient table sessions'
        fresh_dump; printf 'INSERT INTO \x60cache\x60 VALUES\n(1),\n(2);\n' >>"$T/dump.sql"
        dump_check 'rows of another transient table' 'rows for the transient table cache'
    fi
    if want_case G25-dump-lock-known; then
        fresh_dump; dump_append 'LOCK TABLES \x60other\x60 WRITE;\nUNLOCK TABLES;'
        dump_check 'a LOCK TABLES of a table that is not one of the 34' 'LOCK TABLES of the dump names a table that is not one of the 34'
    fi
    if want_case G25-dump-lock-transient; then
        fresh_dump; dump_append 'LOCK TABLES \x60sessions\x60 WRITE;\nUNLOCK TABLES;'
        dump_check 'a LOCK TABLES of a transient table' 'names the transient table sessions'
    fi
    if want_case G25-dump-lock-context; then
        fresh_dump; dump_after "$DISABLE_PEOPLE" 'LOCK TABLES \x60accounts\x60 WRITE;'
        dump_check 'a LOCK TABLES inside another' 'nested, repeated, or for a table not created before it'
        fresh_dump; dump_append 'LOCK TABLES \x60people\x60 WRITE;\nUNLOCK TABLES;'
        dump_check 'a table locked twice' 'nested, repeated, or for a table not created before it'
        fresh_dump; dump_before 'DROP TABLE IF EXISTS \x60accounts\x60;' 'LOCK TABLES \x60people\x60 WRITE;\nUNLOCK TABLES;'
        dump_check 'a table locked before it is created' 'nested, repeated, or for a table not created before it'
    fi
    if want_case G25-dump-keys-known; then
        fresh_dump; dump_append '/*!40000 ALTER TABLE \x60other\x60 DISABLE KEYS */;'
        dump_check 'an ALTER TABLE of a table that is not one of the 34' 'ALTER TABLE of the dump names a table that is not one of the 34'
    fi
    if want_case G25-dump-keys-context; then
        fresh_dump; dump_append '/*!40000 ALTER TABLE \x60people\x60 DISABLE KEYS */;'
        dump_check 'an ALTER TABLE outside a LOCK TABLES' 'not for the table that is locked, or is out of order'
        fresh_dump; dump_replace "$DISABLE_PEOPLE" '/*!40000 ALTER TABLE \x60accounts\x60 DISABLE KEYS */;'
        dump_check 'an ALTER TABLE of another table than the one locked' 'not for the table that is locked, or is out of order'
        fresh_dump; dump_after "$DISABLE_PEOPLE" "$DISABLE_PEOPLE"
        dump_check 'keys disabled twice' 'not for the table that is locked, or is out of order'
        fresh_dump; dump_replace "$DISABLE_PEOPLE" "$ENABLE_PEOPLE"
        dump_check 'keys enabled before they are disabled' 'not for the table that is locked, or is out of order'
    fi
    if want_case G25-dump-unlock-context; then
        fresh_dump; dump_append 'UNLOCK TABLES;'
        dump_check 'an UNLOCK TABLES with nothing locked' 'not for a table it locked, or leaves the keys of one disabled'
        fresh_dump; dump_replace "$ENABLE_PEOPLE" ''
        dump_check 'an UNLOCK TABLES that leaves the keys disabled' 'not for a table it locked, or leaves the keys of one disabled'
    fi
    if want_case G25-dump-insert-context; then
        fresh_dump; dump_append 'INSERT INTO \x60contact_tags\x60 VALUES\n(1);'
        dump_check 'rows outside any LOCK TABLES' 'INSERT of the dump is not for the table that is locked'
        fresh_dump; dump_after "$DISABLE_PEOPLE" 'INSERT INTO \x60accounts\x60 VALUES\n(1);'
        dump_check 'rows of another table than the one locked' 'INSERT of the dump is not for the table that is locked'
        fresh_dump; dump_after "$ENABLE_PEOPLE" 'INSERT INTO \x60people\x60 VALUES\n(1);'
        dump_check 'rows after the keys are enabled again' 'INSERT of the dump is not for the table that is locked'
    fi
    if want_case G25-dump-locked-only; then
        fresh_dump; dump_after "$DISABLE_PEOPLE" 'DROP TABLE IF EXISTS \x60people\x60;'
        dump_check 'a DROP TABLE inside a LOCK TABLES' 'between a LOCK TABLES and its UNLOCK'
        fresh_dump; dump_after "$DISABLE_PEOPLE" '/*!40101 SET NAMES utf8mb4 */;'
        dump_check 'a SET inside a LOCK TABLES' 'between a LOCK TABLES and its UNLOCK'
        fresh_dump; dump_after "$DISABLE_PEOPLE" 'DELETE FROM people;'
        dump_check 'a DELETE inside a LOCK TABLES' 'between a LOCK TABLES and its UNLOCK'
    fi
    if want_case G25-dump-rows; then
        fresh_dump; sed -i 's/^people 14 /people 15 /' "$T/measured.txt"
        dump_check 'a durable table with a row fewer than the database held' 'holds 14 row(s) for the durable table people, and the database held 15'
        fresh_dump; sed -i 's/^people 14 /people 13 /' "$T/measured.txt"
        dump_check 'a durable table with a row more than the database held' 'holds 14 row(s) for the durable table people, and the database held 13'
        fresh_dump; sed -i '/^INSERT INTO \x60resource_assets\x60 VALUES$/,/;$/d' "$T/dump.sql"
        dump_check 'a durable table that held rows and has none in the dump' 'holds 0 row(s) for the durable table resource_assets, and the database held 4'
        fresh_dump; dump_after '/*!40000 ALTER TABLE \x60contact_tags\x60 DISABLE KEYS */;' 'INSERT INTO \x60contact_tags\x60 VALUES\n(1);'
        dump_check 'rows for a durable table that held none' 'holds 1 row(s) for the durable table contact_tags, and the database held 0'
    fi
}

# The stage's own use of that validator, and what it does with a dump that fails it.
t_g25_paired_the_dump_is_checked() {
    paired_world; printf 'INSERT INTO \x60sessions\x60 VALUES\n(1);\n' >>"$W/dump_pass2"
    run_script -- backup-paired
    expect_refused 'a dump with rows of a transient table' 'the dump is not what the backup contract requires'
    expect_has 'the reason' "$ERR" 'rows for the transient table sessions'
    paired_nothing_in_place 'a dump with rows of a transient table'
    [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check snapshot_all ' ]] || bad "an archive was attempted after the dump was refused: $(tr '\n' ' ' <"$W/runs")"
    paired_world; printf 'USE other_db;\n' >>"$W/dump_pass2"
    run_script -- backup-paired
    expect_refused 'a dump that names a database' 'does not recognise'
    paired_nothing_in_place 'a dump that names a database'
}

# --- failures while the artifacts are made, and the evidence they leave ---

t_g25_paired_failures_leave_nothing_that_looks_like_a_backup() {
    if [[ -z "$CURRENT_GUARD" ]]; then
        paired_world; touch "$W/dump_fail"
        run_script -- backup-paired
        expect_rc 'a dump client that fails' 1
        paired_nothing_in_place 'a dump client that fails'
        expect_has 'the trailer' "$ERR" 'backup-paired did not finish'
        expect_has 'nothing of the backup was put in place' "$ERR" 'Nothing of this backup was put in place'
        [[ "$(tr '\n' ' ' <"$W/runs")" == 'files_check snapshot_all ' ]] || bad "an archive was attempted after the dump failed: $(tr '\n' ' ' <"$W/runs")"
        paired_world; printf '3\n' >"$W/fail_files_archive"
        run_script -- backup-paired
        expect_rc 'an archive the container could not make' 3
        paired_nothing_in_place 'an archive the container could not make'
        expect_has 'recovery is named' "$ERR" 'cleanup'
        only_database_left 'an archive the container could not make'
        paired_world; printf '3\n' >"$W/fail_snapshot_all"
        run_script -- backup-paired
        expect_rc 'a snapshot the helper refused' 3
        paired_nothing_in_place 'a snapshot the helper refused'
        [[ ! -e "$W/dumpcalls" ]] || bad 'a dump was taken after the snapshot helper refused'
        # The second measurement fails: both artifacts exist as temporary files, and neither survives.
        paired_world; snapshot_all_output one >"$W/out_snapshot_all.1"; printf 'ledger:count=29\n' >"$W/out_snapshot_all.2"
        run_script -- backup-paired
        expect_refused 'a second snapshot that is incomplete' 'did not report snapshot-all:complete=ok'
        paired_nothing_in_place 'a second snapshot that is incomplete'
    fi
    if want_case G25-paired-archive-nonempty; then
        paired_world; : >"$W/out_files_archive"
        run_script -- backup-paired
        expect_refused 'an archive of no bytes' 'the archive is empty'
        paired_nothing_in_place 'an archive of no bytes'
    fi
    if want_case G25-paired-archive-verified; then
        paired_world; printf 'archive:entries=5\n' >"$W/out_files_verify_archive"
        run_script -- backup-paired
        expect_refused 'an archive that was not reported verified' 'not verified against the files it was made from'
        paired_nothing_in_place 'an archive that was not reported verified'
        paired_world; printf 'archive:entries=4\narchive:verified=ok\n' >"$W/out_files_verify_archive"
        run_script -- backup-paired
        expect_refused 'an archive with the wrong number of entries' 'not verified against the files it was made from'
    fi
    if want_case G25-paired-dump-checked; then
        paired_world; printf 'INSERT INTO \x60cache\x60 VALUES\n(1);\n' >>"$W/dump_pass2"
        run_script -- backup-paired
        expect_refused 'a dump the contract refuses' 'the dump is not what the backup contract requires'
    fi
    if want_case G25-paired-files-manifest-checked; then
        paired_world; printf 'not a time' >"$T/date-raw"
        run_script -- backup-paired
        expect_refused 'a Resource-files manifest that is not consistent' 'the Resource-files manifest just made is not consistent'
        paired_nothing_in_place 'a Resource-files manifest that is not consistent'
    fi
    if want_case G25-paired-unit-checked; then
        # The dump temporary file is altered after the stage has taken its checksum (a comment line, which the dump's own check accepts): the unit is not consistent.
        paired_world
        # shellcheck disable=SC2016 # the text is the hook's, run by bash in the fake daemon
        printf 'for f in "%s"/root/.paired.*; do if grep -q "MariaDB dump" "$f"; then printf "%%s\\n" "-- altered" >>"$f"; fi; done\n' "$T" >"$W/after_files_verify_archive"
        run_script -- backup-paired
        expect_refused 'a unit that is not consistent' 'the recovery unit just made is not consistent'
        expect_has 'what is not' "$ERR" "SHA-256 is not the manifest's"
        paired_nothing_in_place 'a unit that is not consistent'
    fi
    if want_case G25-commit-no-clobber; then
        # A file appears in the state directory after the stage began (the first check passed): nothing is put over it. What was already put in place stays, named, and is NOT a unit.
        paired_world
        printf 'printf planted >"%s/root/resources.manifest.json"; chmod 600 "%s/root/resources.manifest.json"\n' "$T" "$T" >"$W/after_files_verify_archive"
        run_script -- backup-paired
        expect_refused 'a file that appeared mid-stage' 'resources.manifest.json is in the state directory already, and a backup is never put over a file'
        expect_has 'what was put in place' "$ERR" 'current.dump.sql resources.archive.tar.gz'
        expect_has 'it is not a unit' "$ERR" 'recovery.manifest.json was not written, so they are NOT a recovery unit'
        [[ "$(<"$T/root/resources.manifest.json")" == planted ]] || bad 'the file that appeared was overwritten'
        [[ ! -e "$T/root/recovery.manifest.json" ]] || bad 'the recovery manifest was written although the unit was not complete'
        if compgen -G "$T/root/.paired.*" >/dev/null; then bad 'a temporary file was left in the state directory'; fi
        run_fn "ROOT='$T/root'; require_recovery_unit"
        expect_refused 'what is left is not a unit' 'no complete recovery unit'
        run_script -- backup-paired
        expect_refused 'a second attempt' 'never overwritten'
    fi
}

# Double invocation: a backup that was taken is never taken again over itself, and a second try changes nothing.
t_g25_paired_is_never_taken_twice() {
    local f before
    paired_world
    run_script -- backup-paired
    expect_rc 'the first backup' 0
    before="$(cd "$T/root" && sha256sum "${PAIRED_FILES[@]}")"
    rm -f "$W/runs"
    run_script -- backup-paired
    expect_refused 'a second backup' 'never overwritten'
    [[ "$(cd "$T/root" && sha256sum "${PAIRED_FILES[@]}")" == "$before" ]] || bad 'a second backup changed a file of the first'
    no_helper_ran 'a second backup'
    for f in "${PAIRED_FILES[@]}"; do [[ -f "$T/root/$f" ]] || bad "$f disappeared"; done
}

# --- the recovery unit: what a restore may rely on, and what it must not ---

unit_check() { # DESC NEEDLE: the verifier on the state directory as the test left it
    run_fn "ROOT='$T/root'; require_recovery_unit"
    if [[ -z "$2" ]]; then
        expect_rc "$1" 0
    else
        expect_refused "$1" "$2"
    fi
}
unit_case() { # GUARD DESC NEEDLE SED_EXPRESSION: the cached unit, with one edit made to recovery.manifest.json
    want_case "$1" || return 0
    paired_world
    install_paired
    sed -i "$4" "$T/root/recovery.manifest.json"
    unit_check "$2" "$3"
}
zeros() { printf '0%.0s' {1..64}; }

t_g25_recovery_unit_manifest_is_verified() {
    ensure_paired_cache
    if [[ -z "$CURRENT_GUARD" ]]; then
        paired_world; install_paired
        unit_check 'the unit as the stage made it' ''
    fi
    unit_case G25-rm-version 'another manifest version' 'recovery_manifest_version' 's/"recovery_manifest_version": 1,/"recovery_manifest_version": 2,/'
    unit_case G25-rm-run 'another run' "not this run's" 's/"run_token_fingerprint": "[0-9a-f]*"/"run_token_fingerprint": "0000000000000000"/'
    unit_case G25-rm-taken 'a taken_at that is not a time' 'taken_at is not a UTC time' 's/"taken_at": "[^"]*"/"taken_at": "yesterday"/'
    unit_case G25-rm-release 'another release commit' 'not for the current commit' "s/\"commit\": \"$HEAD_SHA\"/\"commit\": \"$(printf 'a%.0s' {1..40})\"/"
    unit_case G25-rm-release 'the historical commit' 'not for the current commit' "s/\"commit\": \"$HEAD_SHA\"/\"commit\": \"$HIST\"/"
    unit_case G25-rm-release-version 'another release version' "release version is not 'unreleased'" 's/"version": "unreleased"/"version": "v0.3.0"/'
    unit_case G25-rm-database 'another database' "not the rehearsal's MariaDB 10.11.18" 's/"name": "flc_rehearsal_app"/"name": "flowlife"/'
    unit_case G25-rm-database 'another server version' "not the rehearsal's MariaDB 10.11.18" 's/"server_version": "[^"]*"/"server_version": "11.4.2-MariaDB"/'
    unit_case G25-rm-keyring 'a keyring fingerprint that does not follow' 'keyring fingerprint is not that of the fingerprints it lists' 's/"keyring_fingerprint": "[0-9a-f]*"/"keyring_fingerprint": "0000000000000000"/'
    if want_case G25-rm-keyring-live; then
        # The listed key and the ring that follows from it are consistent with each other, and are not the keys this run holds.
        run_fn "keyring_fingerprint $FP_FF $FP_PREV"
        paired_world; install_paired
        sed -i "s/\"current_key_fingerprint\": \"$FP_CUR\"/\"current_key_fingerprint\": \"$FP_FF\"/; s/\"keyring_fingerprint\": \"[0-9a-f]*\"/\"keyring_fingerprint\": \"$OUT\"/" "$T/root/recovery.manifest.json"
        unit_check 'a backup that needs a key this run does not hold' 'live keyring lacks a key fingerprint the backup requires'
    fi
    unit_case G25-rm-dump-name 'another dump file' 'its dump is not current.dump.sql' 's/"database_dump_file": "current.dump.sql"/"database_dump_file": "historical.dump.sql"/'
    unit_case G25-rm-dump-sha 'another dump checksum' "dump's SHA-256 is not the manifest's" "s/\"database_dump_sha256\": \"[0-9a-f]*\"/\"database_dump_sha256\": \"$(zeros)\"/"
    unit_case G25-rm-dump-bytes 'another dump size' "dump's size is not the manifest's" 's/"database_dump_bytes": [0-9]*,/"database_dump_bytes": 1,/'
    unit_case G25-rm-migrations 'a ledger of 28' 'migration ledger is not the current schema' 's/"migrations_count": 29,/"migrations_count": 28,/'
    unit_case G25-rm-migrations 'a ledger that ends elsewhere' 'migration ledger is not the current schema' 's/"migrations_last": "[^"]*"/"migrations_last": "2026_10_09_000003_create_sourced_role_grants_table"/'
    unit_case G25-rm-migrations 'a ledger digest that is not a SHA-256' 'migration ledger is not the current schema' 's/"migrations_sha256": "[0-9a-f]*"/"migrations_sha256": "short"/'
    unit_case G25-rm-class 'a durable table classed as transient' 'class for people is not the contract' 's/"name": "people", "class": "durable"/"name": "people", "class": "transient"/'
    unit_case G25-rm-class 'a transient table classed as durable' 'class for sessions is not the contract' 's/"name": "sessions", "class": "transient"/"name": "sessions", "class": "durable"/'
    unit_case G25-rm-tables 'a table missing from the list' 'table list is not exactly the 34 tables' '/"name": "jobs", /d'
    unit_case G25-rm-tables 'a table listed twice' 'table list is not exactly the 34 tables' '/"name": "jobs", /p'
    unit_case G25-rm-tables 'a table the schema does not have' 'table list is not exactly the 34 tables' 's/^\(    { "name": "jobs", .*\)$/\1\n    { "name": "zzz", "class": "durable", "rows": 0, "row_sha256": "'"$EMPTY_DIGEST"'", "structure_sha256": "'"$(zeros)"'" },/'
    unit_case G25-rm-lists 'a schema-only list that is not the contract' "not the contract's 27 and 7" 's/"tables_schema_only": \["cache", /"tables_schema_only": [/'
    unit_case G25-rm-lists 'a durable list that is not the contract' "not the contract's 27 and 7" 's/"tables_with_data": \["account_invitations", /"tables_with_data": [/'
    unit_case G25-rm-structure 'a structure digest that was changed' 'structure digest is not that of the structure digests it lists' "s/\\(\"name\": \"people\", .*\"structure_sha256\": \"\\)[0-9a-f]\\{64\\}/\\1$(zeros)/"
    unit_case G25-rm-structure 'an aggregate structure digest that was changed' 'structure digest is not that of the structure digests it lists' "s/^  \"structure_sha256\": \"[0-9a-f]*\"/  \"structure_sha256\": \"$(zeros)\"/"
    unit_case G25-rm-dump-content 'a manifest that says the database held one row more' 'the dump is not consistent with the manifest' 's/"name": "people", "class": "durable", "rows": 14,/"name": "people", "class": "durable", "rows": 15,/'
    if want_case G25-rm-dump-content; then
        paired_world; install_paired
        sed -i '/^INSERT INTO \x60resource_assets\x60 VALUES$/,/;$/d' "$T/root/current.dump.sql"
        # (the dump changed, so the manifest is re-bound to it: the content check, not the checksum, is what this case is about)
        sed -i "s/\"database_dump_sha256\": \"[0-9a-f]*\"/\"database_dump_sha256\": \"$(sha256sum <"$T/root/current.dump.sql" | cut -c1-64)\"/; s/\"database_dump_bytes\": [0-9]*,/\"database_dump_bytes\": $(wc -c <"$T/root/current.dump.sql" | tr -d ' '),/" "$T/root/recovery.manifest.json"
        unit_check 'a dump that lost the rows of a table, with its checksum corrected' 'the dump is not consistent with the manifest'
    fi
    unit_case G25-rm-inventory 'another inventory digest' 'inventory digest is not that of the files' "s/\"resources_inventory_sha256\": \"[0-9a-f]*\"/\"resources_inventory_sha256\": \"$(zeros)\"/"
    unit_case G25-rm-assets 'a file count that is not the asset rows' 'not one number' 's/"resources_files_count": 4,/"resources_files_count": 3,/'
    unit_case G25-rm-assets 'asset rows that are not the files' 'not one number' 's/"resources_asset_rows": 4,/"resources_asset_rows": 3,/'
    unit_case G25-rm-archive-bound 'another archive checksum' 'archive this manifest binds is not the one' "s/\"resources_archive_sha256\": \"[0-9a-f]*\"/\"resources_archive_sha256\": \"$(zeros)\"/"
    unit_case G25-rm-archive-bound 'another archive size' 'archive this manifest binds is not the one' 's/"resources_archive_bytes": [0-9]*,/"resources_archive_bytes": 1,/'
    unit_case G25-rm-files-manifest-bound 'another Resource-files manifest checksum' 'is not the one this manifest binds' "s/\"resources_manifest_sha256\": \"[0-9a-f]*\"/\"resources_manifest_sha256\": \"$(zeros)\"/"
    unit_case G25-rm-files-manifest-bound 'another Resource-files manifest name' 'is not the one this manifest binds' 's/"resources_manifest_file": "resources.manifest.json"/"resources_manifest_file": "other.json"/'
    unit_case G25-rm-exact 'a line that is not one this script writes' 'not exactly what this script writes' 's/^  "reason": "rehearsal",$/  "reason": "rehearsal",\n  "extra": 1,/'
    unit_case G25-rm-exact 'a backup id that is not the time' 'not exactly what this script writes' 's/"backup_id": "[0-9TZ]*"/"backup_id": "20990101T000000Z"/'
    unit_case G25-rm-exact 'lines swapped' 'not exactly what this script writes' '/"reason": "rehearsal",/{N;s/\(.*\)\n\(.*\)/\2\n\1/}'
}

# What is wrong with the other three files, edited or replaced.
t_g25_recovery_unit_artifacts_are_verified() {
    local sha bytes f
    ensure_paired_cache
    ensure_files_cache
    if want_case G25-unit-required; then
        for f in "${PAIRED_FILES[@]}"; do
            paired_world; install_paired; rm -f "$T/root/$f"
            unit_check "no $f" 'no complete recovery unit'
        done
        paired_world; install_paired; chmod 644 "$T/root/recovery.manifest.json"
        unit_check 'a manifest that is not private' 'no complete recovery unit'
        paired_world; install_paired; rm -f "$T/root/current.dump.sql"; ln -s /dev/null "$T/root/current.dump.sql"
        unit_check 'a dump that is a symbolic link' 'no complete recovery unit'
    fi
    if want_case G25-rm-dump-sha; then
        paired_world; install_paired; printf -- '-- altered\n' >>"$T/root/current.dump.sql"
        unit_check 'a dump altered in place' "dump's SHA-256 is not the manifest's"
        # The same size, another checksum.
        paired_world; install_paired; sed -i '0,/^(1),/s//(2),/' "$T/root/current.dump.sql"
        unit_check 'a dump with one value changed, the same size' "dump's SHA-256 is not the manifest's"
    fi
    if want_case G25-rm-dump-bytes; then
        # The checksum is corrected and the size is not: each is checked on its own.
        paired_world; install_paired; printf -- '-- altered\n' >>"$T/root/current.dump.sql"
        sed -i "s/\"database_dump_sha256\": \"[0-9a-f]*\"/\"database_dump_sha256\": \"$(sha256sum <"$T/root/current.dump.sql" | cut -c1-64)\"/" "$T/root/recovery.manifest.json"
        unit_check 'a dump altered with its checksum corrected' "dump's size is not the manifest's"
    fi
    if want_case G25-rm-archive; then
        paired_world; install_paired; printf 'x' >>"$T/root/resources.archive.tar.gz"
        unit_check 'an archive that grew' 'archive cannot be relied on'
        paired_world; install_paired; printf 'ARCHIVE\x00BYTEZ\xff' >"$T/root/resources.archive.tar.gz"
        unit_check 'an archive with other bytes, the same size' 'archive cannot be relied on'
    fi
    if want_case G25-rm-files-manifest; then
        paired_world; install_paired; sed -i 's/"resource_files_count": 4,/"resource_files_count": 3,/' "$T/root/resources.manifest.json"
        unit_check 'a Resource-files manifest that is not consistent' 'Resource-files manifest cannot be relied on'
        paired_world; install_paired; sed -i "s/\"run_token_fingerprint\": \"[0-9a-f]*\"/\"run_token_fingerprint\": \"0000000000000000\"/" "$T/root/resources.manifest.json"
        unit_check 'a Resource-files manifest of another run' 'Resource-files manifest cannot be relied on'
    fi
    if want_case G25-rm-files-manifest-bound; then
        # Another Resource-files manifest of this run that is valid on its own (the zero-file one) is not the one this manifest binds.
        paired_world; install_paired; cp -p "$T/fcache/zero/resources.manifest.json" "$T/root/resources.manifest.json"
        unit_check 'a swapped Resource-files manifest' 'is not the one this manifest binds'
    fi
    if want_case G25-rm-taken-same; then
        paired_world; install_paired
        sed -i 's/"taken_at": "2023-11-14T22:13:20Z"/"taken_at": "2023-11-14T22:13:21Z"/' "$T/root/resources.manifest.json"
        sha="$(sha256sum <"$T/root/resources.manifest.json" | cut -c1-64)" bytes="$(wc -c <"$T/root/resources.manifest.json" | tr -d ' ')"
        sed -i "s/\"resources_manifest_sha256\": \"[0-9a-f]*\"/\"resources_manifest_sha256\": \"$sha\"/; s/\"resources_manifest_bytes\": [0-9]*,/\"resources_manifest_bytes\": $bytes,/" "$T/root/recovery.manifest.json"
        unit_check 'a Resource-files manifest taken at another time' 'not taken at the same time'
    fi
    if want_case G25-rm-files-present; then
        # The zero-file manifest, valid for this run and bound by the recovery manifest: a paired backup of a database with Resource-file records is not a zero-file one.
        paired_world; install_paired
        cp -p "$T/fcache/zero/resources.manifest.json" "$T/root/resources.manifest.json"
        sha="$(sha256sum <"$T/root/resources.manifest.json" | cut -c1-64)" bytes="$(wc -c <"$T/root/resources.manifest.json" | tr -d ' ')"
        sed -i "s/\"resources_manifest_sha256\": \"[0-9a-f]*\"/\"resources_manifest_sha256\": \"$sha\"/; s/\"resources_manifest_bytes\": [0-9]*,/\"resources_manifest_bytes\": $bytes,/" "$T/root/recovery.manifest.json"
        unit_check 'a zero-file Resource-files manifest bound as the unit' 'not a directory holding files'
    fi
}

# Swapped, substituted and borrowed: parts that are each valid, put together wrongly, and files of the historical profile.
t_g25_recovery_unit_substitutions() {
    ensure_paired_cache
    ensure_cache
    ensure_files_cache
    if [[ -z "$CURRENT_GUARD" ]]; then
        # A second, valid dump of the same database (the same rows, another comment): swapped in, it is not the one the manifest binds.
        paired_world; install_paired
        { cat "$W/dump_pass1" "$W/dump_pass2"; printf -- '-- another dump\n'; } >"$T/root/current.dump.sql"
        unit_check 'a swapped database dump' "dump's SHA-256 is not the manifest's"
        # A second, valid archive: another file set's bytes with their own manifest. The files manifest is bound by the unit and does not follow it.
        paired_world; install_paired
        printf 'ARCHIVE\x00OTHER\xff' >"$T/root/resources.archive.tar.gz"
        unit_check 'a swapped Resource-files archive' 'archive cannot be relied on'
        # The manifest of another run (another token) with every other file this run's.
        paired_world; install_paired
        rm -rf "${T:?}/root"; make_root "$OTHER_TOKEN"; put_prepared_source; install_paired
        unit_check 'a unit of another run' "not this run's"
        # The historical backup's files are not a recovery unit, with or without the files of the Resource backup beside them.
        paired_world; install_state snapshot dump.sql manifest.json; cp -p "$T/fcache/resources.manifest.json" "$T/fcache/resources.archive.tar.gz" "$T/root/"
        unit_check 'the historical backup and a files backup, no recovery manifest' 'no complete recovery unit'
        paired_world; cp -p "$T/cache/historical.dump.sql" "$T/root/current.dump.sql"; cp -p "$T/cache/historical.manifest.json" "$T/root/recovery.manifest.json"
        cp -p "$T/fcache/resources.manifest.json" "$T/fcache/resources.archive.tar.gz" "$T/root/"
        unit_check 'the historical dump and manifest under the recovery unit names' 'recovery_manifest_version'
        # The recovery manifest alone, and the files alone.
        paired_world; cp -p "$T/pcache/recovery.manifest.json" "$T/root/"
        unit_check 'a manifest with nothing it binds' 'no complete recovery unit'
        paired_world; cp -p "$T/pcache/current.dump.sql" "$T/pcache/resources.manifest.json" "$T/pcache/resources.archive.tar.gz" "$T/root/"
        unit_check 'the artifacts with no recovery manifest' 'no complete recovery unit'
    fi
}

# --- the two profiles ---

# --- R2 and R3 of the WU4 review ---

# What the unit check establishes and what it does not. It establishes the integrity of the artifacts (checksums, sizes, the archive against its manifest) and the structural
# consistency of the dump and the manifest (the statement forms, the inventory, the number of ROW LINES of each durable table against the recorded count). It does not read a
# row's content from the dump and derives no digest from it, and the recorded digests are the first measurement's, not authenticated: those are checked by measuring a RESTORED
# database and comparing it with the manifest (WU5's verification; docs, "the contract for WU5"). The cases below make the difference executable, so that the limit is a thing
# a test states and not a sentence that can be read as more than it says.
rebind_dump() { # the dump in the state directory as the manifest's: its SHA-256 and its size written into recovery.manifest.json
    sed -i "s/\"database_dump_sha256\": \"[0-9a-f]*\"/\"database_dump_sha256\": \"$(sha256sum <"$T/root/current.dump.sql" | cut -c1-64)\"/; s/\"database_dump_bytes\": [0-9]*,/\"database_dump_bytes\": $(wc -c <"$T/root/current.dump.sql" | tr -d ' '),/" "$T/root/recovery.manifest.json"
}
t_g25_recovery_unit_verification_boundary() {
    local before after
    ensure_paired_cache
    # Row COUNT agreement is verified: a dump that lost a row of a durable table is refused, whatever its checksum.
    paired_world; install_paired
    sed -i '/^INSERT INTO \x60people\x60 VALUES$/{n;d}' "$T/root/current.dump.sql"
    rebind_dump
    unit_check 'a dump with a row fewer, its checksum and size re-recorded' 'holds 13 row(s) for the durable table people, and the database held 14'
    # Row CONTENT agreement is not: a dump with the same number of rows and another value in one, its checksum and size re-recorded, is accepted. This is the limit, stated.
    paired_world; install_paired
    sed -i '0,/^(1),$/s//(999),/' "$T/root/current.dump.sql"
    rebind_dump
    before="$(sha256sum <"$T/root/current.dump.sql")"
    unit_check 'a dump with one row changed and the same number of rows, its checksum and size re-recorded (accepted: the unit check does not read row content)' ''
    [[ "$(sha256sum <"$T/root/current.dump.sql")" == "$before" ]] || bad 'the unit check changed the dump'
    # The recorded digests are not derived from anything in the unit: a changed recorded row digest is accepted too. It is the restored database's digest, compared with it, that
    # shows the rows came back as recorded.
    paired_world; install_paired
    sed -i 's/"name": "people", "class": "durable", "rows": 14, "row_sha256": "[0-9a-f]*"/"name": "people", "class": "durable", "rows": 14, "row_sha256": "'"$(zeros)"'"/' "$T/root/recovery.manifest.json"
    unit_check 'a manifest whose recorded row digest of a table is another (accepted: it is evidence to compare a restored database with, not a fact derived from the dump)' ''
    # What IS held: the dump the manifest binds by checksum cannot be another one without the manifest being changed to match.
    paired_world; install_paired
    sed -i '0,/^(1),$/s//(999),/' "$T/root/current.dump.sql"
    unit_check 'a dump with one row changed and the checksum not re-recorded' "dump's SHA-256 is not the manifest's"
}

# R3: the three phases of a stage that stops are told apart by the files that are really there.
paired_files_hash() { (cd "$T/root" && sha256sum "${PAIRED_FILES[@]}" 2>/dev/null || true); }
t_g25_paired_failure_phases_are_told_apart() {
    local before
    # Phase 1: before anything was put in place.
    paired_world; touch "$W/dump_fail"
    run_script -- backup-paired
    paired_nothing_in_place 'phase 1'
    expect_has 'phase 1: nothing was put in place' "$ERR" 'Nothing of this backup was put in place.'
    [[ "$ERR" != *'put in place (recovery.manifest.json last)'* && "$ERR" != *'These parts'* ]] || bad "phase 1: the note speaks of files that were put in place: ${ERR:0:300}"
    # Phase 2: some files were put in place and the recovery manifest was not, because a file of that name appeared (a file of another's: it is not part of this backup).
    paired_world
    printf 'printf planted >"%s/root/recovery.manifest.json"; chmod 600 "%s/root/recovery.manifest.json"\n' "$T" "$T" >"$W/after_files_verify_archive"
    run_script -- backup-paired
    expect_refused 'phase 2: the commit point is taken' 'recovery.manifest.json is in the state directory already, and a backup is never put over a file'
    expect_has 'phase 2: what was put in place' "$ERR" 'current.dump.sql resources.archive.tar.gz resources.manifest.json'
    expect_has 'phase 2: it is not a unit' "$ERR" 'recovery.manifest.json was not written, so they are NOT a recovery unit'
    expect_has 'phase 2: the file there is not this stage' "$ERR" 'A file named recovery.manifest.json is in the state directory that this stage did not write'
    [[ "$(<"$T/root/recovery.manifest.json")" == planted ]] || bad 'phase 2: the file that was there was overwritten'
    [[ -s "$T/root/current.dump.sql" && -s "$T/root/resources.archive.tar.gz" && -s "$T/root/resources.manifest.json" ]] || bad 'phase 2: a file that was put in place was removed'
    if compgen -G "$T/root/.paired.*" >/dev/null; then bad 'phase 2: a temporary file was left'; fi
    # Phase 3: the recovery manifest was put in place, and the final verification failed. Four files exist; the note does not call them a backup.
    paired_world
    run_fn "require_recovery_unit() { refuse 'the final verification, forced to fail for the test'; }; main backup-paired"
    expect_rc 'phase 3: the final verification fails' 3
    expect_has 'phase 3: the cause' "$ERR" 'forced to fail for the test'
    expect_has 'phase 3: all four files are there' "$ERR" 'All four files were put in place (recovery.manifest.json last) but the stage failed before it had verified them as put in place'
    expect_has 'phase 3: no reliance' "$ERR" 'only require_recovery_unit accepting them makes them one, and nothing may rely on them until it does'
    expect_has 'phase 3: nothing was repaired' "$ERR" 'Nothing was removed or repaired'
    [[ "$ERR" != *'NOT a recovery unit'* && "$ERR" != *'require_recovery_unit accepted them'* ]] || bad "phase 3: the note says the files are not a unit, or that they were accepted: ${ERR:0:400}"
    local f
    for f in "${PAIRED_FILES[@]}"; do [[ -s "$T/root/$f" ]] || bad "phase 3: $f was removed"; done
    if compgen -G "$T/root/.paired.*" >/dev/null; then bad 'phase 3: a temporary file was left'; fi
    # The authority is require_recovery_unit: the real one accepts what is there (nothing was changed), and the files are as they were.
    before="$(paired_files_hash)"
    unit_check 'phase 3: the files the stage left, judged by require_recovery_unit itself' ''
    [[ "$(paired_files_hash)" == "$before" ]] || bad 'phase 3: judging the files changed them'
    # Phase 3, later: the final verification passed and the stage failed after it. The note says that, and no more.
    paired_world
    # shellcheck disable=SC2016 # the code is run by the inner bash
    run_fn 'say() { case "$*" in *"binds the dump"*) exit 1 ;; esac; printf "%s\n" "$*"; }; main backup-paired'
    expect_rc 'phase 3, later: the stage fails after the verification' 1
    expect_has 'phase 3, later: accepted, then failed' "$ERR" 'recovery.manifest.json was put in place last, with current.dump.sql resources.archive.tar.gz resources.manifest.json, and require_recovery_unit accepted them; the stage failed after that'
    expect_has 'phase 3, later: it is still the verifier that decides' "$ERR" 'Whether they may be relied on is for require_recovery_unit to say again'
    [[ "$ERR" != *'NOT a recovery unit'* ]] || bad "phase 3, later: the note says the files are not a unit: ${ERR:0:400}"
}

# shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
t_g25_the_profiles_are_separate() {
    local f
    # The historical profile: 16 tables, 9 durable, 7 transient, and the dump and manifest it always had.
    run_fn 'historical_inventory | wc -l; durable_inventory | wc -l; printf "%s\n" "${TRANSIENT_TABLES[@]}" | wc -l'
    [[ "$(tr '\n' ' ' <<<"$OUT")" == '16 9 7 ' ]] || bad "the historical profile is not 16, 9 and 7 tables: $OUT"
    run_fn 'durable_inventory | tr "\n" " "'
    [[ "$OUT" == 'account_invitations account_recovery_codes account_totp_factors accounts membership_grants migrations people role_assignments security_events ' ]] || bad "the historical durable tables are not the nine: $OUT"
    # The current profile: 34 tables, 27 durable, the same 7 transient.
    run_fn 'upgraded_inventory | wc -l; current_durable_inventory | wc -l'
    [[ "$(tr '\n' ' ' <<<"$OUT")" == '34 27 ' ]] || bad "the current profile is not 34 and 27 tables: $OUT"
    run_fn 'current_durable_inventory | grep -cxE "sessions|cache|cache_locks|failed_jobs|job_batches|jobs|password_reset_tokens" || true'
    [[ "$OUT" == 0 ]] || bad 'a transient table is in the current durable list'
    run_fn 'current_durable_inventory | grep -cxE "resource_assets|sourced_role_grants|contact_methods|person_relationship_field_values"'
    [[ "$OUT" == 4 ]] || bad 'a newer durable table is not in the current durable list'
    # The historical manifest is still what it was: written for a fixed set of inputs, it is this text and no other (the format L2 proved).
    run_fn "ROOT='$T/root'; CURRENT_FP=$FP_CUR PREVIOUS_FP=$FP_PREV RING_FP=$RING_CUR_PREV manifest_text $(fake_digest dump) 1234 10.11.18-MariaDB 2023-11-14T22:13:20Z 0123456789abcdef"
    [[ "$OUT" == "$(printf '{\n  "manifest_version": 1,\n  "backup_id": "20231114T221320Z",\n  "taken_at": "2023-11-14T22:13:20Z",\n  "reason": "rehearsal",\n  "dump_file": "historical.dump.sql",\n  "dump_sha256": "%s",\n  "dump_bytes": 1234,\n  "release": {\n    "version": "v0.3.0",\n    "commit": "%s"\n  },\n  "database": {\n    "connection": "mariadb",\n    "name": "flc_rehearsal_app",\n    "server_version": "10.11.18-MariaDB",\n    "run_token_fingerprint": "0123456789abcdef"\n  },\n  "keyring": {\n    "current_key_fingerprint": "%s",\n    "previous_key_fingerprints": ["%s"],\n    "keyring_fingerprint": "%s",\n    "previous_keys_configured": true\n  },\n  "tables_with_data": ["account_invitations", "account_recovery_codes", "account_totp_factors", "accounts", "membership_grants", "migrations", "people", "role_assignments", "security_events"],\n  "tables_schema_only": ["cache", "cache_locks", "failed_jobs", "job_batches", "jobs", "password_reset_tokens", "sessions"]\n}' "$(fake_digest dump)" "$HIST" "$FP_CUR" "$FP_PREV" "$RING_CUR_PREV")" ]] || bad "the historical manifest is not what it was: $OUT"
    # The files of the two profiles have different names, and cleanup knows all of them.
    for f in historical.dump.sql historical.manifest.json historical.snapshot current.dump.sql recovery.manifest.json resources.archive.tar.gz resources.manifest.json; do
        run_fn "printf '%s\n' \"\${STATE_FILES[@]}\" | grep -cxF '$f'"
        [[ "$OUT" == 1 ]] || bad "$f is not one of the state files cleanup removes"
    done
    # No command takes a profile: the current profile is chosen by the stage that uses it, and the historical stages are not given the current functions.
    run_fn 'declare -f cmd_take_backup | grep -c "current_dump_problem\|upgraded_inventory\|recovery_" || true'
    [[ "$OUT" == 0 ]] || bad 'take-backup (the historical stage) uses the current profile'
    run_fn 'declare -f cmd_restore_historical cmd_reproduce_wp3_01 cmd_upgrade | grep -c "current_dump_problem\|recovery_unit\|CURRENT_DUMP_FILE" || true'
    [[ "$OUT" == 0 ]] || bad 'a historical stage uses the current profile'
    run_fn 'declare -f cmd_backup_paired | grep -c "\bdump_problem \|historical_inventory\|\bDUMP_FILE\b\|\bMANIFEST_FILE\b" || true'
    [[ "$OUT" == 0 ]] || bad "the paired backup uses the historical profile's names or functions"
}

# --- statics: what the script and the PHP support say about the current schema must be one thing ---

# shellcheck disable=SC2016 # the code in single quotes is run by the inner bash, which expands it
t_static_wu4_lists_agree() {
    local php_tables php_transient script_tables line f
    run_fn 'printf "%s\n" "${HISTORICAL_TABLES[@]}" "${NEWER_TABLES[@]}" | LC_ALL=C sort'
    script_tables="$OUT"
    [[ "$(grep -c . <<<"$script_tables")" == 34 ]] || bad 'the script does not list 34 tables'
    run_fn 'printf "%s\n" "${TRANSIENT_TABLES[@]}" | LC_ALL=C sort'
    [[ "$OUT" == "$(printf '%s\n' "${TRANSIENT[@]}" | LC_ALL=C sort)" ]] || bad 'the script and this test do not list the same transient tables'
    run_fn 'printf "%s" "$CURRENT_LAST_MIGRATION"'
    [[ "$OUT" == "$LEDGER_LAST" && "$OUT" == "$(migration_files | awk 'NR == 29')" ]] || bad "the script's CURRENT_LAST_MIGRATION is not the repository's 29th migration"
    run_fn 'printf "%s" "$CURRENT_LEDGER_COUNT"'
    [[ "$OUT" == "$(migration_files | wc -l)" ]] || bad "the script's CURRENT_LEDGER_COUNT is not the number of migrations in the repository"
    f="$ROOT/apps/platform/tests/Support/CurrentSnapshot.php"
    [[ -f "$f" && -f "$ROOT/apps/platform/tests/Support/CurrentSnapshotRefusal.php" ]] || bad 'CurrentSnapshot.php or CurrentSnapshotRefusal.php is not in the tests support directory'
    grep -qxF 'namespace Tests\Support;' "$f" || bad 'CurrentSnapshot.php is not in the Tests\Support namespace the helper imports'
    grep -qxF 'use Tests\Support\CurrentSnapshot;' "$SRC/php/rehearse.php" || bad 'rehearse.php does not import Tests\Support\CurrentSnapshot'
    grep -qxF 'use Tests\Support\CurrentSnapshotRefusal;' "$SRC/php/rehearse.php" || bad 'rehearse.php does not import Tests\Support\CurrentSnapshotRefusal'
    grep -qF "'snapshot-all' => 'actionSnapshotAll'" "$SRC/php/rehearse.php" || bad 'rehearse.php has no snapshot-all action'
    # Every line the script requires of the helper is one the helper prints.
    for line in 'snapshot-all:complete=ok' 'ledger:count=' 'ledger:last=' 'ledger:digest=' 'key:current=' 'key:previous='; do
        grep -qF "echo \"$line" "$SRC/php/rehearse.php" || grep -qF "echo '$line" "$SRC/php/rehearse.php" || bad "the script requires $line of snapshot-all, which rehearse.php never prints"
    done
    grep -qF "'all-table %s %d %s" "$f" || grep -qF '"all-table %s %d %s' "$f" || bad "CurrentSnapshot does not print the all-table lines the script parses"
    run_fn 'runner_command helper:snapshot-all; printf "%s " "${RUN_CMD[@]}"'
    expect_has 'the snapshot-all vector' "$OUT" 'php /var/www/html/harness/rehearse.php snapshot-all'
    command -v php >/dev/null 2>&1 || return 0
    php_tables="$(php -r "require '$f'; echo implode(\"\\n\", \\Tests\\Support\\CurrentSnapshot::TABLES);")"
    [[ "$php_tables" == "$script_tables" ]] || bad "CurrentSnapshot::TABLES is not the script's 34 tables"
    php_transient="$(php -r "require '$f'; echo implode(\"\\n\", \\Tests\\Support\\CurrentSnapshot::TRANSIENT);" | LC_ALL=C sort)"
    [[ "$php_transient" == "$(printf '%s\n' "${TRANSIENT[@]}" | LC_ALL=C sort)" ]] || bad "CurrentSnapshot::TRANSIENT is not the script's seven transient tables"
    # One digest of a table's rows, whichever of the two harness readers made it, and the digest of none is the one the script calls EMPTY_DIGEST.
    [[ "$(php -r "require '$SRC/php/rehearse.php'; require '$f';
        foreach ([[], [['a' => 3, 'b' => null]], [['a' => '3', 'b' => null]], [['id' => 'x', 'v' => 1.5], ['id' => 'y', 'v' => true]], [['a' => ['k' => 1]]]] as \$rows) { echo rowsDigest(\$rows) === \\Tests\\Support\\CurrentSnapshot::digest(\$rows) ? 'same ' : 'DIFFERENT '; }")" == 'same same same same same ' ]] || bad 'CurrentSnapshot::digest and rehearse.php rowsDigest do not give one digest'
    [[ "$(php -r "require '$f'; echo \\Tests\\Support\\CurrentSnapshot::digest([]);")" == "$EMPTY_DIGEST" ]] || bad "the digest of no rows is not the script's EMPTY_DIGEST"
    # The lines the support class prints are the lines the script parses, for all 34 tables.
    php -r "require '$f'; \$m = []; foreach (\\Tests\\Support\\CurrentSnapshot::TABLES as \$i => \$t) { \$m[\$t] = ['rows' => \$i, 'digest' => hash('sha256', \$t)]; } echo \\Tests\\Support\\CurrentSnapshot::lines(\$m);" >"$T/all-lines.txt"
    run_fn "all_table_lines \"\$(<'$T/all-lines.txt')\""
    expect_rc 'the support class lines parse as the script reads them' 0
    [[ "$(grep -c . <<<"$OUT")" == 34 ]] || bad 'the script did not read 34 lines from the support class output'
    # The attested support classes are what the helper loads (the older test compares the union with SUPPORT_FILES; here the new two are named).
    grep -q "'CurrentSnapshot', 'CurrentSnapshotRefusal'" "$SRC/php/rehearse.php" || bad 'rehearse.php does not load CurrentSnapshot and CurrentSnapshotRefusal from the prepared source'
    php -l "$SRC/php/rehearse.php" >/dev/null 2>&1 || bad 'rehearse.php does not parse'
}

HARNESS_FILES_EXPECTED=(db-ping.php probe.php rehearse.php files.php)

declare -A GUARD_TESTS=(
    [G1-context]='t_g1_context t_g1_applies_to_every_command'
    [G1-context-env]='t_g1_context_env'
    [G1-host]='t_g1_host'
    [G1-tls]='t_g1_tls'
    [G1-endpoint]='t_g1_endpoint'
    [G1-reachable]='t_g1_reachable'
    [G2-env]='t_g2_environment t_g2_applies_to_cleanup'
    [G2-up]='t_g2_applies_to_up'
    [G3-env-mode]='t_g3_compose_env_mode'
    [G3-no-dotenv]='t_g3_dotenv_beside_compose'
    [G3-home]='t_g3_home_unset'
    [G3-root-absolute]='t_g3_root_path_rules'
    [G3-root-location]='t_g3_root_path_rules'
    [G3-root-symlink]='t_g3_root_path_rules'
    [G3-root-dir]='t_g3_root_must_be_ours'
    [G3-root-marker]='t_g3_root_must_be_ours'
    [G3-root-mode]='t_g3_root_must_be_ours'
    [G3-root-owner]='t_g3_root_must_be_ours'
    [G3-cleanup-validate]='t_g3_cleanup_root_path_rules'
    [G3-cleanup-owner]='t_g3_cleanup_root_must_be_ours'
    [G4-c-name]='t_g4_c_name'
    [G4-c-image]='t_g4_c_image'
    [G4-c-label]='t_g4_c_label'
    [G4-c-role]='t_g4_c_role'
    [G4-c-ports]='t_g4_c_ports'
    [G4-c-privilege]='t_g4_c_privilege'
    [G4-c-netmode]='t_g4_c_netmode'
    [G4-c-networks]='t_g4_c_networks'
    [G4-c-mounts]='t_g4_c_mounts'
    [G4-c-token]='t_g4_c_token t_g8_run_token_mismatch_keeps_everything'
    [G4-n-name]='t_g4_n_name'
    [G4-n-internal]='t_g4_n_internal'
    [G4-n-driver]='t_g4_n_driver'
    [G4-n-label]='t_g4_n_label'
    [G4-n-token]='t_g4_n_token'
    [G4-n-endpoints]='t_g4_n_endpoints t_g8_foreign_container_on_our_network'
    [G4-v-name]='t_g4_v_name'
    [G4-v-options]='t_g4_v_options'
    [G4-v-label]='t_g4_v_label'
    [G4-v-token]='t_g4_v_token'
    [G8-volume-in-use]='t_g8_volume_in_use t_g8_volume_in_use_by_a_foreign_container'
    [G4-version]='t_g4_version_is_asked_of_the_server'
    [G4-single]='t_g4_single_container'
    [G4-up-container]='t_g4_up_container_identity'
    [G4-up-network]='t_g4_up_network_identity'
    [G4-up-volume]='t_g4_up_volume_identity'
    [G4-up-version]='t_g4_up_server_version'
    [G4-selfcheck-network]='t_g4_selfcheck_verifies_network'
    [G4-selfcheck-volume]='t_g4_selfcheck_verifies_volume'
    [G4-up-one-network]='t_g4_up_one_network_and_volume'
    [G4-up-one-volume]='t_g4_up_one_volume'
    [G4-pol-internal]='t_g4_pol_internal'
    [G4-pol-forbidden-key]='t_g4_pol_forbidden_key'
    [G4-pol-forbidden-mount]='t_g4_pol_forbidden_mount'
    [G4-pol-one-service]='t_g4_pol_one_service'
    [G4-pol-one-network]='t_g4_pol_one_network'
    [G4-pol-image]='t_g4_pol_image'
    [G4-pol-name]='t_g4_pol_name'
    [G4-pol-net-name]='t_g4_pol_net_name'
    [G4-pol-vol-name]='t_g4_pol_vol_name'
    [G5-token]='t_g5_sentinel_token t_g5_sql_is_preceded_by_the_sentinel_check'
    [G5-sql-container]='t_g5_sql_verifies_container_identity'
    [G5-sql-version]='t_g5_sql_verifies_server_version'
    [G5-sql-sentinel]='t_g5_sql_is_preceded_by_the_sentinel_check'
    [G5-token-format]='t_g5_token_format'
    [G5-create-format]='t_g5_token_format'
    [G6-allowlist]='t_g6_allowlist'
    [G7-allowlist]='t_g7_allowlist t_g7_prune_abbreviations_are_refused'
    [G7-prune-dry-run]='t_g7_prune_dry_run'
    [G7-prune-options]='t_g7_prune_options'
    [G8-state-required]='t_g8_without_state_a_stale_flag_is_required'
    [G8-incomplete]='t_g8_incomplete_cleanup_keeps_state_and_can_be_retried t_g8_cleanup_removes_exactly_the_rehearsal'
    [G8-volume-users-list]='t_g8_volume_users_listing_failure'
    [G8-state-entries]='t_g8_state_with_other_entries_is_kept_whole'
    [G8-reverify-container]='t_g8_reverify_container'
    [G8-reverify-network]='t_g8_reverify_network'
    [G8-reverify-volume]='t_g8_reverify_volume'
    [G9-lock]='t_g9_lock'
    [G9-inventory]='t_g9_listing_failure_up t_g9_listing_failure_self_check t_g9_listing_failure_cleanup t_g9_listing_failure_before_sql'
    [G9-state]='t_g9_stale_state_file'
    [G9-labelled]='t_g9_labelled_leftovers'
    [G9-lookalikes]='t_g9_lookalikes_by_name'
    [G9-selfcheck-state]='t_g9_selfcheck_state'
    [G9-selfcheck-lookalikes]='t_g9_selfcheck_state'
    [G2-substrate]='t_g2_substrate_commands_refuse_inherited_variables'
    [G10-e-name]='t_g10_e_name t_g22_the_files_container_is_verified_before_it_starts'
    [G10-e-image]='t_g10_e_image'
    [G10-e-label]='t_g10_e_label t_g22_the_files_container_is_verified_before_it_starts'
    [G10-e-token]='t_g10_e_token'
    [G10-e-ports]='t_g10_e_ports'
    [G10-e-privilege]='t_g10_e_privilege'
    [G10-e-capdrop]='t_g10_e_capdrop'
    [G10-e-nnp]='t_g10_e_nnp'
    [G10-e-readonly]='t_g10_e_readonly'
    [G10-e-user]='t_g10_e_user t_g22_the_files_container_is_verified_before_it_starts'
    [G10-e-network]='t_g10_e_network t_g22_the_files_container_is_verified_before_it_starts'
    [G10-e-mounts]='t_g10_e_mounts t_g22_the_files_container_is_verified_before_it_starts'
    [G10-e-tmpfs]='t_g10_e_tmpfs t_g22_the_files_container_is_verified_before_it_starts'
    [G10-e-limits]='t_g10_e_limits'
    [G10-image-id]='t_g10_the_image_must_resolve_to_an_id'
    [G10-password-format]='t_g10_password_must_be_the_generated_form'
    [G10-commit-present]='t_g10_prepare_uses_only_verified_commits'
    [G10-commit-exact]='t_g10_prepare_uses_only_verified_commits'
    [G10-lockfile]='t_g10_prepare_uses_only_verified_commits'
    [G10-no-leftover]='t_g10_leftover_ephemeral_containers_stop_everything'
    [G10-prepare-no-leftover]='t_g10_leftover_ephemeral_containers_stop_everything'
    [G10-selfcheck-leftover]='t_g10_leftover_ephemeral_containers_stop_everything'
    [G10-prepare-volume-new]='t_g10_prepare_never_adopts_an_existing_volume'
    [G10-prepare-volume]='t_g10_prepare_verifies_the_volume_it_made'
    [G10-run-volume]='t_g10_run_needs_the_prepared_source'
    [G10-selfcheck-source]='t_g10_self_check_verifies_the_source_volume'
    [G10-probe-lines]='t_g12_probe_refuses_what_it_finds'
    [G11-allowlist]='t_g11_run_policy'
    [G11-arity]='t_g11_run_policy'
    [G11-version]='t_g11_run_policy'
    [G11-artisan-policy]='t_g11_artisan_policy_is_applied_to_the_built_vector'
    [G12-before-run]='t_g12_run_checks_the_account_first'
    [G12-before-sql]='t_g12_there_is_no_root_fallback t_g12_restricted_client_and_guarded_path'
    [G12-grants]='t_g12_account_identity_and_grants t_g12_grant_escaping_matches_batch_output'
    [G12-grants-complete]='t_g12_account_identity_and_grants t_g12_grant_escaping_matches_batch_output'
    [G12-identity]='t_g12_account_identity_and_grants'
    [G12-one-account]='t_g12_account_identity_and_grants'
    [G12-probe-sandbox]='t_g12_probe_refuses_what_it_finds'
    [G12-probe-sentinel]='t_g12_probe_refuses_what_it_finds'
    [G10-s-name]='t_g10_source_volume_identity'
    [G10-s-options]='t_g10_source_volume_identity'
    [G10-s-label]='t_g10_source_volume_identity'
    [G10-s-token]='t_g10_source_volume_identity'
    [G10-s-in-use]='t_g10_source_volume_identity'
    [G10-s-users-list]='t_g10_source_volume_identity'
    [G14-key-format]='t_g14_key_format'
    [G14-keys-agree]='t_g14_keys_agree'
    [G14-previous-agree]='t_g14_keys_agree'
    [G13-commit-present]='t_g13_source_commits'
    [G13-commit-exact]='t_g13_source_commits'
    [G13-clean-checkout]='t_g13_source_commits'
    [G13-empty-target]='t_g13_baseline_preconditions'
    [G13-baseline-inventory]='t_g13_baseline_preconditions'
    [G13-fixture-reported]='t_g13_baseline_fixture'
    [G13-snapshot-complete]='t_g13_baseline_fixture'
    [G13-fixture-people]='t_g13_baseline_fixture'
    [G13-fixture-durable]='t_g13_baseline_fixture'
    [G13-previous-stage]='t_g13_previous_stage'
    [G13-backup-required]='t_g13_backup_required'
    [G13-backup-inventory]='t_g13_backup_preconditions'
    [G13-snapshot-matches]='t_g13_backup_preconditions t_g13_upgrade_preconditions'
    [G13-upgrade-from-historical]='t_g13_upgrade_preconditions'
    [G13-upgrade-inventory]='t_g13_upgrade_results'
    [G13-upgrade-data]='t_g13_upgrade_results'
    [G13-marker]='t_g13_upgrade_results'
    [G12-before-dump]='t_g12_the_dump_runs_the_account_checks'
    [G15-schema-all]='t_g15_dump_contract'
    [G15-schema-drops]='t_g15_dump_contract'
    [G15-data-durable-only]='t_g15_dump_contract'
    [G15-insert-format]='t_g15_dump_contract'
    [G15-no-database]='t_g15_dump_contract'
    [G15-dump-checked]='t_g15_dump_contract'
    [G15-no-overwrite]='t_g15_no_overwrite'
    [G15-manifest-release]='t_g15_manifest_is_checked'
    [G15-manifest-run]='t_g15_manifest_is_checked'
    [G15-manifest-inventory]='t_g15_manifest_is_checked'
    [G15-manifest-classes]='t_g15_manifest_is_checked'
    [G15-manifest-keyring]='t_g15_manifest_is_checked'
    [G15-dump-sha]='t_g15_dump_matches_manifest'
    [G15-dump-bytes]='t_g15_dump_matches_manifest'
    [G16-keyring-present]='t_g16_preconditions'
    [G16-upgraded-inventory]='t_g16_preconditions'
    [G16-upgraded-state]='t_g16_preconditions'
    [G16-import-verified-dump]='t_g16_import_is_reverified'
    [G16-defect-reproduced]='t_g16_unexpected_outcomes'
    [G10-harness-verified]='t_g10_prepare_verifies_the_harness_in_the_volume'
    [G10-support-verified]='t_g10_prepare_verifies_the_support_classes'
    [G13-prepared-recorded]='t_g13_prepared_source'
    [G13-prepared-historical]='t_g13_prepared_source'
    [G13-prepared-current]='t_g13_prepared_source'
    [G13-prepared-harness]='t_g13_prepared_source'
    [G13-prepared-exact]='t_g13_prepared_source'
    [G13-prepared-format]='t_g13_prepared_source'
    [G13-prepared-support]='t_g13_prepared_source'
    [G13-snapshot-reported]='t_g13_snapshot_must_be_reported'
    [G13-structure-complete]='t_g13_snapshot_must_be_reported t_g13_snapshot_semantics'
    [G17-keyring-present]='t_g17_backup_and_keys'
    [G17-start-inventory]='t_g17_preconditions'
    [G17-object-types]='t_g17_preconditions'
    [G17-no-other-objects]='t_g17_preconditions'
    [G17-start-state]='t_g17_preconditions'
    [G17-dump-verified]='t_g17_the_dump_is_reverified'
    [G17-cleared]='t_g17_outcomes'
    [G17-import-verified-dump]='t_g17_the_dump_is_reverified'
    [G17-restored-inventory]='t_g17_outcomes'
    [G17-restored-data]='t_g17_outcomes'
    [G17-restored-structure]='t_g17_outcomes'
    [G17-restored-ledger]='t_g17_semantic_checks'
    [G17-marker-gone]='t_g17_semantic_checks'
    [G18-signin-lines]='t_g18_signin'
    [G18-signin-unchanged]='t_g18_signin'
    [G19-inventory]='t_g19_results'
    [G19-data]='t_g19_results'
    [G19-structure]='t_g19_results'
    [G19-marker-absent]='t_g19_results'
    [G20-no-overwrite]='t_g20_half_fail_preconditions'
    [G20-stub-placed]='t_g20_half_fail_outcomes'
    [G20-helper-reported]='t_g20_half_fail_outcomes'
    [G20-failure-class]='t_g20_half_fail_outcomes'
    [G20-failure-table]='t_g20_half_fail_outcomes'
    [G20-repeat-refuses]='t_g20_half_fail_outcomes'
    [G20-partial-built]='t_g20_half_fail_outcomes'
    [G20-historical-preserved]='t_g20_half_fail_outcomes'
    [G20-structure-historical]='t_g20_half_fail_outcomes'
    [G20-structure-committed]='t_g20_half_fail_outcomes'
    [G20-provenance-not-yet]='t_g20_half_fail_outcomes'
    [G20-structure-contact-methods]='t_g20_half_fail_outcomes'
    [G20-stub-is-a-stub]='t_g20_half_fail_outcomes'
    [G20-partial-counts]='t_g20_partial_state_preconditions'
    [G20-partial-ledger]='t_g20_partial_state_preconditions'
    [G20-stub-identity]='t_g20_partial_state_preconditions'
    [G20-partial-empty]='t_g20_partial_state_preconditions'
    [G20-partial-durable]='t_g20_partial_state_preconditions'
    [G20-partial-recorded]='t_g20_partial_state_preconditions'
    [G20-partial-recorded-exact]='t_g20_partial_state_preconditions'
    [G20-partial-schema]='t_g20_partial_state_preconditions'
    [G20-partial-security]='t_g20_partial_state_preconditions'
    [G21-start-inventory]='t_g21_revoke_preconditions'
    [G21-start-state]='t_g21_revoke_preconditions'
    [G21-before-state]='t_g21_revoke_preconditions'
    [G21-helper-reported]='t_g21_revoke_outcomes'
    [G21-after-state]='t_g21_revoke_outcomes'
    [G21-reversed]='t_g21_a_reversal_that_did_not_happen_is_not_reported'
    [G22-v-name]='t_g22_the_files_volume_is_proven_before_use'
    [G22-v-options]='t_g22_the_files_volume_is_proven_before_use'
    [G22-v-label]='t_g22_the_files_volume_is_proven_before_use'
    [G22-v-token]='t_g22_the_files_volume_is_proven_before_use'
    [G22-v-users-list]='t_g22_the_files_volume_is_proven_before_use'
    [G22-v-in-use]='t_g22_the_files_volume_is_proven_before_use'
    [G22-f-volume]='t_g22_the_files_volume_is_proven_before_use'
    [G22-f-allowlist]='t_g22_only_actions_that_write_may_write'
    [G22-f-no-leftover]='t_g22_files_containers_run_only_beside_the_database'
    [G22-f-src-volume]='t_g22_files_containers_run_only_beside_the_database'
    [G22-f-readonly-mount]='t_g22_backup_happy_path t_g22_seed_happy_path'
    [G22-f-protocol-line]='t_g22_the_helper_output_is_validated'
    [G22-f-protocol-order]='t_g22_the_helper_output_is_validated'
    [G22-f-protocol-state]='t_g22_the_helper_output_is_validated'
    [G22-f-protocol-count]='t_g22_the_helper_output_is_validated'
    [G22-f-protocol-absent]='t_g22_the_helper_output_is_validated'
    [G22-rows-table-answer]='t_g22_seed_preconditions'
    [G22-rows-shape]='t_g22_database_rows_are_validated'
    [G22-rows-order]='t_g22_database_rows_are_validated'
    [G22-seed-inventory]='t_g22_seed_preconditions'
    [G22-seed-state]='t_g22_seed_preconditions'
    [G22-seed-assets-empty]='t_g22_seed_preconditions'
    [G22-seed-reported]='t_g22_seed_outcomes'
    [G22-seed-count]='t_g22_seed_outcomes'
    [G22-seed-rows-match]='t_g22_seed_outcomes'
    [G22-seed-agrees]='t_g22_seed_outcomes'
    [G22-seed-sql-values]='t_g22_seed_outcomes'
    [G22-backup-no-overwrite]='t_g22_backup_is_never_overwritten'
    [G22-backup-db-state]='t_g22_backup_needs_a_known_database_state'
    [G22-backup-check-reported]='t_g22_backup_refusals'
    [G22-backup-agrees]='t_g22_backup_refusals'
    [G22-backup-archive-nonempty]='t_g22_backup_refusals'
    [G22-backup-archive-verified]='t_g22_backup_refusals'
    [G22-manifest-version]='t_g22_the_manifest_is_validated'
    [G22-manifest-run]='t_g22_the_manifest_is_validated'
    [G22-manifest-taken]='t_g22_the_manifest_is_validated'
    [G22-manifest-state]='t_g22_the_manifest_is_validated'
    [G22-manifest-count]='t_g22_the_manifest_is_validated'
    [G22-manifest-zero]='t_g22_the_manifest_is_validated'
    [G22-manifest-archive-name]='t_g22_the_manifest_is_validated'
    [G22-manifest-archive-facts]='t_g22_the_manifest_is_validated'
    [G22-manifest-rows]='t_g22_the_manifest_is_validated'
    [G22-manifest-no-table]='t_g22_the_manifest_is_validated'
    [G22-manifest-exact]='t_g22_the_manifest_is_validated'
    [G22-archive-sha]='t_g22_the_manifest_is_validated'
    [G22-archive-bytes]='t_g22_the_manifest_is_validated'
    [G22-restore-backup-required]='t_g22_the_manifest_is_validated'
    [G22-restore-archive-required]='t_g22_the_manifest_is_validated'
    [G22-restore-zero-no-archive]='t_g22_the_manifest_is_validated'
    [G22-restore-verified-first]='t_g22_restore_refusals'
    [G22-restore-reported]='t_g22_restore_refusals'
    [G22-restore-result]='t_g22_restore_refusals'
    [G22-restore-recheck]='t_g22_restore_refusals'
    [G22-restore-zero-absent]='t_g22_a_zero_file_restore_extracts_nothing'
    [G22-aside-backup-required]='t_g22_aside_refusals'
    [G22-aside-reported]='t_g22_aside_refusals'
    [G22-aside-preserved]='t_g22_aside_refusals'
    [G22-f-volume-gone]='t_g22_a_missing_volume_is_never_recreated'
    [G22-f-volume-not-established]='t_g22_a_missing_volume_is_never_recreated'
    [G22-f-create-scope]='t_g22_creation_is_an_explicit_permission'
    [G24-e-runner-files-mounts]='t_g24_the_prune_dry_run_reads_the_files_volume_read_only'
    [G24-files-current-only]='t_g24_the_files_volume_is_only_for_the_prune_dry_run'
    [G24-files-exact-length]='t_g24_the_files_volume_is_only_for_the_prune_dry_run'
    [G24-files-exact-vector]='t_g24_the_files_volume_is_only_for_the_prune_dry_run'
    [G24-files-record]='t_g24_the_prune_runner_needs_an_established_valid_files_volume'
    [G24-files-ready]='t_g24_the_prune_runner_needs_an_established_valid_files_volume'
    [G24-runner-create-mutation]='t_g24_a_runner_create_is_a_recorded_change'
    [G24-r-recheck-source]='t_g24_every_runner_rechecks_the_source_volume_after_create'
    [G24-r-recheck-files]='t_g24_the_prune_runner_rechecks_the_files_volume_too'
    [G24-r-recheck-refuses]='t_g24_every_runner_rechecks_the_source_volume_after_create'
    [G24-cleanup-plain-runner]='t_g24_cleanup_recognises_both_runner_layouts'
    [G24-cleanup-prune-runner]='t_g24_cleanup_recognises_both_runner_layouts t_g24_cleanup_rechecks_the_runner_layout_before_removing_it'
    [G24-historical-prune]='t_g24_the_historical_prune_is_refused'
    [G22-f-recheck-files-volume]='t_g22_the_mounts_are_verified_again_after_create'
    [G22-f-recheck-source-volume]='t_g22_the_mounts_are_verified_again_after_create'
    [G22-f-recheck-removable]='t_g22_the_mounts_are_verified_again_after_create'
    [G22-f-recheck-refuses]='t_g22_the_mounts_are_verified_again_after_create'
    [G22-evidence-shape]='t_g22_evidence_is_validated_before_it_is_printed'
    [G23-mutation-recorded]='t_g23_a_change_record_that_cannot_be_written_stops_the_stage t_g23_a_failed_stage_does_not_overstate_or_understate'
    [G23-create-mutation]='t_g23_creating_the_files_container_is_a_recorded_change'
    [G23-sql-mutation]='t_g23_a_failed_stage_does_not_overstate_or_understate'
    [G23-helper-mutation]='t_g24_run_helper_records_a_writing_helper_before_the_runner'
    [G23-query-read-only]='t_g23_a_failed_stage_does_not_overstate_or_understate'
    [G22-f-marker]='t_g22_a_missing_volume_is_never_recreated'
    [G22-aside-stamp]='t_g22_aside_refusals'
    [G22-aside-result]='t_g22_aside_refusals'
    [G22-cleanup-dispatch]='t_g22_cleanup_removes_the_files_objects_and_only_those'
    [G22-selfcheck-files]='t_g22_self_check_verifies_the_files_volume'
    [G25-all-complete]='t_g25_paired_the_measurement_is_validated'
    [G25-all-line-shape]='t_g25_paired_the_measurement_is_validated'
    [G25-assets-two-readers]='t_g25_paired_the_measurement_is_validated'
    [G25-commit-no-clobber]='t_g25_paired_failures_leave_nothing_that_looks_like_a_backup'
    [G25-dump-complete]='t_g25_current_dump_contract'
    [G25-dump-create-last]='t_g25_current_dump_contract'
    [G25-dump-create-commas]='t_g25_current_dump_contract'
    [G25-dump-create-reference]='t_g25_current_dump_contract'
    [G25-dump-create-order]='t_g25_current_dump_contract'
    [G25-dump-nul]='t_g25_current_dump_contract'
    [G25-dump-unlock-context]='t_g25_current_dump_contract'
    [G25-dump-lock-known]='t_g25_current_dump_contract'
    [G25-dump-lock-transient]='t_g25_current_dump_contract'
    [G25-dump-lock-context]='t_g25_current_dump_contract'
    [G25-dump-keys-known]='t_g25_current_dump_contract'
    [G25-dump-keys-context]='t_g25_current_dump_contract'
    [G25-dump-insert-context]='t_g25_current_dump_contract'
    [G25-dump-locked-only]='t_g25_current_dump_contract'
    [G25-dump-create-body]='t_g25_current_dump_contract'
    [G25-dump-creates]='t_g25_current_dump_contract'
    [G25-dump-drops]='t_g25_current_dump_contract'
    [G25-dump-row-form]='t_g25_current_dump_contract'
    [G25-dump-rows]='t_g25_current_dump_contract'
    [G25-dump-transient-empty]='t_g25_current_dump_contract'
    [G25-dump-unknown-table]='t_g25_current_dump_contract'
    [G25-dump-unrecognised]='t_g25_current_dump_contract'
    [G25-ledger-evidence]='t_g25_paired_the_measurement_is_validated'
    [G25-paired-agree]='t_g25_paired_preconditions'
    [G25-paired-archive-nonempty]='t_g25_paired_failures_leave_nothing_that_looks_like_a_backup'
    [G25-paired-archive-verified]='t_g25_paired_failures_leave_nothing_that_looks_like_a_backup'
    [G25-paired-assets]='t_g25_paired_preconditions'
    [G25-paired-check-reported]='t_g25_paired_preconditions'
    [G25-paired-dump-checked]='t_g25_paired_the_dump_is_checked t_g25_paired_failures_leave_nothing_that_looks_like_a_backup'
    [G25-paired-files-manifest-checked]='t_g25_paired_failures_leave_nothing_that_looks_like_a_backup'
    [G25-paired-inventory]='t_g25_paired_preconditions'
    [G25-paired-no-overwrite]='t_g25_paired_preconditions'
    [G25-paired-quiescent]='t_g25_paired_a_change_during_the_backup_is_refused'
    [G25-paired-state]='t_g25_paired_preconditions'
    [G25-paired-unit-checked]='t_g25_paired_failures_leave_nothing_that_looks_like_a_backup'
    [G25-rm-archive]='t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-archive-bound]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-assets]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-class]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-database]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-dump-bytes]='t_g25_recovery_unit_manifest_is_verified t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-dump-content]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-dump-name]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-dump-sha]='t_g25_recovery_unit_manifest_is_verified t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-exact]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-files-manifest]='t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-files-manifest-bound]='t_g25_recovery_unit_manifest_is_verified t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-files-present]='t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-inventory]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-keyring]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-keyring-live]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-lists]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-migrations]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-release]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-release-version]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-run]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-structure]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-tables]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-taken]='t_g25_recovery_unit_manifest_is_verified'
    [G25-rm-taken-same]='t_g25_recovery_unit_artifacts_are_verified'
    [G25-rm-version]='t_g25_recovery_unit_manifest_is_verified'
    [G25-snapshot-all-reported]='t_g25_paired_the_measurement_is_validated'
    [G25-unit-required]='t_g25_recovery_unit_artifacts_are_verified'
)

# registry_differences MARKERS REGISTERED (one ID per line each): `unregistered ID` for a marker no GUARD_TESTS entry covers, `unmarked ID` for an entry
# whose marker is gone. Exact membership in two associative arrays, and no pipeline: the check this replaced piped the markers into `grep -qx`, which
# stops at the first match, so the writer could fail with EPIPE ("printf: write error: Broken pipe") and, under `set -o pipefail`, report a marker that
# was there as missing (CI run 43722679970, attempt 1: G13-fixture-people, which the matrix then went on to mutate).
registry_differences() {
    local -A marked=() registered=()
    local id
    while IFS= read -r id; do [[ -z "$id" ]] || marked[$id]=1; done <<<"$1"
    while IFS= read -r id; do [[ -z "$id" ]] || registered[$id]=1; done <<<"$2"
    for id in "${!marked[@]}"; do [[ -n "${registered[$id]:-}" ]] || printf 'unregistered %s\n' "$id"; done
    for id in "${!registered[@]}"; do [[ -n "${marked[$id]:-}" ]] || printf 'unmarked %s\n' "$id"; done
}

# --- running -------------------------------------------------------------------------------------------------------------------------------------

run_test() {
    local name="$1"
    TEST_FAILS=0
    FIRST_BAD=''
    "$name"
    if ((TEST_FAILS == 0)); then
        [[ $QUIET == 1 ]] || printf '  ok    %s\n' "${name#t_}"
        return 0
    fi
    [[ $QUIET == 1 ]] || printf '  FAIL  %s\n' "${name#t_}" >&2
    return 1
}

all_tests() { declare -F | awk '{print $3}' | grep '^t_' | sort; }

# --- the mutation matrix: one mutant, its sharing among workers, and the check that every mutant was run -----------------------------------------------

# matrix_markers: the guard markers of the script under test, sorted and unique, one per line.
matrix_markers() { sed -n 's/.*# guard:\([A-Za-z0-9-]*\)$/\1/p' "$SRC/restore-rehearsal.sh" | sort -u; }

# matrix_mutants: the guards the matrix mutates: the markers that have tests, in marker order. A marker with no entry in GUARD_TESTS is reported by the
# registry check and is not mutated; this list, and nothing else, is what the workers share between them.
matrix_mutants() {
    local id
    while IFS= read -r id; do
        [[ -z "${GUARD_TESTS[$id]:-}" ]] || printf '%s\n' "$id"
    done < <(matrix_markers)
}

# shard_of COUNT INDEX ID...: the IDs whose position (counting from 0) leaves INDEX when divided by COUNT, in the order given. A pure function of its
# arguments: the shares of INDEX 0..COUNT-1 are disjoint, together they are every ID, and they differ in size by at most one.
shard_of() {
    local count="$1" index="$2" id n=0
    shift 2
    for id in "$@"; do
        if ((n % count == index)); then printf '%s\n' "$id"; fi
        n=$((n + 1))
    done
}

# available_cpus: the CPUs this process can really use: the affinity mask (`nproc`), lowered by a cgroup CPU quota where there is one. A machine's CPU
# count is not what a container or a hosted runner may use.
available_cpus() {
    local cpus quota period limit
    cpus="$(nproc 2>/dev/null || echo 1)"
    [[ "$cpus" =~ ^[1-9][0-9]*$ ]] || cpus=1
    if [[ -r /sys/fs/cgroup/cpu.max ]]; then
        read -r quota period </sys/fs/cgroup/cpu.max || true
    elif [[ -r /sys/fs/cgroup/cpu/cpu.cfs_quota_us && -r /sys/fs/cgroup/cpu/cpu.cfs_period_us ]]; then
        quota="$(</sys/fs/cgroup/cpu/cpu.cfs_quota_us)" period="$(</sys/fs/cgroup/cpu/cpu.cfs_period_us)"
    fi
    if [[ "${quota:-}" =~ ^[1-9][0-9]*$ && "${period:-}" =~ ^[1-9][0-9]*$ ]]; then
        limit=$(((quota + period - 1) / period))
        ((limit >= cpus)) || cpus=$limit
    fi
    printf '%d' "$cpus"
}

# available_memory_mb: what is left to use, in MiB: the lower of the kernel's estimate and any cgroup limit less what the cgroup already uses.
available_memory_mb() {
    local kb limit used mb=0
    kb="$(awk '/^MemAvailable:/ { print $2 }' /proc/meminfo 2>/dev/null || true)"
    [[ "$kb" =~ ^[0-9]+$ ]] && mb=$((kb / 1024))
    if [[ -r /sys/fs/cgroup/memory.max && -r /sys/fs/cgroup/memory.current ]]; then
        limit="$(</sys/fs/cgroup/memory.max)" used="$(</sys/fs/cgroup/memory.current)"
        if [[ "$limit" =~ ^[0-9]+$ && "$used" =~ ^[0-9]+$ ]] && ((limit > used)); then
            ((mb > 0 && mb <= (limit - used) / 1048576)) || mb=$(((limit - used) / 1048576))
        fi
    fi
    printf '%d' "$mb"
}

# default_workers CPUS MEMORY_MB: one CPU is left for the ordinary tests, which run in this process while the workers run; at most 4 workers; at most one
# per 512 MiB; and 0 (sequential) when that leaves none.
default_workers() {
    local workers=$(($1 - 1))
    ((workers <= 4)) || workers=4
    (($2 / 512 >= workers)) || workers=$(($2 / 512))
    ((workers >= 0)) || workers=0
    printf '%d' "$workers"
}

# matrix_workers: how many worker processes to use. REHEARSAL_WORKERS says so (0 is sequential), and anything else than a whole number from 0 to 16 is
# refused rather than guessed at.
matrix_workers() {
    if [[ -n "${REHEARSAL_WORKERS+x}" ]]; then
        [[ "$REHEARSAL_WORKERS" =~ ^([0-9]|1[0-6])$ ]] || { printf 'REHEARSAL_WORKERS must be a whole number from 0 (sequential) to 16, not %s\n' "${REHEARSAL_WORKERS:0:20}" >&2; return 2; }
        printf '%d' "$REHEARSAL_WORKERS"
        return 0
    fi
    default_workers "$(available_cpus)" "$(available_memory_mb)"
}

# run_mutant ID: replace the one line that carries the guard ID in a scratch copy of the script, and run the tests written for it until one fails. Prints
# one line: `  ok    ID caught by TEST: WHY`, or `  FAIL  ID ...` and returns 1 when the mutation changed nothing, does not parse, or was not noticed by any test.
# Everything it writes is under $T, which belongs to this process alone.
run_mutant() {
    local id="$1" script_file="$SRC/restore-rehearsal.sh" mutant="$T/mutant-$1" t caught=''
    [[ -n "${GUARD_TESTS[$id]:-}" ]] || { printf '  FAIL  %-26s has no tests in GUARD_TESTS\n' "$id" >&2; return 1; }
    rm -rf "$mutant"
    cp -r "$SRC" "$mutant" || { printf '  FAIL  %-26s the scratch copy could not be made\n' "$id" >&2; return 1; }
    sed -i "s/^.*# guard:${id}\$/:/" "$mutant/restore-rehearsal.sh" || { printf '  FAIL  %-26s the mutation could not be applied\n' "$id" >&2; return 1; }
    if cmp -s "$script_file" "$mutant/restore-rehearsal.sh"; then
        printf '  FAIL  %-26s the mutation changed nothing\n' "$id" >&2
        return 1
    fi
    if ! bash -n "$mutant/restore-rehearsal.sh" 2>/dev/null; then
        printf '  FAIL  %-26s the mutant does not parse, so it proves nothing\n' "$id" >&2
        return 1
    fi
    SUT="$mutant"
    QUIET=1
    CURRENT_GUARD="$id"
    rm -f "$T/first_bad"
    for t in ${GUARD_TESTS[$id]}; do
        # In a subshell, so that the first failed assertion can end the test at once; nothing a test changes needs to outlive it here.
        if ! (ABORT_ON_BAD=1; run_test "$t"); then
            caught="$t"
            FIRST_BAD="$(cat "$T/first_bad" 2>/dev/null || true)"
            break
        fi
    done
    QUIET=0
    CURRENT_GUARD=''
    SUT="$SRC"
    rm -rf "$mutant"
    if [[ -n "$CACHE_DIGEST" && "$(cache_digest)" != "$CACHE_DIGEST" ]]; then
        printf '  FAIL  %-26s its tests changed the fixture caches, which every mutant shares\n' "$id" >&2
        return 1
    fi
    if [[ -n "$caught" ]]; then
        printf '  ok    %-26s caught by %s: %s\n' "$id" "${caught#t_}" "${FIRST_BAD:0:100}"
        return 0
    fi
    printf '  FAIL  %-26s survived: none of [%s] failed without the guard\n' "$id" "${GUARD_TESTS[$id]}" >&2
    return 1
}

# worker_main INDEX COUNT ID...: a worker. Runs exactly the mutants it was given and says how many it ran, so that the process that started it can check.
# The arguments are validated, not trusted: a malformed share, a repeated ID or an ID with no tests is refused (exit 2) before anything runs.
worker_main() {
    local index="${1:-}" count="${2:-}" id assigned=0 caught=0 failed=0
    if ! [[ "$index" =~ ^[0-9]+$ && "$count" =~ ^[1-9][0-9]*$ ]] || ((index >= count)); then
        printf 'usage: rehearsal.sh --worker INDEX COUNT ID...\n' >&2
        return 2
    fi
    shift 2
    local -A given=()
    for id in "$@"; do
        [[ "$id" =~ ^[A-Za-z0-9-]+$ && -n "${GUARD_TESTS[$id]:-}" ]] || { printf 'worker %s/%s: %s is not a guard with tests\n' "$index" "$count" "${id:0:40}" >&2; return 2; }
        [[ -z "${given[$id]:-}" ]] || { printf 'worker %s/%s: %s was given twice\n' "$index" "$count" "$id" >&2; return 2; }
        given[$id]=1
    done
    # Before any mutant, from the unmutated script. A worker that cannot prepare them stops here, without a closing line, so that the parent finds it unfinished.
    prepare_caches || return 1
    for id in "$@"; do
        assigned=$((assigned + 1))
        if run_mutant "$id"; then caught=$((caught + 1)); else failed=$((failed + 1)); fi
    done
    printf 'worker %s/%s: assigned=%d caught=%d failed=%d\n' "$index" "$count" "$assigned" "$caught" "$failed"
    ((failed == 0 && caught == assigned))
}

# MUTANT_LINE[ID]: the result line a worker printed for ID, for the parent to print in guard order. WORKER_PROBLEMS: what worker_problems found. Both are
# filled in the calling shell, not in a command substitution, which would lose them.
declare -A MUTANT_LINE=()
WORKER_PROBLEMS=''
add_problem() {
    local message
    # shellcheck disable=SC2059 # the callers' formats are literals in this file
    printf -v message "$@"
    WORKER_PROBLEMS+="$message"$'\n'
}

# worker_problems INDEX COUNT "ID ..." STATUS OUTPUT: what is wrong with a worker's run, one problem per line in WORKER_PROBLEMS (empty when it is right). A
# worker is right only when it exited with the status its results imply, printed its closing line, and reported every mutant it was assigned exactly once,
# and nothing else. Whatever else it printed is not evidence and is not looked at. Fills MUTANT_LINE.
worker_problems() {
    local index="$1" count="$2" assigned="$3" status="$4" text="$5" line id verdict trailers=0 t_index='' t_count='' t_assigned='' t_caught='' t_failed=''
    local n_assigned=0 n_ok=0 n_fail=0 missing=''
    local -A want=() seen=()
    WORKER_PROBLEMS=''
    for id in $assigned; do
        want[$id]=1
        n_assigned=$((n_assigned + 1))
    done
    while IFS= read -r line; do
        if [[ "$line" =~ ^\ \ (ok|FAIL)\ +([A-Za-z0-9-]+)(\ |$) ]]; then
            verdict="${BASH_REMATCH[1]}" id="${BASH_REMATCH[2]}"
            if [[ -z "${want[$id]:-}" ]]; then
                add_problem 'worker %s/%s reported %s, which was not assigned to it' "$index" "$count" "$id"
            elif [[ -n "${seen[$id]:-}" ]]; then
                add_problem 'worker %s/%s reported %s more than once' "$index" "$count" "$id"
            else
                seen[$id]="$verdict"
                MUTANT_LINE[$id]="$line"
                if [[ "$verdict" == ok ]]; then n_ok=$((n_ok + 1)); else n_fail=$((n_fail + 1)); fi
            fi
        elif [[ "$line" =~ ^worker\ ([0-9]+)/([0-9]+):\ assigned=([0-9]+)\ caught=([0-9]+)\ failed=([0-9]+)$ ]]; then
            trailers=$((trailers + 1))
            t_index="${BASH_REMATCH[1]}" t_count="${BASH_REMATCH[2]}" t_assigned="${BASH_REMATCH[3]}" t_caught="${BASH_REMATCH[4]}" t_failed="${BASH_REMATCH[5]}"
        fi
    done <<<"$text"
    for id in $assigned; do
        [[ -n "${seen[$id]:-}" ]] || missing+="${missing:+ }$id"
    done
    [[ -z "$missing" ]] || add_problem 'worker %s/%s produced no result for: %s' "$index" "$count" "$missing"
    if ((trailers != 1)); then
        add_problem 'worker %s/%s did not finish: it printed its closing line %d times, not once' "$index" "$count" "$trailers"
    elif [[ "$t_index/$t_count/$t_assigned/$t_caught/$t_failed" != "$index/$count/$n_assigned/$n_ok/$n_fail" ]]; then
        add_problem 'worker %s/%s closed with %s/%s assigned=%s caught=%s failed=%s, but its results are assigned=%d caught=%d failed=%d' "$index" "$count" "$t_index" "$t_count" "$t_assigned" "$t_caught" "$t_failed" "$n_assigned" "$n_ok" "$n_fail"
    fi
    if ((n_fail == 0 && status != 0)); then
        add_problem 'worker %s/%s exited with status %s though it reported no failed mutant' "$index" "$count" "$status"
    elif ((n_fail > 0 && status == 0)); then
        add_problem 'worker %s/%s exited 0 though it reported %d failed mutant(s)' "$index" "$count" "$n_fail"
    fi
}

# stop_workers: ends the workers this process started and is still waiting for. Each removes only its own temporary directory (its TERM trap runs its EXIT trap).
stop_workers() {
    local pid
    for pid in ${WORKER_PIDS[@]+"${WORKER_PIDS[@]}"}; do
        kill -TERM "$pid" 2>/dev/null || true
    done
    for pid in ${WORKER_PIDS[@]+"${WORKER_PIDS[@]}"}; do
        wait "$pid" 2>/dev/null || true
    done
    WORKER_PIDS=()
}

# account_worker INDEX COUNT "ID ..." STATUS OUTFILE: judges one finished worker (see worker_problems) and counts each problem it finds as a failure. Fills MUTANT_LINE.
account_worker() {
    local index="$1" count="$2" file="$5" problem
    worker_problems "$index" "$count" "$3" "$4" "$(<"$file")"
    [[ -n "$WORKER_PROBLEMS" ]] || return 0
    while IFS= read -r problem; do
        [[ -z "$problem" ]] || { printf '  FAIL  %s\n' "$problem" >&2; FAILURES=$((FAILURES + 1)); }
    done <<<"$WORKER_PROBLEMS"
    printf '        the last lines worker %d printed:\n' "$index" >&2
    tail -n 5 "$file" | sed 's/^/        | /' >&2
}

# report_mutants WORKERS: prints the results the workers reported, in marker order (MUTANT_IDS), and counts every mutant that did not pass, and every one that has
# no result, as a failure. A mutant that no worker reported is missing from the printout and has been counted by account_worker, with its worker. The closing line
# is printed only when every mutant has a result and nothing, here or earlier in the run, has failed.
report_mutants() {
    local id ran=0 caught=0
    for id in "${MUTANT_IDS[@]}"; do
        [[ -n "${MUTANT_LINE[$id]:-}" ]] || continue
        ran=$((ran + 1))
        case "${MUTANT_LINE[$id]}" in
            '  ok '*) printf '%s\n' "${MUTANT_LINE[$id]}"; caught=$((caught + 1)) ;;
            *) printf '%s\n' "${MUTANT_LINE[$id]}" >&2; FAILURES=$((FAILURES + 1)) ;;
        esac
    done
    MUTANTS_RAN="$ran" MUTANTS_CAUGHT="$caught" # what the summary line reports: the mutants that have a result, and those of them that were caught
    ((ran == ${#MUTANT_IDS[@]})) || { printf '  FAIL  %d of %d mutant(s) have a result, so %d did not run\n' "$ran" "${#MUTANT_IDS[@]}" "$((${#MUTANT_IDS[@]} - ran))" >&2; FAILURES=$((FAILURES + 1)); }
    if ((ran == ${#MUTANT_IDS[@]} && FAILURES == 0)); then printf '  %d mutant(s) run by %d worker(s), each exactly once\n' "$ran" "$1"; fi
}

# The fixture caches (ensure_cache, ensure_files_cache, ensure_half_state) are made from the script under test, once, by whichever test needs them first. In the
# ordinary tests that is the script as it is. A mutant must never be what makes them: a cache made under a mutation would hold the mutant's output, and the next
# mutant would be tried against it. So before the first mutant, in a worker and in a sequential run alike, prepare_caches makes all three from the unmutated
# script (SUT=$SRC), in this process's own temporary directory, and records their digest; run_mutant then requires that no mutant's tests changed them.
# A sequential run asks for them fresh: what the ordinary tests left in its directory is not trusted, since the digest would then record any damage as normal.
# It fails (and says why) if any cache could not be made or is incomplete: `bad` here only counts, since nothing is being aborted, so the count is read.
CACHE_DIGEST=''
cache_digest() { (cd "$T" && find cache fcache -type f -print0 2>/dev/null | LC_ALL=C sort -z | xargs -0 -r sha256sum | sha256sum); }
prepare_caches() { # [fresh]: with 'fresh', whatever caches exist are discarded and made again
    local fails="$TEST_FAILS" first="$FIRST_BAD" problem='' f
    if [[ "${1:-}" == fresh ]]; then
        rm -rf "${T:?}/cache" "${T:?}/fcache"
        CACHE_READY=0 FILES_CACHE=0
    fi
    SUT="$SRC"
    ABORT_ON_BAD=0 # (it is 0 wherever this is called; a failed `bad` must be counted here, not end the process)
    TEST_FAILS=0
    FIRST_BAD=''
    CURRENT_GUARD='' # the caches are the whole fixtures, not the narrowed ones of one guard
    ensure_cache
    ensure_files_cache
    ensure_half_state
    if ((TEST_FAILS > 0)); then problem="$FIRST_BAD"; fi
    for f in cache/historical.snapshot cache/historical.dump.sql cache/historical.manifest.json cache/halfmigrated.state fcache/resources.manifest.json fcache/resources.archive.tar.gz fcache/zero/resources.manifest.json; do
        [[ -s "$T/$f" ]] || problem+="${problem:+; }$f is missing or empty"
    done
    TEST_FAILS="$fails" FIRST_BAD="$first"
    if [[ -n "$problem" ]]; then
        printf 'the fixture caches could not be prepared from the unmutated script: %s\n' "${problem:0:300}" >&2
        return 1
    fi
    CACHE_DIGEST="$(cache_digest)"
}

# tests_worker_main INDEX COUNT NAME...: an ordinary-test worker (routine mode). Runs exactly the tests it was given, in the order given, and says what it did in
# RECORDS: lines written to descriptor 3, which the parent opened on a file of this worker's own. Nothing a test prints is a record: the tests write to the
# ordinary output, the records are written here, to a descriptor that is moved out of the tests' reach (they are run with it closed). Two kinds:
#   RESULT NAME ok|fail                       one per test, when it has run
#   DONE INDEX COUNT ASSIGNED PASSED FAILED   once, last: the worker finished
# The arguments are validated, not trusted: a malformed index or count, no test at all, a name that is not an ordinary test (or is repeated) and a missing record
# descriptor are refused (exit 2) before anything runs. The exit status is 0 only when every test passed. It never prints the summary line: that is the parent's.
tests_worker_main() {
    local index="${1:-}" count="${2:-}" name assigned=0 passed=0 failed=0
    local -a known=()
    local -A is_test=() given=()
    if ! [[ "$index" =~ ^[0-9]+$ && "$count" =~ ^[1-9][0-9]*$ ]] || ((index >= count)); then
        printf 'usage: rehearsal.sh --tests-worker INDEX COUNT NAME...\n' >&2
        return 2
    fi
    shift 2
    (($# > 0)) || { printf 'tests worker %s/%s: no test was assigned to it\n' "$index" "$count" >&2; return 2; }
    mapfile -t known < <(all_tests)
    for name in "${known[@]}"; do is_test[$name]=1; done
    for name in "$@"; do
        [[ "$name" =~ ^t_[A-Za-z0-9_]+$ && -n "${is_test[$name]:-}" ]] || { printf 'tests worker %s/%s: %s is not an ordinary test\n' "$index" "$count" "${name:0:60}" >&2; return 2; }
        [[ -z "${given[$name]:-}" ]] || { printf 'tests worker %s/%s: %s was given twice\n' "$index" "$count" "$name" >&2; return 2; }
        given[$name]=1
    done
    { : >&3; } 2>/dev/null || { printf 'tests worker %s/%s: there is no record file on descriptor 3; this is started by a routine run, not by a person\n' "$index" "$count" >&2; return 2; }
    exec 9>&3 3>&-
    for name in "$@"; do
        assigned=$((assigned + 1))
        # With the record descriptor closed, so that nothing a test starts can write a record.
        if run_test "$name" 9>&-; then
            passed=$((passed + 1))
            printf 'RESULT %s ok\n' "$name" >&9
        else
            failed=$((failed + 1))
            printf 'RESULT %s fail\n' "$name" >&9
        fi
    done
    printf 'DONE %s %s %d %d %d\n' "$index" "$count" "$assigned" "$passed" "$failed" >&9
    ((failed == 0))
}

# A worker (see the top of this file): the mutants it was given and nothing else. Selected by ARGUMENTS only, so that nothing in the environment can make a
# gate run skip its tests and its registry check. (An ordinary-test worker is dispatched at the entry point, below, where every function is defined: it runs
# ordinary tests, and some of them call the functions that run the modes.)
if [[ "${1:-}" == --worker ]]; then
    shift
    worker_main "$@" || exit "$?"
    exit 0
fi

# --- how the file is run: the modes ------------------------------------------------------------------------------------------------------------------
#
#   rehearsal.sh             FULL: the ordinary tests, the registry check and the whole mutation matrix. The complete verification, and the only thing that
#                            may be called one (release assurance, and any change to restore-rehearsal.sh or to this file, before an independent review).
#   rehearsal.sh --routine   ROUTINE: every ordinary test exactly once, the registry check (both directions), and NO mutation experiment. It says so, in words
#                            a reader cannot take for the full run: it never prints the full run's closing line or its "mutant(s) run by" line.
#   rehearsal.sh --worker I N ID...   one share of the matrix (see worker_main); started by a full run, never by a person.
#
# A weaker run is chosen by an ARGUMENT, never by the environment, and anything else is refused before a single test runs: an unknown option, an option given
# twice or beside another, and --routine together with REHEARSAL_TESTS (which runs only the tests it names). REHEARSAL_TESTS alone keeps its old meaning, for
# working on one test, and is not a mode of verification: it prints no summary. A run that is interrupted prints no summary and no closing line and is not
# a success; the summary line, the last thing printed but the closing sentence, is the only machine-readable result.
#
#   REHEARSAL_SUMMARY mode=routine|full result=passed|failed ordinary_run=N ordinary_failed=N registry=consistent|inconsistent guards_registered=N
#                     mutants_executed=N mutants_caught=N matrix=not-run|run [workers=N]

# MODE (full or routine), or refuses: exit 2 before anything runs.
parse_mode() {
    MODE=full
    case "$#" in
        0) ;;
        1) if [[ "$1" == --routine ]]; then MODE=routine; else printf 'rehearsal.sh: unknown option %s. Usage: rehearsal.sh [--routine]\n' "${1:0:40}" >&2; return 2; fi ;;
        *) printf 'rehearsal.sh: more than one option. Usage: rehearsal.sh [--routine]\n' >&2; return 2 ;;
    esac
    if [[ "$MODE" == routine && -v REHEARSAL_TESTS ]]; then
        printf 'rehearsal.sh: --routine runs every ordinary test, and REHEARSAL_TESTS runs only the tests it names: they cannot be combined.\n' >&2
        return 2
    fi
    return 0
}

ORDINARY_TESTS=() # the ordinary tests of this run (all_tests); a list of its own so that the modes can be tested with a short one
ORDINARY_RAN=0 ORDINARY_FAILED=0

# run_ordinary: every test in ORDINARY_TESTS exactly once, in this process. A failed test is counted and the run goes on; a run that did not run every test once is
# itself a failure (a test run twice or not at all cannot be reported as passed).
run_ordinary() {
    local t n=0
    local -A seen=()
    ORDINARY_RAN=0 ORDINARY_FAILED=0
    ((${#ORDINARY_TESTS[@]} > 0)) || { printf '  FAIL  there are no ordinary tests to run\n' >&2; FAILURES=$((FAILURES + 1)); return 0; }
    for t in "${ORDINARY_TESTS[@]}"; do
        if [[ -n "${seen[$t]:-}" ]]; then printf '  FAIL  %s appears twice in the list of ordinary tests\n' "${t#t_}" >&2; FAILURES=$((FAILURES + 1)); continue; fi
        seen[$t]=1
        n=$((n + 1))
        if ! run_test "$t"; then
            FAILURES=$((FAILURES + 1))
            ORDINARY_FAILED=$((ORDINARY_FAILED + 1))
        fi
        ORDINARY_RAN=$n
    done
    ((ORDINARY_RAN == ${#ORDINARY_TESTS[@]})) || { printf '  FAIL  %d of %d ordinary tests were run\n' "$ORDINARY_RAN" "${#ORDINARY_TESTS[@]}" >&2; FAILURES=$((FAILURES + 1)); }
}

# --- routine mode: the ordinary tests shared among workers (CI-2) ---------------------------------------------------------------------------------------
# Kept apart from the matrix's workers and their accounting, whose contracts are not changed by anything here: the matrix's IDs are of a different shape from the
# tests' names (worker_problems matches [A-Za-z0-9-]+, a test name has underscores), a worker's evidence is a record file and not what it printed, and a hung
# worker is ended with the whole process group it leads (a test may leave a process behind), which a matrix worker's TERM trap does not do.

ORDINARY_TIMEOUT=1800 # seconds a routine run waits for its ordinary-test workers: far more than the slowest measured share, so that only a hang reaches it
ORDINARY_GRACE=10     # seconds between asking a worker to stop (TERM) and making it (KILL)
declare -A ORDINARY_VERDICT=() # NAME -> ok|fail: the first valid record of each assigned test, from every worker. Filled in the calling shell.
SHARD_TESTS=()                 # SHARD_TESTS[i]: the tests (a space-separated list) assigned to ordinary-test worker i
STARTED_PID=''

# default_ordinary_workers CPUS MEMORY_MB: in a routine run only the workers run tests (this process only waits), so every CPU may have one: at most 4, at most one
# per 512 MiB, and 0 (sequential, in this process) when that leaves fewer than two, since one worker is a sequential run with more machinery.
default_ordinary_workers() {
    local workers=$1
    ((workers <= 4)) || workers=4
    (($2 / 512 >= workers)) || workers=$(($2 / 512))
    ((workers >= 2)) || workers=0
    printf '%d' "$workers"
}

# ordinary_workers: how many ordinary-test workers a routine run uses. REHEARSAL_WORKERS says so (0 is sequential; validated as the matrix's workers are, so that a
# value that means nothing is refused, not guessed at); unset, the default above.
ordinary_workers() {
    if [[ -n "${REHEARSAL_WORKERS+x}" ]]; then
        matrix_workers
    else
        default_ordinary_workers "$(available_cpus)" "$(available_memory_mb)"
    fi
}

# plan_ordinary_shards COUNT: SHARD_TESTS[0..COUNT-1] from ORDINARY_TESTS by shard_of, and the check that does not trust it: every ordinary test is in exactly one share,
# nothing else is in any, and no share is empty. Says what is wrong and returns 1 if not; the caller counts the failure.
plan_ordinary_shards() {
    local n="$1" i t total=0 problems=0
    local -a share=()
    local -A owner=()
    SHARD_TESTS=()
    for ((i = 0; i < n; i++)); do
        mapfile -t share < <(shard_of "$n" "$i" "${ORDINARY_TESTS[@]}")
        SHARD_TESTS[i]="${share[*]}"
        if ((${#share[@]} == 0)); then printf '  FAIL  ordinary worker %d/%d would be given no test\n' "$i" "$n" >&2; problems=$((problems + 1)); fi
        for t in ${share[@]+"${share[@]}"}; do
            total=$((total + 1))
            if [[ -n "${owner[$t]:-}" ]]; then
                printf '  FAIL  %s is assigned to ordinary workers %s and %d\n' "${t#t_}" "${owner[$t]}" "$i" >&2
                problems=$((problems + 1))
            else
                owner[$t]=$i
            fi
        done
    done
    for t in "${ORDINARY_TESTS[@]}"; do
        if [[ -z "${owner[$t]:-}" ]]; then printf '  FAIL  %s is assigned to no ordinary worker\n' "${t#t_}" >&2; problems=$((problems + 1)); fi
    done
    if ((total != ${#ORDINARY_TESTS[@]})); then printf '  FAIL  the ordinary workers were given %d tests between them, not %d\n' "$total" "${#ORDINARY_TESTS[@]}" >&2; problems=$((problems + 1)); fi
    ((problems == 0))
}

# pgid_of PID: its process group, or nothing (and status 1) when there is no such process.
pgid_of() {
    local stat pgrp
    stat="$(cat "/proc/$1/stat" 2>/dev/null)" || return 1
    stat="${stat##*) }" # what follows the command name: state, parent, group
    read -r _ _ pgrp _ <<<"$stat"
    [[ "$pgrp" =~ ^[0-9]+$ ]] || return 1
    printf '%s' "$pgrp"
}

# start_ordinary_worker INDEX COUNT RECORDS OUTPUT NAME...: starts `rehearsal.sh --tests-worker INDEX COUNT NAME...` in the background and leaves its pid in STARTED_PID.
# It leads a session (and so a process group) of its own, so that ending it can end everything it started; its record file is opened on descriptor 3; its output
# (the tests' diagnostics, which are not evidence) goes to OUTPUT; and its temporary directory is made inside a scratch directory of this process's, which this
# process removes whatever happens to the worker. The environment is the matrix workers': the script directory under test, and REHEARSAL_WORKERS is not passed on.
start_ordinary_worker() {
    local index="$1" count="$2" records="$3" output="$4" scratch="$T/scratch-$1"
    local -a own=()
    shift 4
    mkdir -p "$scratch"
    ! command -v setsid >/dev/null 2>&1 || own=(setsid)
    env -u REHEARSAL_WORKERS TMPDIR="$scratch" REHEARSAL_SRC="$SRC" ${own[@]+"${own[@]}"} bash "$THIS_SCRIPT" --tests-worker "$index" "$count" "$@" 3>"$records" >"$output" 2>&1 </dev/null &
    STARTED_PID=$!
}

# end_ordinary_workers PID...: ends each worker, and whatever it started, and waits for it. TERM first (to the group, where the worker leads one), then, after
# ORDINARY_GRACE seconds, KILL to whatever is left of the group, so that nothing it started outlives it.
end_ordinary_workers() {
    local pid waited alive
    local -A target=() leader=()
    for pid in "$@"; do
        [[ -n "$pid" ]] || continue
        target[$pid]="$pid"
        if [[ "$(pgid_of "$pid" || true)" == "$pid" ]]; then target[$pid]="-$pid" leader[$pid]=1; fi
        kill -TERM -- "${target[$pid]}" 2>/dev/null || true
    done
    for ((waited = 0; waited < ORDINARY_GRACE * 2; waited++)); do
        alive=0
        for pid in "${!target[@]}"; do ! kill -0 "$pid" 2>/dev/null || alive=1; done
        ((alive == 1)) || break
        sleep 0.5
    done
    for pid in "${!target[@]}"; do
        if [[ -n "${leader[$pid]:-}" ]] || kill -0 "$pid" 2>/dev/null; then kill -KILL -- "${target[$pid]}" 2>/dev/null || true; fi
        wait "$pid" 2>/dev/null || true
    done
}

# stop_tests_workers: ends the ordinary-test workers this process started and has not yet found finished (an interrupted run).
stop_tests_workers() {
    end_ordinary_workers ${TESTS_WORKER_PIDS[@]+"${TESTS_WORKER_PIDS[@]}"}
    TESTS_WORKER_PIDS=()
}

# ordinary_worker_problems INDEX COUNT "NAME ..." STATUS RECORDS [STOPPED]: what is wrong with an ordinary-test worker's run, one problem per line in WORKER_PROBLEMS
# (empty when it is right). RECORDS is the worker's record file and nothing else. A worker is right only when every line of it is a record, it exited with the
# status its results imply, wrote its DONE record exactly once, last, with the counts of its results, and reported every test it was assigned exactly once, and
# nothing else. STOPPED (a number of seconds) says the parent ended it for taking too long. Fills ORDINARY_VERDICT, and WORKER_TEST_FAILURES (tests it reported as failed).
WORKER_TEST_FAILURES=0
ordinary_worker_problems() {
    local index="$1" count="$2" assigned="$3" status="$4" text="$5" stopped="${6:-}" line name verdict dones=0 after=0 d_index='' d_count='' d_assigned='' d_passed='' d_failed=''
    local n_assigned=0 n_ok=0 n_fail=0 missing=''
    local -A want=() seen=()
    WORKER_PROBLEMS='' WORKER_TEST_FAILURES=0
    for name in $assigned; do
        want[$name]=1
        n_assigned=$((n_assigned + 1))
    done
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        if [[ "$line" =~ ^RESULT\ (t_[A-Za-z0-9_]+)\ (ok|fail)$ ]]; then
            name="${BASH_REMATCH[1]}" verdict="${BASH_REMATCH[2]}"
            ((dones == 0)) || after=$((after + 1))
            if [[ -z "${want[$name]:-}" ]]; then
                add_problem 'ordinary worker %s/%s reported %s, which was not assigned to it' "$index" "$count" "${name#t_}"
            elif [[ -n "${seen[$name]:-}" ]]; then
                add_problem 'ordinary worker %s/%s reported %s more than once' "$index" "$count" "${name#t_}"
            else
                seen[$name]="$verdict"
                ORDINARY_VERDICT[$name]="$verdict"
                if [[ "$verdict" == ok ]]; then n_ok=$((n_ok + 1)); else n_fail=$((n_fail + 1)); fi
            fi
        elif [[ "$line" =~ ^DONE\ ([0-9]+)\ ([0-9]+)\ ([0-9]+)\ ([0-9]+)\ ([0-9]+)$ ]]; then
            dones=$((dones + 1))
            d_index="${BASH_REMATCH[1]}" d_count="${BASH_REMATCH[2]}" d_assigned="${BASH_REMATCH[3]}" d_passed="${BASH_REMATCH[4]}" d_failed="${BASH_REMATCH[5]}"
        else
            add_problem 'ordinary worker %s/%s wrote a record that is not one: %s' "$index" "$count" "${line:0:60}"
        fi
    done <<<"$text"
    WORKER_TEST_FAILURES=$n_fail
    for name in $assigned; do
        [[ -n "${seen[$name]:-}" ]] || missing+="${missing:+ }${name#t_}"
    done
    [[ -z "$missing" ]] || add_problem 'ordinary worker %s/%s produced no result for: %s' "$index" "$count" "${missing:0:300}"
    if [[ -n "$stopped" ]]; then add_problem 'ordinary worker %s/%s was stopped after %s seconds, which is the longest a routine run waits for one, with %d of its %d tests reported' "$index" "$count" "$stopped" "$((n_ok + n_fail))" "$n_assigned"; fi
    if ((dones != 1)); then
        add_problem 'ordinary worker %s/%s did not finish: it wrote its closing record %d times, not once' "$index" "$count" "$dones"
    elif [[ "$d_index/$d_count/$d_assigned/$d_passed/$d_failed" != "$index/$count/$n_assigned/$n_ok/$n_fail" ]]; then
        add_problem 'ordinary worker %s/%s closed with %s/%s assigned=%s passed=%s failed=%s, but its results are assigned=%d passed=%d failed=%d' "$index" "$count" "$d_index" "$d_count" "$d_assigned" "$d_passed" "$d_failed" "$n_assigned" "$n_ok" "$n_fail"
    elif ((after > 0)); then
        add_problem 'ordinary worker %s/%s wrote %d result(s) after its closing record' "$index" "$count" "$after"
    fi
    if ((n_fail == 0 && status != 0)); then
        add_problem 'ordinary worker %s/%s exited with status %s though it reported no failed test' "$index" "$count" "$status"
    elif ((n_fail > 0 && status == 0)); then
        add_problem 'ordinary worker %s/%s exited 0 though it reported %d failed test(s)' "$index" "$count" "$n_fail"
    fi
}

# account_ordinary_worker INDEX COUNT "NAME ..." STATUS RECORDS OUTPUT [STOPPED]: judges one finished worker (see ordinary_worker_problems), counts each problem it finds as
# a failure, and, for a worker that has a problem or a failed test, shows what it printed (without its passing lines), each line marked so that nothing it printed
# can be taken for a line of this process's own (the summary's, above all).
account_ordinary_worker() {
    local index="$1" count="$2" problem
    ordinary_worker_problems "$1" "$2" "$3" "$4" "$(cat "$5" 2>/dev/null || true)" "${7:-}"
    while IFS= read -r problem; do
        [[ -z "$problem" ]] || { printf '  FAIL  %s\n' "$problem" >&2; FAILURES=$((FAILURES + 1)); }
    done <<<"$WORKER_PROBLEMS"
    if [[ -n "$WORKER_PROBLEMS" || "$WORKER_TEST_FAILURES" -gt 0 ]]; then
        printf '        what ordinary worker %d/%d printed (its passing lines left out):\n' "$index" "$count" >&2
        { grep -v '^  ok ' "$6" 2>/dev/null || true; } | tail -n 60 | sed 's/^/        | /' >&2
    fi
}

# run_ordinary_sharded WORKERS: every test in ORDINARY_TESTS exactly once, in WORKERS workers (clamped to the number of tests), while this process waits. Then, in the
# order of the list, what the workers reported. A test is counted as run only when a worker reported it, once, and as the one it was assigned to; anything less
# (a missing, repeated, unassigned or contradicted report, a worker that did not finish, was killed, or had to be stopped) is a failure, and so is a test that
# did not pass. Nothing is judged from the workers' exit statuses alone, or from what their tests printed.
run_ordinary_sharded() {
    local n="$1" total="${#ORDINARY_TESTS[@]}" i t status verdict pid alive started="$SECONDS" next_beat reported
    local -a names=() out=() records=() finished=() statuses=() stopped=()
    local -A seen=()
    ORDINARY_RAN=0 ORDINARY_FAILED=0
    ORDINARY_VERDICT=()
    ((total > 0)) || { printf '  FAIL  there are no ordinary tests to run\n' >&2; FAILURES=$((FAILURES + 1)); return 0; }
    for t in "${ORDINARY_TESTS[@]}"; do
        if [[ -n "${seen[$t]:-}" ]]; then printf '  FAIL  %s appears twice in the list of ordinary tests\n' "${t#t_}" >&2; FAILURES=$((FAILURES + 1)); return 0; fi
        seen[$t]=1
    done
    ((n <= total)) || n=$total
    plan_ordinary_shards "$n" || { FAILURES=$((FAILURES + 1)); return 0; }

    printf 'Starting %d ordinary-test worker(s) for %d test(s), each with a fake daemon and caches of its own (REHEARSAL_WORKERS=0 runs them one after another, in this process)\n' "$n" "$total"
    for ((i = 0; i < n; i++)); do
        out[i]="$T/ordinary-$i.out" records[i]="$T/ordinary-$i.records"
        read -ra names <<<"${SHARD_TESTS[i]}"
        start_ordinary_worker "$i" "$n" "${records[i]}" "${out[i]}" "${names[@]}"
        TESTS_WORKER_PIDS[i]="$STARTED_PID"
    done

    # Wait for them, and say so as each finishes; a worker still running after ORDINARY_TIMEOUT is ended, and fails. The record files are not read until a worker is done.
    alive=$n next_beat=$((SECONDS + 120))
    while ((alive > 0)); do
        for ((i = 0; i < n; i++)); do
            [[ -z "${finished[i]:-}" ]] || continue
            pid="${TESTS_WORKER_PIDS[i]}"
            if ! kill -0 "$pid" 2>/dev/null; then
                status=0
                wait "$pid" || status=$?
                statuses[i]="$status" finished[i]=1 TESTS_WORKER_PIDS[i]=''
                alive=$((alive - 1))
                printf '  ordinary worker %d/%d finished after %ds (exit %d)\n' "$i" "$n" "$((SECONDS - started))" "$status"
            fi
        done
        ((alive > 0)) || break
        if ((SECONDS - started >= ORDINARY_TIMEOUT)); then
            for ((i = 0; i < n; i++)); do
                [[ -z "${finished[i]:-}" ]] || continue
                pid="${TESTS_WORKER_PIDS[i]}"
                end_ordinary_workers "$pid"
                statuses[i]=124 finished[i]=1 stopped[i]="$ORDINARY_TIMEOUT" TESTS_WORKER_PIDS[i]='' # 124 as `timeout` says it
            done
            break
        fi
        if ((SECONDS >= next_beat)); then
            reported=0
            for ((i = 0; i < n; i++)); do
                status="$(grep -c '^RESULT ' "${records[i]}" 2>/dev/null)" || true
                reported=$((reported + ${status:-0}))
            done
            printf '  ... %d of %d ordinary test(s) reported after %ds; %d worker(s) still running\n' "$reported" "$total" "$((SECONDS - started))" "$alive"
            next_beat=$((SECONDS + 120))
        fi
        sleep 0.5
    done
    TESTS_WORKER_PIDS=()

    for ((i = 0; i < n; i++)); do
        account_ordinary_worker "$i" "$n" "${SHARD_TESTS[i]}" "${statuses[i]:-1}" "${records[i]}" "${out[i]}" "${stopped[i]:-}"
    done
    for t in "${ORDINARY_TESTS[@]}"; do
        verdict="${ORDINARY_VERDICT[$t]:-}"
        case "$verdict" in
            ok) printf '  ok    %s\n' "${t#t_}"; ORDINARY_RAN=$((ORDINARY_RAN + 1)) ;;
            fail) printf '  FAIL  %s\n' "${t#t_}" >&2; ORDINARY_RAN=$((ORDINARY_RAN + 1)) ORDINARY_FAILED=$((ORDINARY_FAILED + 1)); FAILURES=$((FAILURES + 1)) ;;
        esac
    done
    ((ORDINARY_RAN == total)) || { printf '  FAIL  %d of %d ordinary tests were run\n' "$ORDINARY_RAN" "$total" >&2; FAILURES=$((FAILURES + 1)); }
}

# registry_report: the registry check, once, in this process (see registry_differences). Its findings are printed where they have always been, and counted.
REGISTRY_PROBLEMS=''
registry_report() {
    local problem
    mapfile -t MARKERS < <(matrix_markers)
    REGISTRY_PROBLEMS="$(registry_differences "$(printf '%s\n' "${MARKERS[@]}")" "$(printf '%s\n' "${!GUARD_TESTS[@]}")" | LC_ALL=C sort)"
    while IFS= read -r problem; do
        [[ -n "$problem" ]] || continue
        case "$problem" in
            'unregistered '*) printf '  FAIL  %s has no tests in GUARD_TESTS\n' "${problem#unregistered }" >&2 ;;
            'unmarked '*) printf '  FAIL  GUARD_TESTS lists %s, which has no marker in the script\n' "${problem#unmarked }" >&2 ;;
        esac
        FAILURES=$((FAILURES + 1))
    done <<<"$REGISTRY_PROBLEMS"
}

# summary MODE WORKERS: the one machine-readable line.
summary() {
    local result=passed registry=consistent state=run note=''
    ((FAILURES == 0)) || result=failed
    [[ -z "$REGISTRY_PROBLEMS" ]] || registry=inconsistent
    if [[ "$1" == routine ]]; then state=not-run; else note=" workers=$2"; fi
    printf 'REHEARSAL_SUMMARY mode=%s result=%s ordinary_run=%d ordinary_failed=%d registry=%s guards_registered=%d mutants_executed=%d mutants_caught=%d matrix=%s%s\n' \
        "$1" "$result" "$ORDINARY_RAN" "$ORDINARY_FAILED" "$registry" "${#MARKERS[@]}" "$MUTANTS_RAN" "$MUTANTS_CAUGHT" "$state" "$note"
}

# rehearsal_routine: see the modes above. Returns 0 only when every ordinary test passed once, the registry is consistent in both directions, and nothing failed.
rehearsal_routine() { # [WORKERS]: ordinary-test workers; 0 (the default) runs the tests here, in order
    local workers="${1:-0}"
    FAILURES=0 MUTANTS_RAN=0 MUTANTS_CAUGHT=0
    registry_report
    mapfile -t MUTANT_IDS < <(matrix_mutants)
    if ((workers > 0)); then run_ordinary_sharded "$workers"; else run_ordinary; fi
    printf '\nMutation matrix: NOT RUN (--routine). %d guard(s) are registered and 0 mutation experiments were executed: this run does not show that the ordinary tests catch the removal of any guard.\n' "${#MARKERS[@]}"
    printf 'The full verification is: scripts/tests/rehearsal.sh (or ./flow check --mutation).\n'
    summary routine 0
    if ((FAILURES > 0)); then
        printf '%d rehearsal check(s) failed (routine mode)\n' "$FAILURES" >&2
        return 1
    fi
    printf 'Routine rehearsal checks passed: %d ordinary test(s) and the registry check. The mutation matrix was NOT RUN.\n' "$ORDINARY_RAN"
}

# rehearsal_full: the ordinary tests, the registry and the whole matrix, each mutant exactly once, in this process or in WORKERS workers.
rehearsal_full() {
    local i id share status
    FAILURES=0 MUTANTS_RAN=0 MUTANTS_CAUGHT=0
    # The registry, once, in this process: every marker in the script has an entry in GUARD_TESTS and every entry has a marker (by exact membership: see
    # registry_differences). Its findings are printed with the matrix, where they have always been, so the first thing printed here is the workers' start.
    mapfile -t MARKERS < <(matrix_markers)
    REGISTRY_PROBLEMS="$(registry_differences "$(printf '%s\n' "${MARKERS[@]}")" "$(printf '%s\n' "${!GUARD_TESTS[@]}")" | LC_ALL=C sort)"
    mapfile -t MUTANT_IDS < <(matrix_mutants)
    WORKERS="$(matrix_workers)" || return 2
    if ((WORKERS > ${#MUTANT_IDS[@]})); then WORKERS=${#MUTANT_IDS[@]}; fi

    # With workers: each gets its own share and its own output file (made here, written by that worker alone), and they run while this process runs the
    # ordinary tests. Output is read back only after they have finished, and printed in marker order, so it does not depend on which finished first.
    SHARD_OUT=() SHARD_IDS=() SHARD_STATUS=()
    if ((WORKERS > 0)); then
        printf 'Starting %d mutation worker(s) for %d mutant(s) (REHEARSAL_WORKERS=0 runs them one after another, in this process)\n' "$WORKERS" "${#MUTANT_IDS[@]}"
        for ((i = 0; i < WORKERS; i++)); do
            SHARD_OUT[i]="$T/shard-$i.out"
            mapfile -t share < <(shard_of "$WORKERS" "$i" "${MUTANT_IDS[@]}")
            SHARD_IDS[i]="${share[*]}"
            : >"${SHARD_OUT[i]}"
            REHEARSAL_SRC="$SRC" bash "$THIS_SCRIPT" --worker "$i" "$WORKERS" "${share[@]}" >"${SHARD_OUT[i]}" 2>&1 &
            WORKER_PIDS[i]=$!
        done
    fi

    run_ordinary

    printf '\nMutation matrix (each safeguard line replaced by ":" in a scratch copy; its tests must then fail)\n'
    while IFS= read -r id; do
        [[ -n "$id" ]] || continue
        case "$id" in
            'unregistered '*) printf '  FAIL  %s has no tests in GUARD_TESTS\n' "${id#unregistered }" >&2 ;;
            'unmarked '*) printf '  FAIL  GUARD_TESTS lists %s, which has no marker in the script\n' "${id#unmarked }" >&2 ;;
        esac
        FAILURES=$((FAILURES + 1))
    done <<<"$REGISTRY_PROBLEMS"

    if ((WORKERS == 0)); then
        # The same lifecycle as a worker's: the caches are made again, from the script as it is, before the first mutant (not the ordinary tests' own, whatever state they are in).
        if prepare_caches fresh; then
            for id in "${MUTANT_IDS[@]}"; do
                MUTANTS_RAN=$((MUTANTS_RAN + 1))
                if run_mutant "$id"; then MUTANTS_CAUGHT=$((MUTANTS_CAUGHT + 1)); else FAILURES=$((FAILURES + 1)); fi
            done
        else
            printf '  FAIL  the mutants were not run\n' >&2
            FAILURES=$((FAILURES + 1))
        fi
    else
        for ((i = 0; i < WORKERS; i++)); do
            status=0
            wait "${WORKER_PIDS[i]}" || status=$?
            SHARD_STATUS[i]="$status"
        done
        WORKER_PIDS=()
        for ((i = 0; i < WORKERS; i++)); do account_worker "$i" "$WORKERS" "${SHARD_IDS[i]}" "${SHARD_STATUS[i]}" "${SHARD_OUT[i]}"; done
        report_mutants "$WORKERS"
    fi

    printf '\n'
    summary full "$WORKERS"
    if ((FAILURES > 0)); then
        printf '%d rehearsal check(s) failed\n' "$FAILURES" >&2
        return 1
    fi
    printf 'All rehearsal guard tests passed\n'
}

# run_targeted: the tests named in REHEARSAL_TESTS, once each, in this process. Every name must be an ordinary test (a t_ function this file defines) and at least one must
# be named: an unknown name, or a selection of nothing, is refused (exit 2) before any test runs, since a run that tested nothing cannot be reported as passing.
# A selected test that fails makes the run fail (exit 1). It never prints the summary line: it is not a verification.
run_targeted() { # SELECTION: the value of REHEARSAL_TESTS
    local -a names=()
    local t known ordinary
    IFS=$' \t\n' read -r -d '' -a names <<<"$1" || true
    if ((${#names[@]} == 0)); then
        printf 'rehearsal.sh: REHEARSAL_TESTS is set but names no test. Name the tests to run, or unset it for a verification run.\n' >&2
        return 2
    fi
    for t in "${names[@]}"; do
        known=0
        for ordinary in "${ORDINARY_TESTS[@]}"; do [[ "$ordinary" == "$t" ]] && known=1 && break; done
        if ((known == 0)); then
            printf 'rehearsal.sh: REHEARSAL_TESTS names %s, which is not a test in this file (tests are the t_ functions; nothing was run).\n' "${t:0:60}" >&2
            return 2
        fi
    done
    FAILURES=0
    for t in "${names[@]}"; do run_test "$t" || FAILURES=$((FAILURES + 1)); done
    if ((FAILURES > 0)); then
        printf 'Targeted run: %d of %d selected test(s) failed.\n' "$FAILURES" "${#names[@]}" >&2
        return 1
    fi
    printf 'Targeted run: %d selected test(s) passed. This is not a verification: no registry check and no mutants were run.\n' "${#names[@]}"
}

# --- the entry point -----------------------------------------------------------------------------------------------------------------------------------

# An ordinary-test worker (see tests_worker_main), selected by ARGUMENTS only, like the matrix's: before the modes are parsed, so that nothing else is run.
if [[ "${1:-}" == --tests-worker ]]; then
    shift
    tests_worker_main "$@" || exit "$?"
    exit 0
fi
parse_mode "$@" || exit "$?"
printf 'Rehearsal guard tests (fake daemon; no container, network, volume or database is created)\n'
mapfile -t ORDINARY_TESTS < <(all_tests)
# REHEARSAL_TESTS="t_a t_b" runs just those (no registry check, no mutation matrix), for working on a test. It is not a mode of verification: it prints no summary,
# and `./flow check` refuses it. A variable that is SET (even to nothing) selects this path, so it can never fall through to a different run than the one asked for.
if [[ -v REHEARSAL_TESTS ]]; then
    run_targeted "$REHEARSAL_TESTS" || exit "$?"
    exit 0
fi
if [[ "$MODE" == routine ]]; then
    # A malformed REHEARSAL_WORKERS is refused here, before any test, as in a full run.
    ORDINARY_WORKERS="$(ordinary_workers)" || exit "$?"
    rehearsal_routine "$ORDINARY_WORKERS" || exit 1
    exit 0
fi
rehearsal_full || exit "$?"
