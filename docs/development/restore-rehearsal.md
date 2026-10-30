# The local recovery rehearsal: isolation foundation

**Status.** G6 WP3c, slice 1 ([contract](../audits/g6/README.md#wp3--data-integrity-upgrade-and-restore-safety)). This is the isolated environment and its safeguards only. It seeds nothing, installs nothing and restores nothing. The first live run against a Docker daemon needs the owner's separate authorization.

**What it is for.** Later slices use it to prove, with synthetic data on a local machine, that the organization can recover v0.3.0 after an unsuccessful upgrade. It never touches production or staging, reads no application `.env`, holds no host credentials and uses no production data. A local rehearsal does not replace the decision on the real host ([contract §9](../audits/g6/README.md#9-release-track-outside-g6)).

**What it is not.** A restore tool. There is no restore, drop, reset or production command, and it is deliberately not a `./flow` command: the runbooks state that `./flow` has no `backup` or `restore`.

## Using it

```bash
scripts/rehearsal/restore-rehearsal.sh self-check          # read-only: environment, and the project if one exists
scripts/rehearsal/restore-rehearsal.sh up                  # start the isolated MariaDB 10.11.18 and verify it
scripts/rehearsal/restore-rehearsal.sh cleanup --dry-run   # list exactly what cleanup would remove
scripts/rehearsal/restore-rehearsal.sh cleanup             # remove it
```

Run it from a clean shell. It refuses if any `COMPOSE_*`, `DB_*`, `MYSQL_*`, `MARIADB_*`, `PG*`, `POSTGRES_*`, `APP_*`, `REHEARSAL_*` or `DATABASE_URL` variable, or `DOCKER_HOST`, `DOCKER_CONTEXT` or a Docker TLS variable, is set. It names the variables and never prints their values.

If `up` is interrupted, or an earlier run was never cleaned, the next `up` refuses and says so. Run `cleanup --dry-run`, read the list, then `cleanup`.

**A cleanup that cannot finish says so.** If any rehearsal-labelled object is kept (a foreign container is attached to the network or uses the volume, an object no longer verifies, or a removal fails), `cleanup` removes what it can prove is its own, lists what remains and why, **keeps the state directory** (it holds the run token) and exits 1. Remove the cause yourself, then run plain `cleanup` again: it completes what is left, including when some objects are already gone. `--stale` is not needed for this and should not be used for it.

`cleanup --stale` is needed only when the private state file has been lost, because the run token then cannot be checked: review `cleanup --dry-run --stale` first. Every other identity check still applies to it.

If `compose.env` exists but is malformed, nothing can be checked against it and every command (including `--stale`) refuses without changing anything. The script never edits, discards or rebuilds that file. Move it out of the state directory to a place of your choosing if you want to keep it as evidence (`cp -p` first if you prefer a copy), review `cleanup --dry-run --stale`, then `cleanup --stale`. The script never deletes it; a file left inside the state directory keeps the directory in place (next paragraph).

**The state directory is only removed if it holds nothing else.** When the objects are gone, cleanup removes `compose.env`, `run.lock` and the marker, then the directory. If the directory holds any other entry (a file, a directory, a dotfile, or a symlink where one of those three files should be), cleanup removes **nothing** from it, names the entries, keeps the marker and the run token (so the directory is still one this script recognises, and `up` can use it once `compose.env` is gone), exits 1, and finishes normally on the next `cleanup` after you have dealt with those entries. It never removes what it did not write.

## What it creates

One container, one network and one volume, all named in [compose.yaml](../../scripts/rehearsal/compose.yaml) with literal names and the label `io.flowlife.rehearsal=restore-v1`:

| Object | Name | Properties |
| --- | --- | --- |
| container | `flc-rehearsal-restore-mariadb` | `mirror.gcr.io/library/mariadb:10.11.18`, no published port, no bind mount, no Docker socket, not privileged |
| network | `flc-rehearsal-restore-db` | `internal: true`: no route to external networks, nothing published to the host; the bridge gateway is a residual (see Limits) |
| volume | `flc-rehearsal-restore-db-data` | plain `local` volume |
| state directory | `~/.local/state/flowlife-restore-rehearsal` (override: `FLC_REHEARSAL_ROOT`) | mode 700, `compose.env` (per-run credentials and run token, mode 600), lock file |

Databases: `flc_rehearsal_app` (the target), `flc_rehearsal_identity` (the sentinel holding the run token). Scratch databases `flc_rehearsal_scratch_1` to `_3` are allowlisted for later slices and not created.

Pulling the image uses the Docker daemon. The database container never gains network egress as a result: the network is internal. Composer dependencies for the historical trees will be fetched in a separate preparation step with no database credentials and no route to this network (a later slice). The network's isolation is not weakened to make that work.

## Slice 2.1: the PHP execution substrate

**Status.** Implemented and tested against the fake daemon only. The first live run (L1, below) needs the owner's separate authorization. It runs no migration and restores nothing: the backup, schema clearing, restore and evidence cycle are slice 2.2.

```bash
scripts/rehearsal/restore-rehearsal.sh prepare                       # after `up`: archive source and install dependencies into a labelled volume
scripts/rehearsal/restore-rehearsal.sh run <historical|current> <name> # one approved command in a disposable PHP runner
scripts/rehearsal/restore-rehearsal.sh probe                         # fixed isolation probe (read-only)
```

**`prepare`.** Archives `apps/platform` from two *verified* commits with `git archive` (no historical worktree): v0.3.0 (`2f385b79a7b83323c6f15a6685ee610ccd85b5bf`, what production runs) and the current `HEAD`, plus the fixed helpers in [`scripts/rehearsal/php/`](../../scripts/rehearsal/php), into the labelled volume `flc-rehearsal-restore-src` (`/var/www/html/{historical,current,harness}`). Composer runs before the harness is copied in, so nothing it ran can have written to the harness. If the historical commit is not in the clone (a shallow clone), `prepare` refuses, names the commit and proposes `git fetch`; it never substitutes another commit. Each tree then gets `composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader` (the release build's flags, `scripts/lib/release.sh`) from its own `composer.lock`. Dependency acquisition is the only step with a route out, and it runs in a container with no database credentials that is never attached to the database network. It refuses if the volume already exists; it never adopts one. Uncommitted changes are not archived: `current` means the commit `HEAD` names, which `prepare` prints.

**`run`.** One disposable container from the development image `flowlife-dev/php:8.3` (resolved once to an image ID, launched by that ID, never pulled or built here), on the internal database network only, with the source mounted read-only, a read-only root filesystem, small `tmpfs` mounts for `/tmp` and each tree's `storage` and `bootstrap/cache`, all capabilities dropped, `no-new-privileges`, uid/gid 33, 1 GiB memory, one CPU and 256 processes, no published port, no Docker socket and no bind mount. The container is created, its identity is checked against what Docker reports (name, image ID, labels, user, root filesystem, capabilities, network, mounts, tmpfs, limits), and only then started; it is removed afterwards. The caller supplies a version and a name; the script builds the whole argument vector:

| Name | Runs |
| --- | --- |
| `about`, `migrate:status`, `relationships:check`, `release:show` | `php artisan <name>` |
| `migrate` | `php artisan migrate --force --no-interaction` (default migration path, no override) |
| `resources:assets:prune` | `php artisan resources:assets:prune --dry-run` |
| `helper:db-ping` | the fixed helper `php /var/www/html/harness/db-ping.php` |

Anything else, any extra argument, any other version name, and any caller-chosen option, file, alias or environment is refused before Docker is contacted. Abbreviations (`res:ass:pru`) are refused because only exact names match. The runner's environment is fixed (`APP_ENV=production`, array cache and session, `LOG_CHANNEL=stderr`, database host, name and user) plus `DB_PASSWORD`, which is the application account's synthetic per-run password and the only secret it receives. `APP_KEY` is a fixed placeholder, not a secret: the data is synthetic and nothing leaves the runner. Slice 2.2 may need a per-run key if it exercises encrypted data.

**The launcher.** `dkr` runs docker under `env -i`, so `docker run -e NAME` would not see the password. `launch_docker` instead unsets every variable but `PATH`, `HOME`, `LANG` and `DOCKER_CONFIG` in a subshell, exports `DB_PASSWORD`, and runs `docker create ... -e DB_PASSWORD` (a name, never a value). `env -i NAME=value` is not used because it would put the value in a process argument. Only the runner's `create` goes through it; preparation containers, `start`, `inspect` and `rm` never see the password. The password is read from the private state file and validated as the 48-character hex value `up` generates.

**The application-user SQL path.** `mariadb_app` runs the MariaDB client inside the database container as the OS user `mysql`, as the application account, with `--no-defaults --sandbox --local-infile=0`, no `--force`, `MARIADB_ROOT_PASSWORD` and the other password variables removed from the client's environment, the application password read inside the container and passed as `MYSQL_PWD` (never a host command-line argument), and editor and pager variables neutralised. `guarded_app_sql` runs every Slice 1 check (container identity, server version, sentinel, database allowlist) and then verifies the account itself: exactly one account named `flc_rehearsal`, the client's `CURRENT_USER()` is `flc_rehearsal@%`, and its grants are exactly `USAGE ON *.*` plus `ALL PRIVILEGES ON flc_rehearsal_app.*` (the escaped underscores as `--batch` prints them, doubled backslashes, or the unescaped name), with no role, global, other-database, wildcard or grant-option line. Authentication hashes are cut from each line before comparison; they are never compared or printed. There is no root fallback: if the account lacks something it needs, the operation refuses and names the smallest correction (grant that privilege on `flc_rehearsal_app.*` to `flc_rehearsal`, by hand).

**The probe.** `probe` checks, in order: the account verification above; that the restricted client cannot read the sentinel database; that the client's sandbox does not run `\! <shell command>`; and then, inside the runner, that PHP is 8.3 and uid 33, that an external IP connection and an external DNS lookup both fail, that the root filesystem and the source volume are not writable and `/tmp` is, that the application account connects, sees only `flc_rehearsal_app` and `information_schema`, and is denied the sentinel table. Every runner line must read `ok`.

**Identity and cleanup.** The database container is still identified and protected by the Slice 1 checks and the sentinel. The runner and preparation containers have their own narrow identity check (above), the source volume has its own (plain local volume, labelled `role=source`, this run's token, used by no container but ours), and `self-check` and `cleanup` know about them. `cleanup` selects a labelled container's check by its role label, and keeps (and reports) anything that does not satisfy that complete check; the current PHP image ID is not required for removal. A leftover runner or preparation container stops `run`, `prepare` and `self-check` until `cleanup` has dealt with it.

### L1: the first live validation (needs separate authorization)

1. Make the historical commit available (`git fetch origin 2f385b79a7b83323c6f15a6685ee610ccd85b5bf`, or `git fetch --unshallow`), confirm `git rev-parse --verify 2f385b79a7b83323c6f15a6685ee610ccd85b5bf^{commit}`, and build the development PHP image (`./flow setup`).
2. `restore-rehearsal.sh self-check` (read-only; expects no project), then `up`.
3. Gate check, read-only: `docker exec flc-rehearsal-restore-mariadb mariadb --help | grep -e --sandbox`. Slice 2.1 was written without access to a MariaDB 10.11.18 client; if `--sandbox` is not listed, stop and report. The script does not weaken the flags.
4. `self-check`: now also verifies the application account through the restricted client. If it refuses, report the grants it saw (`SHOW GRANTS` text, with the hash removed).
5. `prepare`. Confirms the new volume is writable by uid 33 (it relies on Docker copying the ownership of the image's `/var/www/html` into the empty volume) and that both trees install from their own lockfiles.
6. `self-check`, then `probe`.
7. `run historical about`, `run current about`, `run current helper:db-ping`, `run current migrate:status` (a fresh database reports the migration table missing; that is not a failure of the substrate). Do **not** run `migrate`; that is slice 2.2.
8. Network boundary, narrow and non-destructive (a measurement, not a scan; no host firewall is read or changed). Read the gateway address with `docker network inspect flc-rehearsal-restore-db --format '{{json .IPAM.Config}}'` (read-only, and not proof of anything by itself). Then, once the runner works (after step 7), with the owner's approval run **one** throw-away container on that network from the same verified PHP image ID, with the runner's isolation options, no credentials and no source volume, that makes TCP *connect-only* attempts (no payload is sent) to that gateway address on two ports the owner names: one where the host is known to listen (for example 22) and one known closed. Report each as connected, refused or timed out. Nothing else on the host, and no address other than that one gateway, is contacted. A connect to a listening host port is a finding to record, not a failure of S2.1. The same container also tries one external IP to confirm the route-out result the probe reports.
9. `cleanup --dry-run`, `cleanup`, `self-check` (expects no project). Record the timings.

## The safeguards

Each is one line in `restore-rehearsal.sh` carrying a `# guard:<ID>` marker.

| | Guard |
| --- | --- |
| G1 | The daemon is the local one: `default` context, a unix-socket endpoint, reachable. Every `docker` call runs under a scrubbed environment, so `DOCKER_HOST` is refused even when it names a unix socket (it would not be the daemon acted on). |
| G2 | No inherited database, application or Compose override. Checked before any Docker call. |
| G3 | Credentials and the run token are generated per run into a private file and never printed. No `.env` is read, and one beside the compose file is refused. |
| G4 | Identity is verified against the object itself (name, image, labels, network, mounts, ports, privilege, run token), not trusted from a label. The rendered Compose file is checked for the same policy before anything starts. The server's own `VERSION()` must be 10.11.18, whatever the image tag says. |
| G5 | The sentinel database holds the run token. Every SQL statement is preceded by checking it matches this run's token. |
| G6 | SQL runs only against a fixed allowlist of database names. |
| G7 | `artisan` commands are denied unless listed, by exact name (Symfony Console accepts abbreviations, so refusing the prune command by name would not be enough). `resources:assets:prune` is allowed only as an exact `--dry-run`, and `schedule:run` (which runs it) is not allowed. |
| G8 | Cleanup removes only objects whose complete identity verifies, by exact ID or name, re-verified immediately before each removal. It never prunes, never matches by name pattern and never runs `compose down`. A network with a foreign container attached, or a volume used by one, is kept. Unknown objects are reported and kept, and a cleanup that keeps or fails to remove any labelled object is reported as incomplete: it exits 1 and keeps its state file. Removing the state directory removes only the three files the script wrote, then `rmdir`; it is never recursive. |
| G9 | One run at a time (`flock`). A leftover state file, a leftover labelled object, or an unlabelled object with the rehearsal's name or Compose project makes `up` refuse, with recovery instructions. A Docker listing that fails is a refusal, not an empty inventory (the listings go through one helper that checks their status in the main shell, so the result does not depend on `set -e` inside command substitutions). |
| G10 | The runner and preparation containers are checked against what Docker created, before they start: name, image ID, labels, run token, no published port, not privileged, all capabilities dropped, `no-new-privileges`, read-only root, uid/gid 33, network, mounts (only the source volume, read-only for the runner), tmpfs, limits. The source volume is a plain local labelled volume used only by them. Source comes from verified commits only; an existing volume is never adopted. |
| G11 | `run` takes a version and one approved name and builds the whole argument vector; nothing else is accepted. |
| G12 | Application-user SQL uses the restricted client, verifies the account's identity and grants before every use, and never falls back to root. |

## Tests

`scripts/tests/rehearsal.sh` (run by `./flow check repo`) creates no container, network, volume or database. It runs the script against a fake `docker` in a private tool directory that contains no real `docker`, so a mistaken test cannot reach the daemon. It also renders the committed `compose.yaml` with `docker compose config`, which starts nothing.

It then runs a mutation matrix: for each guard it replaces the marked line with `:` in a scratch copy and requires a test to fail. A marker without tests, or tests without a marker, fail the run. Markers sit on the safeguards' bodies and also on the call sites whose removal would be a safety loss: the environment refusal in `up`, the state-directory checks in `cleanup`, the container, network, volume and version checks `up` makes before it writes to the server, and the identity and version checks every SQL statement is preceded by. Properties a line replacement cannot express are pinned by tests of their own: that the state directory is only ever emptied of the files the script wrote and removed with `rmdir`, never recursively (including an entry that appears while it is being emptied), and that a dry run against a complete project leaves every Docker object, every state file, its content and its permissions as they were (only the lock file is touched: the script opens it to take the lock).

The fake daemon fails any call on request (so a failed listing can be tested), rejects an `exec` with no container, renders an absent label as `<no value>` and refuses to render a bind-mounted container, each as the real daemon does.

## Limits

- The fake daemon models the inspect fields the script uses. Those templates and the listing filters were also run against a real container, network and volume (read-only) when this slice was written. The first live cycle is where the remaining semantics, such as Compose's behaviour with `--wait` on an internal network, are confirmed.
- The image is pinned by tag, and the server version is checked separately. It is not pinned by digest.
- A stopped container that is attached to the rehearsal network does not appear in the network's `.Containers`, so the "foreign container attached" check cannot see it. Removing the network then does not harm that container (it only loses a network it could not start on), and a volume used by any container, stopped or not, is still seen and kept.
- The container inspect template cannot be rendered for a container with a bind mount (the real daemon errors). Such a container is treated as not the rehearsal database and kept.
- Cleanup checks the state directory before it removes anything, but an entry written by the same user in the instant after that check can still make the final `rmdir` fail. Cleanup then exits 1 with the marker already removed, so the directory is no longer recognised as the script's; the entry is left untouched and the directory has to be dealt with by hand. There is no automatic recovery from that exceptional condition.
- Slice 2.2 must send only fixed, validated SQL: the database allowlist (G6) constrains the `-D` argument, not the statements, and there is deliberately no SQL parser. `run` builds the complete Artisan argument vector itself (G11), so no caller-supplied option reaches a migration.
- Slice 2.1 is tested against the fake daemon only. What it cannot show: that the MariaDB 10.11.18 client supports `--sandbox`, that the empty source volume is writable by uid 33 (Docker's copy of the image directory's ownership), the exact text `SHOW GRANTS` prints (the comparison expects what the entrypoint and `--batch` should produce, `flc\\_rehearsal\\_app` with two backslashes, or the unescaped name; anything else refuses, which is safe but would need a correction), whether the S2.1 inspect templates (`EPHEMERAL_FMT`, `ROLE_FMT`) render on a real daemon (only the Slice 1 templates were ever run live), and whether the daemon lists `tmpfs` mounts among `.Mounts` (both are handled). L1 settles these.
- **Host gateway residual.** `internal: true` withholds a route to external networks and publishes nothing, but Docker does not promise that a container on an internal network cannot reach a service listening on that network's bridge gateway address (the host side of the bridge). The rehearsal does not claim complete host isolation, and reading the network's IPAM configuration is not proof that host services are unreachable. What the design does guarantee: no port is published, there is no route to the internet or to other development networks, and the runner holds no credential but the synthetic application password. Whether a host service answers on the gateway is a fact about the machine, to be measured in L1 (step 8), not assumed. No firewall or extra network infrastructure is added for it; a finding there is a decision for the owner.
- The runner and the preparation container share the development image, so the image's PHP settings (`zz-flow-dev.ini`) apply. `APP_KEY` is a fixed placeholder.
- An interrupted credentials write can leave a `.compose.env.*` temporary file (mode 600) in the state directory; cleanup treats it as another entry (above), names it and keeps the directory until you remove it; `run.lock` is removed while held, which allows a negligible race; `stat -c` is GNU-specific.
- Identity labels and the run token guard against mistakes. They are not a defence against someone who can already create arbitrary Docker objects on the machine.
