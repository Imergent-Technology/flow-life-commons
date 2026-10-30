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

# --- the tool directory ------------------------------------------------------------------------------------------------------------

mkdir -p "$BIN"
for tool in bash env cat grep awk tr od id mktemp mv chmod rm rmdir mkdir dirname cut tail head wc flock sed sort date sleep ln cp tee basename true tar; do
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
    "exec -i")
        if [[ "$3" == --user ]]; then # the restricted application-user client: `exec -i --user U ID sh -c SNIPPET sh ARGS...`
            [[ -d "$W/c/$5" ]] || { echo "Error: No such container: $5" >&2; exit 1; }
            sql="$(cat)"
            [[ ! -e "$W/app_cannot_connect" ]] || exit 1
            case "$sql" in
                *'SELECT CURRENT_USER();'*)
                    cat "$W/app_identity" 2>/dev/null || echo 'flc_rehearsal@%'
                    if [[ -f "$W/app_grants" ]]; then cat "$W/app_grants"; else
                        echo "GRANT USAGE ON *.* TO \`flc_rehearsal\`@\`%\` IDENTIFIED BY PASSWORD '*0123456789ABCDEF0123456789ABCDEF01234567'"
                        echo "GRANT ALL PRIVILEGES ON \`flc\\_rehearsal\\_app\`.* TO \`flc_rehearsal\`@\`%\`"
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
        cname='' cuser='' ccapdrop='' csecopt='' cnet='' cmem='' cpids='' ccpus='' cro=false cimage='' ctmpfs=() cmounts_out='' cenvs=() clabels=()
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
                --memory-swap | --pull | --entrypoint | --workdir) shift 2 ;;
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
        # The password reaches the daemon client only through its environment; record whether it arrived, never print it.
        printf '%s' "${DB_PASSWORD:-}" >"$W/create_password_seen.$n"
        env | cut -d= -f1 | sort >"$W/create_env_names.$n"
        if [[ -f "$W/create_override" ]]; then while read -r field value; do printf '%s' "$value" >"$d/$field"; done <"$W/create_override"; fi
        printf '%s\n' "$cid" ;;
    "start "*)
        id="$(lastarg "$@")"
        [[ -d "$W/c/$id" ]] || { echo "Error: No such container: $id" >&2; exit 1; }
        printf '%s\n' "$(cat "$W/c/$id/name")" >>"$W/started"
        case " $* " in *" -i "*) cat >"$W/stdin.$(wc -l <"$W/started")" ;; esac
        [[ ! -e "$W/start_fail" ]] || exit "$(cat "$W/start_fail")"
        if [[ -f "$W/run_stdout" ]]; then cat "$W/run_stdout"; else echo ok; fi ;;
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
    printf 'REHEARSAL_DB_ROOT_PASSWORD=%s\nREHEARSAL_DB_APP_PASSWORD=%s\nREHEARSAL_RUN_TOKEN=%s\n' "$ROOT_PW" "$APP_PW" "${1:-$TOKEN}" >"$T/root/compose.env"
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
bad() {
    TEST_FAILS=$((TEST_FAILS + 1))
    [[ -n "$FIRST_BAD" ]] || FIRST_BAD="$*"
    if [[ $QUIET == 0 ]]; then printf '        FAIL: %s\n' "$*" >&2; fi
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
    expect_lacks help "$OUT" $'\n  restore'
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
    local value
    while IFS='=' read -r _ value; do
        [[ ${#value} -ge 32 && "$value" =~ ^[0-9a-f]+$ ]] || bad "a generated value is too short or not hex: ${#value}"
        expect_lacks 'script output' "$OUT$ERR" "$value"
    done <<<"$first"
    run_script -- cleanup
    expect_rc 'cleanup' 0
    new_world
    enable_up
    run_script -- up
    expect_rc 'second up' 0
    second="$(<"$f")"
    [[ "$first" != "$second" ]] || bad 'two runs produced identical credentials'
    [[ "$(grep -c . <<<"$second")" == 3 ]] || bad 'compose.env should hold exactly three values'
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
prep_world() {
    good_world
    put "$W" image_id "$IMG_ID"
    printf '%s %s\nHEAD %s\n' "$HIST" "$HIST" "$HEAD_SHA" >"$W/commits"
}
src_world() {
    prep_world
    add_volume "$NAME_SRC" "name=$NAME_SRC" l_role=source
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
no_secret_anywhere() { # DESC -- the generated passwords appear in no docker argument, git argument, output or log
    local secret what
    for secret in "$ROOT_PW" "$APP_PW"; do
        for what in "$(cat "$W/calls")" "$(cat "$W/gitcalls" 2>/dev/null)" "$OUT" "$ERR"; do
            [[ "$what" != *"$secret"* ]] || bad "$1: a generated password appears in a docker or git argument, in the output or in the error output"
        done
    done
}

t_g10_run_happy_path() {
    local create name allowed_env='^(PATH|HOME|LANG|DB_PASSWORD|_|PWD|OLDPWD|SHLVL)$'
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
    expect_has 'harness' "$(tar -tf "$W/stdin.3" | tr '\n' ' ')" 'db-ping.php probe.php'
    creates="$(grep -E '^create ' "$W/calls")"
    [[ "$(grep -c . <<<"$creates")" == 5 ]] || bad "expected five preparation containers, got: $creates"
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
        if ((i <= 3)); then expect_has "step $i" "$line" '--network none'; else expect_has "step $i" "$line" '--network bridge'; fi
    done <<<"$creates"
    for line in historical current; do
        expect_has "composer $line" "$creates" "composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader --working-dir=/var/www/html/$line"
    done
    no_secret_anywhere prepare
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
    local db='GRANT ALL PRIVILEGES ON `flc\_rehearsal\_app`.* TO `flc_rehearsal`@`%`' usage='GRANT USAGE ON *.* TO `flc_rehearsal`@`%`'
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

# --- tests: static -------------------------------------------------------------------------------------------------------------------

t_static_substrate() {
    local script="$SRC/restore-rehearsal.sh" f
    # The application password is exported in exactly one place, the launcher, and is never a value on a command line.
    [[ "$(grep -c 'DB_PASSWORD=' "$script")" == 1 ]] || bad 'DB_PASSWORD= appears other than in the launcher'
    grep -q 'export DB_PASSWORD=' "$script" || bad 'the launcher does not export DB_PASSWORD'
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
    [[ "$(grep -c '^  [a-z-]*:$' "$SRC/compose.yaml")" -ge 1 ]] || true
}
HARNESS_FILES_EXPECTED=(db-ping.php probe.php)

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
    [G12-grants]='t_g12_account_identity_and_grants'
    [G12-grants-complete]='t_g12_account_identity_and_grants'
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
    for t in ${GUARD_TESTS[$id]}; do
        if ! run_test "$t"; then caught="$t"; break; fi
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
