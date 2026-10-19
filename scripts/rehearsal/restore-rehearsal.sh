#!/usr/bin/env bash
# G6 WP3c, slice 1: the isolation foundation of the local recovery rehearsal.
#
#   restore-rehearsal.sh up           start the isolated MariaDB and verify what started
#   restore-rehearsal.sh self-check   read-only: verify the environment and, if present, the project
#   restore-rehearsal.sh cleanup [--dry-run] [--stale]
#                                     remove exactly the objects this script created, nothing else
#
# WHAT THIS IS. A rehearsal environment for proving, with synthetic data on a local machine, that the organization can recover
# v0.3.0 after an unsuccessful upgrade (docs/audits/g6/README.md, WP3c). This slice creates only the isolated database server and
# the checks that make it safe to build on. It seeds nothing, restores nothing and installs nothing.
#
# WHAT THIS IS NOT. A restore tool. There is no restore, drop, reset or production command here, and none may be added without the
# owner's decision (the runbooks say ./flow has no backup/restore either, and this script is deliberately not a ./flow command).
# It has no production connectivity, reads no application .env, holds no host credentials and uses no production data.
#
# THE SAFEGUARDS. Each is one line carrying a `# guard:<ID>` marker, because scripts/tests/rehearsal.sh deletes each marked line in
# a scratch copy and requires its tests to notice. A guard added without a marker and a test is a defect.
#
#   G1  the Docker daemon is the local one: default context, a unix socket, no DOCKER_HOST/DOCKER_CONTEXT/TLS override
#   G2  no inherited COMPOSE_*, DB_*, MYSQL_*, MARIADB_*, PG*, POSTGRES_*, APP_*, REHEARSAL_* or DATABASE_URL (values never printed)
#   G3  credentials and the run token are generated per run into a private file; no .env is read, and none may sit beside the compose file
#   G4  identity is checked against the object, not its label: name, image, labels, networks, mounts, ports, privilege, and the
#       server's own VERSION() (a tag names an image, not what it runs); the rendered Compose file is checked for the same policy
#   G5  a sentinel database holds the run token; every SQL statement is preceded by checking it matches this run's token
#   G6  SQL runs only against a fixed allowlist of database names
#   G7  artisan commands are denied unless listed; Resource pruning is allowed only as an exact `--dry-run`
#   G8  cleanup removes only objects whose complete identity verifies, by exact ID or name, re-checked before each removal; no prune,
#       no wildcard, no `compose down`; unknown objects are reported and kept, and a cleanup that leaves any labelled object says so,
#       keeps its state file and exits 1
#   G9  one run at a time (flock), a leftover, partial or foreign project makes `up` refuse with recovery instructions, and a Docker
#       listing that fails is a refusal, never an empty inventory
#
# Exit status: 0 success; 1 a failure; 3 a safeguard refused; 64 usage.
set -Eeuo pipefail

# --- identity: every name here is the literal in compose.yaml, which scripts/tests/rehearsal.sh pins ------------------------------

readonly PROJECT='flc-rehearsal-restore'
readonly LABEL_NS_KEY='io.flowlife.rehearsal'
readonly LABEL_NS_VALUE='restore-v1'
readonly CONTAINER_NAME='flc-rehearsal-restore-mariadb'
readonly NETWORK_NAME='flc-rehearsal-restore-db'
readonly VOLUME_NAME='flc-rehearsal-restore-db-data'
readonly IMAGE='mirror.gcr.io/library/mariadb:10.11.18'
readonly SERVER_VERSION_RE='^10\.11\.18(-|$)'
readonly APP_DB='flc_rehearsal_app'
readonly SENTINEL_DB='flc_rehearsal_identity'
readonly ALLOWED_TARGETS=("$APP_DB" flc_rehearsal_scratch_1 flc_rehearsal_scratch_2 flc_rehearsal_scratch_3)
readonly ROOT_MARKER_NAME='.flc-rehearsal-root'
readonly ROOT_MARKER_TEXT='restore-rehearsal-v1'
readonly REFUSED_ENV_RE='^(COMPOSE_|DB_|MYSQL_|MARIADB_|PG|POSTGRES_|APP_|REHEARSAL_)|^DATABASE_URL$'

# The inspect templates. Every expression in them is one the scripts/tests/rehearsal.sh fake daemon understands (it refuses any other),
# and each was run against a real container, network and volume on this Docker version while this slice was written.
# shellcheck disable=SC2016 # Go template text for `docker inspect`, not shell expansions
readonly CONTAINER_FMT='{{.Id}}|{{.Name}}|{{.Config.Image}}|{{index .Config.Labels "io.flowlife.rehearsal"}}|{{index .Config.Labels "io.flowlife.rehearsal.role"}}|{{index .Config.Labels "io.flowlife.rehearsal.run-token"}}|{{len .HostConfig.PortBindings}}|{{.HostConfig.Privileged}}|{{json .HostConfig.CapAdd}}|{{.HostConfig.NetworkMode}}|{{range $k,$v := .NetworkSettings.Networks}}{{$k}},{{end}}|{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}},{{end}}'
# shellcheck disable=SC2016 # Go template text for `docker network inspect`, not shell expansions
readonly NETWORK_FMT='{{.Id}}|{{.Name}}|{{.Internal}}|{{.Driver}}|{{index .Labels "io.flowlife.rehearsal"}}|{{index .Labels "io.flowlife.rehearsal.role"}}|{{index .Labels "io.flowlife.rehearsal.run-token"}}|{{range $id,$c := .Containers}}{{$id}},{{end}}'
# shellcheck disable=SC2016 # Go template text for `docker volume inspect`, not shell expansions
readonly VOLUME_FMT='{{.Name}}|{{.Driver}}|{{len .Options}}|{{index .Labels "io.flowlife.rehearsal"}}|{{index .Labels "io.flowlife.rehearsal.role"}}|{{index .Labels "io.flowlife.rehearsal.run-token"}}'

CID=''
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
readonly COMPOSE_FILE_PATH="$SCRIPT_DIR/compose.yaml"

# --- output ------------------------------------------------------------------------------------------------------------------------

say() { printf '%s\n' "$*"; }
note() { printf '  %s\n' "$*"; }
warn() { printf 'restore-rehearsal: %s\n' "$*" >&2; }
fail() {
    warn "$*"
    exit 1
}
refuse() {
    printf 'restore-rehearsal: REFUSED: %s\n' "$*" >&2
    exit 3
}

usage() {
    cat <<'USAGE'
Usage: restore-rehearsal.sh <command>

  up            Start the isolated MariaDB 10.11.18 (internal network, nothing published to the host) and verify it.
  self-check    Read-only. Verify the environment and, if a rehearsal project exists, its complete identity.
  cleanup       Remove exactly the objects this script created. --dry-run lists them and removes nothing. If anything
                labelled is kept or cannot be removed, cleanup says what remains, keeps its state file and exits 1; run
                it again once the cause is gone. --stale is needed only when the private state file is gone (the run
                token then cannot be checked): review the --dry-run list first.

Local only. It never talks to production or staging, reads no application .env, and has no restore command. Notes:
docs/development/restore-rehearsal.md
USAGE
}

# --- docker, under a scrubbed environment ---------------------------------------------------------------------------------------------

# Every docker invocation gets only what it needs to find the local daemon, never the caller's environment (G2 refuses the
# variables that matter, this keeps the rest out as well).
dkr() {
    local env_args=("PATH=$PATH" "HOME=${HOME:-/nonexistent}" "LANG=C")
    if [[ -n "${DOCKER_CONFIG:-}" ]]; then env_args+=("DOCKER_CONFIG=$DOCKER_CONFIG"); fi
    env -i "${env_args[@]}" docker "$@"
}

# G1: the daemon must be this machine's, reached through the default context's unix socket. DOCKER_HOST is refused even when it names
# a unix socket, because every command below runs under a scrubbed environment: a socket named only in the caller's environment
# would not be the one used, and the check would be about a different daemon from the one acted on.
verify_local_daemon() {
    [[ -z "${DOCKER_CONTEXT:-}" ]] || refuse "DOCKER_CONTEXT is set; the rehearsal uses the default context only. Unset it." # guard:G1-context-env
    [[ -z "${DOCKER_HOST:-}" ]] || refuse "DOCKER_HOST is set; the rehearsal uses the default local socket only. Unset it." # guard:G1-host
    [[ -z "${DOCKER_TLS_VERIFY:-}${DOCKER_CERT_PATH:-}" ]] || refuse "DOCKER_TLS_VERIFY or DOCKER_CERT_PATH is set; that describes a remote daemon." # guard:G1-tls
    local context endpoint
    context="$(dkr context show 2>/dev/null)" || fail "cannot ask Docker for its current context"
    [[ "$context" == default ]] || refuse "the current Docker context is '$context', not 'default'. Switch to the local default context; this rule is not bypassed." # guard:G1-context
    endpoint="$(dkr context inspect "$context" --format '{{.Endpoints.docker.Host}}' 2>/dev/null)" || fail "cannot read the Docker context endpoint"
    [[ "$endpoint" == unix://* ]] || refuse "the default context's endpoint is '$endpoint', not a local unix socket." # guard:G1-endpoint
    dkr info --format '{{.ServerVersion}}' >/dev/null 2>&1 || refuse "the local Docker daemon is not reachable." # guard:G1-reachable
}

# G2: names only, never values.
verify_clean_environment() {
    local name found=()
    while IFS= read -r name; do
        if [[ "$name" =~ $REFUSED_ENV_RE ]]; then found+=("$name"); fi
    done < <(compgen -e)
    [[ ${#found[@]} -eq 0 ]] || refuse "inherited variable(s) that could redirect or override the rehearsal: ${found[*]}. Run it from a clean shell (env -i) or unset them." # guard:G2-env
}

# --- the private state directory (G3, G9) ---------------------------------------------------------------------------------------------

root_dir() {
    if [[ -n "${FLC_REHEARSAL_ROOT:-}" ]]; then
        printf '%s' "$FLC_REHEARSAL_ROOT"
        return 0
    fi
    [[ -n "${HOME:-}" ]] || refuse "HOME is not set, so the default state directory cannot be chosen. Set FLC_REHEARSAL_ROOT to an absolute path." # guard:G3-home
    printf '%s' "$HOME/.local/state/flowlife-restore-rehearsal"
}

# A path this script may keep credentials in, and later delete from. It is never removed recursively: only the three files it writes.
validate_root_path() {
    local root="$1"
    [[ "$root" == /* ]] || refuse "the state directory '$root' is not an absolute path." # guard:G3-root-absolute
    [[ "$root" != *..* && "$root" != / && "$root" != "${HOME:-/nonexistent}" && "$root" != "${HOME:-/nonexistent}/" ]] || refuse "the state directory '$root' is not an acceptable location." # guard:G3-root-location
    [[ ! -L "$root" ]] || refuse "the state directory '$root' is a symbolic link." # guard:G3-root-symlink
}

# Create the directory if absent. Adopt an existing one only if it is ours: private, owned by this user, carrying the marker.
prepare_root() {
    local root="$1"
    validate_root_path "$root"
    if [[ ! -e "$root" ]]; then
        (umask 077 && mkdir -p -- "$root")
        (umask 077 && printf '%s\n' "$ROOT_MARKER_TEXT" >"$root/$ROOT_MARKER_NAME")
    fi
    verify_root_ownership "$root"
}

verify_root_ownership() {
    local root="$1"
    [[ -d "$root" ]] || refuse "the state path '$root' is not a directory." # guard:G3-root-dir
    [[ "$(stat -c %u -- "$root")" == "$(id -u)" ]] || refuse "the state directory '$root' is not owned by you." # guard:G3-root-owner
    [[ "$(stat -c %a -- "$root")" == 700 ]] || refuse "the state directory '$root' is not private (mode 700)." # guard:G3-root-mode
    [[ -f "$root/$ROOT_MARKER_NAME" && "$(<"$root/$ROOT_MARKER_NAME")" == "$ROOT_MARKER_TEXT" ]] || refuse "'$root' exists but is not a rehearsal state directory (marker missing); it is left alone." # guard:G3-root-marker
}

# G9: one run at a time. The descriptor stays open for the life of the process.
acquire_lock() {
    local root="$1"
    command -v flock >/dev/null 2>&1 || fail "flock is required for the single-run lock"
    exec 9>"$root/run.lock"
    flock -n 9 || refuse "another rehearsal run holds the lock in '$root'." # guard:G9-lock
}

random_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }

# G3: new credentials and a new run token every run, written once to a private file, never printed. compose.yaml has no env_file and
# no ${VAR} that this file does not define; nothing is read from the application's .env or the development .env.
write_credentials() {
    local root="$1" tmp
    [[ ! -e "$SCRIPT_DIR/.env" ]] || refuse "a .env file sits beside the compose file ($SCRIPT_DIR/.env); Compose would load it. Remove it." # guard:G3-no-dotenv
    tmp="$(umask 077 && mktemp -- "$root/.compose.env.XXXXXX")"
    (
        umask 077
        printf 'REHEARSAL_DB_ROOT_PASSWORD=%s\n' "$(random_hex 24)"
        printf 'REHEARSAL_DB_APP_PASSWORD=%s\n' "$(random_hex 24)"
        printf 'REHEARSAL_RUN_TOKEN=%s\n' "$(random_hex 16)"
    ) >"$tmp"
    chmod 600 -- "$tmp"
    mv -- "$tmp" "$root/compose.env"
    [[ "$(stat -c %a -- "$root/compose.env")" == 600 ]] || refuse "the credentials file is not private (mode 600)." # guard:G3-env-mode
}

# The run token recorded for this run, validated before it is ever placed in a comparison or a statement.
state_token() {
    local root="$1" token
    [[ -f "$root/compose.env" ]] || return 1
    token="$(grep -E '^REHEARSAL_RUN_TOKEN=' "$root/compose.env" | tail -n 1 | cut -d= -f2-)" || token=''
    [[ "$token" =~ ^[0-9a-f]{32}$ ]] || refuse "the run token in '$root/compose.env' is not a 32-character hex value, so nothing can be checked against it and nothing was changed. The script never edits, discards or rebuilds that file. If you want to keep it as evidence, copy it elsewhere (cp -p). To recover, move it aside inside the state directory (mv '$root/compose.env' '$root/compose.env.unreadable'), then review: restore-rehearsal.sh cleanup --dry-run --stale ; and only if the list is the rehearsal's: restore-rehearsal.sh cleanup --stale. The moved-aside file keeps the state directory in place." # guard:G5-token-format
    printf '%s' "$token"
}

compose() {
    dkr compose -p "$PROJECT" --project-directory "$SCRIPT_DIR" -f "$COMPOSE_FILE_PATH" --env-file "$(root_dir)/compose.env" "$@"
}

# --- G4: the rendered Compose file honours the isolation policy ------------------------------------------------------------------------

# Names of the direct children of a top-level section of rendered `docker compose config` output.
section_children() {
    awk -v section="$1" '
        /^[A-Za-z0-9_.-]+:/ { in_section = ($0 == section ":"); next }
        in_section && /^  [A-Za-z0-9_.-]+:/ { sub(/^  /, ""); sub(/:.*/, ""); print }
    '
}

# Reads rendered output on stdin. Prints the first problem and returns 1, or prints nothing and returns 0.
compose_policy_problem() {
    local rendered key
    rendered="$(cat)"
    grep -Eq '^    internal: true$' <<<"$rendered" || { echo "the database network is not internal"; return 1; } # guard:G4-pol-internal
    for key in ports network_mode privileged cap_add env_file devices pid ipc external external_links extra_hosts; do
        ! grep -Eq "^[[:space:]]+${key}:" <<<"$rendered" || { echo "the compose file uses '$key'"; return 1; } # guard:G4-pol-forbidden-key
    done
    ! grep -Eq 'type: bind|docker\.sock' <<<"$rendered" || { echo "the compose file mounts a host path or the Docker socket"; return 1; } # guard:G4-pol-forbidden-mount
    [[ "$(section_children services <<<"$rendered")" == mariadb ]] || { echo "the compose file must define exactly one service, mariadb"; return 1; } # guard:G4-pol-one-service
    [[ "$(section_children networks <<<"$rendered")" == rehearsal-db ]] || { echo "the compose file must define exactly one network, rehearsal-db"; return 1; } # guard:G4-pol-one-network
    grep -Fxq "    image: $IMAGE" <<<"$rendered" || { echo "the image is not $IMAGE"; return 1; } # guard:G4-pol-image
    grep -Fxq "    container_name: $CONTAINER_NAME" <<<"$rendered" || { echo "the container name is not $CONTAINER_NAME"; return 1; } # guard:G4-pol-name
    grep -Fxq "    name: $NETWORK_NAME" <<<"$rendered" || { echo "the network name is not $NETWORK_NAME"; return 1; } # guard:G4-pol-net-name
    grep -Fxq "    name: $VOLUME_NAME" <<<"$rendered" || { echo "the volume name is not $VOLUME_NAME"; return 1; } # guard:G4-pol-vol-name
    return 0
}

# --- G4: identity of the objects that exist ---------------------------------------------------------------------------------------------

# container_identity_problem ID TOKEN: prints the first respect in which the container is not the one this script creates, returns 1;
# prints nothing and returns 0 when its identity is complete. TOKEN is compared when given.
container_identity_problem() {
    local id="$1" token="${2:-}" out
    out="$(dkr inspect --type container --format "$CONTAINER_FMT" "$id" 2>/dev/null)" || { echo "cannot be inspected (a missing container, or one the inspect template cannot render, such as a bind-mounted one, is not the rehearsal database)"; return 1; }
    local name image l_ns l_role l_token ports privileged capadd netmode networks mounts
    IFS='|' read -r _ name image l_ns l_role l_token ports privileged capadd netmode networks mounts <<<"$out"
    [[ "$name" == "/$CONTAINER_NAME" ]] || { echo "its name is '$name', not '/$CONTAINER_NAME'"; return 1; } # guard:G4-c-name
    [[ "$image" == "$IMAGE" ]] || { echo "its image is '$image', not '$IMAGE'"; return 1; } # guard:G4-c-image
    [[ "$l_ns" == "$LABEL_NS_VALUE" ]] || { echo "it lacks the $LABEL_NS_KEY=$LABEL_NS_VALUE label"; return 1; } # guard:G4-c-label
    [[ "$l_role" == database ]] || { echo "its role label is '$l_role', not 'database'"; return 1; } # guard:G4-c-role
    [[ "$ports" == 0 ]] || { echo "it publishes $ports port(s) to the host"; return 1; } # guard:G4-c-ports
    [[ "$privileged" == false && "$capadd" == null ]] || { echo "it is privileged or adds capabilities"; return 1; } # guard:G4-c-privilege
    [[ "$netmode" == "$NETWORK_NAME" ]] || { echo "its network mode is '$netmode', not '$NETWORK_NAME'"; return 1; } # guard:G4-c-netmode
    [[ "$networks" == "$NETWORK_NAME," ]] || { echo "it is attached to '$networks', not only to '$NETWORK_NAME'"; return 1; } # guard:G4-c-networks
    [[ "$mounts" == "volume:$VOLUME_NAME:/var/lib/mysql," ]] || { echo "its mounts are '$mounts', not only the rehearsal data volume"; return 1; } # guard:G4-c-mounts
    [[ -z "$token" || "$l_token" == "$token" ]] || { echo "its run token differs from this state directory's"; return 1; } # guard:G4-c-token
    return 0
}

# network_identity_problem ID TOKEN ALLOWED_ENDPOINTS: ALLOWED_ENDPOINTS is a comma-separated list of full container IDs that may be
# attached (none when empty). Any other attached container is not ours and means the network is not ours to remove.
network_identity_problem() {
    local id="$1" token="${2:-}" allowed="${3:-}" out
    out="$(dkr network inspect --format "$NETWORK_FMT" "$id" 2>/dev/null)" || { echo "cannot be inspected"; return 1; }
    local name internal driver l_ns l_role l_token endpoints endpoint
    IFS='|' read -r _ name internal driver l_ns l_role l_token endpoints <<<"$out"
    [[ "$name" == "$NETWORK_NAME" ]] || { echo "its name is '$name', not '$NETWORK_NAME'"; return 1; } # guard:G4-n-name
    [[ "$internal" == true ]] || { echo "it is not an internal network"; return 1; } # guard:G4-n-internal
    [[ "$driver" == bridge ]] || { echo "its driver is '$driver', not 'bridge'"; return 1; } # guard:G4-n-driver
    [[ "$l_ns" == "$LABEL_NS_VALUE" && "$l_role" == database-network ]] || { echo "its labels are not this script's"; return 1; } # guard:G4-n-label
    [[ -z "$token" || "$l_token" == "$token" ]] || { echo "its run token differs from this state directory's"; return 1; } # guard:G4-n-token
    local IFS=','
    for endpoint in $endpoints; do
        [[ ",$allowed," == *",$endpoint,"* ]] || { echo "container ${endpoint:0:12} that is not ours is attached"; return 1; } # guard:G4-n-endpoints
    done
    return 0
}

# volume_identity_problem NAME TOKEN ALLOWED_USERS: containers other than ALLOWED_USERS (comma-separated full IDs) using the volume make
# it not ours to remove.
volume_identity_problem() {
    local name="$1" token="${2:-}" allowed="${3:-}" out user users='' users_failed=0
    out="$(dkr volume inspect --format "$VOLUME_FMT" "$name" 2>/dev/null)" || { echo "cannot be inspected"; return 1; }
    local v_name driver options l_ns l_role l_token
    IFS='|' read -r v_name driver options l_ns l_role l_token <<<"$out"
    [[ "$v_name" == "$VOLUME_NAME" ]] || { echo "its name is '$v_name', not '$VOLUME_NAME'"; return 1; } # guard:G4-v-name
    [[ "$driver" == local && "$options" == 0 ]] || { echo "it is not a plain local volume (it carries driver options such as a host path)"; return 1; } # guard:G4-v-options
    [[ "$l_ns" == "$LABEL_NS_VALUE" && "$l_role" == database-data ]] || { echo "its labels are not this script's"; return 1; } # guard:G4-v-label
    [[ -z "$token" || "$l_token" == "$token" ]] || { echo "its run token differs from this state directory's"; return 1; } # guard:G4-v-token
    users="$(dkr ps -aq --no-trunc --filter "volume=$name")" || users_failed=1
    [[ $users_failed == 0 ]] || { echo "the containers using it cannot be listed"; return 1; } # guard:G8-volume-users-list
    while IFS= read -r user; do
        [[ -z "$user" || ",$allowed," == *",$user,"* ]] || { echo "container ${user:0:12} that is not ours uses it"; return 1; } # guard:G8-volume-in-use
    done <<<"$users"
    return 0
}

# --- looking for objects --------------------------------------------------------------------------------------------------------------

label_filter() { printf 'label=%s=%s' "$LABEL_NS_KEY" "$LABEL_NS_VALUE"; }

labelled_containers() { dkr ps -aq --no-trunc --filter "$(label_filter)"; }
labelled_networks() { dkr network ls -q --no-trunc --filter "$(label_filter)"; }
labelled_volumes() { dkr volume ls -q --filter "$(label_filter)"; }

in_list() { [[ -n "$2" ]] && grep -Fxq -- "$1" <<<"$2"; }

# inventory VAR WHAT COMMAND [ARGS...]: runs an enumeration and stores its output in VAR, or refuses. A listing that failed is not an
# empty listing, and a failure inside $(...), a pipeline or a process substitution does not reliably stop a script that relies on
# `set -e`, so every enumeration whose emptiness would be read as "nothing there" goes through here, in the main shell.
inventory() {
    local inv_var="$1" inv_what="$2" inv_out='' inv_status=0
    shift 2
    inv_out="$("$@")" || inv_status=$?
    ((inv_status == 0)) || refuse "Docker could not list $inv_what (status $inv_status). An unreadable inventory is not an empty one; nothing was changed." # guard:G9-inventory
    printf -v "$inv_var" '%s' "$inv_out"
}

# Objects of the project's exact names or Compose project that do NOT carry the rehearsal label (the three arguments are the labelled
# containers, networks and volumes, one per line). For detection and reporting only: they are never selected for removal.
foreign_lookalikes() {
    local labelled_c="${1:-}" labelled_n="${2:-}" labelled_v="${3:-}" id name listing
    listing="$(dkr ps -a --no-trunc --filter "name=$CONTAINER_NAME" --format '{{.ID}} {{.Names}}')" || return 1
    while read -r id name; do
        if [[ "$name" == "$CONTAINER_NAME" || "$name" == "/$CONTAINER_NAME" ]] && ! in_list "$id" "$labelled_c"; then say "container ${id:0:12} named $CONTAINER_NAME"; fi
    done <<<"$listing"
    listing="$(dkr network ls --no-trunc --filter "name=$NETWORK_NAME" --format '{{.ID}} {{.Name}}')" || return 1
    while read -r id name; do
        if [[ "$name" == "$NETWORK_NAME" ]] && ! in_list "$id" "$labelled_n"; then say "network ${id:0:12} named $NETWORK_NAME"; fi
    done <<<"$listing"
    listing="$(dkr volume ls --filter "name=$VOLUME_NAME" --format '{{.Name}}')" || return 1
    while read -r name; do
        if [[ "$name" == "$VOLUME_NAME" ]] && ! in_list "$name" "$labelled_v"; then say "volume named $VOLUME_NAME"; fi
    done <<<"$listing"
    listing="$(dkr ps -aq --no-trunc --filter "label=com.docker.compose.project=$PROJECT")" || return 1
    while read -r id; do
        if [[ -n "$id" ]] && ! in_list "$id" "$labelled_c"; then say "container ${id:0:12} in Compose project $PROJECT"; fi
    done <<<"$listing"
}

# --- sentinel and guarded SQL (G5, G6) ------------------------------------------------------------------------------------------------

# The one place that talks to the server. SQL on stdin; extra arguments (a -D <database> from the allowlist) go to the client.
mariadb_root() {
    local cid="$1"
    shift
    # shellcheck disable=SC2016 # the password expands inside the container, so it never appears on this host's command line
    dkr exec -i "$cid" sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --batch --skip-column-names "$@"' sh "$@"
}

server_version_problem() {
    local cid="$1" version
    version="$(printf 'SELECT VERSION();\n' | mariadb_root "$cid" 2>/dev/null)" || { echo "the server does not answer"; return 1; }
    [[ "$version" =~ $SERVER_VERSION_RE ]] || { echo "the server reports version '$version', not 10.11.18"; return 1; } # guard:G4-version
    return 0
}

target_allowed() {
    local candidate="$1" name
    for name in "${ALLOWED_TARGETS[@]}"; do
        if [[ "$candidate" == "$name" ]]; then return 0; fi
    done
    return 1
}

# The project's container, found by label and then proven by identity. Sets CID.
resolve_project_container() {
    local root="$1" token ids
    token="$(state_token "$root")" || refuse "there is no state file in '$root'; nothing to verify against."
    inventory ids 'the labelled containers' labelled_containers
    [[ -n "$ids" && "$ids" != *$'\n'* ]] || refuse "expected exactly one rehearsal container, found $(grep -c . <<<"$ids" || true)." # guard:G4-single
    local why
    why="$(container_identity_problem "$ids" "$token")" || refuse "the container ${ids:0:12} is not the rehearsal database: $why."
    CID="$ids"
}

# G5: the server must carry this run's token in its sentinel database.
sentinel_problem() {
    local cid="$1" token="$2" stored
    stored="$(printf 'SELECT token FROM %s.run_token;\n' "$SENTINEL_DB" | mariadb_root "$cid" 2>/dev/null)" || { echo "the sentinel database cannot be read"; return 1; }
    [[ "$stored" == "$token" ]] || { echo "the sentinel's run token is not this run's"; return 1; } # guard:G5-token
    return 0
}

# Everything that must hold before any SQL is sent. A destructive statement may follow only this.
assert_sql_target() {
    local root="$1" db="$2" token why
    target_allowed "$db" || refuse "'$db' is not one of the rehearsal's databases (${ALLOWED_TARGETS[*]})." # guard:G6-allowlist
    resolve_project_container "$root" # guard:G5-sql-container
    token="$(state_token "$root")"
    why="$(server_version_problem "$CID")" || refuse "$why." # guard:G5-sql-version
    why="$(sentinel_problem "$CID" "$token")" || refuse "$why." # guard:G5-sql-sentinel
}

# SQL on stdin, against one allowlisted database, after assert_sql_target.
guarded_sql() {
    local root="$1" db="$2"
    assert_sql_target "$root" "$db"
    mariadb_root "$CID" -D "$db"
}

# Creates the sentinel and records the run token. Fixed statements; the token was validated as hex, so nothing is interpolated that
# could be anything else.
create_sentinel() {
    local cid="$1" token="$2"
    [[ "$token" =~ ^[0-9a-f]{32}$ ]] || refuse "the run token is not a 32-character hex value." # guard:G5-create-format
    mariadb_root "$cid" <<SQL
CREATE DATABASE ${SENTINEL_DB};
CREATE TABLE ${SENTINEL_DB}.run_token (token CHAR(32) NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO ${SENTINEL_DB}.run_token VALUES ('${token}');
SQL
}

# --- G7: artisan, deny by default ---------------------------------------------------------------------------------------------------

readonly ARTISAN_ALLOWED=(about migrate migrate:status relationships:check release:show resources:assets:prune)

# artisan_problem ARGS...: why the rehearsal refuses to run `php artisan ARGS`, or nothing. The first argument must be one of the exact
# names above: Symfony Console resolves abbreviations (`res:ass:pru` is the prune command), so refusing the prune command by name would
# be bypassed by an abbreviation; allowing only exact names cannot be. `schedule:run` is not listed because it runs the prune.
artisan_problem() {
    local command="${1:-}" allowed arg dry=0 listed=0
    for allowed in "${ARTISAN_ALLOWED[@]}"; do
        if [[ "$command" == "$allowed" ]]; then listed=1; fi
    done
    [[ $listed == 1 ]] || { echo "'$command' is not an artisan command the rehearsal allows"; return 1; } # guard:G7-allowlist
    if [[ "$command" == resources:assets:prune ]]; then
        for arg in "${@:2}"; do
            [[ "$arg" != --dry-run ]] || dry=1
            [[ "$arg" == --dry-run || "$arg" != -* ]] || { echo "resources:assets:prune takes no option but an exact --dry-run"; return 1; } # guard:G7-prune-options
        done
        [[ $dry == 1 ]] || { echo "resources:assets:prune is allowed only with --dry-run"; return 1; } # guard:G7-prune-dry-run
    fi
    return 0
}

# --- commands -------------------------------------------------------------------------------------------------------------------------

UP_RECOVERY=0
on_up_exit() {
    local status=$?
    if ((status != 0 && UP_RECOVERY == 1)); then
        warn "up did not finish. The project may be partly created; nothing was removed."
        warn "  1. restore-rehearsal.sh cleanup --dry-run   (lists exactly what it would remove)"
        warn "  2. restore-rehearsal.sh cleanup"
    fi
}

# G9: anything left from an earlier run, partial or whole, or anything of ours-by-name that is not ours, stops `up`.
refuse_if_stale() {
    local root="$1" leftovers lc ln lv
    [[ ! -e "$root/compose.env" ]] || refuse "a previous run left its state in '$root'. Run: restore-rehearsal.sh cleanup --dry-run, then cleanup." # guard:G9-state
    inventory lc 'the labelled containers' labelled_containers
    inventory ln 'the labelled networks' labelled_networks
    inventory lv 'the labelled volumes' labelled_volumes
    leftovers="$(printf '%s %s %s' "$lc" "$ln" "$lv" | tr '\n' ' ')"
    [[ -z "${leftovers// /}" ]] || refuse "objects from an earlier run exist (${leftovers:0:120}...). Run: restore-rehearsal.sh cleanup --dry-run, then cleanup." # guard:G9-labelled
    inventory leftovers 'the objects named like the rehearsal' foreign_lookalikes '' '' ''
    [[ -z "$leftovers" ]] || refuse "objects with the rehearsal's names or Compose project exist that this script cannot prove it created: $(tr '\n' ';' <<<"$leftovers") They are left alone. Remove them yourself if they are yours." # guard:G9-lookalikes
}

cmd_up() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    verify_clean_environment # guard:G2-up
    local root
    root="$(root_dir)"
    validate_root_path "$root"
    verify_local_daemon
    prepare_root "$root"
    acquire_lock "$root"
    refuse_if_stale "$root"

    trap on_up_exit EXIT
    UP_RECOVERY=1
    write_credentials "$root"
    local token rendered why
    token="$(state_token "$root")"
    rendered="$(compose config)" || fail "docker compose cannot render the rehearsal compose file"
    why="$(compose_policy_problem <<<"$rendered")" || refuse "$why."
    rendered=''

    say "Starting the isolated MariaDB (project $PROJECT; image pulls use the Docker daemon, the database network has no route out) ..."
    compose up -d --wait

    resolve_project_container "$root" # guard:G4-up-container
    local nets vols
    inventory nets 'the labelled networks' labelled_networks
    inventory vols 'the labelled volumes' labelled_volumes
    [[ -n "$nets" && "$nets" != *$'\n'* ]] || refuse "expected exactly one rehearsal network." # guard:G4-up-one-network
    [[ -n "$vols" && "$vols" != *$'\n'* ]] || refuse "expected exactly one rehearsal volume." # guard:G4-up-one-volume
    why="$(network_identity_problem "$nets" "$token" "$CID")" || refuse "the network is not the rehearsal's: $why." # guard:G4-up-network
    why="$(volume_identity_problem "$vols" "$token" "$CID")" || refuse "the volume is not the rehearsal's: $why." # guard:G4-up-volume
    why="$(server_version_problem "$CID")" || refuse "$why." # guard:G4-up-version
    create_sentinel "$CID" "$token"
    why="$(sentinel_problem "$CID" "$token")" || refuse "$why."
    printf 'SELECT 1;\n' | guarded_sql "$root" "$APP_DB" >/dev/null

    UP_RECOVERY=0
    say "Ready."
    note "container   $CONTAINER_NAME (${CID:0:12}), image $IMAGE, server reports 10.11.18"
    note "network     $NETWORK_NAME (internal; nothing is published to the host)"
    note "databases   $APP_DB (target), $SENTINEL_DB (identity); run token verified"
    note "state       $root (credentials in compose.env, mode 600, never printed)"
}

cmd_self_check() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    verify_clean_environment
    local root
    root="$(root_dir)"
    validate_root_path "$root"
    verify_local_daemon
    ok_line() { say "ok   $*"; }
    ok_line "no inherited database, application or Compose override"
    ok_line "Docker daemon is local (default context, unix socket)"
    local containers networks volumes lookalikes
    inventory containers 'the labelled containers' labelled_containers
    inventory networks 'the labelled networks' labelled_networks
    inventory volumes 'the labelled volumes' labelled_volumes
    inventory lookalikes 'the objects named like the rehearsal' foreign_lookalikes "$containers" "$networks" "$volumes"
    if [[ -z "$containers$networks$volumes$lookalikes" ]]; then
        if [[ -e "$root/compose.env" ]]; then fail "a state file exists in '$root' but no rehearsal objects do: a previous run was interrupted. Run: restore-rehearsal.sh cleanup"; fi
        ok_line "no rehearsal project is present"
        return 0
    fi
    [[ -z "$lookalikes" ]] || refuse "objects with the rehearsal's names or Compose project exist that this script cannot prove it created: $(tr '\n' ';' <<<"$lookalikes")" # guard:G9-selfcheck-lookalikes
    [[ -f "$root/compose.env" ]] || refuse "rehearsal objects exist but the state file is missing from '$root'. Review them with: restore-rehearsal.sh cleanup --dry-run --stale" # guard:G9-selfcheck-state
    verify_root_ownership "$root"
    local token why
    token="$(state_token "$root")"
    resolve_project_container "$root"
    ok_line "container ${CID:0:12} is the rehearsal database (name, image, labels, network, mounts, no published ports, run token)"
    [[ -n "$networks" && "$networks" != *$'\n'* ]] || refuse "expected exactly one rehearsal network."
    why="$(network_identity_problem "$networks" "$token" "$CID")" || refuse "the network is not the rehearsal's: $why."
    ok_line "network is internal and holds only the rehearsal database"
    [[ -n "$volumes" && "$volumes" != *$'\n'* ]] || refuse "expected exactly one rehearsal volume."
    why="$(volume_identity_problem "$volumes" "$token" "$CID")" || refuse "the volume is not the rehearsal's: $why."
    ok_line "volume is a plain local volume used only by the rehearsal database"
    why="$(server_version_problem "$CID")" || refuse "$why."
    ok_line "the server itself reports MariaDB 10.11.18"
    why="$(sentinel_problem "$CID" "$token")" || refuse "$why."
    ok_line "sentinel database carries this run's token"
    printf 'SELECT 1;\n' | guarded_sql "$root" "$APP_DB" >/dev/null
    ok_line "$APP_DB answers through the guarded path"
    if target_allowed flowlife; then refuse "the allowlist accepts a development database name."; fi
    ok_line "the allowlist refuses a development database name"
}

# Every labelled object that cleanup leaves behind, and why. A cleanup that leaves any is incomplete: it keeps its state, exits non-zero,
# and can be run again once the obstruction is gone.
REMAINING=()
retain() {
    say "keep    $1"
    REMAINING+=("$1")
}

# cleanup: classify every candidate, then remove only the ones whose complete identity verifies, each re-verified just before it is
# removed. What does not verify is reported and kept, and the cleanup then reports itself incomplete.
cmd_cleanup() {
    local dry=0 stale=0 arg
    for arg in "$@"; do
        case "$arg" in
            --dry-run) dry=1 ;;
            --stale) stale=1 ;;
            *) usage >&2; exit 64 ;;
        esac
    done
    verify_clean_environment
    local root have_root=0 token=''
    root="$(root_dir)"
    validate_root_path "$root" # guard:G3-cleanup-validate
    verify_local_daemon
    if [[ -e "$root" ]]; then
        verify_root_ownership "$root" # guard:G3-cleanup-owner
        have_root=1
        acquire_lock "$root"
        if [[ -f "$root/compose.env" ]]; then token="$(state_token "$root")"; fi
    fi

    local containers networks volumes
    inventory containers 'the labelled containers' labelled_containers
    inventory networks 'the labelled networks' labelled_networks
    inventory volumes 'the labelled volumes' labelled_volumes
    if [[ -n "$containers$networks$volumes" && -z "$token" ]]; then
        [[ $stale == 1 ]] || refuse "objects carrying the rehearsal label exist but there is no state file, so their run token cannot be checked. Review them with: restore-rehearsal.sh cleanup --dry-run --stale ; then, if they are yours: restore-rehearsal.sh cleanup --stale" # guard:G8-state-required
    fi

    local owned_containers=() owned_networks=() owned_volumes=() id why owned_ids=''
    for id in $containers; do
        if why="$(container_identity_problem "$id" "$token")"; then
            owned_containers+=("$id")
            owned_ids+="$id,"
        else
            retain "container ${id:0:12}: $why"
        fi
    done
    for id in $networks; do
        if why="$(network_identity_problem "$id" "$token" "$owned_ids")"; then owned_networks+=("$id"); else
            retain "network ${id:0:12}: $why"
        fi
    done
    for id in $volumes; do
        if why="$(volume_identity_problem "$id" "$token" "$owned_ids")"; then owned_volumes+=("$id"); else
            retain "volume $id: $why"
        fi
    done
    local lookalikes
    inventory lookalikes 'the objects named like the rehearsal' foreign_lookalikes "$containers" "$networks" "$volumes"
    if [[ -n "$lookalikes" ]]; then
        say "note    not the rehearsal's (no label), so never selected for removal: $(tr '\n' ';' <<<"$lookalikes")"
    fi

    for id in "${owned_containers[@]}"; do
        if [[ $dry == 1 ]]; then say "would remove container ${id:0:12} ($CONTAINER_NAME)"; continue; fi
        why="$(container_identity_problem "$id" "$token")" || { retain "container ${id:0:12}: changed since it was checked: $why"; continue; } # guard:G8-reverify-container
        if dkr stop -t 30 "$id" >/dev/null && dkr rm "$id" >/dev/null; then say "removed container ${id:0:12}"; else
            warn "container ${id:0:12} could not be removed"
            REMAINING+=("container ${id:0:12}: could not be removed")
        fi
    done
    for id in "${owned_networks[@]}"; do
        if [[ $dry == 1 ]]; then say "would remove network ${id:0:12} ($NETWORK_NAME)"; continue; fi
        why="$(network_identity_problem "$id" "$token" '')" || { retain "network ${id:0:12}: $why"; continue; } # guard:G8-reverify-network
        if dkr network rm "$id" >/dev/null; then say "removed network ${id:0:12}"; else
            warn "network ${id:0:12} could not be removed"
            REMAINING+=("network ${id:0:12}: could not be removed")
        fi
    done
    for id in "${owned_volumes[@]}"; do
        if [[ $dry == 1 ]]; then say "would remove volume $id"; continue; fi
        why="$(volume_identity_problem "$id" "$token" '')" || { retain "volume $id: $why"; continue; } # guard:G8-reverify-volume
        if dkr volume rm "$id" >/dev/null; then say "removed volume $id"; else
            warn "volume $id could not be removed"
            REMAINING+=("volume $id: could not be removed")
        fi
    done

    local incomplete=0
    ((${#REMAINING[@]} == 0)) || incomplete=1 # guard:G8-incomplete
    if [[ $dry == 1 ]]; then
        if ((incomplete)); then say "${#REMAINING[@]} object(s) would be kept because they are not provably the rehearsal's; a real cleanup would then report itself incomplete and keep its state file."; fi
    elif ((incomplete)); then
        warn "cleanup is NOT complete: ${#REMAINING[@]} rehearsal-labelled object(s) remain."
        for id in "${REMAINING[@]}"; do warn "  remains: $id"; done
        if ((have_root)); then
            warn "The state file (which holds the run token) was kept, so a normal cleanup can finish once the cause is gone. --stale is not needed for that."
        else
            warn "There is no state file to keep. Review what remains with: restore-rehearsal.sh cleanup --dry-run --stale"
        fi
        warn "Remove the cause listed above yourself (this script will not remove what it cannot prove is its own), then run: restore-rehearsal.sh cleanup"
        exit 1
    elif ((have_root)); then
        remove_state "$root"
    fi
    if [[ -z "${owned_containers[*]}${owned_networks[*]}${owned_volumes[*]}" && $incomplete -eq 0 ]]; then say "nothing of the rehearsal's was found."; fi
}

# Removes the files this script wrote, by name, and the directory if that leaves it empty. Never recursive, never a glob.
remove_state() {
    local root="$1" file
    verify_root_ownership "$root"
    for file in compose.env run.lock "$ROOT_MARKER_NAME"; do
        if [[ -f "$root/$file" && ! -L "$root/$file" ]]; then rm -f -- "${root:?}/$file"; fi
    done
    if rmdir -- "$root" 2>/dev/null; then say "removed state directory $root"; else warn "state directory $root holds other files and was kept"; fi
}

main() {
    local command="${1:-}"
    case "$command" in
        up) shift; cmd_up "$@" ;;
        self-check) shift; cmd_self_check "$@" ;;
        cleanup) shift; cmd_cleanup "$@" ;;
        help | -h | --help) usage ;;
        *) usage >&2; exit 64 ;;
    esac
}

# Sourcing this file (scripts/tests/rehearsal.sh does) defines the functions and runs nothing.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
