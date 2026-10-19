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

If `up` is interrupted, or an earlier run was never cleaned, the next `up` refuses and says so. Run `cleanup --dry-run`, read the list, then `cleanup`. `cleanup --stale` is needed only when the private state file has been lost, because the run token then cannot be checked: review `cleanup --dry-run --stale` first.

## What it creates

One container, one network and one volume, all named in [compose.yaml](../../scripts/rehearsal/compose.yaml) with literal names and the label `io.flowlife.rehearsal=restore-v1`:

| Object | Name | Properties |
| --- | --- | --- |
| container | `flc-rehearsal-restore-mariadb` | `mirror.gcr.io/library/mariadb:10.11.18`, no published port, no bind mount, no Docker socket, not privileged |
| network | `flc-rehearsal-restore-db` | `internal: true`: no route out, none in from the host |
| volume | `flc-rehearsal-restore-db-data` | plain `local` volume |
| state directory | `~/.local/state/flowlife-restore-rehearsal` (override: `FLC_REHEARSAL_ROOT`) | mode 700, `compose.env` (per-run credentials and run token, mode 600), lock file |

Databases: `flc_rehearsal_app` (the target), `flc_rehearsal_identity` (the sentinel holding the run token). Scratch databases `flc_rehearsal_scratch_1` to `_3` are allowlisted for later slices and not created.

Pulling the image uses the Docker daemon. The database container never gains network egress as a result: the network is internal. Composer dependencies for the historical trees will be fetched in a separate preparation step with no database credentials and no route to this network (a later slice). The network's isolation is not weakened to make that work.

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
| G8 | Cleanup removes only objects whose complete identity verifies, by exact ID or name, re-verified immediately before each removal. It never prunes, never matches by name pattern and never runs `compose down`. A network with a foreign container attached, or a volume used by one, is kept. Unknown objects are reported and kept. |
| G9 | One run at a time (`flock`). A leftover state file, a leftover labelled object, or an unlabelled object with the rehearsal's name or Compose project makes `up` refuse, with recovery instructions. |

## Tests

`scripts/tests/rehearsal.sh` (run by `./flow check repo`) creates no container, network, volume or database. It runs the script against a fake `docker` in a private tool directory that contains no real `docker`, so a mistaken test cannot reach the daemon. It also renders the committed `compose.yaml` with `docker compose config`, which starts nothing.

It then runs a mutation matrix: for each guard it replaces the marked line with `:` in a scratch copy and requires a test to fail. A marker without tests, or tests without a marker, fail the run.

## Limits

- The fake daemon models the inspect fields the script uses. Those templates and the listing filters were also run against a real container, network and volume (read-only) when this slice was written. The first live cycle is where the remaining semantics, such as Compose's behaviour with `--wait` on an internal network, are confirmed.
- The image is pinned by tag, and the server version is checked separately. It is not pinned by digest.
- Identity labels and the run token guard against mistakes. They are not a defence against someone who can already create arbitrary Docker objects on the machine.
