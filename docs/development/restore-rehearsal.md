# The local recovery rehearsal

**Status.** G6 WP3c ([contract](../audits/g6/README.md#wp3--data-integrity-upgrade-and-restore-safety)). Slice 1 is the isolated database environment and its safeguards; slice 2.1 adds a PHP execution substrate (a source volume, a preparation step and disposable runners) and passed its first live validation (L1, below). Slice 2.2A, the first half of the recovery cycle (historical baseline, backup, upgrade, and a demonstration of the audited WP3-01 restore defect), and slice 2.2B, the second half (the corrected restore, a sign-in with TOTP on the restored application, and the re-upgrade), are implemented and tested against the fake daemon, and the PHP harness has also been run against the real v0.3.0 and current applications on SQLite (`scripts/tests/rehearsal-harness-sqlite.sh`): **no live run of either slice has been made or authorized.** Resource files, half-failed migrations and security reconciliation are slice 2.2C.

**What it is for.** Later slices use it to prove, with synthetic data on a local machine, that the organization can recover v0.3.0 after an unsuccessful upgrade. It never touches production or staging, reads no application `.env`, holds no host credentials and uses no production data. A local rehearsal does not replace the decision on the real host ([contract §9](../audits/g6/README.md#9-release-track-outside-g6)).

**What it is not.** A general restore or backup tool, and not an automatic rollback. No command takes a file, a database name or a statement from the caller. The only things that change the rehearsal database are the application's own migrations, the fixed fixture and marker helper, `reproduce-wp3-01` (which loads the one dump this script took in order to show a defect and is not a restoration), and `restore-historical`, which drops exactly the 34 tables this script names and loads that same dump. None takes an argument. It is deliberately not a `./flow` command: the runbooks state that `./flow` has no `backup` or `restore`.

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

`up` creates one container, one network and one volume (the base database environment), all named in [compose.yaml](../../scripts/rehearsal/compose.yaml) with literal names and the label `io.flowlife.rehearsal=restore-v1`. Slice 2.1 adds, outside Compose, a source volume `flc-rehearsal-restore-src` (created by `prepare`) and short-lived preparation and runner containers (see below); `cleanup` removes all of them.

| Object | Name | Properties |
| --- | --- | --- |
| container | `flc-rehearsal-restore-mariadb` | `mirror.gcr.io/library/mariadb:10.11.18`, no published port, no bind mount, no Docker socket, not privileged |
| network | `flc-rehearsal-restore-db` | `internal: true`: no route to external networks, nothing published to the host; the bridge gateway is a residual (see Limits) |
| volume | `flc-rehearsal-restore-db-data` | plain `local` volume |
| state directory | `~/.local/state/flowlife-restore-rehearsal` (override: `FLC_REHEARSAL_ROOT`) | mode 700, `compose.env` (per-run credentials and run token, mode 600), lock file |

Databases: `flc_rehearsal_app` (the target), `flc_rehearsal_identity` (the sentinel holding the run token). Scratch databases `flc_rehearsal_scratch_1` to `_3` are allowlisted for later slices and not created.

Pulling the image uses the Docker daemon. The database container never gains network egress as a result: the network is internal. Composer dependencies are fetched by `prepare` (slice 2.1, below) in a separate preparation container with no database credentials and no route to this network. The network's isolation is not weakened to make that work.

## Slice 2.1: the PHP execution substrate

**Status.** Implemented, tested against the fake daemon, and validated live once (L1, below: passed on 2026-11-12). It runs no migration and restores nothing: the backup, schema clearing, restore and evidence cycle are slice 2.2. Any further live Docker lifecycle needs the owner's separate authorization.

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

### L1: the first live validation (passed 2026-11-12)

**Result.** Run once, locally, at commit `872365150ab1`, with the owner's authorization, against synthetic data only. Every stage below passed (steps 1 to 7 and 9; step 8 was deliberately not run, see its note). Verified: MariaDB server 10.11.18 with client 15.1 (10.11.18), which lists `--sandbox`; the application account has exactly `USAGE ON *.*` plus `ALL PRIVILEGES ON flc_rehearsal_app.*`, and `SHOW GRANTS` prints the underscores doubled (`flc\\_rehearsal\\_app`), as the comparison expects; `prepare` archived both trees and installed 79 packages in each from its own lockfile (about 30 s); the source volume was writable by uid 33; all 13 probe lines read `ok`; `run historical about` and `run current about` both reported Laravel 13.32.0 on PHP 8.3.33 (production); `run current helper:db-ping` connected as `flc_rehearsal@%` to `flc_rehearsal_app`. `cleanup` removed the database container, the network and both volumes and the state directory, `self-check` then found no project, and the Docker container, volume, network and image inventories were identical before and after. No migration, restore, import or schema change was run, and the regular development stack and database were not touched. This settles the live-only questions listed under Limits; it does not measure the gateway residual.

The procedure, for a repeat run:

1. Make the historical commit available (`git fetch origin 2f385b79a7b83323c6f15a6685ee610ccd85b5bf`, or `git fetch --unshallow`) and confirm `git rev-parse --verify 2f385b79a7b83323c6f15a6685ee610ccd85b5bf^{commit}`. Check that the development PHP image exists (`docker image inspect flowlife-dev/php:8.3`). If it does not, build only the platform image with `docker compose -f compose.yaml build platform`. Do not run `./flow setup`: it also starts the regular development database and runs migrations, which are outside the rehearsal.
2. `restore-rehearsal.sh self-check` (read-only; expects no project), then `up`.
3. Gate check, read-only: `docker exec flc-rehearsal-restore-mariadb mariadb --help | grep -e --sandbox`. Slice 2.1 was written without access to a MariaDB 10.11.18 client; if `--sandbox` is not listed, stop and report. The script does not weaken the flags.
4. `self-check`: now also verifies the application account through the restricted client. If it refuses, report the grants it saw (`SHOW GRANTS` text, with the hash removed).
5. `prepare`. Confirms the new volume is writable by uid 33 (it relies on Docker copying the ownership of the image's `/var/www/html` into the empty volume) and that both trees install from their own lockfiles.
6. `self-check`, then `probe`.
7. `run historical about`, `run current about`, `run current helper:db-ping`; `run current migrate:status` is optional (a fresh database has no migration table, which is not a failure of the substrate, and L1 did not run it). Do **not** run `migrate`; that is slice 2.2.
8. **Optional, not run in the first L1.** The owner accepted the gateway residual for the synthetic rehearsal without measuring it, so this step was omitted. The network's configuration was only inspected (`internal: true`, gateway `172.25.0.1`). Network boundary, narrow and non-destructive (a measurement, not a scan; no host firewall is read or changed). Read the gateway address with `docker network inspect flc-rehearsal-restore-db --format '{{json .IPAM.Config}}'` (read-only, and not proof of anything by itself). Then, once the runner works (after step 7), with the owner's approval run **one** throw-away container on that network from the same verified PHP image ID, with the runner's isolation options, no credentials and no source volume, that makes TCP *connect-only* attempts (no payload is sent) to that gateway address on two ports the owner names: one where the host is known to listen (for example 22) and one known closed. Report each as connected, refused or timed out. Nothing else on the host, and no address other than that one gateway, is contacted. A connect to a listening host port is a finding to record, not a failure of S2.1. The same container also tries one external IP to confirm the route-out result the probe reports.
9. `cleanup --dry-run`, `cleanup`, `self-check` (expects no project). Record the timings (L1: each stage took between about 1 and 31 s, `up` about 19 s).

## Slice 2.2A: the first half of the recovery cycle

**Status.** Implemented and tested against the fake daemon only. It has not been run against a live Docker daemon, and the PHP harness it relies on has never run against a database: the first live run (L2) needs the owner's separate authorization. It changes no application code, no migration, no deployment behaviour and no runbook.

**What it proves, and what it leaves.** It builds the production baseline (v0.3.0) in the rehearsal database with the v0.3.0 application, takes the backup the runbook describes, upgrades with the current application, and then reproduces WP3-01 ([audit](../audits/g6/wp3-audit.md), finding WP3-01): loading the historical dump over the upgraded database **without clearing it** leaves the 18 newer tables behind. The corrected restore, which clears the schema first and must make the same inventory check pass, is slice 2.2B and reuses the checks below.

```bash
scripts/rehearsal/restore-rehearsal.sh baseline          # after `up` and `prepare`: v0.3.0 schema, synthetic fixture, snapshot
scripts/rehearsal/restore-rehearsal.sh take-backup       # the runbook's two-pass dump, and its manifest
scripts/rehearsal/restore-rehearsal.sh upgrade           # the current migrations, judged against the snapshot; one synthetic marker
scripts/rehearsal/restore-rehearsal.sh reproduce-wp3-01  # load the dump over the upgraded database; require that the newer tables remain
```

None of them takes an argument. Each starts from the state the one before left and checks it against the database and the state directory themselves, never against a flag, so running them out of order, twice, or against a database that was changed in between is a refusal that changes nothing.

| Stage | Checks before it changes anything | What it does and verifies |
| --- | --- | --- |
| `baseline` | The historical commit `2f385b79…` resolves to exactly that commit; no uncommitted or untracked change under `apps/platform` or `scripts/rehearsal` (`prepare` archives commits, so a dirty tree would be a rehearsal of code nobody is looking at); the target database is **empty**. | Runs the v0.3.0 tree's own `migrate`, so the schema is the one production has and not a replacement. Requires exactly the 16 v0.3.0 tables. Loads `HistoricalFixture` in one transaction (all of it or none) and requires it complete (every table's row count, 13 people). Writes the snapshot. |
| `take-backup` | A snapshot exists; the database still has exactly the 16 tables and still equals the snapshot; no backup exists yet. | The two-pass dump as the application account (never root). Requires the dump to create and drop exactly the 16 tables, carry rows for exactly the 9 durable tables, and name no database. Writes the manifest and re-checks it and the dump's checksum. |
| `upgrade` | A complete, verified backup exists and belongs to this run; the database is exactly the 16 tables and equals the snapshot. | Runs the current tree's `migrate`. Requires exactly the 34 tables and every v0.3.0 table equal to what the upgrade is expected to make of it (the 5 `guardian` role assignments renamed, the 14 migrations recorded, nothing else changed). Writes one post-upgrade Person and CRM profile. |
| `reproduce-wp3-01` | The backup is verified as for `upgrade`; the live keyring holds every key fingerprint the manifest requires; the database is exactly the 34 tables and the post-upgrade state (14 people, 1 marker profile, 29 migrations); the dump's checksum is re-verified immediately before it is loaded. | Loads the dump, then requires the table-inventory equality to **fail** with exactly the 18 newer tables extra and none missing. See below. |

**The historical fixture and snapshot.** The harness (`scripts/rehearsal/php/rehearse.php`) requires the WP3b test support (`HistoricalFixture`, `HistoricalSnapshot`, `UpgradeDatabase::HISTORICAL`) from the prepared current tree by path. The runner installs with `--no-dev`, so Composer's `autoload-dev` is absent; nothing here changes the application's autoloading or installs a test dependency. The fixture is loaded by the v0.3.0 application's own encrypter, so the TOTP ciphertext is what v0.3.0 would have written under the run's key. The snapshot is **digests, never rows**: for each of the 16 tables, its row count and the SHA-256 of its rows as the driver returns them, ordered by primary key (`HistoricalSnapshot::take`), so a changed value, type or order is a different digest, and no hash, ciphertext or secret reaches a log. It also records, from `HistoricalSnapshot::expected`, what the upgrade must make of each table. A query that fails, a table that is absent, a table that appears twice or a line that is malformed is a refusal, never an empty snapshot or a false equality.

**State files.** The snapshot, the dump and the manifest are written into the state directory (mode 600, whole or not at all) as `historical.snapshot`, `historical.dump.sql` and `historical.manifest.json` (slice 2.2B adds `prepared.source` and `upgraded.structure`, below); `cleanup` removes them with the other files the script wrote, by name, and keeps the directory if anything else is in it.

**The backup and its manifest.** The dump follows the [deployment runbook](../runbooks/deployment.md#5-taking-a-backup) exactly: pass 1 is `--no-data --no-tablespaces` (so every table is dropped and recreated), pass 2 is `--no-create-info --single-transaction --quick --no-tablespaces` with the seven transient tables ignored (so they come back empty). It is not compressed; the checksum is of the file that is loaded. The manifest holds the runbook's fields (release, database, dump SHA-256 and byte count, keyring fingerprints, `tables_with_data`, `tables_schema_only`) and a SHA-256 fingerprint of the run token that binds it to the run; it holds no key, password, token, TOTP secret or recovery code. The Resource-files fields are slice 2.2C's. The manifest and the snapshot must agree on the complete 16-table inventory, and its durable and schema-only lists must be the contract's.

**Encryption-key fingerprints.** Each run generates two synthetic keys (`base64:` and 32 random bytes) into the private state file: a current key and a previous one, handed to the runner only through its environment (`APP_KEY`, `APP_PREVIOUS_KEYS`, as variable names, never values). The definition is the runbook's, with its undefined `k` fixed as the **decoded 32 key bytes**:

```
key_fingerprint(k)  = sha256("flc-key-v1|" + k)[0:16]
keyring_fingerprint = sha256("flc-ring-v1|" + join(",", sorted(unique(key_fingerprints))))[0:16]
```

Test vectors (computed independently with Python's `hashlib`; pinned in `scripts/tests/rehearsal.sh`, and the PHP harness reproduces the key ones):

| Key | Fingerprint |
| --- | --- |
| bytes `00 01 … 1f`, `base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=` | `2c8a734376e488fb` |
| 32 zero bytes, `base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=` | `dd55ac8a8f7837a8` |
| 32 bytes of `ff`, `base64://////////////////////////////////////////8=` | `da15800f513e503f` |

| Keyring | Fingerprint |
| --- | --- |
| the first key alone | `aa8b784552ea86fa` |
| the first and second (in either order; a repeated fingerprint changes nothing) | `5fa4bb2695669a8f` |
| all three | `357d42c365bacbce` |

The application reports the fingerprints of the keys it actually used, and the script requires them to be the ones it generated, so the manifest cannot name keys the data was not encrypted with. `reproduce-wp3-01` applies the runbook's subset test before loading anything: every fingerprint the manifest requires must be in the live keyring, or nothing is loaded. (Making this check a gate on every restore is slice 2.2B.)

**How WP3-01 is reproduced and detected.** The dump's pass 1 drops and recreates only the 16 tables it names. Loaded over the 34-table upgraded database it therefore leaves the 18 newer tables. The stage requires four things, each measured, and fails (exit 1, saying what it found) if any is otherwise:

1. `inventory_difference` of the manifest's tables against the database reports **exactly** the 18 newer tables as `extra` and nothing `missing`. This is the same equality a corrected restore must satisfy with an empty difference; here it is required to fail, and only in this way.
2. The post-upgrade CRM profile survives with no Person behind it (profiles without a Person: 1), and the ledger says 15 migrations while 34 tables exist (the next `migrate` would fail: *Table already exists*).
3. Yet the 16 historical tables match the pre-upgrade snapshot (durable tables identical, transient tables empty): a restore that is judged by those tables alone would pass.
4. Everything was loaded through the same guarded application-user path as every other statement, into the one allowlisted database, from the dump whose checksum was verified a moment before.

The demonstration leaves the database in the defective state; slice 2.2B starts from there, or run `cleanup`. If `baseline`, `upgrade` or the demonstration stops part-way, the database may be partly changed: the rehearsal is disposable, so run `cleanup`, then `up`, `prepare` and the stages again.

## Slice 2.2B: the corrected restore, the sign-in and the re-upgrade

**Status.** Implemented and tested against the fake daemon, and its PHP side against the real applications on SQLite. Not run live; L2 needs the owner's separate authorization. No application code, migration, deployment behaviour or runbook is changed.

```bash
scripts/rehearsal/restore-rehearsal.sh restore-historical   # clear exactly the 34 tables, load the verified dump, verify the result
scripts/rehearsal/restore-rehearsal.sh verify-signin        # the restored v0.3.0 application signs the administrator in (password, TOTP); rolled back
scripts/rehearsal/restore-rehearsal.sh reupgrade            # the current migrations on the restored database, judged against the first upgrade
```

None takes an argument, a file, a database or a statement. `restore-historical` is not a restore tool: it exists to prove the corrected sequence on this rehearsal database, from the one dump this script took.

**Prepared-source identity.** `prepare` now digests the helper scripts **inside the volume** (a sixth container with no network) and refuses unless they equal the ones in the checkout, then records `prepared.source`: the historical commit, the current commit (`HEAD`) and each helper's SHA-256. Every stage (the four of slice 2.2A and the three above) re-derives that text and compares: a missing or non-private record, another historical commit, a `HEAD` that moved, a helper that changed, or any other difference is a refusal that changes nothing. A commit after `prepare`, even a docs-only one, therefore needs `cleanup`, `up` and `prepare` again: the rehearsal says what it ran, and it ran `HEAD` as it was. `run` itself does not check the record (it is the slice 2.1 command).

**Three snapshot states, never mixed.** The snapshot is of the database as the fixture left it. What each later stage compares with is derived from it by one function, and a database is judged against nothing else.

| State | What it is | Where |
| --- | --- | --- |
| ORIGINAL | the 16 tables as loaded, the transient tables with their fixture rows (3 sessions, 2 reset tokens) | the `table` lines of `historical.snapshot` |
| RESTORED | the original with every transient table emptied (zero rows, the digest of none): what the two-pass dump (structure everywhere, rows for the 9 durable tables) restores | `emptied_transients` of ORIGINAL |
| UPGRADED | the original with the 5 `guardian` assignments renamed and the 14 migrations recorded (a 29-entry ledger); after a restore and a re-upgrade, the same with the transient tables emptied | the `upgrade` lines (from `HistoricalSnapshot::expected`), through `emptied_transients` |

Comparison is by one function (`digest_differences`) that reports a name present in only one side as a difference, so a durable table that vanished cannot read as "nothing differs". A snapshot helper that did not print `snapshot:complete=ok`, a table missing or repeated, a malformed line or a structure line missing is a refusal, never an empty or partial result. The row digest is of every value as text (NULL stays NULL), so two application trees whose drivers return `3` or `'3'` do not disagree, while a changed value, a lost row or a different order still does. Structure (columns, indexes, foreign keys of every table, as the engine reports them) is digested the same way: the original's is kept in the snapshot, and the first upgrade's 34 tables in `upgraded.structure`.

**The corrected restore (`restore-historical`).** Every precondition is established **by SQL alone** before any destructive statement, because a database in the WP3-01 state has the newer tables and the older ledger, a state no application can be expected to boot against: nothing starts Laravel against it before the clearing.

| Before the clearing | Refused unless |
| --- | --- |
| prepared source | as above |
| backup | the snapshot, manifest (this run, the v0.3.0 commit, its tables agreeing with the snapshot) and dump (size, SHA-256, two-pass contract) are intact |
| keyring | every key fingerprint the manifest requires is in the live keyring |
| schema | exactly the 34 tables, each a plain `BASE TABLE`; no view, sequence, trigger, routine or event: anything else is refused, never added to the deletion |
| state | either the upgraded state (14 people, 1 profile, 29 migrations) or what `reproduce-wp3-01` leaves (13, 1, 15, 1 profile without a Person) |
| identity | the allowlisted database, this run's sentinel and token, the application account's identity and grants, as for every statement; no root fallback |

Then: one statement as the application account, `SET FOREIGN_KEY_CHECKS=0; DROP TABLE` for the 34 names taken from this script's own lists (never from what the database returned), no `IF EXISTS`, no `DROP DATABASE`, no pattern; the inventory must then be empty; the dump's checksum is verified again; the dump is loaded. After it the historical application's own snapshot must show: exactly the 16 tables; every durable table identical to ORIGINAL (the administrator, role assignments, MFA factors, recovery codes and membership grants included); every transient table present and empty; the ledger the 15 migrations; the structure of all 16 tables the original's; and the marker Person and profile gone (13 people, no `contact_profiles`).

**Sign-in (`verify-signin`).** The restored v0.3.0 application signs the administrator in over its real HTTP stack, in the harness process: its kernel and middleware, `/api/v1/me` for the session and CSRF cookies, `POST /api/v1/login` (password; expects the 202 second-factor challenge), `POST /api/v1/mfa/challenge` (a TOTP code), then `GET /api/v1/me` (the administrator, with `console.access` and `access.roles.assign`). The session is the application's own database session. Only the socket is missing: requests are made in-process, as the application's feature tests do. The code is generated by `Tests\Support\Totp`, the tests' independent RFC 6238 implementation, so the platform's verification is not checked against itself.

The fixture's own passwords and TOTP secrets are random and forgotten, so for the administrator `fixture-load` replaces the password hash and the TOTP ciphertext with values derived from the run's synthetic `APP_KEY` (HMAC-SHA256 of a fixed label; nothing is stored or printed), and encrypts the TOTP secret under the **previous** key. Signing in after a restore therefore needs the whole keyring, which the helper proves: it first requires that the current key alone cannot read the enrolment. All of it runs in a transaction that is rolled back; the helper prints the table digests after the rollback and the script requires them to be exactly RESTORED. The application's logging is switched off for it, because an exception's message carries a statement and its bindings. The difference from a browser is only the transport; a decrypted secret on its own would not have been enough, and is not what is tested.

**Re-upgrade (`reupgrade`).** Starts only from the verified restored state with a working sign-in. It runs the current `migrate`, then requires exactly the 34 tables (the duplicate-table failure of WP3-01 did not occur), every v0.3.0 table as UPGRADED-with-transients-emptied (renamed roles, 29-entry ledger, durable records preserved), the structure of all 34 tables equal to the first upgrade's, and no marker (13 people, no profile).

**Restore compatibility with the WP3-01 state.** `restore-historical` accepts exactly the state `reproduce-wp3-01` leaves, and the upgraded state; the tests build both. It never runs a helper before the clearing (a test records which helpers had run at the moment of the `DROP`).

**What the SQLite check shows.** `scripts/tests/rehearsal-harness-sqlite.sh` (not part of `./flow check repo`; needs PHP with `pdo_sqlite`, Composer offline, the v0.3.0 commit and `apps/platform/vendor`) runs the harness against the real v0.3.0 application and the real current one in a temporary directory, with the script's own functions reading what it prints. It confirms: the fixture loads; the sign-in succeeds over the real HTTP path and changes nothing; it fails, still changing nothing, without the previous key and under a different key; the two application trees agree on every digest after the upgrade; the original, restored and re-upgraded states compare as described; the structure is the same after the re-upgrade as after the first upgrade. It cannot show the MariaDB behaviours (information_schema, `mariadb-dump`, the foreign keys, the clearing and loading), which stay for L2.

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
| G10 | The runner and preparation containers are checked against what Docker created, before they start: name, image ID, labels, run token, no published port, not privileged, all capabilities dropped, `no-new-privileges`, read-only root, uid/gid 33, network, mounts (only the source volume, read-only for the runner), tmpfs, limits. The source volume is a plain local labelled volume used only by them. Source comes from verified commits only; an existing volume is never adopted. After the harness is copied in, its digests are taken inside the volume and must equal the checkout's (slice 2.2B). |
| G11 | `run` takes a version and one approved name and builds the whole argument vector; nothing else is accepted. |
| G12 | Application-user SQL uses the restricted client, verifies the account's identity and grants before every use, and never falls back to root. The dump client follows the same rule. |
| G13 | The stages each start from the state the previous one left, checked against the database and the state directory (never a flag): the prepared source (both commits and every helper script) as `prepare` recorded it, a clean checkout, an empty target, the exact 16-table schema, a complete fixture and snapshot (a helper that did not finish is refused), a database that still equals its snapshot, the exact 34-table schema, a verified backup before the upgrade. |
| G14 | Encryption keys are per-run synthetic values in the private state file, validated before use, passed to the runner only as environment names, and never in the manifest, a log or an argument; the application's own key fingerprints must be the generated keys'. |
| G15 | The backup is checked before anything relies on it: structure of every table, rows for durable tables only, no database named, a manifest that agrees with the snapshot and this run, and the dump's size and SHA-256 re-verified before it is loaded; an existing backup is never overwritten. |
| G16 | The WP3-01 demonstration loads only the verified dump, only into the verified upgraded rehearsal database, only after the backup's keyring is shown to be present, and succeeds only if exactly the newer tables remain. |
| G17 | The corrected restore clears and loads only after every precondition holds, established by SQL alone: the verified backup, the keyring, the exact 34 plain tables with no view, trigger, routine or event, and one of the two states the earlier stages leave. It clears only the tables it names, confirms they are gone, re-verifies the dump, and requires the restored database to equal the original snapshot (transient tables emptied), in rows and structure. |
| G18 | The restored application signs in over its real HTTP stack, with a TOTP secret that needs the previous key, and the database afterwards is exactly what it was. |
| G19 | The re-upgrade starts from the verified restored state and must give the first upgrade's inventory, data and structure, with no marker. |

## Tests

`scripts/tests/rehearsal.sh` (run by `./flow check repo`) creates no container, network, volume or database. It runs the script against a fake `docker` in a private tool directory that contains no real `docker`, so a mistaken test cannot reach the daemon. It also renders the committed `compose.yaml` with `docker compose config`, which starts nothing.

It then runs a mutation matrix: for each guard it replaces the marked line with `:` in a scratch copy and requires a test to fail. A marker without tests, or tests without a marker, fail the run. Markers sit on the safeguards' bodies and also on the call sites whose removal would be a safety loss: the environment refusal in `up`, the state-directory checks in `cleanup`, the container, network, volume and version checks `up` makes before it writes to the server, and the identity and version checks every SQL statement is preceded by. Properties a line replacement cannot express are pinned by tests of their own: that the state directory is only ever emptied of the files the script wrote and removed with `rmdir`, never recursively (including an entry that appears while it is being emptied), and that a dry run against a complete project leaves every Docker object, every state file, its content and its permissions as they were (only the lock file is touched: the script opens it to take the lock).

Slice 2.2A adds tests for each stage and guard. The fake stands in for the PHP harness too: for each helper a test says what it prints, what it changes in the world afterwards, or that it fails. The three state files the later stages need are made once by the real script's earlier stages and copied. Lists the script holds (the 16 historical tables, the 18 newer ones, the 7 transient ones, the fixture's row counts) are pinned against `HistoricalSnapshot::TABLES`, `HistoricalFixture::NEW_TABLES`, the deployment runbook's `--ignore-table` list and the harness, and, where PHP is installed, the harness's own functions are run (fingerprint vectors, and the lines it prints parsed by the script's own reader). The mutation matrix runs each test in a subshell and stops it at its first failed assertion, which cut the matrix's time by more than a third.

At the end of slice 2.2A the file has 191 tests and 169 guard mutants, all caught, and takes about 4m53s on the development machine (WSL2), against 162 tests, 130 mutants and 3m07s before the slice.

Slice 2.2B adds tests for the prepared-source record, the snapshot semantics, the restore, the sign-in and the re-upgrade, and 27 guard markers (the fake daemon gains the schema-object queries, the clearing statement and the sign-in and digest helpers). It leaves 206 tests and 196 guard mutants, all caught, and the full file takes about 10m55s on the same machine, against 4m53s after slice 2.2A. The growth is the number of worlds the new tests build (each stage test runs the real script several times) multiplied by the mutants they must catch; the matrix runs mutants one after another because they share the fake daemon's world. The SQLite check of the harness (`scripts/tests/rehearsal-harness-sqlite.sh`) takes under a minute and is separate.

The fake daemon fails any call on request (so a failed listing can be tested), rejects an `exec` with no container, renders an absent label as `<no value>` and refuses to render a bind-mounted container, each as the real daemon does.

## Limits

- The fake daemon models the inspect fields the script uses. Those templates and the listing filters were also run against a real container, network and volume (read-only) when this slice was written. L1 then confirmed the remaining semantics against the real daemon, including Compose's behaviour with `--wait` on an internal network (`up` reached healthy).
- The image is pinned by tag, and the server version is checked separately. It is not pinned by digest.
- A stopped container that is attached to the rehearsal network does not appear in the network's `.Containers`, so the "foreign container attached" check cannot see it. Removing the network then does not harm that container (it only loses a network it could not start on), and a volume used by any container, stopped or not, is still seen and kept.
- The container inspect template cannot be rendered for a container with a bind mount (the real daemon errors). Such a container is treated as not the rehearsal database and kept.
- Cleanup checks the state directory before it removes anything, but an entry written by the same user in the instant after that check can still make the final `rmdir` fail. Cleanup then exits 1 with the marker already removed, so the directory is no longer recognised as the script's; the entry is left untouched and the directory has to be dealt with by hand. There is no automatic recovery from that exceptional condition.
- Slice 2.2 must send only fixed, validated SQL: the database allowlist (G6) constrains the `-D` argument, not the statements, and there is deliberately no SQL parser. `run` builds the complete Artisan argument vector itself (G11), so no caller-supplied option reaches a migration.
- The fake-daemon tests could not show the following, and L1 settled each on the real daemon (one run, one machine, Docker 29.9.2): the MariaDB 10.11.18 client supports `--sandbox`; the empty source volume is writable by uid 33; `SHOW GRANTS` prints `flc\\_rehearsal\\_app` with two backslashes, which the comparison accepts; the S2.1 inspect templates (`EPHEMERAL_FMT`, `ROLE_FMT`) render, and the runner's identity check passes against the daemon's real mount listing (the script handles a daemon that lists `tmpfs` mounts among `.Mounts` or not; L1 does not record which this one does). A different daemon version or platform is not covered.
- Not exercised live in L1: `migrate`, `migrate:status`, `release:show`, `relationships:check`, `resources:assets:prune` and the refusal paths (they are tested against the fake daemon only), and any cleanup that has to keep or refuse an object.
- **Host gateway residual.** `internal: true` withholds a route to external networks and publishes nothing, but Docker does not promise that a container on an internal network cannot reach a service listening on that network's bridge gateway address (the host side of the bridge). The rehearsal does not claim complete host isolation, and reading the network's IPAM configuration is not proof that host services are unreachable. What the design does guarantee: no port is published, there is no route to the internet or to other development networks, and the runner holds no credential but the synthetic application password. Whether a host service answers on the gateway is a fact about the machine. The owner accepted this residual for the synthetic rehearsal without measuring it: the optional connect test (L1 step 8) was deliberately omitted, so the gateway behaviour on this machine is **unmeasured**, not shown safe. It should be measured, with the owner's approval, before the rehearsal is given anything beyond synthetic data. No firewall or extra network infrastructure is added for it; a finding there is a decision for the owner.
- The runner and the preparation container share the development image, so the image's PHP settings (`zz-flow-dev.ini`) apply. Its `APP_KEY` and `APP_PREVIOUS_KEYS` are the run's synthetic keys (slice 2.2A), passed by name through the launcher; like the application password they are in the runner's environment while it exists, so `docker inspect` of a running runner shows them, and they protect nothing real.
- An interrupted credentials write can leave a `.compose.env.*` temporary file (mode 600) in the state directory; cleanup treats it as another entry (above), names it and keeps the directory until you remove it; `run.lock` is removed while held, which allows a negligible race; `stat -c` is GNU-specific.
- Identity labels and the run token guard against mistakes. They are not a defence against someone who can already create arbitrary Docker objects on the machine.

**Slice 2.2A limits.**

- **Never run live.** Everything above is tested against the fake daemon. The PHP harness (`rehearse.php`) has never run against a database: its non-database code (fingerprints, digests, the expected-upgrade lines built from the real `HistoricalSnapshot::expected`) was run on the host, but booting the application in the runner, loading the fixture, and reading `information_schema` are unconfirmed. L2 must confirm them, and also: that `mariadb-dump` as the application account takes both passes (its option names, including `--no-tablespaces`, are in the 10.11.18 binary; the account's privileges for them are not confirmed), that loading the dump leaves the 18 newer tables with the foreign keys the audit measured, that PDO returns integers for the ledger so the expected-upgrade digests match, and the stage timings.
- **The prepared source's commits were not recorded in the volume** in this slice; slice 2.2B records them (see above) and every stage checks them.
- **The snapshot detects, it does not describe.** It is digests, so it says that a table changed, and which, not how.
- **Not the whole backup.** The dump is not compressed (the checksum is of the file that is loaded), the Resource files archive and its manifest fields are slice 2.2C's, and nothing here reconciles invitations or other security-sensitive changes after a restore.
- **A stage that stops part-way can leave the database partly changed** (a migration, a fixture, a load). The rehearsal is disposable: `cleanup`, then `up`, `prepare` and the stages again. A temporary `.dump.*` or `.<file>.*` file left by an interrupted write is another entry that keeps the state directory, as for `.compose.env.*`.
- The demonstration proves the defect with this fixture and these 34 tables. It does not prove that nothing else would differ in a different failure, such as a half-applied migration (slice 2.2C).

**Slice 2.2B limits.**

- **Never run live.** The harness has run against the real applications on SQLite, not against MariaDB. L2 must confirm: `information_schema.tables`, `.triggers`, `.routines` and `.events` are readable by the application account and report what the stages expect; the one `DROP TABLE` statement (34 names, foreign keys off for its session) works through the restricted client; the dump loads as the application account with the foreign keys it carries; the structure digests (Laravel's schema introspection on MariaDB) are equal before and after a round trip through the dump; PDO's value types do not matter (digests are of text); the in-process HTTP sign-in works in the runner (read-only root, `session.driver=database`, trusted hosts from `APP_URL`).
- **The sign-in is in-process.** It uses the application's kernel, middleware, cookies, CSRF check and database session, but not a socket, a web server or a browser, and it runs inside a transaction that is rolled back. A behaviour that depends on a commit (a hook after commit) is not exercised.
- **The administrator's TOTP secret is derived from the run's key** and the previous key encrypts it, so the keyring is exercised for one enrolment; the other factors, and the recovery codes, are verified as restored rows (digests) and not used to sign in.
- **A docs-only commit after `prepare` needs a new `prepare`**, because the record names `HEAD`.
- **A stage that stops after the clearing leaves an empty database** (or a half-loaded one). The rehearsal is disposable: `cleanup`, then `up`, `prepare` and the stages again. There is no automatic recovery.
- The structure comparison covers columns, indexes and foreign keys as the engine reports them; it does not cover table options (engine, collation of the table), triggers or grants, which the schema check refuses or the dump does not carry.
