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
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
SRC="${REHEARSAL_SRC:-$ROOT/scripts/rehearsal}"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
# Run from a throwaway directory. A mutant with a path guard removed would otherwise create its "relative" state directory here.
mkdir "$T/cwd"
cd "$T/cwd"
BIN="$T/bin"
REAL_DOCKER="$(command -v docker || true)"
SUT="$SRC" # the script under test: the repository's, or a mutated copy
QUIET=0
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
hook() { if [[ -f "$W/hook_$1" ]]; then bash "$W/hook_$1" && rm -f "$W/hook_$1"; fi; }

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
            *'rehearse.php snapshot'*) key="snapshot_${wd##*/}" ;;
            *'rehearse.php mark'*) key=mark ;;
            *'rehearse.php signin'*) key=signin ;;
            *' exec sha256sum -- '*) key=harness_sums ;;
            *' migrate --force'*) key="migrate_${wd##*/}" ;;
        esac
        [[ -z "$key" ]] || printf '%s\n' "$key" >>"$W/runs"
        if [[ -n "$key" && -f "$W/fail_$key" ]]; then echo 'harness:refused the fake helper was told to fail' >&2; exit "$(cat "$W/fail_$key")"; fi
        if [[ -n "$key" && -f "$W/out_$key" ]]; then cat "$W/out_$key"; elif [[ -f "$W/run_stdout" ]]; then cat "$W/run_stdout"; else echo ok; fi
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
    cat-file) [[ ! -f "$W/no_lockfile" ]] || exit 1 ;;
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
    rm -rf "${T:?}/root" "${T:?}/home" "${T:?}/stat-override" "${T:?}/rm-race"
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
    for var in COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES DB_HOST DB_PASSWORD MYSQL_PWD MYSQL_HOST MARIADB_ROOT_PASSWORD PGHOST PGPASSWORD POSTGRES_PASSWORD APP_KEY APP_ENV DATABASE_URL REHEARSAL_RUN_TOKEN; do
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
harness_sums() { (cd "$SUT/php" && sha256sum -- db-ping.php probe.php rehearse.php); }
# What `prepare` records, for a state directory that has been prepared: the two commits and each helper script's digest.
prepared_text() { printf 'prepared-v1\nhistorical=%s\ncurrent=%s\n' "$HIST" "$HEAD_SHA"; harness_sums | sed 's/^/harness /'; }
put_prepared_source() {
    prepared_text >"$T/root/prepared.source"
    chmod 600 "$T/root/prepared.source"
}
prep_world() {
    good_world
    put "$W" image_id "$IMG_ID"
    printf '%s %s\nHEAD %s\n' "$HIST" "$HIST" "$HEAD_SHA" >"$W/commits"
    harness_sums >"$W/out_harness_sums"
}
src_world() {
    prep_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source
    put_prepared_source
}

# add_ephemeral ID ROLE [field=value ...]: a runner or preparation container as `docker create` makes it from this script's arguments.
add_ephemeral() {
    local id="$1" role="$2" d name="$NAME_R" mode="$NAME_N" rw=false tmpfs kv
    shift 2
    tmpfs='/tmp,/var/www/html/current/bootstrap/cache,/var/www/html/current/storage,/var/www/html/historical/bootstrap/cache,/var/www/html/historical/storage,'
    if [[ "$role" == prepare ]]; then name="$NAME_P" mode=none rw=true tmpfs='/tmp,'; fi
    d="$W/c/$id"
    mkdir -p "$d"
    put "$d" id "$id"; put "$d" name "/$name"; put "$d" image "$IMG_ID"; put "$d" l_ns restore-v1; put "$d" l_role "$role"; put "$d" l_token "$TOKEN"
    put "$d" ports 0; put "$d" privileged false; put "$d" capadd null; put "$d" capdrop '["ALL"]'; put "$d" secopt '["no-new-privileges"]'
    put "$d" readonly_root true; put "$d" user 33:33; put "$d" netmode "$mode"; put "$d" nets "$mode,"
    put "$d" mounts "volume:$NAME_SRC:/var/www/html:$rw,"; put "$d" tmpfs "$tmpfs"
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
    local name
    declare -A want=(
        [about]='php artisan about' [migrate:status]='php artisan migrate:status' [migrate]='php artisan migrate --force --no-interaction'
        [relationships:check]='php artisan relationships:check' [release:show]='php artisan release:show'
        [resources:assets:prune]='php artisan resources:assets:prune --dry-run' [helper:db-ping]='php /var/www/html/harness/db-ping.php'
        [helper:fixture-load]='php /var/www/html/harness/rehearse.php fixture-load' [helper:snapshot]='php /var/www/html/harness/rehearse.php snapshot'
        [helper:post-upgrade-mark]='php /var/www/html/harness/rehearse.php mark' [helper:sign-in]='php /var/www/html/harness/rehearse.php signin'
    )
    for name in "${!want[@]}"; do
        src_world
        run_script -- run historical "$name"
        expect_rc "run $name" 0
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
    [[ "$(grep -c . <<<"$creates")" == 6 ]] || bad "expected six preparation containers, got: $creates"
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
    expect_has 'the volume check' "$(tail -n 1 <<<"$creates")" 'exec sha256sum -- "$@" sh /var/www/html/harness db-ping.php probe.php rehearse.php'
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
put_dumps() { # the two passes as mariadb-dump writes their parts that the script reads
    local t
    : >"$W/dump_pass1"
    : >"$W/dump_pass2"
    for t in "${HIST_TABLES[@]}"; do
        printf 'DROP TABLE IF EXISTS \x60%s\x60;\nCREATE TABLE \x60%s\x60 (\n  \x60id\x60 int\n) ENGINE=InnoDB;\n' "$t" "$t" >>"$W/dump_pass1"
        [[ " ${TRANSIENT[*]} " == *" $t "* ]] || printf 'INSERT INTO \x60%s\x60 VALUES (1);\n' "$t" >>"$W/dump_pass2"
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
    backup_world; printf 'INSERT INTO \x60sessions\x60 VALUES (1);\n' >>"$W/dump_pass2"; check 'rows of a transient table' 'rows for other than exactly the durable tables'
    backup_world; sed -i '/^INSERT INTO \x60people\x60/d' "$W/dump_pass2"; check 'a durable table without rows' 'rows for other than exactly the durable tables'
    backup_world; printf 'USE other_db;\n' >>"$W/dump_pass2"; check 'a dump that names a database' 'names a database'
    backup_world; printf 'CREATE DATABASE other_db;\n' >>"$W/dump_pass1"; check 'a dump that creates a database' 'names a database'
    backup_world; printf 'CREATE TABLE \x60extra\x60 (\n  \x60id\x60 int\n) ENGINE=InnoDB;\n' >>"$W/dump_pass1"; check 'a table beyond the sixteen' 'does not create exactly'
    backup_world; touch "$W/dump_fail"; run_script -- take-backup; expect_rc 'a dump client that fails' 1
    [[ ! -e "$T/root/historical.manifest.json" && ! -e "$T/root/historical.dump.sql" ]] || bad 'a backup was kept for a failed dump'
    ! leftover_dump || bad 'the temporary file of a failed dump was left behind'
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
    sed -i '0,/VALUES (1)/s//VALUES (2)/' "$f" # the same size, another checksum
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
    printf "sed -i '0,/VALUES (1)/s//VALUES (2)/' '%s/root/historical.dump.sql'\n" "$T" >"$W/hook_app_sql"
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
    printf "sed -i '0,/VALUES (1)/s//VALUES (2)/' '%s/root/historical.dump.sql'\n" "$T" >"$W/hook_app_sql"
    restore_refused 'a dump changed before the schema is cleared' 'is not the one the manifest describes'
    # Changed after the schema was cleared: nothing is loaded, and the cleared state is reported honestly.
    restore_world
    printf 'sed -i "0,/VALUES (1)/s//VALUES (2)/" "%s/root/historical.dump.sql"\n: >"%s/inventory"\n' "$T" "$W" >"$W/on_clear"
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

# G13: every stage runs only against the source `prepare` recorded.
t_g13_prepared_source() {
    local c
    ensure_cache
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade; do
        restore_world; rm -f "$T/root/prepared.source"
        run_script -- "$c"
        expect_refused "$c without a record of the prepared source" 'prepare has not finished'
        no_helper_ran "$c without a record"; no_clear_or_import "$c without a record"
        restore_world; printf 'HEAD %s\n%s %s\n' "$(printf 'c%.0s' {1..40})" "$HIST" "$HIST" >"$W/commits"
        run_script -- "$c"
        expect_refused "$c after HEAD moved" 'prepared source is stale'
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
    restore_world; sed -i '$d' "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a record that lacks a helper' 'helper script in scripts/rehearsal/php'
    restore_world; sed -i 's/^prepared-v1$/prepared-v0/' "$T/root/prepared.source"
    run_script -- restore-historical
    expect_refused 'a record in another format' 'not exactly what prepare writes'
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
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade; do
        good_world
        run_script -- "$c" /tmp/other.sql
        expect_rc "$c with an argument" 64
        assert_no_docker_call "$c with an argument"
        run_script -- "$c" --database flowlife
        expect_rc "$c with a database" 64
    done
    run_script -- --help
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade; do expect_has 'usage' "$OUT" "$c"; done
    expect_has 'usage names the defect' "$OUT" 'not a restoration'
}

t_g2_stage_commands_refuse_inherited_variables() {
    local c
    for c in baseline take-backup upgrade reproduce-wp3-01 restore-historical verify-signin reupgrade; do
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
    run_script -- cleanup --dry-run
    expect_rc 'dry run' 0
    [[ -e "$T/root/historical.dump.sql" ]] || bad 'a dry run removed the dump'
    run_script -- cleanup
    expect_rc 'cleanup' 0
    [[ ! -e "$T/root" ]] || bad 'cleanup left the state directory although it held only files this script wrote (the prepared-source record and the upgrade structure included)'
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
HARNESS_FILES_EXPECTED=(db-ping.php probe.php rehearse.php)

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
    [G10-e-name]='t_g10_e_name'
    [G10-e-image]='t_g10_e_image'
    [G10-e-label]='t_g10_e_label'
    [G10-e-token]='t_g10_e_token'
    [G10-e-ports]='t_g10_e_ports'
    [G10-e-privilege]='t_g10_e_privilege'
    [G10-e-capdrop]='t_g10_e_capdrop'
    [G10-e-nnp]='t_g10_e_nnp'
    [G10-e-readonly]='t_g10_e_readonly'
    [G10-e-user]='t_g10_e_user'
    [G10-e-network]='t_g10_e_network'
    [G10-e-mounts]='t_g10_e_mounts'
    [G10-e-tmpfs]='t_g10_e_tmpfs'
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
    [G13-prepared-recorded]='t_g13_prepared_source'
    [G13-prepared-historical]='t_g13_prepared_source'
    [G13-prepared-current]='t_g13_prepared_source'
    [G13-prepared-harness]='t_g13_prepared_source'
    [G13-prepared-exact]='t_g13_prepared_source'
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
)

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

printf 'Rehearsal guard tests (fake daemon; no container, network, volume or database is created)\n'
# REHEARSAL_TESTS="t_a t_b" runs just those (no mutation matrix), for working on one test.
if [[ -n "${REHEARSAL_TESTS:-}" ]]; then
    for t in $REHEARSAL_TESTS; do run_test "$t" || FAILURES=$((FAILURES + 1)); done
    ((FAILURES == 0)) || exit 1
    exit 0
fi
for t in $(all_tests); do run_test "$t" || FAILURES=$((FAILURES + 1)); done

printf '\nMutation matrix (each safeguard line replaced by ":" in a scratch copy; its tests must then fail)\n'
SCRIPT_FILE="$SRC/restore-rehearsal.sh"
mapfile -t MARKERS < <(sed -n 's/.*# guard:\([A-Za-z0-9-]*\)$/\1/p' "$SCRIPT_FILE" | sort -u)
for id in "${MARKERS[@]}"; do
    if [[ -z "${GUARD_TESTS[$id]:-}" ]]; then
        printf '  FAIL  %s has no tests in GUARD_TESTS\n' "$id" >&2
        FAILURES=$((FAILURES + 1))
    fi
done
for id in "${!GUARD_TESTS[@]}"; do
    if ! printf '%s\n' "${MARKERS[@]}" | grep -qx "$id"; then
        printf '  FAIL  GUARD_TESTS lists %s, which has no marker in the script\n' "$id" >&2
        FAILURES=$((FAILURES + 1))
    fi
done
for id in "${MARKERS[@]}"; do
    [[ -n "${GUARD_TESTS[$id]:-}" ]] || continue
    mutant="$T/mutant-$id"
    rm -rf "$mutant"
    cp -r "$SRC" "$mutant"
    sed -i "s/^.*# guard:${id}\$/:/" "$mutant/restore-rehearsal.sh"
    if cmp -s "$SCRIPT_FILE" "$mutant/restore-rehearsal.sh"; then
        printf '  FAIL  %-26s the mutation changed nothing\n' "$id" >&2
        FAILURES=$((FAILURES + 1))
        continue
    fi
    if ! bash -n "$mutant/restore-rehearsal.sh" 2>/dev/null; then
        printf '  FAIL  %-26s the mutant does not parse, so it proves nothing\n' "$id" >&2
        FAILURES=$((FAILURES + 1))
        continue
    fi
    SUT="$mutant"
    QUIET=1
    caught=''
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
    SUT="$SRC"
    if [[ -n "$caught" ]]; then
        printf '  ok    %-26s caught by %s: %s\n' "$id" "${caught#t_}" "${FIRST_BAD:0:100}"
    else
        printf '  FAIL  %-26s survived: none of [%s] failed without the guard\n' "$id" "${GUARD_TESTS[$id]}" >&2
        FAILURES=$((FAILURES + 1))
    fi
    rm -rf "$mutant"
done

printf '\n'
if ((FAILURES > 0)); then
    printf '%d rehearsal check(s) failed\n' "$FAILURES" >&2
    exit 1
fi
printf 'All rehearsal guard tests passed\n'
