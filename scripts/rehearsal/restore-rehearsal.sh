#!/usr/bin/env bash
# G6 WP3c, slice 1: the isolation foundation of the local recovery rehearsal.
#
#   restore-rehearsal.sh up           start the isolated MariaDB and verify what started
#   restore-rehearsal.sh self-check   read-only: verify the environment and, if present, the project
#   restore-rehearsal.sh cleanup [--dry-run] [--stale]
#                                     remove exactly the objects this script created, nothing else
#   restore-rehearsal.sh prepare      (slice 2.1) archive the historical and current source and the fixed helpers into a labelled volume
#   restore-rehearsal.sh run <historical|current> <command>
#                                     run one approved Artisan command or fixed helper in the disposable PHP runner
#   restore-rehearsal.sh probe        fixed isolation probe: no external network, app account cannot see the sentinel, client sandbox
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
#   G10 the disposable containers (source preparation, PHP runner) are checked against what was asked of Docker before they start:
#       name, image ID, labels, user, read-only root, dropped capabilities, no-new-privileges, network, mounts, tmpfs, limits; the
#       source volume is plain, local, labelled and used only by them; source comes from verified commits, never a substitute
#   G11 `run` takes a version and one approved name; the script builds the whole argument vector, so no option, file, alias, path or
#       environment is chosen by the caller
#   G12 application-user SQL: restricted client (sandbox, no local infile, non-root, no root password in its environment, no pager or
#       editor), the account's identity and grants verified before every use, and never a fallback to root
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
readonly RUNNER_NAME='flc-rehearsal-restore-runner'
readonly PREPARE_NAME='flc-rehearsal-restore-prepare'
readonly SRC_VOLUME_NAME='flc-rehearsal-restore-src'
readonly PHP_IMAGE_REF='flowlife-dev/php:8.3' # the development image (compose.yaml); resolved to an ID once per command, launched by ID
readonly SRC_MOUNT='/var/www/html'            # an existing, non-root-owned directory of the image, so a new volume mounted here is writable by uid 33
readonly EPHEMERAL_USER='33:33'               # www-data in the PHP image
readonly APP_USER='flc_rehearsal'
readonly APP_TREE='apps/platform'
readonly HISTORICAL_COMMIT='2f385b79a7b83323c6f15a6685ee610ccd85b5bf' # v0.3.0, what production runs
readonly HARNESS_FILES=(db-ping.php probe.php)
# Not a secret: the rehearsal's data is synthetic and the runner has no route anywhere. Laravel only needs some valid key.
readonly PLACEHOLDER_APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
readonly EPHEMERAL_MEMORY=1073741824
readonly EPHEMERAL_NANOCPUS=1000000000
readonly EPHEMERAL_PIDS=256
readonly ROOT_MARKER_NAME='.flc-rehearsal-root'
readonly ROOT_MARKER_TEXT='restore-rehearsal-v1'
readonly REFUSED_ENV_RE='^(COMPOSE_|DB_|MYSQL_|MARIADB_|PG|POSTGRES_|APP_|REHEARSAL_)|^DATABASE_URL$'

# The inspect templates. Every expression in them is one the scripts/tests/rehearsal.sh fake daemon understands (it refuses any other),
# and each Slice 1 template (CONTAINER_FMT, NETWORK_FMT, VOLUME_FMT) was run against a real container, network and volume on this Docker
# version while that slice was written. The Slice 2.1 templates (EPHEMERAL_FMT, ROLE_FMT) have NOT been run against a live daemon yet:
# the fake daemon models them, and the first live run (L1) is where they are confirmed.
# shellcheck disable=SC2016 # Go template text for `docker inspect`, not shell expansions
readonly CONTAINER_FMT='{{.Id}}|{{.Name}}|{{.Config.Image}}|{{index .Config.Labels "io.flowlife.rehearsal"}}|{{index .Config.Labels "io.flowlife.rehearsal.role"}}|{{index .Config.Labels "io.flowlife.rehearsal.run-token"}}|{{len .HostConfig.PortBindings}}|{{.HostConfig.Privileged}}|{{json .HostConfig.CapAdd}}|{{.HostConfig.NetworkMode}}|{{range $k,$v := .NetworkSettings.Networks}}{{$k}},{{end}}|{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}},{{end}}'
# shellcheck disable=SC2016 # Go template text for `docker network inspect`, not shell expansions
readonly NETWORK_FMT='{{.Id}}|{{.Name}}|{{.Internal}}|{{.Driver}}|{{index .Labels "io.flowlife.rehearsal"}}|{{index .Labels "io.flowlife.rehearsal.role"}}|{{index .Labels "io.flowlife.rehearsal.run-token"}}|{{range $id,$c := .Containers}}{{$id}},{{end}}'
# shellcheck disable=SC2016 # Go template text for `docker volume inspect`, not shell expansions
readonly VOLUME_FMT='{{.Name}}|{{.Driver}}|{{len .Options}}|{{index .Labels "io.flowlife.rehearsal"}}|{{index .Labels "io.flowlife.rehearsal.role"}}|{{index .Labels "io.flowlife.rehearsal.run-token"}}'

# shellcheck disable=SC2016 # Go template text for `docker inspect`, not shell expansions
readonly EPHEMERAL_FMT='{{.Id}}|{{.Name}}|{{.Image}}|{{index .Config.Labels "io.flowlife.rehearsal"}}|{{index .Config.Labels "io.flowlife.rehearsal.role"}}|{{index .Config.Labels "io.flowlife.rehearsal.run-token"}}|{{len .HostConfig.PortBindings}}|{{.HostConfig.Privileged}}|{{json .HostConfig.CapAdd}}|{{json .HostConfig.CapDrop}}|{{json .HostConfig.SecurityOpt}}|{{.HostConfig.ReadonlyRootfs}}|{{.Config.User}}|{{.HostConfig.NetworkMode}}|{{range $k,$v := .NetworkSettings.Networks}}{{$k}},{{end}}|{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}}:{{.RW}},{{end}}|{{range $k,$v := .HostConfig.Tmpfs}}{{$k}},{{end}}|{{.HostConfig.Memory}}|{{.HostConfig.PidsLimit}}|{{.HostConfig.NanoCpus}}'
# shellcheck disable=SC2016 # Go template text for `docker inspect`, not shell expansions
readonly ROLE_FMT='{{index .Config.Labels "io.flowlife.rehearsal.role"}}'

CID=''
PHP_IMAGE_ID=''
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
readonly COMPOSE_FILE_PATH="$SCRIPT_DIR/compose.yaml"
readonly REPO_ROOT="$SCRIPT_DIR/../.."

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
  prepare       Archive the historical (v0.3.0) and current application source and the fixed PHP helpers into a labelled
                volume, and install each tree's Composer dependencies in a container with no database access.
  run <historical|current> <command>
                Run one approved Artisan command (about, migrate, migrate:status, relationships:check, release:show,
                resources:assets:prune) or helper:db-ping in a disposable PHP runner. The script supplies every option.
  probe         Check the runner and the restricted SQL client are isolated as intended. Read-only.
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
    [[ "$token" =~ ^[0-9a-f]{32}$ ]] || refuse "the run token in '$root/compose.env' is not a 32-character hex value, so nothing can be checked against it and nothing was changed. The script never edits, discards or rebuilds that file. If you want to keep it as evidence, copy it elsewhere (cp -p). To recover, move it out of the state directory to a place of your choosing (for example mv '$root/compose.env' <a directory outside $root>/compose.env.unreadable), then review: restore-rehearsal.sh cleanup --dry-run --stale ; and only if the list is the rehearsal's: restore-rehearsal.sh cleanup --stale. A file left inside the state directory would keep it in place." # guard:G5-token-format
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
db_containers() { dkr ps -aq --no-trunc --filter "$(label_filter)" --filter "label=$LABEL_NS_KEY.role=database"; }
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
    local cname vname
    for cname in "$CONTAINER_NAME" "$RUNNER_NAME" "$PREPARE_NAME"; do
        listing="$(dkr ps -a --no-trunc --filter "name=$cname" --format '{{.ID}} {{.Names}}')" || return 1
        while read -r id name; do
            if [[ "$name" == "$cname" || "$name" == "/$cname" ]] && ! in_list "$id" "$labelled_c"; then say "container ${id:0:12} named $cname"; fi
        done <<<"$listing"
    done
    listing="$(dkr network ls --no-trunc --filter "name=$NETWORK_NAME" --format '{{.ID}} {{.Name}}')" || return 1
    while read -r id name; do
        if [[ "$name" == "$NETWORK_NAME" ]] && ! in_list "$id" "$labelled_n"; then say "network ${id:0:12} named $NETWORK_NAME"; fi
    done <<<"$listing"
    for vname in "$VOLUME_NAME" "$SRC_VOLUME_NAME"; do
        listing="$(dkr volume ls --filter "name=$vname" --format '{{.Name}}')" || return 1
        while read -r name; do
            if [[ "$name" == "$vname" ]] && ! in_list "$name" "$labelled_v"; then say "volume named $vname"; fi
        done <<<"$listing"
    done
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
    inventory ids 'the rehearsal database containers' db_containers
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

# --- G10: the disposable containers --------------------------------------------------------------------------------------------------

# ephemeral_identity_problem ID TOKEN ROLE NETMODE [IMAGE]: the container was asked of Docker by this script, as the PHP runner (ROLE runner, on the
# database network) or as a source-preparation container (ROLE prepare, NETMODE none or bridge, never the database network). The template
# never prints the environment, so the application password cannot appear here. Prints the first problem and returns 1.
ephemeral_identity_problem() {
    local id="$1" token="${2:-}" role="$3" netmode="$4" want_image="${5:-$PHP_IMAGE_ID}" out
    out="$(dkr inspect --type container --format "$EPHEMERAL_FMT" "$id" 2>/dev/null)" || { echo "cannot be inspected"; return 1; }
    local name image l_ns l_role l_token ports privileged capadd capdrop secopt readonly_root user mode networks mounts tmpfs memory pids cpus
    IFS='|' read -r _ name image l_ns l_role l_token ports privileged capadd capdrop secopt readonly_root user mode networks mounts tmpfs memory pids cpus <<<"$out"
    local want_name="$RUNNER_NAME" want_rw=false want_tmpfs='/tmp,/var/www/html/current/bootstrap/cache,/var/www/html/current/storage,/var/www/html/historical/bootstrap/cache,/var/www/html/historical/storage,'
    if [[ "$role" == prepare ]]; then
        want_name="$PREPARE_NAME" want_rw=true want_tmpfs='/tmp,'
    else
        netmode="$NETWORK_NAME"
    fi
    [[ "$name" == "/$want_name" ]] || { echo "its name is '$name', not '/$want_name'"; return 1; } # guard:G10-e-name
    [[ "$want_image" == any || ( -n "$want_image" && "$image" == "$want_image" ) ]] || { echo "its image is not the verified PHP image ID"; return 1; } # guard:G10-e-image
    [[ "$l_ns" == "$LABEL_NS_VALUE" && "$l_role" == "$role" ]] || { echo "its labels are not this script's $role labels"; return 1; } # guard:G10-e-label
    [[ -z "$token" || "$l_token" == "$token" ]] || { echo "its run token differs from this state directory's"; return 1; } # guard:G10-e-token
    [[ "$ports" == 0 ]] || { echo "it publishes $ports port(s) to the host"; return 1; } # guard:G10-e-ports
    [[ "$privileged" == false && "$capadd" == null ]] || { echo "it is privileged or adds capabilities"; return 1; } # guard:G10-e-privilege
    [[ "$capdrop" == '["ALL"]' ]] || { echo "it does not drop all capabilities"; return 1; } # guard:G10-e-capdrop
    [[ "$secopt" == '["no-new-privileges"]' || "$secopt" == '["no-new-privileges=true"]' || "$secopt" == '["no-new-privileges:true"]' ]] || { echo "it does not set no-new-privileges"; return 1; } # guard:G10-e-nnp
    [[ "$readonly_root" == true ]] || { echo "its root filesystem is writable"; return 1; } # guard:G10-e-readonly
    [[ "$user" == "$EPHEMERAL_USER" ]] || { echo "it runs as '$user', not '$EPHEMERAL_USER'"; return 1; } # guard:G10-e-user
    [[ "$mode" == "$netmode" && "$networks" == "$netmode," ]] || { echo "its network is '$networks' (mode '$mode'), not '$netmode'"; return 1; } # guard:G10-e-network
    # Whether the daemon lists tmpfs mounts among .Mounts varies, so they are set aside here and checked by their own field below.
    local entry kept='' entries=()
    IFS=',' read -ra entries <<<"$mounts"
    for entry in ${entries[@]+"${entries[@]}"}; do
        [[ "$entry" == tmpfs:* ]] || kept+="$entry,"
    done
    [[ "$kept" == "volume:$SRC_VOLUME_NAME:$SRC_MOUNT:$want_rw," ]] || { echo "its mounts are '$kept', not only the source volume (writable: $want_rw)"; return 1; } # guard:G10-e-mounts
    [[ "$tmpfs" == "$want_tmpfs" ]] || { echo "its temporary filesystems are '$tmpfs', not '$want_tmpfs'"; return 1; } # guard:G10-e-tmpfs
    [[ "$memory" == "$EPHEMERAL_MEMORY" && "$pids" == "$EPHEMERAL_PIDS" && "$cpus" == "$EPHEMERAL_NANOCPUS" ]] || { echo "its memory, process or CPU limit is not the one this script sets"; return 1; } # guard:G10-e-limits
    return 0
}

# source_volume_identity_problem NAME TOKEN ALLOWED_USERS: the labelled source volume, plain and local, used by no container but ours.
source_volume_identity_problem() {
    local name="$1" token="${2:-}" allowed="${3:-}" out user users='' users_failed=0
    out="$(dkr volume inspect --format "$VOLUME_FMT" "$name" 2>/dev/null)" || { echo "cannot be inspected"; return 1; }
    local v_name driver options l_ns l_role l_token
    IFS='|' read -r v_name driver options l_ns l_role l_token <<<"$out"
    [[ "$v_name" == "$SRC_VOLUME_NAME" ]] || { echo "its name is '$v_name', not '$SRC_VOLUME_NAME'"; return 1; } # guard:G10-s-name
    [[ "$driver" == local && "$options" == 0 ]] || { echo "it is not a plain local volume (it carries driver options such as a host path)"; return 1; } # guard:G10-s-options
    [[ "$l_ns" == "$LABEL_NS_VALUE" && "$l_role" == source ]] || { echo "its labels are not this script's"; return 1; } # guard:G10-s-label
    [[ -z "$token" || "$l_token" == "$token" ]] || { echo "its run token differs from this state directory's"; return 1; } # guard:G10-s-token
    users="$(dkr ps -aq --no-trunc --filter "volume=$name")" || users_failed=1
    [[ $users_failed == 0 ]] || { echo "the containers using it cannot be listed"; return 1; } # guard:G10-s-users-list
    while IFS= read -r user; do
        [[ -z "$user" || ",$allowed," == *",$user,"* ]] || { echo "container ${user:0:12} that is not ours uses it"; return 1; } # guard:G10-s-in-use
    done <<<"$users"
    return 0
}

# The PHP image, resolved once to an ID that every launch then uses. A tag can be moved between a check and a launch; an ID cannot.
resolve_php_image() {
    local id
    id="$(dkr image inspect --format '{{.Id}}' "$PHP_IMAGE_REF" 2>/dev/null)" || refuse "the development PHP image $PHP_IMAGE_REF is not present locally. Build it first (./flow setup); this script never pulls or builds it."
    [[ "$id" =~ ^sha256:[0-9a-f]{64}$ ]] || refuse "the PHP image did not resolve to an image ID." # guard:G10-image-id
    PHP_IMAGE_ID="$id"
}

# The application database password, validated before it is placed in any environment.
app_password() {
    local root="$1" password
    password="$(grep -E '^REHEARSAL_DB_APP_PASSWORD=' "$root/compose.env" 2>/dev/null | tail -n 1 | cut -d= -f2-)" || password=''
    [[ "$password" =~ ^[0-9a-f]{48}$ ]] || refuse "the application database password in '$root/compose.env' is not the 48-character hex value this script generates." # guard:G10-password-format
    printf '%s' "$password"
}

# The dedicated launcher: docker with ONLY what it needs to find the local daemon, plus DB_PASSWORD for `-e DB_PASSWORD` (a name, so the
# value is never an argument to any process). `env -i NAME=value` would put the value on a command line, so the environment is built by
# unsetting everything else in a subshell and exporting the one value. Used only to create the runner; nothing else gets the password.
launch_docker() {
    local root="$1" password
    shift
    password="$(app_password "$root")" || exit 3 # a failure inside a command substitution under `||` would not stop the script by itself
    (
        local name
        for name in $(compgen -e); do
            case "$name" in
                PATH | HOME | LANG | DOCKER_CONFIG) ;;
                *) unset "$name" 2>/dev/null || true ;;
            esac
        done
        export LANG=C
        export DB_PASSWORD="$password"
        exec docker "$@"
    )
}

# Creates (never starts) a disposable container with the isolation every one of them gets, verifies what was created, and prints its ID.
# ARGS are the role-specific options, then the image and command. The common options are checked against the created container by
# ephemeral_identity_problem, not against this list. A container that does not verify is removed by the ID the create returned.
create_verified() { # ROOT TOKEN ROLE NETMODE LAUNCHER -- ARGS...
    local root="$1" token="$2" role="$3" netmode="$4" launcher="$5" id why name="$RUNNER_NAME"
    shift 6
    [[ "$role" != prepare ]] || name="$PREPARE_NAME"
    id="$("$launcher" "$root" create \
        --name "$name" \
        --label "$LABEL_NS_KEY=$LABEL_NS_VALUE" --label "$LABEL_NS_KEY.role=$role" --label "$LABEL_NS_KEY.run-token=$token" \
        --pull never --entrypoint '' \
        --user "$EPHEMERAL_USER" --read-only --cap-drop ALL --security-opt no-new-privileges \
        --memory 1g --memory-swap 1g --cpus 1 --pids-limit "$EPHEMERAL_PIDS" \
        "$@")" || fail "docker could not create the $role container"
    [[ "$id" =~ ^[0-9a-f]{64}$ ]] || fail "docker returned something that is not a container ID for the $role container"
    if ! why="$(ephemeral_identity_problem "$id" "$token" "$role" "$netmode")"; then
        dkr rm "$id" >/dev/null 2>&1 || true
        refuse "the $role container ${id:0:12} was not created as asked ($why); it was removed and not started."
    fi
    printf '%s' "$id"
}

# Starts a verified container, relays its output, removes it, and returns its exit status.
start_and_remove() { # ID [-i]
    local id="$1" rc=0
    shift
    dkr start -a "$@" "$id" || rc=$?
    dkr rm "$id" >/dev/null || warn "container ${id:0:12} could not be removed; run: restore-rehearsal.sh cleanup"
    return "$rc"
}

# --- G12: SQL as the application user, never as root ----------------------------------------------------------------------------------

# The one place that talks to the server as the application account. It runs in the database container (which has the client), as the
# non-root `mysql` user, with the root password and every other password variable removed from the client's environment, the
# application password read inside the container and handed over as MYSQL_PWD (not a command-line argument anywhere), the client's
# sandbox on (no shell, no file access, no source or tee), no editor, no pager, local infile off, and no --force so an error stops it.
mariadb_app() {
    local cid="$1"
    shift
    # shellcheck disable=SC2016 # expands inside the container: neither password appears on this host's command line
    dkr exec -i --user mysql "$cid" sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec env -u MARIADB_ROOT_PASSWORD -u MYSQL_ROOT_PASSWORD -u MARIADB_PASSWORD -u MYSQL_PASSWORD EDITOR=/bin/false VISUAL=/bin/false PAGER=cat MYSQL_PAGER=cat mariadb --no-defaults --sandbox --local-infile=0 --protocol=tcp --host=127.0.0.1 --user=flc_rehearsal --batch --skip-column-names "$@"' sh "$@"
}

# shellcheck disable=SC2016 # the backticks are part of the grant text MariaDB prints, not command substitutions
readonly APP_GRANT_USAGE='GRANT USAGE ON *.* TO `flc_rehearsal`@`%`'
# shellcheck disable=SC2016 # as above
readonly APP_GRANT_DB='GRANT ALL PRIVILEGES ON `flc_rehearsal_app`.* TO `flc_rehearsal`@`%`'
# MariaDB's entrypoint escapes the underscores (`flc\_rehearsal\_app`) and the client's --batch output doubles each backslash, so the
# line arrives with two. (--raw would print one; --batch stays, because it also escapes newlines and tabs in values.)
# shellcheck disable=SC2016 # as above
readonly APP_GRANT_DB_ESCAPED='GRANT ALL PRIVILEGES ON `flc\\_rehearsal\\_app`.* TO `flc_rehearsal`@`%`'

# The account the client actually became, and what it may do. Root only counts the matching accounts; the account describes itself.
# Authentication hashes are cut from every line before it is compared, so they are never compared, printed or logged.
app_account_problem() {
    local cid="$1" accounts out line current usage=0 db=0 other=0
    accounts="$(printf "SELECT COUNT(*) FROM mysql.user WHERE User='%s';\n" "$APP_USER" | mariadb_root "$cid" 2>/dev/null)" || { echo "the accounts cannot be counted"; return 1; }
    [[ "$accounts" == 1 ]] || { echo "there are $accounts accounts named $APP_USER, not exactly one"; return 1; } # guard:G12-one-account
    out="$(printf "SELECT CURRENT_USER();\nSHOW GRANTS FOR CURRENT_USER();\nSELECT IFNULL(CURRENT_ROLE(), '');\n" | mariadb_app "$cid" 2>/dev/null)" || { echo "the application account cannot connect through the restricted client"; return 1; }
    current="${out%%$'\n'*}"
    out="${out#*$'\n'}"
    [[ "$current" == "$APP_USER@%" ]] || { echo "the client is not the application account (it is '$current')"; return 1; } # guard:G12-identity
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue # the last line is the current role, which must be empty
        line="${line%% IDENTIFIED *}"
        case "$line" in
            "$APP_GRANT_USAGE") usage=$((usage + 1)) ;;
            "$APP_GRANT_DB" | "$APP_GRANT_DB_ESCAPED") db=$((db + 1)) ;;
            *) other=1 ;;
        esac
    done <<<"$out"
    [[ $other == 0 ]] || { echo "the account has a privilege, role or grant option beyond the rehearsal database"; return 1; } # guard:G12-grants
    [[ $usage == 1 && $db == 1 ]] || { echo "the account's grants are not exactly USAGE plus all privileges on $APP_DB"; return 1; } # guard:G12-grants-complete
    return 0
}

# The application-user path: every check root-side identity does, then the account's own, then the statement. SQL on stdin. There is no
# root fallback: a refusal here ends the operation.
guarded_app_sql() {
    local root="$1" db="$2" why
    assert_sql_target "$root" "$db"
    why="$(app_account_problem "$CID")" || refuse "$why. If the account lacks a privilege it needs, the smallest correction is to GRANT that privilege on $APP_DB.* to $APP_USER yourself; this script never falls back to root." # guard:G12-before-sql
    mariadb_app "$CID" -D "$db"
}

# --- G11: what `run` may execute -------------------------------------------------------------------------------------------------------

# runner_command NAME: sets RUN_CMD to the complete argument vector for an approved name, or refuses. The caller supplies a name and
# nothing else: options, file names, aliases, migration paths and environment are all fixed here.
runner_command() {
    local name="$1" why
    RUN_CMD=()
    case "$name" in
        about | migrate:status | relationships:check | release:show) RUN_CMD=(php artisan "$name") ;;
        migrate) RUN_CMD=(php artisan migrate --force --no-interaction) ;;
        resources:assets:prune) RUN_CMD=(php artisan resources:assets:prune --dry-run) ;;
        helper:db-ping) RUN_CMD=(php "$SRC_MOUNT/harness/db-ping.php") ;;
        *) ;;
    esac
    ((${#RUN_CMD[@]} > 0)) || refuse "'$name' is not a command the rehearsal runs. Approved: ${ARTISAN_ALLOWED[*]} helper:db-ping." # guard:G11-allowlist
    if [[ "${RUN_CMD[1]:-}" == artisan ]]; then
        why="$(artisan_problem "${RUN_CMD[@]:2}")" || refuse "$why." # guard:G11-artisan-policy
    fi
}

# run_in_runner ROOT WORKDIR CMD...: the checks, then one disposable runner on the rehearsal network with the application password, the
# read-only source and nothing else. Output and exit status are the command's.
run_in_runner() {
    local root="$1" workdir="$2" token why ids net_id id
    shift 2
    assert_sql_target "$root" "$APP_DB" # container, version and sentinel, before anything can touch the database
    token="$(state_token "$root")"
    why="$(app_account_problem "$CID")" || refuse "$why." # guard:G12-before-run
    inventory ids 'the labelled containers' labelled_containers
    [[ "$ids" == "$CID" ]] || refuse "a rehearsal container other than the database exists (an interrupted run or preparation). Run: restore-rehearsal.sh cleanup --dry-run, then cleanup." # guard:G10-no-leftover
    inventory net_id 'the labelled networks' labelled_networks
    [[ -n "$net_id" && "$net_id" != *$'\n'* ]] || refuse "expected exactly one rehearsal network."
    why="$(network_identity_problem "$net_id" "$token" "$CID")" || refuse "the network is not the rehearsal's: $why."
    why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" '')" || refuse "the source volume is not the rehearsal's (has 'prepare' been run?): $why." # guard:G10-run-volume
    resolve_php_image
    app_password "$root" >/dev/null # refuses a malformed password here, in this shell, before anything is created
    id="$(create_verified "$root" "$token" runner "$NETWORK_NAME" launch_docker -- \
        --network "$NETWORK_NAME" \
        --mount "type=volume,source=$SRC_VOLUME_NAME,target=$SRC_MOUNT,readonly" \
        --tmpfs /tmp:rw,noexec,nosuid,size=64m,uid=33,gid=33 \
        --tmpfs "$SRC_MOUNT/historical/storage:rw,noexec,nosuid,size=64m,uid=33,gid=33" \
        --tmpfs "$SRC_MOUNT/historical/bootstrap/cache:rw,noexec,nosuid,size=16m,uid=33,gid=33" \
        --tmpfs "$SRC_MOUNT/current/storage:rw,noexec,nosuid,size=64m,uid=33,gid=33" \
        --tmpfs "$SRC_MOUNT/current/bootstrap/cache:rw,noexec,nosuid,size=16m,uid=33,gid=33" \
        --workdir "$SRC_MOUNT/$workdir" \
        -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$PLACEHOLDER_APP_KEY" \
        -e LOG_CHANNEL=stderr -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
        -e DB_CONNECTION=mariadb -e "DB_HOST=$CONTAINER_NAME" -e DB_PORT=3306 -e "DB_DATABASE=$APP_DB" -e "DB_USERNAME=$APP_USER" -e DB_PASSWORD \
        "$PHP_IMAGE_ID" \
        sh -c 'mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs 2>/dev/null; exec "$@"' sh "$@")" || exit "$?"
    start_and_remove "$id"
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
    [[ "$containers" == "$CID" ]] || refuse "a rehearsal container other than the database exists (an interrupted run or preparation, or a run in progress). Run: restore-rehearsal.sh cleanup --dry-run" # guard:G10-selfcheck-leftover
    [[ -n "$networks" && "$networks" != *$'\n'* ]] || refuse "expected exactly one rehearsal network."
    why="$(network_identity_problem "$networks" "$token" "$CID")" || refuse "the network is not the rehearsal's: $why." # guard:G4-selfcheck-network
    ok_line "network is internal and holds only the rehearsal database"
    local data_volume
    data_volume="$(grep -vxF -- "$SRC_VOLUME_NAME" <<<"$volumes" || true)"
    [[ -n "$data_volume" && "$data_volume" != *$'\n'* ]] || refuse "expected exactly one rehearsal data volume."
    why="$(volume_identity_problem "$data_volume" "$token" "$CID")" || refuse "the volume is not the rehearsal's: $why." # guard:G4-selfcheck-volume
    ok_line "volume is a plain local volume used only by the rehearsal database"
    if in_list "$SRC_VOLUME_NAME" "$volumes"; then
        why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" '')" || refuse "the source volume is not the rehearsal's: $why." # guard:G10-selfcheck-source
        ok_line "source volume is a plain local volume used by no container"
    fi
    why="$(server_version_problem "$CID")" || refuse "$why."
    ok_line "the server itself reports MariaDB 10.11.18"
    why="$(sentinel_problem "$CID" "$token")" || refuse "$why."
    ok_line "sentinel database carries this run's token"
    printf 'SELECT 1;\n' | guarded_sql "$root" "$APP_DB" >/dev/null
    ok_line "$APP_DB answers through the guarded path"
    printf 'SELECT 1;\n' | guarded_app_sql "$root" "$APP_DB" >/dev/null
    ok_line "the application account answers through the restricted client (one account, USAGE plus $APP_DB only)"
    if target_allowed flowlife; then refuse "the allowlist accepts a development database name."; fi
    ok_line "the allowlist refuses a development database name"
}

# Cleanup's identity check for a labelled container: the role label only chooses which complete check applies; a container that does not
# satisfy that check is kept. The image ID is not part of removal safety, so cleanup does not require the current PHP image.
cleanup_container_problem() {
    local id="$1" token="${2:-}" role
    role="$(dkr inspect --type container --format "$ROLE_FMT" "$id" 2>/dev/null)" || role=''
    case "$role" in
        runner) ephemeral_identity_problem "$id" "$token" runner "$NETWORK_NAME" any ;;
        prepare) ephemeral_identity_problem "$id" "$token" prepare none any >/dev/null || ephemeral_identity_problem "$id" "$token" prepare bridge any ;;
        *) container_identity_problem "$id" "$token" ;;
    esac
}

# The name of a container that cleanup_container_problem has just verified: the check requires exactly the name of its role.
verified_container_name() {
    case "$(dkr inspect --type container --format "$ROLE_FMT" "$1" 2>/dev/null)" in
        runner) printf '%s' "$RUNNER_NAME" ;;
        prepare) printf '%s' "$PREPARE_NAME" ;;
        *) printf '%s' "$CONTAINER_NAME" ;;
    esac
}

cleanup_volume_problem() {
    if [[ "$1" == "$SRC_VOLUME_NAME" ]]; then source_volume_identity_problem "$@"; else volume_identity_problem "$@"; fi
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
        if why="$(cleanup_container_problem "$id" "$token")"; then
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
        if why="$(cleanup_volume_problem "$id" "$token" "$owned_ids")"; then owned_volumes+=("$id"); else
            retain "volume $id: $why"
        fi
    done
    local lookalikes
    inventory lookalikes 'the objects named like the rehearsal' foreign_lookalikes "$containers" "$networks" "$volumes"
    if [[ -n "$lookalikes" ]]; then
        say "note    not the rehearsal's (no label), so never selected for removal: $(tr '\n' ';' <<<"$lookalikes")"
    fi

    for id in "${owned_containers[@]}"; do
        if [[ $dry == 1 ]]; then say "would remove container ${id:0:12} ($(verified_container_name "$id"))"; continue; fi
        why="$(cleanup_container_problem "$id" "$token")" || { retain "container ${id:0:12}: changed since it was checked: $why"; continue; } # guard:G8-reverify-container
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
        why="$(cleanup_volume_problem "$id" "$token" '')" || { retain "volume $id: $why"; continue; } # guard:G8-reverify-volume
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
        remove_state "$root" || exit 1
    fi
    if [[ -z "${owned_containers[*]}${owned_networks[*]}${owned_volumes[*]}" && $incomplete -eq 0 ]]; then say "nothing of the rehearsal's was found."; fi
}

# Removes the files this script wrote, by name, and the directory if that leaves it empty. Never recursive, never a glob over what is
# removed. Nothing is removed unless every entry in the directory is one of the three files this script wrote: a directory that holds
# anything else keeps its marker, its run token and its lock file, so it stays a state directory this script recognises and cleanup can
# be run again once the other entries are dealt with. Returns 1 when it could not finish.
remove_state() {
    local root="$1" file entry name known unexpected=()
    verify_root_ownership "$root"
    for entry in "$root"/* "$root"/.[!.]* "$root"/..?*; do
        [[ -e "$entry" || -L "$entry" ]] || continue
        name="${entry##*/}"
        known=0
        for file in compose.env run.lock "$ROOT_MARKER_NAME"; do
            if [[ "$name" == "$file" && -f "$entry" && ! -L "$entry" ]]; then known=1; fi
        done
        if [[ $known == 0 ]]; then unexpected+=("$name"); fi
    done
    ((${#unexpected[@]} == 0)) || { warn "the objects are gone, but the state directory $root holds entries this script did not write (${unexpected[*]}); nothing in it was removed, and its marker and run token were kept. Deal with those entries yourself (this script never removes what it did not write), then run: restore-rehearsal.sh cleanup"; return 1; } # guard:G8-state-entries
    for file in compose.env run.lock "$ROOT_MARKER_NAME"; do
        if [[ -f "$root/$file" && ! -L "$root/$file" ]]; then rm -f -- "${root:?}/$file"; fi
    done
    if rmdir -- "$root" 2>/dev/null; then say "removed state directory $root"; else
        warn "state directory $root gained entries while it was being emptied and was kept; nothing but the files this script wrote was removed"
        return 1
    fi
}

# --- slice 2.1 commands ---------------------------------------------------------------------------------------------------------------

# What every one of them checks first: a clean environment, the local daemon, our own state directory, the lock.
preflight() { # sets ROOT
    verify_clean_environment # guard:G2-substrate
    ROOT="$(root_dir)"
    validate_root_path "$ROOT"
    verify_local_daemon
    [[ -e "$ROOT" ]] || refuse "there is no rehearsal state in '$ROOT'. Run: restore-rehearsal.sh up"
    verify_root_ownership "$ROOT"
    acquire_lock "$ROOT"
}

# git, with only what it needs and none of the caller's GIT_* variables (GIT_DIR would silently change which repository is archived).
gitq() { env -i PATH="$PATH" HOME="${HOME:-/nonexistent}" LANG=C GIT_CONFIG_NOSYSTEM=1 git -C "$REPO_ROOT" "$@"; }

# A commit this script verified itself: the full ID asked for must be the full ID found. Nothing is substituted for a missing one.
verified_commit() { # REV -> prints the commit ID
    local sha
    sha="$(gitq rev-parse --verify --quiet "$1^{commit}")" || return 1
    [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || return 1
    printf '%s' "$sha"
}

# Plain docker for a container that gets no secret; the first argument (the state directory) is only there so it fits create_verified.
plain_docker() {
    shift
    dkr "$@"
}

# prepare_run TOKEN NETMODE STDIN CMD...: one source-preparation container with the volume writable and no database credentials.
prepare_run() {
    local token="$1" netmode="$2" stdin="$3" id
    shift 3
    local interactive=()
    [[ "$stdin" == 1 ]] && interactive=(-i)
    id="$(create_verified "$ROOT" "$token" prepare "$netmode" plain_docker -- \
        --network "$netmode" \
        --mount "type=volume,source=$SRC_VOLUME_NAME,target=$SRC_MOUNT" \
        --tmpfs /tmp:rw,noexec,nosuid,size=256m,uid=33,gid=33 \
        ${interactive[@]+"${interactive[@]}"} "$PHP_IMAGE_ID" "$@")" || exit "$?"
    start_and_remove "$id" ${interactive[@]+"${interactive[@]}"}
}

PREPARE_RECOVERY=0
on_prepare_exit() {
    local status=$?
    if ((status != 0 && PREPARE_RECOVERY == 1)); then
        warn "prepare did not finish. The source volume may be partly filled; nothing was removed."
        warn "  1. restore-rehearsal.sh cleanup --dry-run   (lists exactly what it would remove)"
        warn "  2. restore-rehearsal.sh cleanup             (this also removes the database; run 'up' again afterwards)"
    fi
}

cmd_prepare() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    local token ids why historical current tree
    resolve_project_container "$ROOT"
    token="$(state_token "$ROOT")"
    inventory ids 'the labelled containers' labelled_containers
    [[ "$ids" == "$CID" ]] || refuse "a rehearsal container other than the database exists. Run: restore-rehearsal.sh cleanup --dry-run, then cleanup." # guard:G10-prepare-no-leftover

    historical="$(verified_commit "$HISTORICAL_COMMIT")" || refuse "the historical commit $HISTORICAL_COMMIT (v0.3.0, what production runs) is not in this clone (shallow clone: $(gitq rev-parse --is-shallow-repository 2>/dev/null || echo unknown)). Nothing was substituted. Fetch it yourself, e.g. git fetch origin $HISTORICAL_COMMIT (or git fetch --unshallow), then run prepare again." # guard:G10-commit-present
    [[ "$historical" == "$HISTORICAL_COMMIT" ]] || refuse "the historical commit resolved to '$historical', not $HISTORICAL_COMMIT." # guard:G10-commit-exact
    current="$(verified_commit HEAD)" || refuse "HEAD is not a commit."
    for tree in "$historical" "$current"; do
        gitq cat-file -e "$tree:$APP_TREE/composer.lock" 2>/dev/null || refuse "commit ${tree:0:12} has no $APP_TREE/composer.lock, so its dependencies cannot be installed as locked." # guard:G10-lockfile
    done
    resolve_php_image
    ! dkr volume inspect "$SRC_VOLUME_NAME" >/dev/null 2>&1 || refuse "a volume named $SRC_VOLUME_NAME already exists. Run: restore-rehearsal.sh cleanup --dry-run, then cleanup, then up and prepare again." # guard:G10-prepare-volume-new

    trap on_prepare_exit EXIT
    PREPARE_RECOVERY=1
    say "Preparing source (historical ${historical:0:12}, current ${current:0:12}) ..."
    dkr volume create --label "$LABEL_NS_KEY=$LABEL_NS_VALUE" --label "$LABEL_NS_KEY.role=source" --label "$LABEL_NS_KEY.run-token=$token" "$SRC_VOLUME_NAME" >/dev/null
    why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" '')" || refuse "the source volume is not the rehearsal's: $why." # guard:G10-prepare-volume

    local version commit
    for version in historical current; do
        commit="$historical"
        [[ "$version" == historical ]] || commit="$current"
        # shellcheck disable=SC2016 # the snippet runs in the container; its arguments follow it
        gitq archive --format=tar "$commit" "$APP_TREE" | prepare_run "$token" none 1 \
            sh -c 'mkdir -p "$1/storage" "$1/bootstrap/cache" && tar -x -C "$1" --strip-components=2 --no-same-owner' sh "$SRC_MOUNT/$version"
        note "$version: archived from $commit"
    done
    # Dependency acquisition: the only step with a route out, and it has no database credentials and no route to the database network.
    # The flags are the ones the release build uses (scripts/lib/release.sh); each tree installs from its own composer.lock.
    for version in historical current; do
        prepare_run "$token" bridge 0 composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader --working-dir="$SRC_MOUNT/$version"
        note "$version: composer install finished"
    done
    # The harness goes in last, after the only step that runs third-party code with a route out, so nothing Composer ran can have
    # written to it.
    local file
    # shellcheck disable=SC2016 # as above
    (cd "$SCRIPT_DIR/php" && tar -c -- "${HARNESS_FILES[@]}") | prepare_run "$token" none 1 \
        sh -c 'mkdir -p "$1" && tar -x -C "$1" --no-same-owner' sh "$SRC_MOUNT/harness"
    for file in "${HARNESS_FILES[@]}"; do note "harness: $file"; done

    PREPARE_RECOVERY=0
    say "Prepared. Source volume $SRC_VOLUME_NAME (mounted read-only into the runner)."
}

cmd_run() {
    [[ $# -eq 2 ]] || { usage >&2; exit 64; } # guard:G11-arity
    local version="$1" name="$2"
    [[ "$version" == historical || "$version" == current ]] || { usage >&2; exit 64; } # guard:G11-version
    runner_command "$name" # refuses before any docker call
    preflight
    run_in_runner "$ROOT" "$version" "${RUN_CMD[@]}"
}

# The fixed isolation probe. Read-only: it reads from the database and writes nothing anywhere.
cmd_probe() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    local why out expected line
    assert_sql_target "$ROOT" "$APP_DB"
    why="$(app_account_problem "$CID")" || refuse "$why."
    say "ok   the application account is the only account, with USAGE plus all privileges on $APP_DB"
    if printf 'SELECT token FROM %s.run_token;\n' "$SENTINEL_DB" | mariadb_app "$CID" >/dev/null 2>&1; then refuse "the application account can read the sentinel database."; fi # guard:G12-probe-sentinel
    say "ok   the application account cannot read the sentinel database (restricted client)"
    # The marker is assembled by the shell command itself, so an error message that quotes the statement cannot contain it.
    out="$(printf '\\! echo FLC_ESC""APED\n' | mariadb_app "$CID" 2>&1 || true)"
    [[ "$out" != *FLC_ESCAPED* ]] || refuse "the client sandbox ran a shell command." # guard:G12-probe-sandbox
    say "ok   the client sandbox did not run a shell command"
    out="$(run_in_runner "$ROOT" harness php "$SRC_MOUNT/harness/probe.php")" || refuse "the runner probe failed: ${out:0:300}"
    for expected in php-version user network-external dns-external root-fs source-readonly tmp-writable db-app databases sentinel-denied; do
        grep -Fxq "probe:$expected=ok" <<<"$out" || refuse "the runner probe did not report $expected=ok: ${out:0:400}" # guard:G10-probe-lines
    done
    while IFS= read -r line; do [[ "$line" == probe:* ]] && say "ok   runner ${line#probe:}"; done <<<"$out"
    say "Probe passed."
}

main() {
    local command="${1:-}"
    case "$command" in
        up) shift; cmd_up "$@" ;;
        self-check) shift; cmd_self_check "$@" ;;
        cleanup) shift; cmd_cleanup "$@" ;;
        prepare) shift; cmd_prepare "$@" ;;
        run) shift; cmd_run "$@" ;;
        probe) shift; cmd_probe "$@" ;;
        help | -h | --help) usage ;;
        *) usage >&2; exit 64 ;;
    esac
}

# Sourcing this file (scripts/tests/rehearsal.sh does) defines the functions and runs nothing.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
