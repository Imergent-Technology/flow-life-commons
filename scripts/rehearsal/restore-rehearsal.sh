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
#   restore-rehearsal.sh baseline     (slice 2.2A) build the v0.3.0 schema with the v0.3.0 application, load the synthetic fixture, snapshot it
#   restore-rehearsal.sh take-backup  (2.2A) the documented two-pass dump of that database, and its manifest
#   restore-rehearsal.sh upgrade      (2.2A) run the current migrations, judge the result, add one synthetic post-upgrade marker
#   restore-rehearsal.sh reproduce-wp3-01
#                                     (2.2A) DEMONSTRATE the audited defect: load the dump over the upgraded database without clearing it
#   restore-rehearsal.sh restore-historical
#                                     (slice 2.2B) the corrected restore: clear exactly the 34 tables, load the verified dump, verify the result
#   restore-rehearsal.sh verify-signin
#                                     (2.2B) the restored v0.3.0 application signs the administrator in with password and TOTP; nothing changes
#   restore-rehearsal.sh reupgrade    (2.2B) the current application's migrations on the restored database, judged against the first upgrade
#   restore-rehearsal.sh half-fail-upgrade
#                                     (slice 2.2C-1) from the RESTORED database: place one stub table where the Relationships migration will
#                                     find it, run the real `migrate` twice, and require the half-failed schema that leaves
#   restore-rehearsal.sh revoke-after-backup
#                                     (2.2C-1) from the UPGRADED database: remove one role assignment and record its event, after the backup,
#                                     so that the restore can be seen to reverse it
#   restore-rehearsal.sh seed-files   (slice 2.2C-2) from the UPGRADED database: four synthetic files (uploads made after the upgrade) in the files
#                                     volume and one asset row for each
#   restore-rehearsal.sh backup-files (2.2C-2) the Resource-files half of a backup: the directory held to the asset rows, then a zero-file record, or
#                                     an archive (the runbook's tar command) and a manifest
#   restore-rehearsal.sh aside-files  (2.2C-2) with a verified files backup: rename the live Resources directory to resources.before-restore-<UTC time>,
#                                     measure that what moved is what was there; nothing is deleted
#   restore-rehearsal.sh restore-files
#                                     (2.2C-2) verify the files backup, then extract it where there is no Resources directory (the database restore
#                                     is not part of it)
#   restore-rehearsal.sh backup-paired
#                                     (slice 2.2C-3, WU4) from the UPGRADED database with its seeded Resource files: measure the 34 tables and the Resources
#                                     directory, take the two-pass dump and the files archive, measure again, and bind both in recovery.manifest.json. Changes
#                                     no database and no volume; its only writes are four files in the state directory, put in place last and never over a file
#   restore-rehearsal.sh preserve-failed-state
#                                     (2.2C-3, WU3) read-only on the database and the Resource files: measure, export the failed state (the fixed two-pass dump), measure
#                                     again, and write failed-state.dump.sql then preserve.record into the state directory, never over a file. Evidence, not a rollback.
#                                     restore-historical refuses to clear Resource records or files unless this record is intact and equals them (R-preserve)
#   restore-rehearsal.sh verify-aside (2.2C-3, WU3) read-only: the directory aside-files moved aside is what aside.record and the preservation say, a sibling of the
#                                     active Resources directory, which is absent; nothing is moved back
#   restore-rehearsal.sh verify-zero-resources
#                                     (2.2C-3, WU3) read-only: no resource_assets row and no active Resources directory (the historical zero-file state)
#
# WHAT THIS IS. A rehearsal environment for proving, with synthetic data on a local machine, that the organization can recover
# v0.3.0 after an unsuccessful upgrade (docs/audits/g6/README.md, WP3c). Slice 1 is the isolated database server and the checks that
# make it safe to build on; slice 2.1 adds the disposable PHP runner; slice 2.2A adds the first half of the recovery cycle; slice 2.2B the second.
#
# WHAT THIS IS NOT. A general restore or backup tool, and not an automatic rollback. There is no command that takes a file, a database
# name or a statement from the caller, and none may be added without the owner's decision (the runbooks say ./flow has no
# backup/restore either, and this script is deliberately not a ./flow command). The statements that change the rehearsal database are
# exactly these: the application's own migrations, the fixed fixture and marker helper, the loading of the one dump this script took by
# `reproduce-wp3-01` (which exists to show a defect and is not a restoration), and, in `restore-historical`, the DROP TABLE of exactly the 34
# tables (or, from the one half-failed state `half-fail-upgrade` makes, exactly the 32) this script names followed by the loading of that same dump.
# Slice 2.2C-1 adds two more, each a fixed statement or helper: the one stub CREATE TABLE of `half-fail-upgrade` and the one role assignment and
# event of `revoke-after-backup`. Slice 2.2C-2 adds one: the INSERT of the four asset rows of `seed-files`, from values it has validated twice. `restore-historical` takes no argument, no file and no database, works only on the allowlisted rehearsal
# database, and is not a restore tool for anything else.
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
#   G13 the stages of the first half of the cycle each start from the state the previous one left, checked against the database and the
#       state directory themselves (never a flag): verified commits and a clean checkout, an empty target, the exact 16-table
#       historical schema, a complete fixture, a database that still equals its snapshot, the exact 34-table upgraded schema
#   G14 encryption keys are per-run synthetic values in the private state file, validated before use and handed to the runner only
#       through its environment; the manifest holds fingerprints, never keys, and they must agree with what the application used
#   G15 the backup is checked before anything relies on it: every table's structure, rows for durable tables only, no database named,
#       a manifest that agrees with the snapshot, and the dump's byte count and SHA-256 re-verified before it is loaded
#   G16 the WP3-01 demonstration loads only that verified dump, only into the verified upgraded rehearsal database, only after the
#       backup's keyring is shown to be present, and succeeds only if the newer tables remain (the table-inventory equality fails)
#
#   G17 the corrected restore clears and loads only after every precondition holds, established by SQL alone (no application is started
#       against a schema that may be inconsistent): the verified backup and manifest, the keyring, the exact 34-table inventory of plain tables
#       with no view, trigger, routine or event, and one of the two states the earlier stages leave; it clears only the tables it names,
#       confirms they are gone, re-verifies the dump, and then requires the restored database to equal the original snapshot
#   G18 the restored application signs in over its real HTTP stack, and the database after is exactly what it was
#   G19 the re-upgrade starts from the verified restored state and must give the first upgrade's inventory, data and structure, with no marker
#
#   G20 (slice 2.2C-1) the half-failed upgrade, and the one new state the corrected restore will clear. `half-fail-upgrade` starts only from the verified
#       restored database, places a stub whose statement is fixed, and requires the real failure (class, SQLSTATE, driver code, the table) twice, the
#       historical rows unchanged, the committed tables' structure unchanged (contact_methods as its CREATING migration left it: the later provenance
#       migration never runs), and the stub not the real table. `restore-historical` then recognises that
#       state, and only that, by SQL alone: exactly the 32 tables of this script's own lists as plain tables, the 25 migrations in order, the
#       stub as the stub, every newer table empty, the durable tables' row counts, and the schema and the security tables as `half-fail-upgrade`
#       recorded them. Anything else, an unknown table or object, a missing or reordered migration, a changed structure or row of the security
#       columns the digest covers, is refused. A same-count change to a row elsewhere is NOT detected here (only the counts are); `half-fail-upgrade`
#       checked every row when it made the state, and the restore verifies every row against the snapshot afterwards.
#   G21 (slice 2.2C-1) a security change made after the backup: `revoke-after-backup` removes one role assignment and records its `role.revoked` event;
#       `restore-historical` reports, from the database itself, that the restore reversed both. No reconciliation, replay or repair exists.
#
#   G22 (slice 2.2C-2) the Resource files. A files volume of the rehearsal's own and a files container with no network, no credential and no application,
#       both proven before use and removed only if proven; the files helper's output validated before anything relies on it; the database rows read by two
#       fixed statements and refused unless each is a storage key, a size and a SHA-256; `seed-files` only from the exact post-upgrade state; `backup-files`
#       only from the 16- or 34-table database, only when the directory and the rows agree on every key, size and checksum, never overwriting; a files
#       manifest and archive validated against the run, the contract and each other before a restore relies on them; `restore-files` verifies before it
#       writes and extracts only into a volume with no Resources directory; `aside-files` renames and deletes nothing. The file and archive logic itself is
#       apps/platform/tests/Support/ResourceFileArchive.php, whose own guards (`F-*`) are held by tests/Unit/Rehearsal/ResourceFileArchiveTest.php.
#
#   G25 (slice 2.2C-3, WU4) the CURRENT profile of the backup: 34 tables (27 durable, 7 transient), 29 migrations, and one recovery unit for the database and the Resource
#       files. The historical profile (16 tables, 9 durable) and its functions are unchanged; no profile is chosen by the caller. `snapshot-all` is a read-only helper of its own
#       (the 34 tables' rows as digests in primary-key order, their structure, the ledger, the keys), validated as completely as the historical snapshot is. `backup-paired`
#       starts only from the upgraded 34-table database with the four seeded Resource files and their asset rows, measures database and directory before and after BOTH artifacts
#       and refuses any difference, checks the dump line by line against the forms in current_dump_problem (an unrecognised statement is a refusal, a durable table with no rows
#       needs no INSERT, and every durable table's rows are those the first measurement counted), verifies the archive, and puts nothing in place until the whole recovery unit is
#       verified (recovery_unit_problem: the artifacts' integrity and the dump's and the manifest's structural consistency; it does NOT read a row's content from the dump, and
#       what the restored database holds is for the restore's own verification to establish). It claims no change was OBSERVED, never an atomic snapshot (cmd_backup_paired
#       says why). Nothing is overwritten and the recovery manifest is the last file put in place: without it the others are not a recovery unit.
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
readonly HARNESS_FILES=(db-ping.php probe.php rehearse.php files.php)
# The test-support classes the helpers load BY PATH from the current tree of the source volume (rehearse.php's and files.php's loadSupport(), and nothing else: no
# autoload-dev is installed). Each is attested individually: the digest the verified commit holds is compared with the one taken inside the volume, and recorded.
readonly SUPPORT_DIR='tests/Support'
readonly SUPPORT_FILES=(CurrentSnapshot CurrentSnapshotRefusal HalfFailedShape HistoricalFixture HistoricalSnapshot ResourceFileArchive ResourceFileRefusal Totp UpgradeDatabase)
# Slice 2.2C-2. The Resource FILES: one more rehearsal-owned, labelled volume (the files volume, whose root is the application's storage/app/private, so its
# `resources` directory is the private Resources disk's root) and one more disposable container (the files container: no network, no credentials, no
# application, no Docker socket; the prepared source read-only and the files volume, read-only unless the action writes). tests/Support/ResourceFileArchive.php
# does the work; scripts/rehearsal/php/files.php is its fixed command line.
readonly FILES_NAME='flc-rehearsal-restore-files'
readonly FILES_VOLUME_NAME='flc-rehearsal-restore-files-data'
readonly FILES_MOUNT='/var/www/html'      # an existing www-data-owned directory of the image, so a new volume copies its ownership here, as the source volume does at SRC_MOUNT
readonly FILES_SRC_MOUNT='/rehearsal/src' # the prepared source in the files container; only the helper and its two support classes are read from it
readonly FILES_STORE_DIR='resources'      # inside the volume (whose root is storage/app/private): the private `resources` disk's root, storage/app/private/resources (config/filesystems.php); ResourceFileArchive::STORE
# Slice 2.2C-3 (WU2). The PHP runner may see the files volume for exactly one operation, `resources:assets:prune --dry-run` in the current tree, read-only. The volume
# is mounted at a path of its own, NOT under the application, because Docker copies an image directory's content into an empty named volume that is mounted over it,
# and because the files volume's root is the application's `storage/app/private` (the files helper mounts it at FILES_MOUNT), so the Resources directory is
# `<volume>/FILES_STORE_DIR` and the application's `storage/app/private/resources` is a link, made inside the runner's own temporary storage (a tmpfs, gone with
# the container), to that directory. See RUNNER_FILES_SETUP.
readonly RUNNER_FILES_MOUNT='/rehearsal/files'
readonly FILES_ARCHIVE_FILE='resources.archive.tar.gz'
readonly FILES_MANIFEST_FILE='resources.manifest.json'
readonly FILES_VOLUME_MARKER='files.volume' # written once the files volume has been created and verified: from then on a missing volume is refused, never recreated
readonly FILES_SEED_COUNT=4
readonly FILES_SEED_PERSON='01jbp000000000000000000001' # HistoricalFixture::person(1), the administrator: provenance only, there is no foreign key
readonly KEY_RE='^[0-7][0-9a-hjkmnp-tv-z]{25}$'          # App\Modules\Resources\Domain\StorageKey::PATTERN
readonly FILE_LINE_RE='^    \{ "key": "([0-7][0-9a-hjkmnp-tv-z]{25})", "bytes": (0|[1-9][0-9]{0,11}), "sha256": "([0-9a-f]{64})" \},?$'
readonly FILES_TABLE_SQL="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$APP_DB' AND table_name = 'resource_assets';"
readonly FILES_ROWS_SQL='SELECT storage_key, byte_size, sha256 FROM resource_assets ORDER BY storage_key;'
readonly EPHEMERAL_MEMORY=1073741824
readonly EPHEMERAL_NANOCPUS=1000000000
readonly EPHEMERAL_PIDS=256
readonly ROOT_MARKER_NAME='.flc-rehearsal-root'
readonly ROOT_MARKER_TEXT='restore-rehearsal-v1'

# Slice 2.2A. The 16 tables v0.3.0's 15 migrations make (`migrations` included), the 18 the 14 later migrations add (34 together), and the
# seven the backup keeps the structure of and none of the rows (deployment runbook, "Taking a backup"). scripts/tests/rehearsal.sh pins
# each list against the support classes the PHP harness reads (HistoricalSnapshot::TABLES, HistoricalFixture::NEW_TABLES) and the runbook.
readonly HISTORICAL_TABLES=(account_invitations account_recovery_codes account_totp_factors accounts cache cache_locks failed_jobs job_batches jobs membership_grants migrations password_reset_tokens people role_assignments security_events sessions)
readonly NEWER_TABLES=(contact_interactions contact_methods contact_profiles contact_tag_assignments contact_tags discussion_messages discussions person_relationship_field_values person_relationship_role_provisions person_relationship_status_changes person_relationships resource_assets resource_card_audiences resource_cards resource_categories resource_pack_audiences resource_packs sourced_role_grants)
readonly TRANSIENT_TABLES=(cache cache_locks failed_jobs job_batches jobs password_reset_tokens sessions)
readonly FIXTURE_PEOPLE=13 # the fixture's Persons (HistoricalFixture::load); the harness checks every table's count, this is the one stated here
readonly RELEASE_VERSION='v0.3.0'
readonly SNAPSHOT_FILE='historical.snapshot'
readonly DUMP_FILE='historical.dump.sql'
readonly MANIFEST_FILE='historical.manifest.json'
readonly PREPARED_FILE='prepared.source'      # slice 2.2B: what `prepare` put in the source volume (the two commits, the helper scripts' digests)
readonly UPGRADED_FILE='upgraded.structure'   # slice 2.2B: the structure digests of the 34 tables after the first upgrade, for the re-upgrade to equal
readonly HALF_FILE='halfmigrated.state'       # slice 2.2C-1: what `half-fail-upgrade` observed and verified, for `restore-historical` to compare the database with
# Slice 2.2C-3 (WU4). The CURRENT-schema profile of the backup: the 34 tables (HISTORICAL_TABLES and NEWER_TABLES), the same seven of them transient and the other 27
# durable, the 29 migrations, and one RECOVERY UNIT that binds the database dump to the Resource files. The historical profile (16 tables, nine durable) and everything
# it uses is not touched: a function of this script is for one profile by its name, or by an argument the stage that calls it fixes. No profile is chosen by whoever
# runs the script, and no stage takes a file, a table, a database or a statement from the caller.
readonly CURRENT_DUMP_FILE='current.dump.sql'                # the two-pass dump of the 34-table database (the historical profile's is $DUMP_FILE)
readonly RECOVERY_MANIFEST_FILE='recovery.manifest.json'      # the commit point of a paired backup: it exists only when the dump, the archive and both manifests were verified
readonly RECOVERY_MANIFEST_VERSION=1
readonly PRESERVE_DUMP_FILE='failed-state.dump.sql'           # slice 2.2C-3 (WU3): the export of the failed state: evidence, never a restore source
readonly PRESERVE_RECORD_FILE='preserve.record'               # (WU3) the commit point of preserve-failed-state: what the failed state was, binding the export
readonly PRESERVE_RECORD_VERSION='preserve-v1'
readonly ASIDE_RECORD_FILE='aside.record'                     # (WU3) what aside-files measured when it renamed the Resources directory
readonly ASIDE_RECORD_VERSION='aside-v1'
readonly CURRENT_RELEASE_VERSION='unreleased'                 # the tree under test is a commit, not a tag: `release.json` is made by `./flow release build` and is not in a `git archive`
readonly CURRENT_LEDGER_COUNT=29                              # the migrations of the current schema: v0.3.0's 15 and the 14 after; scripts/tests/rehearsal.sh pins both against the repository
readonly CURRENT_LAST_MIGRATION='2026_10_11_000001_add_provenance_to_contact_methods'
readonly BT=$'\x60' # a backtick, for the dump's quoted table names
readonly BS=$'\\' # a backslash, which a comment of the dump may not hold
# The only line forms a current-profile dump may hold (current_dump_problem); a line of any other form is refused. Each is a pattern for ONE line and is as narrow as the
# real tool's output allows: a variable, a column type or an ENGINE option this list does not name is a refusal, so that what is accepted is only what is recognised.
# They are NOT a SQL parser and they do not prove an arbitrary dump safe: they decide whether the dump is made only of the statement forms the fixed two-pass contract
# writes. What they were composed from, and what has not been seen from a real 34-table dump, is the development note's "WU4" section.
readonly DUMP_COMMENT_RE=$'^--( [ -~\t]*)?$'                    # `--` alone, or `-- ` and printable ASCII or tabs (a backslash is refused beside it: current_dump_problem)
readonly DUMP_SANDBOX_RE='^/\*M!999999\\- enable the sandbox mode \*/ ?$' # mariadb-dump 10.19 of MariaDB 10.11.18 writes ONE space after `*/` (live diagnostic, 2026-11-20): none or one, and nothing else
# A conditional `SET` is one of the lines in dump_set_kind, byte for byte, and no other: the pairs mariadb-dump 10.19 of MariaDB 10.11.18 writes, as a live two-pass dump of the
# 34 tables showed them (2026-11-20). Nothing is matched by pattern, so a user variable cannot be given another system variable than its own, a value cannot be another, and a
# statement cannot hide in a comment the server would run. Two settings change how the rest of the file is READ, and current_dump_problem follows them: sql_mode (a dump could
# otherwise switch on NO_BACKSLASH_ESCAPES, which turns the backslash in a string into data) and the client character set (a multi-byte set can swallow a backslash). Rows are
# accepted only while the pinned SQL mode and `utf8mb4` are in effect, and the pin, the per-table save and the closing restores must come in the order the tool writes them.
readonly DUMP_KEYS_RE="^/\\*!40000 ALTER TABLE ${BT}([a-z0-9_]+)${BT} (DISABLE|ENABLE) KEYS \\*/;\$"
readonly DUMP_DROP_RE="^DROP TABLE IF EXISTS ${BT}([a-z0-9_]+)${BT};\$"
readonly DUMP_CREATE_RE="^CREATE TABLE ${BT}([a-z0-9_]+)${BT} \\(\$"
readonly DUMP_INSERT_RE="^INSERT INTO ${BT}([a-z0-9_]+)${BT} VALUES\$"
readonly DUMP_LOCK_RE="^LOCK TABLES ${BT}([a-z0-9_]+)${BT} WRITE;\$"
# The body of a CREATE TABLE, one line each, as SHOW CREATE TABLE writes the 34 tables of the current schema (apps/platform/tests/Fixtures/rehearsal/current-create-tables.sql
# holds them, and a test holds that file to the migrated schema): a column (the types and options the schema uses), PRIMARY KEY, [UNIQUE] KEY and a FOREIGN KEY, each with
# its comma except the last line; and the closing line. A column type, a table option or an ENGINE the schema does not use is not accepted.
readonly DUMP_ID="${BT}[a-z0-9_]+${BT}"
readonly DUMP_IDS="${DUMP_ID}(,${DUMP_ID})*"
readonly DUMP_COLUMN_RE="^  ${DUMP_ID} ((tiny|small|medium|big)?int\\([0-9]{1,3}\\)( unsigned)?|(var)?char\\([0-9]{1,5}\\)|(tiny|medium|long)?text|datetime|timestamp)( CHARACTER SET [a-z0-9_]+ COLLATE [a-z0-9_]+)?( NOT NULL| DEFAULT NULL)?( DEFAULT ('[^'\\\;]*'|-?[0-9]+|current_timestamp\\(\\)))?( AUTO_INCREMENT)?( CHECK \\(json_valid\\(${DUMP_ID}\\)\\))?,?\$"
readonly DUMP_PRIMARY_RE="^  PRIMARY KEY \\(${DUMP_IDS}\\),?\$"
readonly DUMP_KEY_RE="^  (UNIQUE )?KEY ${DUMP_ID} \\(${DUMP_IDS}\\),?\$"
readonly DUMP_FOREIGN_RE="^  CONSTRAINT ${DUMP_ID} FOREIGN KEY \\(${DUMP_IDS}\\) REFERENCES ${DUMP_ID} \\(${DUMP_IDS}\\)( ON (DELETE|UPDATE) (CASCADE|SET NULL|RESTRICT|NO ACTION))*,?\$"
readonly DUMP_REFERENCES_RE="REFERENCES ${BT}([a-z0-9_]+)${BT} "
readonly DUMP_CREATE_END_RE='^\) ENGINE=InnoDB( AUTO_INCREMENT=[0-9]+)? DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;$'
# One row of an INSERT, on its own line: `(`, one or more values, `)`, and `,` or `;`. A value is NULL, a number, a hexadecimal literal or a quoted string (with the
# backslash escapes mariadb-dump writes); nothing else may stand between the parentheses, so a `;` outside a string, or a second statement, cannot be part of a row line.
readonly DUMP_ROW_VALUE="(NULL|-?[0-9]+(\\.[0-9]+)?([eE][-+]?[0-9]+)?|0x[0-9A-Fa-f]+|(_binary )?'([^'\\\\]|\\\\.)*')"
readonly DUMP_ROW_RE="^\\(${DUMP_ROW_VALUE}(,${DUMP_ROW_VALUE})*\\)[,;]\$"
# One line of the recovery manifest's table list: the table, its class, its rows, the digest of its rows and of its structure.
readonly RM_TABLE_RE='^    \{ "name": "([a-z0-9_]+)", "class": "(durable|transient)", "rows": (0|[1-9][0-9]{0,11}), "row_sha256": "([0-9a-f]{64})", "structure_sha256": "([0-9a-f]{64})" \},?$'
# Slice 2.2C-1. The half-failed upgrade. A stub table is placed where the Relationships migration (the 11th of the 14 after v0.3.0) creates its THIRD table;
# the ten migrations before it complete and commit (MariaDB commits every DDL statement), the migration creates its first two tables and fails on the
# third with error 1050, and its record is never written. tests/Upgrade/HalfFailedMigrationTest.php measures exactly this on a real MariaDB with the real
# migration runner; scripts/tests/rehearsal.sh pins the lists below against the repository's migrations and tables, and STUB_SQL against the fixture the
# test runs. Nothing in any migration is changed, and the stub is the only thing placed.
readonly STUB_TABLE='person_relationship_field_values'
readonly STUB_COMMENT='flc-rehearsal-stub-s22c1'
readonly STUB_SQL="CREATE TABLE \`$STUB_TABLE\` (\`stub\` CHAR(1) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='$STUB_COMMENT';"
# What reading the stub back must print: engine, collation, comment, type, the columns, then the counts of its indexes, of foreign keys naming it, and of its rows.
readonly STUB_EXPECTED=$'InnoDB\tutf8mb4_unicode_ci\t'"$STUB_COMMENT"$'\tBASE TABLE\tstub:char(1):NO\t0\t0\t0'
# The 15 tables the work before the failure leaves (13 from the ten completed migrations, two from the Relationships migration), besides the stub.
readonly PARTIAL_NEWER_TABLES=(contact_interactions contact_methods contact_profiles contact_tag_assignments contact_tags discussion_messages discussions person_relationship_status_changes person_relationships resource_assets resource_card_audiences resource_cards resource_categories resource_pack_audiences resource_packs)
# Of those 15, the 14 that nothing after the Relationships migration changes (their structure equals the first upgrade's), and the one that something does:
# `2026_10_11_000001_add_provenance_to_contact_methods` (the 29th migration, which the half-failed state never reaches) adds a column to contact_methods.
# That table is held to the structure its creating migration left (apps/platform/tests/Support/HalfFailedShape.php), not to the first upgrade's.
readonly PARTIAL_UNCHANGED_TABLES=(contact_interactions contact_profiles contact_tag_assignments contact_tags discussion_messages discussions person_relationship_status_changes person_relationships resource_assets resource_card_audiences resource_cards resource_categories resource_pack_audiences resource_packs)
readonly PROVENANCE_TABLE=contact_methods
# The ledger of that state: v0.3.0's 15 migrations (batch 1), then the ten that completed (batch 2). The Relationships migration is not in it.
readonly HISTORICAL_MIGRATIONS=(0001_01_01_000001_create_cache_table 0001_01_01_000002_create_jobs_table 2026_09_19_000001_create_people_table 2026_09_19_000002_create_accounts_table 2026_09_19_000003_create_account_invitations_table 2026_09_20_000001_create_security_events_table 2026_09_20_000002_create_sessions_table 2026_09_20_000003_create_role_assignments_table 2026_09_20_000004_add_role_key_index_to_role_assignments 2026_09_20_000005_create_password_reset_tokens_table 2026_09_21_000001_create_account_totp_factors_table 2026_09_21_000002_create_account_recovery_codes_table 2026_09_22_000001_add_channel_to_account_invitations 2026_09_23_000001_add_security_generation_to_accounts 2026_09_23_000002_create_membership_grants_table)
readonly PARTIAL_MIGRATIONS=(2026_10_01_000001_create_contact_profiles_table 2026_10_01_000002_create_contact_methods_table 2026_10_01_000003_create_contact_tags_tables 2026_10_02_000001_create_contact_interactions_table 2026_10_02_000002_create_discussions_table 2026_10_02_000003_create_discussion_messages_table 2026_10_04_000001_create_resource_categories_table 2026_10_04_000002_create_resource_packs_tables 2026_10_04_000003_create_resource_cards_tables 2026_10_04_000004_create_resource_assets_table)
# The row counts of the eight durable tables other than the ledger (account_invitations account_recovery_codes account_totp_factors accounts
# membership_grants people role_assignments security_events), in that order: the fixture's, which nothing that happens before the failure changes.
readonly DURABLE_ROWS=$'4\t5\t3\t11\t5\t13\t12\t8'
# Slice 2.2C-1, the security change made after the backup: Person 3's role assignment (the `guardian` the upgrade renamed to `guardian-full`), by its
# fixed ids (HistoricalFixture::person(3) and ::assignment(4)), and the event that records it, by its fixed id (HistoricalFixture::id('E', 9)). The five
# numbers it is read as: role assignments, security events, that assignment present by exact bytes under either key, `role.revoked` events, and the ONE
# event with that id, of that type, about that Person. The fifth is what ties the event to Person 3: the fourth alone counts any `role.revoked` event.
# Before the revocation, and after the restore: 12 8 1 0 0. After the revocation: 11 9 0 1 1.
readonly REVOKE_PERSON='01jbp000000000000000000003'
readonly REVOKE_ASSIGNMENT='01jbr000000000000000000004'
readonly REVOKE_EVENT='01jbe000000000000000000009'
readonly REVOKE_SQL="SELECT (SELECT COUNT(*) FROM role_assignments), (SELECT COUNT(*) FROM security_events), (SELECT COUNT(*) FROM role_assignments WHERE id = '$REVOKE_ASSIGNMENT' AND person_id = '$REVOKE_PERSON' AND BINARY role_key IN ('guardian', 'guardian-full')), (SELECT COUNT(*) FROM security_events WHERE type = 'role.revoked'), (SELECT COUNT(*) FROM security_events WHERE id = '$REVOKE_EVENT' AND type = 'role.revoked' AND subject_person_id = '$REVOKE_PERSON');"
readonly REVOKE_BEFORE=$'12\t8\t1\t0\t0'
readonly REVOKE_AFTER=$'11\t9\t0\t1\t1'
# The sha256 of an empty row list as the harness digests it: what every transient table must show after a restore.
EMPTY_DIGEST="$(printf '[]' | sha256sum | cut -c1-64)"
readonly EMPTY_DIGEST
# Every file this script writes into the state directory, and so every file `cleanup` may remove from it. Nothing else is ever removed.
readonly STATE_FILES=(compose.env run.lock "$ROOT_MARKER_NAME" "$PREPARED_FILE" "$SNAPSHOT_FILE" "$DUMP_FILE" "$MANIFEST_FILE" "$UPGRADED_FILE" "$HALF_FILE" "$FILES_ARCHIVE_FILE" "$FILES_MANIFEST_FILE" "$FILES_VOLUME_MARKER" "$CURRENT_DUMP_FILE" "$RECOVERY_MANIFEST_FILE" "$PRESERVE_DUMP_FILE" "$PRESERVE_RECORD_FILE" "$ASIDE_RECORD_FILE")
readonly REFUSED_ENV_RE='^(COMPOSE_|DB_|MYSQL_|MARIADB_|PG|POSTGRES_|APP_|REHEARSAL_|FILES_)|^DATABASE_URL$'

# The inspect templates. Every expression in them is one the scripts/tests/rehearsal.sh fake daemon understands (it refuses any other),
# and each was run against a real container, network and volume on a live daemon: the Slice 1 templates (CONTAINER_FMT, NETWORK_FMT,
# VOLUME_FMT) while that slice was written, the Slice 2.1 templates (EPHEMERAL_FMT, ROLE_FMT) in the first live validation, L1.
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
                resources:assets:prune) or helper (helper:db-ping, helper:fixture-load, helper:snapshot,
                helper:snapshot-all, helper:post-upgrade-mark, helper:sign-in, helper:half-migrate, helper:revoke) in a disposable PHP runner. The script
                supplies every option.
  probe         Check the runner and the restricted SQL client are isolated as intended. Read-only.
  baseline      Build the v0.3.0 schema with the v0.3.0 application in the empty rehearsal database, load the synthetic
                v0.3.0 fixture, and record a snapshot of it.
  take-backup   Take the documented two-pass dump of that database (as the application account) and write its manifest.
  upgrade       Run the current migrations, judge the result against the snapshot, and add one synthetic marker.
  reproduce-wp3-01
                Demonstrate the audited WP3-01 defect: load the dump over the upgraded database WITHOUT clearing it, and
                require that the newer tables remain. This is not a restoration.
  restore-historical
                The corrected restore. From the upgraded or the WP3-01 state (clear exactly the 34 tables) or from the half-failed
                state half-fail-upgrade made (clear exactly the 32): load the verified dump, and require the 16 historical tables,
                the original rows and structure, and no marker.
  verify-signin The restored v0.3.0 application signs the administrator in (password, then TOTP) and shows /me; rolled back.
  reupgrade     Run the current migrations on the restored database and judge the result against the first upgrade.
  half-fail-upgrade
                From the restored database only: place one stub table, run the real migrations (which fail on it), and require
                the half-failed schema they leave. The one other state restore-historical will clear.
  revoke-after-backup
                From the upgraded database only: remove one role assignment and record its event, after the backup, so the
                restore can be seen to reverse them.
  seed-files    From the upgraded database only: write four synthetic files (uploads made after the upgrade) into the rehearsal's files
                volume and one asset row for each, so that the files and the rows agree. (Slice 2.2C-2.)
  backup-files  The Resource-files half of a backup: hold the Resources directory to the asset rows (an absent directory is a
                zero-file backup only with no rows), then record that, or archive the directory and write its manifest.
  aside-files   Needs a verified files backup. Rename the live Resources directory to resources.before-restore-<UTC time> in the files volume,
                read it before and after, and require the two to be identical. Nothing is deleted.
  restore-files Verify the files backup, then extract it into a files volume that has no Resources directory (or, for a zero-file
                backup, require there to be none). The database restore is not part of it.
  backup-paired From the upgraded database with its seeded Resource files only: measure the 34 tables and the Resources directory, take the
                two-pass dump and the Resource-files archive, measure again, and bind the four files in one recovery.manifest.json. Nothing
                is put in place unless every check passes, and nothing is overwritten. (Slice 2.2C-3, WU4.)
  preserve-failed-state
                From the 34-table database and its Resource files, read-only: measure them, export the failed state with the fixed two-pass dump, measure
                again, and write failed-state.dump.sql and then preserve.record (the commit point) into the state directory, never over a file. Evidence only:
                it is not a rollback and not a restore. restore-historical refuses to clear Resource records or files without it. (Slice 2.2C-3, WU3.)
  verify-aside  Read-only. Hold the directory aside-files moved aside to aside.record and to the preservation: the same keys, sizes and SHA-256 values, a
                sibling of the active Resources directory, which is absent. Nothing is moved back. (WU3.)
  verify-zero-resources
                Read-only. The restored historical state has no resource_assets row and no active Resources directory; the application's prune is not run.
                (WU3.)
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

# A synthetic Laravel encryption key: `base64:` and 32 random bytes (the AES-256-CBC key length the application's config uses).
random_key() { printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"; }

# G3: new credentials and a new run token every run, written once to a private file, never printed. compose.yaml has no env_file and
# no ${VAR} that this file does not define; nothing is read from the application's .env or the development .env. The two encryption
# keys (slice 2.2A) are synthetic and per run, so no key is ever shared between runs or taken from anywhere; compose.yaml does not use them.
write_credentials() {
    local root="$1" tmp
    [[ ! -e "$SCRIPT_DIR/.env" ]] || refuse "a .env file sits beside the compose file ($SCRIPT_DIR/.env); Compose would load it. Remove it." # guard:G3-no-dotenv
    tmp="$(umask 077 && mktemp -- "$root/.compose.env.XXXXXX")"
    (
        umask 077
        printf 'REHEARSAL_DB_ROOT_PASSWORD=%s\n' "$(random_hex 24)"
        printf 'REHEARSAL_DB_APP_PASSWORD=%s\n' "$(random_hex 24)"
        printf 'REHEARSAL_RUN_TOKEN=%s\n' "$(random_hex 16)"
        printf 'REHEARSAL_APP_KEY=%s\n' "$(random_key)"
        printf 'REHEARSAL_APP_PREVIOUS_KEY=%s\n' "$(random_key)"
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

# --- G14: encryption keys and their fingerprints ----------------------------------------------------------------------------------------

# A key in the form Laravel reads: `base64:` and exactly 32 bytes.
key_form_ok() {
    [[ "$1" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] && [[ "$(printf '%s' "${1#base64:}" | base64 -d 2>/dev/null | wc -c | tr -d ' ')" == 32 ]]
}

# state_key ROOT NAME: one of this run's synthetic keys from the private state file, validated before it is placed anywhere.
state_key() {
    local root="$1" name="$2" key
    key="$(grep -E "^${name}=" "$root/compose.env" 2>/dev/null | tail -n 1 | cut -d= -f2-)" || key=''
    key_form_ok "$key" || refuse "the encryption key $name in '$root/compose.env' is missing or is not a base64: key of 32 bytes. A state directory from before slice 2.2A has none: run cleanup, then up again." # guard:G14-key-format
    printf '%s' "$key"
}

# The fingerprint of one key, as the deployment runbook defines it: sha256("flc-key-v1|" + the key's decoded 32 bytes), first 16 hex characters.
key_fingerprint() {
    { printf 'flc-key-v1|'; printf '%s' "${1#base64:}" | base64 -d; } | sha256sum | cut -c1-16
}

# The fingerprint of a keyring: sha256("flc-ring-v1|" + the sorted, de-duplicated key fingerprints joined by commas), first 16 hex characters.
keyring_fingerprint() { # FINGERPRINT...
    printf 'flc-ring-v1|%s' "$(printf '%s\n' "$@" | LC_ALL=C sort -u | tr '\n' ',' | sed 's/,$//')" | sha256sum | cut -c1-16
}

# keyring_missing REQUIRED LIVE: the required key fingerprints (one per line) that are not among the live ones (one per line). A live ring
# that holds every required fingerprint, and more, is the normal state after a rotation; any required fingerprint missing is a stop.
keyring_missing() {
    local required="$1" live="$2" fingerprint
    while IFS= read -r fingerprint; do
        [[ -z "$fingerprint" ]] || in_list "$fingerprint" "$live" || printf '%s\n' "$fingerprint"
    done <<<"$required"
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
    for cname in "$CONTAINER_NAME" "$RUNNER_NAME" "$PREPARE_NAME" "$FILES_NAME"; do
        listing="$(dkr ps -a --no-trunc --filter "name=$cname" --format '{{.ID}} {{.Names}}')" || return 1
        while read -r id name; do
            if [[ "$name" == "$cname" || "$name" == "/$cname" ]] && ! in_list "$id" "$labelled_c"; then say "container ${id:0:12} named $cname"; fi
        done <<<"$listing"
    done
    listing="$(dkr network ls --no-trunc --filter "name=$NETWORK_NAME" --format '{{.ID}} {{.Name}}')" || return 1
    while read -r id name; do
        if [[ "$name" == "$NETWORK_NAME" ]] && ! in_list "$id" "$labelled_n"; then say "network ${id:0:12} named $NETWORK_NAME"; fi
    done <<<"$listing"
    for vname in "$VOLUME_NAME" "$SRC_VOLUME_NAME" "$FILES_VOLUME_NAME"; do
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

server_version_text() { printf 'SELECT VERSION();\n' | mariadb_root "$1" 2>/dev/null; }

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
# The one operation that may see the files volume. runner_command builds its vector from this, and run_in_runner compares what it is asked to run with it, element
# by element, so the two cannot drift apart.
readonly PRUNE_DRY_RUN_CMD=(php artisan resources:assets:prune --dry-run)

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
    local id="$1" token="${2:-}" role="$3" netmode="$4" want_image="${5:-$PHP_IMAGE_ID}" files_rw="${6:-${FILES_RW:-false}}" runner_files="${7:-${RUNNER_FILES:-0}}" out
    out="$(dkr inspect --type container --format "$EPHEMERAL_FMT" "$id" 2>/dev/null)" || { echo "cannot be inspected"; return 1; }
    local name image l_ns l_role l_token ports privileged capadd capdrop secopt readonly_root user mode networks mounts tmpfs memory pids cpus
    IFS='|' read -r _ name image l_ns l_role l_token ports privileged capadd capdrop secopt readonly_root user mode networks mounts tmpfs memory pids cpus <<<"$out"
    local want_name="$RUNNER_NAME" want_rw=false want_tmpfs='/tmp,/var/www/html/current/bootstrap/cache,/var/www/html/current/storage,/var/www/html/historical/bootstrap/cache,/var/www/html/historical/storage,'
    if [[ "$role" == prepare ]]; then
        want_name="$PREPARE_NAME" want_rw=true want_tmpfs='/tmp,'
    elif [[ "$role" == files ]]; then
        want_name="$FILES_NAME" want_tmpfs='/tmp,' netmode=none # the files container has no network at all: not the database's, and not a route out
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
    # Sorted, because the daemon does not promise an order and the files container has two.
    local entry kept='' entries=() want_kept="volume:$SRC_VOLUME_NAME:$SRC_MOUNT:$want_rw,"
    IFS=',' read -ra entries <<<"$mounts"
    for entry in ${entries[@]+"${entries[@]}"}; do
        [[ "$entry" == tmpfs:* ]] || kept+="$entry"$'\n'
    done
    kept="$(printf '%s' "$kept" | LC_ALL=C sort | tr '\n' ',')"
    [[ "$role" != files ]] || want_kept="volume:$FILES_VOLUME_NAME:$FILES_MOUNT:$files_rw,volume:$SRC_VOLUME_NAME:$FILES_SRC_MOUNT:false,"
    # The runner of the prune dry-run (and only that one) has the files volume as well, and read-only: a writable mount, or another path, is refused like any other.
    if [[ "$role" == runner && "$runner_files" == 1 ]]; then
        want_kept="$(printf '%s\n' "volume:$FILES_VOLUME_NAME:$RUNNER_FILES_MOUNT:false" "volume:$SRC_VOLUME_NAME:$SRC_MOUNT:false" | LC_ALL=C sort | tr '\n' ',')" # guard:G24-e-runner-files-mounts
    fi
    [[ "$kept" == "$want_kept" ]] || { echo "its mounts are '$kept', not '$want_kept'"; return 1; } # guard:G10-e-mounts
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

# files_volume_identity_problem NAME TOKEN ALLOWED_USERS: the labelled files volume (slice 2.2C-2), plain and local, used by no container but ours. Its own guards:
# it holds the files a restore is rehearsed with, and cleanup removes it, so what it is must be proven as the source volume's is.
files_volume_identity_problem() {
    local name="$1" token="${2:-}" allowed="${3:-}" out user users='' users_failed=0
    out="$(dkr volume inspect --format "$VOLUME_FMT" "$name" 2>/dev/null)" || { echo "cannot be inspected"; return 1; }
    local v_name driver options l_ns l_role l_token
    IFS='|' read -r v_name driver options l_ns l_role l_token <<<"$out"
    [[ "$v_name" == "$FILES_VOLUME_NAME" ]] || { echo "its name is '$v_name', not '$FILES_VOLUME_NAME'"; return 1; } # guard:G22-v-name
    [[ "$driver" == local && "$options" == 0 ]] || { echo "it is not a plain local volume (it carries driver options such as a host path)"; return 1; } # guard:G22-v-options
    [[ "$l_ns" == "$LABEL_NS_VALUE" && "$l_role" == files-data ]] || { echo "its labels are not this script's"; return 1; } # guard:G22-v-label
    [[ -z "$token" || "$l_token" == "$token" ]] || { echo "its run token differs from this state directory's"; return 1; } # guard:G22-v-token
    users="$(dkr ps -aq --no-trunc --filter "volume=$name")" || users_failed=1
    [[ $users_failed == 0 ]] || { echo "the containers using it cannot be listed"; return 1; } # guard:G22-v-users-list
    while IFS= read -r user; do
        [[ -z "$user" || ",$allowed," == *",$user,"* ]] || { echo "container ${user:0:12} that is not ours uses it"; return 1; } # guard:G22-v-in-use
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

# The dedicated launcher: docker with ONLY what it needs to find the local daemon, plus DB_PASSWORD, APP_KEY and APP_PREVIOUS_KEYS for
# `-e NAME` (names, so no value is ever an argument to any process). `env -i NAME=value` would put the value on a command line, so the
# environment is built by unsetting everything else in a subshell and exporting the values. Used only to create the runner; nothing
# else gets the password or the keys.
launch_docker() {
    local root="$1" password key previous
    shift
    password="$(app_password "$root")" || exit 3 # a failure inside a command substitution under `||` would not stop the script by itself
    key="$(state_key "$root" REHEARSAL_APP_KEY)" || exit 3
    previous="$(state_key "$root" REHEARSAL_APP_PREVIOUS_KEY)" || exit 3
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
        export APP_KEY="$key"
        export APP_PREVIOUS_KEYS="$previous"
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
    [[ "$role" != files ]] || name="$FILES_NAME"
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
guarded_app_sql() { # ROOT DB [read]: with `read` (app_query's, which only runs a SELECT) the statement is not a change; any other use is one, as far as a failed stage may say
    local root="$1" db="$2" why
    [[ "${3:-}" == read ]] || mutation_begins # guard:G23-sql-mutation
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
        resources:assets:prune) RUN_CMD=("${PRUNE_DRY_RUN_CMD[@]}") ;;
        helper:db-ping) RUN_CMD=(php "$SRC_MOUNT/harness/db-ping.php") ;;
        helper:fixture-load) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" fixture-load) ;;
        helper:snapshot) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" snapshot) ;;
        helper:snapshot-all) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" snapshot-all) ;;
        helper:post-upgrade-mark) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" mark) ;;
        helper:sign-in) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" signin) ;;
        helper:half-migrate) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" half-migrate) ;;
        helper:revoke) RUN_CMD=(php "$SRC_MOUNT/harness/rehearse.php" revoke) ;;
        *) ;;
    esac
    ((${#RUN_CMD[@]} > 0)) || refuse "'$name' is not a command the rehearsal runs. Approved: ${ARTISAN_ALLOWED[*]} helper:db-ping helper:fixture-load helper:snapshot helper:snapshot-all helper:post-upgrade-mark helper:sign-in helper:half-migrate helper:revoke." # guard:G11-allowlist
    if [[ "${RUN_CMD[1]:-}" == artisan ]]; then
        why="$(artisan_problem "${RUN_CMD[@]:2}")" || refuse "$why." # guard:G11-artisan-policy
    fi
}

# What the runner runs first, inside the container: the writable directories Laravel needs beneath its (temporary) storage, then the command itself. Fixed text; the
# caller supplies nothing.
readonly RUNNER_SETUP='mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs 2>/dev/null; exec "$@"'
# The same for the prune dry-run, which has the files volume at $1 (RUNNER_FILES_MOUNT, a constant of this script; its root is the application's storage/app/private,
# so its Resources directory is $1/FILES_STORE_DIR, written into the text below from that constant) and sees the application's Resources directory as a link to it: the application resolves its `resources` disk through storage_path('app/private/resources'), which is a path in the runner's tmpfs and
# so would otherwise be empty. The link is made in that tmpfs only (the mount is read-only and nothing is written to it), and both ends are proven before the
# command runs: the directory in the volume must be a plain directory whose physical path is exactly the one expected (not a link to somewhere else in the
# container), and the link must resolve to it. A volume with no Resources directory at all is the zero-file state: no link is made, and the application finds the
# empty store it would find on a new installation. Anything else stops here with status 70, and nothing is run. POSIX sh only.
# shellcheck disable=SC2016 # the text is run by `sh` inside the container, which expands it
readonly RUNNER_FILES_SETUP='store=$1; shift
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs 2>/dev/null
dir="$store/'"$FILES_STORE_DIR"'"
if [ -e "$dir" ] || [ -L "$dir" ]; then
  [ "$(cd "$dir" 2>/dev/null && pwd -P)" = "$dir" ] || { echo "restore-rehearsal: the Resources directory in the files volume is not a plain directory; nothing was run." >&2; exit 70; }
  mkdir -p storage/app/private && ln -s "$dir" storage/app/private/resources && [ "$(cd storage/app/private/resources 2>/dev/null && pwd -P)" = "$dir" ] || { echo "restore-rehearsal: the Resources directory could not be made visible to the application; nothing was run." >&2; exit 70; }
fi
exec "$@"'

# prune_vector CMD...: the argument vector is exactly the prune dry-run's. What is compared is the vector itself, element by element, not a name a caller supplied.
prune_vector() {
    local i
    (($# == ${#PRUNE_DRY_RUN_CMD[@]})) || return 1 # guard:G24-files-exact-length
    for i in "${!PRUNE_DRY_RUN_CMD[@]}"; do
        [[ "$1" == "${PRUNE_DRY_RUN_CMD[i]}" ]] || return 1 # guard:G24-files-exact-vector
        shift
    done
    return 0
}

# runner_may_read_files WORKDIR CMD...: true for exactly one operation, the Resources prune dry-run in the current tree.
runner_may_read_files() {
    local workdir="$1"
    shift
    [[ "$workdir" == current ]] || return 1 # guard:G24-files-current-only
    prune_vector "$@"
}

# run_in_runner ROOT WORKDIR CMD...: the checks, then one disposable runner on the rehearsal network with the application password, the
# read-only source and nothing else; the prune dry-run's runner also has the files volume, read-only (runner_may_read_files). Output and exit status are the
# command's.
run_in_runner() {
    local root="$1" workdir="$2" token why ids net_id id with_files=0 files_args=() setup="$RUNNER_SETUP" lead=()
    shift 2
    if runner_may_read_files "$workdir" "$@"; then with_files=1; fi
    # The prune dry-run is only run where it is given the files volume. In the historical tree it would not be: it would report an empty store whatever the volume
    # holds (or fail, for want of the command), which reads as a finding and is not one. Refused here, before any rehearsal object is inspected or created.
    if [[ "$workdir" != current ]] && prune_vector "$@"; then
        refuse "the Resources prune dry-run is only run in the current tree, where it is given the files volume to read; in the '$workdir' tree it would see an empty store whatever the volume holds. Run: restore-rehearsal.sh run current resources:assets:prune" # guard:G24-historical-prune
    fi
    assert_sql_target "$root" "$APP_DB" # container, version and sentinel, before anything can touch the database
    token="$(state_token "$root")"
    why="$(app_account_problem "$CID")" || refuse "$why." # guard:G12-before-run
    inventory ids 'the labelled containers' labelled_containers
    [[ "$ids" == "$CID" ]] || refuse "a rehearsal container other than the database exists (an interrupted run or preparation). Run: restore-rehearsal.sh cleanup --dry-run, then cleanup." # guard:G10-no-leftover
    inventory net_id 'the labelled networks' labelled_networks
    [[ -n "$net_id" && "$net_id" != *$'\n'* ]] || refuse "expected exactly one rehearsal network."
    why="$(network_identity_problem "$net_id" "$token" "$CID")" || refuse "the network is not the rehearsal's: $why."
    why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" '')" || refuse "the source volume is not the rehearsal's (has 'prepare' been run?): $why." # guard:G10-run-volume
    if ((with_files == 1)); then
        # The files volume must already be an established one of this run: this operation never creates it, and a volume that is missing is refused, never taken for an
        # empty store. files_volume_ready is called with no permission to create, and finds a volume that is missing, foreign, unlabelled, optioned or shared.
        [[ -e "$root/$FILES_VOLUME_MARKER" || -L "$root/$FILES_VOLUME_MARKER" ]] || refuse "no files volume has been established in this run, so there is nothing for the prune dry-run to read. Run seed-files or backup-files first." # guard:G24-files-record
        files_volume_ready "$token" 0 # guard:G24-files-ready
        files_args=(--mount "type=volume,source=$FILES_VOLUME_NAME,target=$RUNNER_FILES_MOUNT,readonly")
        setup="$RUNNER_FILES_SETUP" lead=("$RUNNER_FILES_MOUNT")
    fi
    RUNNER_FILES="$with_files"
    resolve_php_image
    app_password "$root" >/dev/null # refuses a malformed password here, in this shell, before anything is created
    state_key "$root" REHEARSAL_APP_KEY >/dev/null # and a missing or malformed key, for the same reason
    state_key "$root" REHEARSAL_APP_PREVIOUS_KEY >/dev/null
    # Docker sets up a container's mounts when it is created, and makes a named volume that is missing then (empty, unlabelled): whatever the command does, from the
    # moment the create is attempted a volume of the rehearsal may have changed, and a stage that is armed says so first. Every refusal above came before it.
    mutation_begins # guard:G24-runner-create-mutation
    id="$(create_verified "$root" "$token" runner "$NETWORK_NAME" launch_docker -- \
        --network "$NETWORK_NAME" \
        --mount "type=volume,source=$SRC_VOLUME_NAME,target=$SRC_MOUNT,readonly" \
        ${files_args[@]+"${files_args[@]}"} \
        --tmpfs /tmp:rw,noexec,nosuid,size=64m,uid=33,gid=33 \
        --tmpfs "$SRC_MOUNT/historical/storage:rw,noexec,nosuid,size=64m,uid=33,gid=33" \
        --tmpfs "$SRC_MOUNT/historical/bootstrap/cache:rw,noexec,nosuid,size=16m,uid=33,gid=33" \
        --tmpfs "$SRC_MOUNT/current/storage:rw,noexec,nosuid,size=64m,uid=33,gid=33" \
        --tmpfs "$SRC_MOUNT/current/bootstrap/cache:rw,noexec,nosuid,size=16m,uid=33,gid=33" \
        --workdir "$SRC_MOUNT/$workdir" \
        -e APP_ENV=production -e APP_DEBUG=false \
        -e LOG_CHANNEL=stderr -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
        -e DB_CONNECTION=mariadb -e "DB_HOST=$CONTAINER_NAME" -e DB_PORT=3306 -e "DB_DATABASE=$APP_DB" -e "DB_USERNAME=$APP_USER" -e DB_PASSWORD \
        -e APP_KEY -e APP_PREVIOUS_KEYS \
        "$PHP_IMAGE_ID" \
        sh -c "$setup" sh ${lead[@]+"${lead[@]}"} "$@")" || exit "$?"
    runner_mounts_recheck "$id" "$token" "$with_files"
    start_and_remove "$id"
}

# runner_mounts_recheck ID TOKEN WITH_FILES: what files_mounts_recheck does for the files container, for the runner. The source volume (and, for the prune dry-run's
# runner, the files volume) was proven before the create and is proven again now that the create has returned, with the new container as the only consumer
# allowed: a volume that was missing was made by Docker (empty, unlabelled), one that was replaced is not the one that was proven, one that gained a user is shared.
# Nothing starts when any of that is found.
runner_mounts_recheck() {
    local id="$1" token="$2" with_files="$3" problem='' why
    why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" "$id")" || problem="the source volume is no longer the rehearsal's: $why" # guard:G24-r-recheck-source
    if [[ -z "$problem" && "$with_files" == 1 ]]; then
        why="$(files_volume_identity_problem "$FILES_VOLUME_NAME" "$token" "$id")" || problem="the files volume is no longer the rehearsal's: $why" # guard:G24-r-recheck-files
    fi
    [[ -z "$problem" ]] || refuse_after_create "$id" "$token" runner "$NETWORK_NAME" "$problem" # guard:G24-r-recheck-refuses
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
    data_volume="$(grep -vxF -e "$SRC_VOLUME_NAME" -e "$FILES_VOLUME_NAME" <<<"$volumes" || true)"
    [[ -n "$data_volume" && "$data_volume" != *$'\n'* ]] || refuse "expected exactly one rehearsal data volume."
    why="$(volume_identity_problem "$data_volume" "$token" "$CID")" || refuse "the volume is not the rehearsal's: $why." # guard:G4-selfcheck-volume
    ok_line "volume is a plain local volume used only by the rehearsal database"
    if in_list "$SRC_VOLUME_NAME" "$volumes"; then
        why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" '')" || refuse "the source volume is not the rehearsal's: $why." # guard:G10-selfcheck-source
        ok_line "source volume is a plain local volume used by no container"
    fi
    if in_list "$FILES_VOLUME_NAME" "$volumes"; then
        why="$(files_volume_identity_problem "$FILES_VOLUME_NAME" "$token" '')" || refuse "the files volume is not the rehearsal's: $why." # guard:G22-selfcheck-files
        ok_line "files volume is a plain local volume used by no container"
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

# runner_cleanup_problem ID TOKEN: the PHP runner has exactly two valid layouts, and cleanup says which it is checking (never taken from the environment): the ordinary
# runner, with the read-only source volume and nothing else, or the prune dry-run's runner, which also has the files volume read-only at RUNNER_FILES_MOUNT. The
# complete identity check is the same for both and differs only in the mounts it requires; a container that satisfies neither is not the rehearsal's runner.
runner_cleanup_problem() {
    local id="$1" token="$2" plain special
    plain="$(ephemeral_identity_problem "$id" "$token" runner "$NETWORK_NAME" any false 0)" && return 0 # guard:G24-cleanup-plain-runner
    special="$(ephemeral_identity_problem "$id" "$token" runner "$NETWORK_NAME" any false 1)" && return 0 # guard:G24-cleanup-prune-runner
    printf '%s (and it is not the prune dry-run runner either: %s)\n' "$plain" "$special"
    return 1
}

# Cleanup's identity check for a labelled container: the role label only chooses which complete check applies; a container that does not
# satisfy that check is kept. The image ID is not part of removal safety, so cleanup does not require the current PHP image.
cleanup_container_problem() {
    local id="$1" token="${2:-}" role
    role="$(dkr inspect --type container --format "$ROLE_FMT" "$id" 2>/dev/null)" || role=''
    case "$role" in
        runner) runner_cleanup_problem "$id" "$token" ;;
        prepare) ephemeral_identity_problem "$id" "$token" prepare none any >/dev/null || ephemeral_identity_problem "$id" "$token" prepare bridge any ;;
        files) ephemeral_identity_problem "$id" "$token" files none any true >/dev/null || ephemeral_identity_problem "$id" "$token" files none any false ;;
        *) container_identity_problem "$id" "$token" ;;
    esac
}

# The name of a container that cleanup_container_problem has just verified: the check requires exactly the name of its role.
verified_container_name() {
    case "$(dkr inspect --type container --format "$ROLE_FMT" "$1" 2>/dev/null)" in
        runner) printf '%s' "$RUNNER_NAME" ;;
        prepare) printf '%s' "$PREPARE_NAME" ;;
        files) printf '%s' "$FILES_NAME" ;;
        *) printf '%s' "$CONTAINER_NAME" ;;
    esac
}

cleanup_volume_problem() {
    if [[ "$1" == "$FILES_VOLUME_NAME" ]]; then files_volume_identity_problem "$@"; return; fi # guard:G22-cleanup-dispatch
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

# Removes the files this script wrote, by name (STATE_FILES), and the directory if that leaves it empty. Never recursive, never a glob over
# what is removed. Nothing is removed unless every entry in the directory is one of the files this script wrote: a directory that holds
# anything else keeps its marker, its run token and its lock file, so it stays a state directory this script recognises and cleanup can
# be run again once the other entries are dealt with. Returns 1 when it could not finish.
remove_state() {
    local root="$1" file entry name known unexpected=()
    verify_root_ownership "$root"
    for entry in "$root"/* "$root"/.[!.]* "$root"/..?*; do
        [[ -e "$entry" || -L "$entry" ]] || continue
        name="${entry##*/}"
        known=0
        for file in "${STATE_FILES[@]}"; do
            if [[ "$name" == "$file" && -f "$entry" && ! -L "$entry" ]]; then known=1; fi
        done
        if [[ $known == 0 ]]; then unexpected+=("$name"); fi
    done
    ((${#unexpected[@]} == 0)) || { warn "the objects are gone, but the state directory $root holds entries this script did not write (${unexpected[*]}); nothing in it was removed, and its marker and run token were kept. Deal with those entries yourself (this script never removes what it did not write), then run: restore-rehearsal.sh cleanup"; return 1; } # guard:G8-state-entries
    # The marker goes last: a directory kept because of something else must stay one this script recognises.
    for file in "${STATE_FILES[@]}"; do
        [[ "$file" != "$ROOT_MARKER_NAME" ]] || continue
        if [[ -f "$root/$file" && ! -L "$root/$file" ]]; then rm -f -- "${root:?}/$file"; fi
    done
    if [[ -f "$root/$ROOT_MARKER_NAME" && ! -L "$root/$ROOT_MARKER_NAME" ]]; then rm -f -- "${root:?}/$ROOT_MARKER_NAME"; fi
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
    # What is in the volume must be what is in this checkout: the digests are taken inside the volume, by a container with no network,
    # and compared with the ones taken here. Only then is the source recorded, for every later stage to compare with what it finds.
    local host_sums volume_sums
    host_sums="$(cd "$SCRIPT_DIR/php" && sha256sum -- "${HARNESS_FILES[@]}")"
    # shellcheck disable=SC2016 # the snippet runs in the container; its arguments follow it
    capture volume_sums 'checking the helper scripts in the source volume' prepare_run "$token" none 0 \
        sh -c 'cd "$1" && shift && exec sha256sum -- "$@"' sh "$SRC_MOUNT/harness" "${HARNESS_FILES[@]}"
    [[ "$volume_sums" == "$host_sums" ]] || refuse "the helper scripts in the source volume are not the ones in this checkout (their digests differ). Nothing was recorded. Run: restore-rehearsal.sh cleanup, then up and prepare again." # guard:G10-harness-verified
    # The support classes the helpers load from the current tree are held to the same standard: the digests the verified commit holds, taken here from git, are
    # compared with the ones taken inside the volume by a container with no network. A file that is missing, changed or added to the list is a refusal.
    local support_host support_volume
    support_host="$(support_digests "$current")" || exit "$?"
    # shellcheck disable=SC2016 # the snippet runs in the container; its arguments follow it
    capture support_volume 'checking the support classes in the source volume' prepare_run "$token" none 0 \
        sh -c 'cd "$1" && shift && exec sha256sum -- "$@"' sh "$SRC_MOUNT/current/$SUPPORT_DIR" "${SUPPORT_FILES[@]/%/.php}"
    [[ "$support_volume" == "$support_host" ]] || refuse "the test-support classes in the source volume are not the ones the commit ${current:0:12} holds (their digests differ). Nothing was recorded. Run: restore-rehearsal.sh cleanup, then up and prepare again." # guard:G10-support-verified
    write_state_file "$PREPARED_FILE" "$(prepared_source_text "$historical" "$current")"
    note "recorded: both commits and the digests of ${#HARNESS_FILES[@]} helper scripts and ${#SUPPORT_FILES[@]} support classes ($PREPARED_FILE)"

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

# --- slice 2.2A: the historical baseline, its backup, the upgrade, and the WP3-01 defect ---------------------------------------------------

# capture VAR WHAT COMMAND...: stores the command's standard output in VAR. A command that fails ends the script with its own status (a
# refusal stays a refusal) after saying what failed, because a failure inside $(...) does not stop a script that relies on `set -e`.
capture() {
    local cap_var="$1" cap_what="$2" cap_out='' cap_rc=0
    shift 2
    cap_out="$("$@")" || cap_rc=$?
    if ((cap_rc != 0)); then
        warn "$cap_what failed (status $cap_rc)"
        exit "$cap_rc"
    fi
    printf -v "$cap_var" '%s' "$cap_out"
}

in_array() { # NEEDLE ELEMENT...
    local needle="$1" element
    shift
    for element in "$@"; do
        if [[ "$element" == "$needle" ]]; then return 0; fi
    done
    return 1
}

sorted_list() { printf '%s\n' "$@" | LC_ALL=C sort; }
historical_inventory() { sorted_list "${HISTORICAL_TABLES[@]}"; }
upgraded_inventory() { sorted_list "${HISTORICAL_TABLES[@]}" "${NEWER_TABLES[@]}"; }
durable_inventory() { set_minus "$(historical_inventory)" "$(sorted_list "${TRANSIENT_TABLES[@]}")"; }

# set_minus A B: the lines of A (one name per line) that are not lines of B.
set_minus() {
    [[ -n "$1" ]] || return 0
    if [[ -z "$2" ]]; then
        printf '%s\n' "$1"
        return 0
    fi
    printf '%s\n' "$1" | grep -Fxv -f <(printf '%s\n' "$2") || true
}

# inventory_difference EXPECTED ACTUAL (each one table name per line): `missing NAME` for an expected table that is absent, `extra NAME` for a
# present table that is not expected. Nothing is printed when the two are the same set. This is the table-inventory equality the WP3-01
# demonstration expects to FAIL and the corrected restore (slice 2.2B) will require to hold.
inventory_difference() {
    local name
    while IFS= read -r name; do
        [[ -z "$name" ]] || printf 'missing %s\n' "$name"
    done < <(set_minus "$1" "$2")
    while IFS= read -r name; do
        [[ -z "$name" ]] || printf 'extra %s\n' "$name"
    done < <(set_minus "$2" "$1")
}

# One fixed statement as the application account, through every check of the guarded path (G5, G6, G12). Nothing here takes SQL from
# whoever runs the script: every caller below passes a literal statement.
app_query() { # STATEMENT: a read. Only a statement that begins with SELECT is run as one.
    [[ "$1" == SELECT* ]] || refuse "app_query runs reads only; '${1:0:20}' is not one." # guard:G23-query-read-only
    printf '%s\n' "$1" | guarded_app_sql "$ROOT" "$APP_DB" read
}

# The sorted names of every table in the rehearsal database, one per line, from the server. A query that fails is a refusal and never an
# empty inventory; a name this script does not recognise as a plain table name is a refusal too.
db_inventory() {
    local out name
    capture out 'reading the rehearsal database inventory' app_query "SELECT table_name FROM information_schema.tables WHERE table_schema = '$APP_DB' ORDER BY table_name;"
    while IFS= read -r name; do
        [[ -z "$name" || "$name" =~ ^[a-z0-9_]+$ ]] || refuse "the database inventory holds a name this script does not accept ('${name:0:40}')."
    done <<<"$out"
    LC_ALL=C sort <<<"$out" | grep -v '^$' || true
}

# require_inventory EXPECTED WHAT: refuses unless the database holds exactly the expected tables.
require_inventory() {
    local actual diff
    capture actual 'reading the table inventory' db_inventory
    diff="$(inventory_difference "$1" "$actual")"
    [[ -z "$diff" ]] || refuse "the rehearsal database is not exactly $2 ($(tr '\n' ';' <<<"$diff"))."
}

# The approved commands that are read-only by what they are (a database ping, the snapshot, the ledger and the version display); every other one, the
# sign-in and the checks included, may change something as far as a failed stage may say.
helper_is_read_only() {
    case "$1" in
        helper:db-ping | helper:snapshot | helper:snapshot-all | migrate:status | about | release:show) return 0 ;;
        *) return 1 ;;
    esac
}

# run_helper TREE NAME VAR: one approved command in a disposable runner, its standard output stored in VAR. A failed or refused helper ends
# the script with the helper's own status.
run_helper() {
    local tree="$1" name="$2" var="$3"
    runner_command "$name"
    helper_is_read_only "$name" || mutation_begins # guard:G23-helper-mutation
    capture "$var" "'$name' in the $tree tree" run_in_runner "$ROOT" "$tree" "${RUN_CMD[@]}"
}

STAGE=''
# What a stage that did not finish may say about what it changed. A claim that nothing changed is only as good as the proof, so the default is the cautious
# message, and the other one needs both facts: the stage armed a record when it started (so the tracking worked), and nothing that could change the rehearsal
# database, the files volume or the state directory was begun since. The record is two files, so that it survives where a variable does not: a failure
# inside a function called from a command substitution, a pipeline or a subshell happens in another process, and only a file is seen from here.
#   MUTATION_MARK          made when the stage arms its trap (a private file outside the state directory; it holds nothing);
#   MUTATION_MARK.changed  made by mutation_begins BEFORE every operation that could change one of those three things.
# Every such operation is behind one of the callers of mutation_begins: write_state_file, guarded_app_sql (unless it is app_query's read), run_helper (unless
# the command is one of the read-only ones), the creation of the dump and archive temporary files, the files volume's creation, and the files container's
# `seed`, `aside` and `restore`. A refusal after any of those is "may be partly changed", whatever else it was.
MUTATION_MARK=''
arm_stage() { # STAGE_NAME EXIT_HANDLER
    STAGE="$1"
    MUTATION_MARK="$(umask 077 && mktemp -- "${TMPDIR:-/tmp}/flc-rehearsal-stage.XXXXXX" 2>/dev/null)" || MUTATION_MARK=''
    # shellcheck disable=SC2064 # the handler name is a literal chosen by the caller
    trap "$2" EXIT
    # An interrupted stage ends through the exit trap too, so that it says what it may have changed and removes its record; the statuses are the usual ones.
    trap 'exit 130' INT
    trap 'exit 143' TERM
    trap 'exit 129' HUP
}
mutation_begins() {
    [[ -n "$MUTATION_MARK" ]] || return 0
    [[ -e "$MUTATION_MARK.changed" ]] || : >"$MUTATION_MARK.changed" || fail "the record of what this stage may change could not be written, so nothing was begun" # guard:G23-mutation-recorded
}
stage_changed_nothing() { [[ -n "$MUTATION_MARK" && -f "$MUTATION_MARK" && ! -e "$MUTATION_MARK.changed" ]]; }
stage_record_remove() {
    [[ -z "$MUTATION_MARK" ]] || rm -f -- "$MUTATION_MARK" "$MUTATION_MARK.changed"
}
# The trailer. Only a stage that provably began nothing is told it changed nothing; everything else keeps the cautious words.
stage_trailer() { # WHAT_MAY_BE_CHANGED
    if stage_changed_nothing; then
        warn "$STAGE did not finish, and it stopped before it began to change anything: only read-only checks had run, so the rehearsal database, the files volume and the state directory are as they were. No database, volume or network was removed."
    else
        warn "$STAGE did not finish. $1 may be partly changed. No database, volume or network was removed; a disposable container is removed when it has finished, or when it fails its checks, and says so."
    fi
    warn "The rehearsal is disposable: run"
    warn "  restore-rehearsal.sh cleanup --dry-run ; cleanup ; then up, prepare and the stages again."
}
on_stage_exit() {
    local status=$?
    if ((status != 0)); then stage_trailer 'The rehearsal database'; fi
    stage_record_remove
}

# G13: the commits the rehearsal means, and the checkout they are compared with. `prepare` archives COMMITS, never the working tree, so a
# working tree that differs from them would be a rehearsal of code nobody is looking at.
require_source_commits() {
    local historical dirty
    historical="$(verified_commit "$HISTORICAL_COMMIT")" || refuse "the historical commit $HISTORICAL_COMMIT (v0.3.0, what production runs) is not in this clone. Nothing was substituted. Fetch it yourself (git fetch origin $HISTORICAL_COMMIT), then run the stage again." # guard:G13-commit-present
    [[ "$historical" == "$HISTORICAL_COMMIT" ]] || refuse "the historical commit resolved to '$historical', not $HISTORICAL_COMMIT." # guard:G13-commit-exact
    dirty="$(gitq status --porcelain -- "$APP_TREE" scripts/rehearsal)" || fail "git could not report the state of the working tree"
    [[ -z "$dirty" ]] || refuse "the working tree has uncommitted or untracked changes under $APP_TREE or scripts/rehearsal ($(grep -c . <<<"$dirty") entries). prepare archives commits, so the rehearsal would not be running what you are looking at. Commit or stash them, then run cleanup, up, prepare and the stage again." # guard:G13-clean-checkout
}

# The text `prepare` records and every stage re-derives: the two commits the source volume was archived from, and the digest of each helper
# script as it is in this checkout. A stage compares what it derives NOW with what was recorded THEN, so a source that has gone stale (HEAD
# moved, a helper edited) is refused instead of run.
prepared_source_text() { # HISTORICAL_COMMIT CURRENT_COMMIT
    printf 'prepared-v2\nhistorical=%s\ncurrent=%s\n' "$1" "$2"
    (cd "$SCRIPT_DIR/php" && sha256sum -- "${HARNESS_FILES[@]}") | sed 's/^/harness /'
    support_digests "$2" | sed 's/^/support /'
}

# support_digests COMMIT: the SHA-256 of each test-support class as COMMIT holds it, one line each in the form sha256sum prints from the support directory
# (`<sha>  <Name>.php`). A class the commit does not hold is a refusal: the expected digests come from the verified commit and from nowhere else.
support_digests() {
    local commit="$1" name sha
    for name in "${SUPPORT_FILES[@]}"; do
        sha="$(gitq cat-file blob "$commit:$APP_TREE/$SUPPORT_DIR/$name.php" | sha256sum | cut -c1-64)" || refuse "commit ${commit:0:12} does not hold $APP_TREE/$SUPPORT_DIR/$name.php, which the helpers load, so it cannot be attested."
        [[ "$sha" =~ ^[0-9a-f]{64}$ ]] || refuse "the digest of $name.php did not come out as a SHA-256."
        printf '%s  %s.php\n' "$sha" "$name"
    done
}

# G13: every stage starts here. The historical commit is the exact one and the checkout is clean (require_source_commits), and what `prepare`
# recorded is what this checkout is now: the historical commit, the current commit (HEAD) and every helper script. A docs-only commit after
# `prepare` moves HEAD and so also needs a new `prepare`: the rehearsal says what it ran, and it ran HEAD as it was.
require_prepared_source() {
    local recorded current
    require_source_commits
    state_file_ok "$PREPARED_FILE" || refuse "'$PREPARED_FILE' is missing from the state directory, or is not a private file of this script's: prepare has not finished, or the state is not intact. Run: restore-rehearsal.sh prepare. Nothing was changed." # guard:G13-prepared-recorded
    recorded="$(<"$ROOT/$PREPARED_FILE")"
    [[ "$(head -n 1 <<<"$recorded")" == prepared-v2 ]] || refuse "'$PREPARED_FILE' was written by an earlier version of prepare (or is not a record of one), and does not attest the test-support classes: the prepared source is stale. Run cleanup, then up and prepare again. Nothing was changed." # guard:G13-prepared-format
    current="$(verified_commit HEAD)" || refuse "HEAD is not a commit."
    [[ "$(sed -n 's/^historical=//p' <<<"$recorded")" == "$HISTORICAL_COMMIT" ]] || refuse "the source volume was not archived from the historical commit $HISTORICAL_COMMIT. Run cleanup, then up and prepare again. Nothing was changed." # guard:G13-prepared-historical
    [[ "$(sed -n 's/^current=//p' <<<"$recorded")" == "$current" ]] || refuse "HEAD is not the commit the source volume was archived from (it was $(sed -n 's/^current=\(.\{12\}\).*/\1/p' <<<"$recorded")): the prepared source is stale. Run cleanup, then up and prepare again. Nothing was changed." # guard:G13-prepared-current
    [[ "$(grep '^harness ' <<<"$recorded")" == "$(prepared_source_text "$HISTORICAL_COMMIT" "$current" | grep '^harness ')" ]] || refuse "a helper script in scripts/rehearsal/php is not the one that was copied into the source volume: the prepared source is stale. Run cleanup, then up and prepare again. Nothing was changed." # guard:G13-prepared-harness
    [[ "$(grep '^support ' <<<"$recorded")" == "$(prepared_source_text "$HISTORICAL_COMMIT" "$current" | grep '^support ')" ]] || refuse "a test-support class recorded for the source volume is not the one the commit holds (or one is missing or added): the prepared source is stale. Run cleanup, then up and prepare again. Nothing was changed." # guard:G13-prepared-support
    [[ "$recorded" == "$(prepared_source_text "$HISTORICAL_COMMIT" "$current")" ]] || refuse "'$PREPARED_FILE' is not exactly what prepare writes. Run cleanup, then up and prepare again. Nothing was changed." # guard:G13-prepared-exact
}

# --- state files: what one stage leaves for the next ---------------------------------------------------------------------------------

# write_state_file NAME CONTENT: a private (mode 600) file in the state directory, written whole or not at all.
write_state_file() {
    local name="$1" content="$2" tmp
    mutation_begins
    tmp="$(umask 077 && mktemp -- "$ROOT/.${name}.XXXXXX")"
    (
        umask 077
        printf '%s\n' "$content" >"$tmp"
    )
    chmod 600 -- "$tmp"
    mv -- "$tmp" "$ROOT/$name"
}

# state_file_ok NAME: a regular, private file this script wrote (not a symlink, not group- or world-readable).
state_file_ok() {
    [[ -f "$ROOT/$1" && ! -L "$ROOT/$1" && "$(stat -c %a -- "$ROOT/$1")" == 600 ]]
}

# read_state_file VAR NAME: the content of a file an earlier stage wrote, or a refusal that names the stage to run first.
read_state_file() {
    state_file_ok "$2" || refuse "'$2' is missing from the state directory, or is not a private file of this script's: the stage that writes it has not been run, or the state is not intact. Run the stages in order (baseline, take-backup, upgrade, reproduce-wp3-01); nothing was changed." # guard:G13-previous-stage
    printf -v "$1" '%s' "$(<"$ROOT/$2")"
}

# --- the snapshot: what the database was, as digests, never as rows --------------------------------------------------------------------------

# table_lines KIND TEXT: the `KIND <table> <rows> <digest>` lines of the harness's output, validated and sorted, as `<table> <rows> <digest>`.
# Exactly one for each of the 16 v0.3.0 tables or a refusal: a snapshot with a table missing, repeated or malformed is not an empty one and
# is not equal to anything.
table_lines() {
    local kind="$1" text="$2" k name rows digest extra lines='' names
    while IFS=' ' read -r k name rows digest extra; do
        [[ "$k" == "$kind" ]] || continue
        [[ -z "$extra" && "$name" =~ ^[a-z_]+$ && "$rows" =~ ^[0-9]+$ && "$digest" =~ ^[0-9a-f]{64}$ ]] || refuse "a '$kind' line of the snapshot is malformed."
        lines+="$name $rows $digest"$'\n'
    done <<<"$text"
    lines="${lines%$'\n'}"
    names="$(cut -d' ' -f1 <<<"$lines" | LC_ALL=C sort)"
    [[ "$names" == "$(historical_inventory)" ]] || refuse "the snapshot's '$kind' lines are not exactly one for each of the 16 v0.3.0 tables (a failed query or an absent table is a refusal, never an empty snapshot)." # guard:G13-snapshot-complete
    printf '%s' "$(LC_ALL=C sort <<<"$lines")"
}

# fixture_problem TABLE_LINES: what a complete fixture must show here. (The harness has already checked every table's row count against the
# written-out counts; this states the Persons, which the owner named, and that every durable table holds something to back up.)
fixture_problem() {
    local name rows
    while read -r name rows _; do
        [[ "$name" != people || "$rows" == "$FIXTURE_PEOPLE" ]] || { echo "people holds $rows rows, not the fixture's $FIXTURE_PEOPLE"; return 1; } # guard:G13-fixture-people
        in_array "$name" "${TRANSIENT_TABLES[@]}" || [[ "$rows" != 0 ]] || { echo "the durable table $name is empty"; return 1; } # guard:G13-fixture-durable
    done <<<"$1"
    return 0
}

# digest_differences A B (lines each, `<name> <rows> <digest>` or `<name> <digest>`): the names whose line differs, that are in only one of
# the two, or both. A name missing from B is a difference, not an omission: a durable table that vanished must not read as "nothing differs".
digest_differences() {
    awk 'NR == FNR { want[$1] = $2 " " $3; next }
         { seen[$1] = 1; if (!($1 in want) || want[$1] != $2 " " $3) print $1 }
         END { for (n in want) if (n != "" && !(n in seen)) print n }' <(printf '%s\n' "$1") <(printf '%s\n' "$2") | grep -v '^$' | LC_ALL=C sort -u || true
}

# The same lines with every transient table as the EMPTY table (zero rows and the digest of none). The three states the rehearsal compares:
#   ORIGINAL   the snapshot taken after the fixture was loaded: durable tables, and the transient ones with their fixture rows (sessions, reset tokens)
#   RESTORED   the original with every transient table emptied: what the two-pass dump (structure everywhere, rows for durable tables only) restores
#   UPGRADED   the `upgrade` lines of the snapshot: the original with the renamed roles and the 29-entry ledger; a database restored and THEN upgraded
#              is those lines with the transient tables emptied, because the restore emptied them and no upgrade fills them
# Nothing is compared across states except through this function, so a database is never judged against a snapshot of another moment.
emptied_transients() { # TABLE_LINES
    local name rows digest
    while read -r name rows digest; do
        [[ -n "$name" ]] || continue
        if in_array "$name" "${TRANSIENT_TABLES[@]}"; then printf '%s 0 %s\n' "$name" "$EMPTY_DIGEST"; else printf '%s %s %s\n' "$name" "$rows" "$digest"; fi
    done <<<"$1"
}

# restore_state_differences ORIGINAL NOW: the tables that are not what a restore of the two-pass dump leaves: each durable table exactly as in
# the original snapshot, each transient table empty. (The WP3-01 demonstration and the corrected restore both use it.)
restore_state_differences() {
    digest_differences "$(emptied_transients "$1")" "$2"
}

# structure_lines TEXT NAMES: the `structure <table> <digest>` lines of the harness's output as `<table> <digest>`, sorted: exactly one for each
# table in NAMES (one per line) and no other, or a refusal.
structure_lines() {
    local k name digest extra lines='' names
    while IFS=' ' read -r k name digest extra; do
        [[ "$k" == structure ]] || continue
        [[ -z "$extra" && "$name" =~ ^[a-z0-9_]+$ && "$digest" =~ ^[0-9a-f]{64}$ ]] || refuse "a 'structure' line of the snapshot is malformed."
        lines+="$name $digest"$'\n'
    done <<<"$1"
    lines="${lines%$'\n'}"
    names="$(cut -d' ' -f1 <<<"$lines" | LC_ALL=C sort)"
    [[ "$names" == "$2" ]] || refuse "the structure lines are not exactly one for each expected table (a table whose structure could not be read is a refusal, never a missing line)." # guard:G13-structure-complete
    printf '%s' "$(LC_ALL=C sort <<<"$lines")"
}

# run_snapshot TREE VAR: the application's own snapshot helper in that tree, its whole output in VAR. A helper that did not say it finished
# is refused: output that stopped part-way is not an empty snapshot.
run_snapshot() {
    local snap_text
    run_helper "$1" helper:snapshot snap_text
    grep -Fxq 'snapshot:complete=ok' <<<"$snap_text" || refuse "the snapshot helper did not report snapshot:complete=ok: its output is incomplete, and an incomplete snapshot is refused, never read as empty." # guard:G13-snapshot-reported
    printf -v "$2" '%s' "$snap_text"
}

# require_database_matches_snapshot TREE SNAPSHOT_TABLE_LINES: a fresh snapshot, taken by the application's own runner, must equal the one the
# backup describes. Nothing may have changed the database since.
require_database_matches_snapshot() {
    local out now diff
    run_snapshot "$1" out
    capture now 'validating the current snapshot' table_lines table "$out"
    diff="$(digest_differences "$2" "$now")"
    [[ -z "$diff" ]] || refuse "the database no longer equals the snapshot the backup describes (changed: $(tr '\n' ' ' <<<"$diff"))." # guard:G13-snapshot-matches
}

# --- G14: the keyring ------------------------------------------------------------------------------------------------------------------------

# load_keyring: this run's two keys, from the state file, as fingerprints in CURRENT_FP, PREVIOUS_FP and RING_FP. The keys themselves are
# never kept in a variable beyond this function and never printed.
load_keyring() {
    local current previous
    capture current 'reading the current encryption key' state_key "$ROOT" REHEARSAL_APP_KEY
    capture previous 'reading the previous encryption key' state_key "$ROOT" REHEARSAL_APP_PREVIOUS_KEY
    CURRENT_FP="$(key_fingerprint "$current")"
    PREVIOUS_FP="$(key_fingerprint "$previous")"
    RING_FP="$(keyring_fingerprint "$CURRENT_FP" "$PREVIOUS_FP")"
}

# keys_problem TEXT: the key fingerprints the application itself reported (`key:current=`, `key:previous=`) must be those of the keys this
# script generated, or the manifest would claim keys the data was not encrypted with. Needs load_keyring first.
keys_problem() {
    local reported_current reported_previous
    reported_current="$(sed -n 's/^key:current=\([0-9a-f]\{16\}\)$/\1/p' <<<"$1")"
    reported_previous="$(sed -n 's/^key:previous=\([0-9a-f]\{16\}\)$/\1/p' <<<"$1" | LC_ALL=C sort)"
    [[ "$reported_current" == "$CURRENT_FP" ]] || { echo "the key the application used is not the one this run generated (the fingerprints differ)"; return 1; } # guard:G14-keys-agree
    [[ "$reported_previous" == "$PREVIOUS_FP" ]] || { echo "the previous keys the application holds are not the one this run generated"; return 1; } # guard:G14-previous-agree
    return 0
}

# keyring_problem: the live keyring (the two keys of this run, as fingerprints) must hold every key fingerprint the backup's manifest requires,
# or nothing may be changed: data encrypted under a key that is not in the ring could not be read again. Needs load_keyring and a manifest.
keyring_problem() {
    local missing
    missing="$(keyring_missing "$(printf '%s\n%s\n' "$(manifest_value "$ROOT/$MANIFEST_FILE" current_key_fingerprint)" "$(manifest_names "$ROOT/$MANIFEST_FILE" previous_key_fingerprints)")" "$(printf '%s\n%s\n' "$CURRENT_FP" "$PREVIOUS_FP")")"
    [[ -z "$missing" ]] || { echo "the live keyring lacks a key fingerprint the backup requires ($(tr '\n' ' ' <<<"$missing"))"; return 1; }
}

# --- the dump (G12, G15) --------------------------------------------------------------------------------------------------------------------

# The application account's dump client, run in the database container as the non-root `mysql` user with the same environment scrubbing as
# mariadb_app (no root password, no other password variable, the application password read inside the container and handed over as MYSQL_PWD,
# no editor, no pager). Read-only by nature. Options and the database are chosen by the callers below, never by whoever runs the script.
mariadb_dump_app() {
    local cid="$1"
    shift
    # shellcheck disable=SC2016 # expands inside the container: neither password appears on this host's command line
    dkr exec --user mysql "$cid" sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec env -u MARIADB_ROOT_PASSWORD -u MYSQL_ROOT_PASSWORD -u MARIADB_PASSWORD -u MYSQL_PASSWORD EDITOR=/bin/false VISUAL=/bin/false PAGER=cat MYSQL_PAGER=cat mariadb-dump --no-defaults --protocol=tcp --host=127.0.0.1 --user=flc_rehearsal "$@"' sh "$@"
}

# guarded_app_dump ROOT DB OPTIONS...: every check the SQL path makes, then the account's own, then the dump of that one allowlisted database.
guarded_app_dump() {
    local root="$1" db="$2" why
    shift 2
    assert_sql_target "$root" "$db"
    why="$(app_account_problem "$CID")" || refuse "$why. This script never falls back to root." # guard:G12-before-dump
    mariadb_dump_app "$CID" "$@" "$db"
}

# take_dump FILE: the deployment runbook's two-pass dump (section 5), as the application account. Pass 1 is the structure of every table, with
# the DROP TABLE IF EXISTS before each CREATE; pass 2 is the rows of the durable tables only, so the transient tables come back empty.
take_dump() {
    local out="$1" table ignore=()
    for table in "${TRANSIENT_TABLES[@]}"; do ignore+=("--ignore-table=$APP_DB.$table"); done
    guarded_app_dump "$ROOT" "$APP_DB" --no-data --no-tablespaces >"$out" || { warn "the structure pass of the dump failed"; return 1; }
    guarded_app_dump "$ROOT" "$APP_DB" --no-create-info --single-transaction --quick --no-tablespaces "${ignore[@]}" >>"$out" || { warn "the data pass of the dump failed"; return 1; }
}

# dump_problem FILE: what the backup contract requires of the dump itself. It is read for table names only, never judged as SQL: this is a
# check that the two passes did what the runbook says, not a parser.
dump_problem() {
    local file="$1" drops creates inserts all statements recognised
    all="$(historical_inventory)"
    creates="$(sed -n 's/^CREATE TABLE \x60\([a-z0-9_]*\)\x60 (.*$/\1/p' "$file" | LC_ALL=C sort)"
    drops="$(sed -n 's/^DROP TABLE IF EXISTS \x60\([a-z0-9_]*\)\x60;$/\1/p' "$file" | LC_ALL=C sort)"
    # mariadb-dump 10.11.18 ends the statement's first line at VALUES and puts the rows on the lines after it; the single-line form (VALUES (...))
    # is accepted too. Every statement is counted before the names are made unique, so a statement in any other form cannot hide behind a
    # recognised one for the same table.
    statements="$(grep -c '^INSERT' "$file" || true)"
    inserts="$(sed -n 's/^INSERT INTO \x60\([a-z0-9_]*\)\x60 VALUES\( .*\)\{0,1\}$/\1/p' "$file" | LC_ALL=C sort)"
    recognised=0
    [[ -z "$inserts" ]] || recognised="$(printf '%s\n' "$inserts" | wc -l)"
    [[ "$creates" == "$all" ]] || { echo "it does not create exactly the 16 v0.3.0 tables"; return 1; } # guard:G15-schema-all
    [[ "$drops" == "$all" ]] || { echo "it does not drop exactly the 16 tables it creates"; return 1; } # guard:G15-schema-drops
    [[ "$statements" -eq "$recognised" ]] || { echo "it has an INSERT statement in a form this check does not recognise ($((statements - recognised)) of $statements)"; return 1; } # guard:G15-insert-format
    inserts="$(printf '%s\n' "$inserts" | LC_ALL=C sort -u)"
    [[ "$inserts" == "$(durable_inventory)" ]] || { echo "it carries rows for other than exactly the durable tables (a transient table's rows, or a durable table's absence)"; return 1; } # guard:G15-data-durable-only
    ! grep -Eq '^(USE |CREATE DATABASE)' "$file" || { echo "it names a database"; return 1; } # guard:G15-no-database
    return 0
}

# --- the manifest ------------------------------------------------------------------------------------------------------------------------

json_list() { # NAME... -> ["a", "b"]
    local out='' name
    for name in "$@"; do out+="${out:+, }\"$name\""; done
    printf '[%s]' "$out"
}

# The fingerprint of the run token (the manifest never holds the token): it binds a manifest to the run that wrote it.
token_fingerprint() { printf 'flc-run-v1|%s' "$1" | sha256sum | cut -c1-16; }

# manifest_text DUMP_SHA256 DUMP_BYTES SERVER_VERSION TAKEN_AT TOKEN_FINGERPRINT: the runbook's manifest (deployment runbook, "The manifest"),
# with no key, password, token or secret in it: fingerprints and names only. The Resource-files fields are slice 2.2C's.
manifest_text() {
    local with_data=() table
    for table in "${HISTORICAL_TABLES[@]}"; do in_array "$table" "${TRANSIENT_TABLES[@]}" || with_data+=("$table"); done
    cat <<MANIFEST
{
  "manifest_version": 1,
  "backup_id": "${4//[-:]/}",
  "taken_at": "$4",
  "reason": "rehearsal",
  "dump_file": "$DUMP_FILE",
  "dump_sha256": "$1",
  "dump_bytes": $2,
  "release": {
    "version": "$RELEASE_VERSION",
    "commit": "$HISTORICAL_COMMIT"
  },
  "database": {
    "connection": "mariadb",
    "name": "$APP_DB",
    "server_version": "$3",
    "run_token_fingerprint": "$5"
  },
  "keyring": {
    "current_key_fingerprint": "$CURRENT_FP",
    "previous_key_fingerprints": ["$PREVIOUS_FP"],
    "keyring_fingerprint": "$RING_FP",
    "previous_keys_configured": true
  },
  "tables_with_data": $(json_list "${with_data[@]}"),
  "tables_schema_only": $(json_list "${TRANSIENT_TABLES[@]}")
}
MANIFEST
}

# manifest_value FILE KEY: the one string or number the manifest holds under KEY, or nothing (also nothing when KEY appears more than once).
manifest_value() {
    local found
    found="$(sed -n "s/^ *\"$2\": \"\{0,1\}\([^\",]*\)\"\{0,1\},\{0,1\}\$/\1/p" "$1")"
    [[ -n "$found" && "$found" != *$'\n'* ]] || return 1
    printf '%s' "$found"
}

# manifest_names FILE KEY: the names in a one-line list, one per line, sorted.
manifest_names() {
    sed -n "s/^ *\"$2\": \[\(.*\)\],\{0,1\}\$/\1/p" "$1" | tr -d '" ' | tr ',' '\n' | LC_ALL=C sort
}

# manifest_problem FILE SNAPSHOT_TABLE_LINES: the manifest is this run's, for the v0.3.0 release, and agrees with the snapshot and the
# contract on every table.
manifest_problem() {
    local file="$1" snapshot_names value with_data schema_only ring
    value="$(manifest_value "$file" manifest_version)" && [[ "$value" == 1 ]] || { echo "its manifest_version is not 1"; return 1; }
    value="$(manifest_value "$file" commit)" && [[ "$value" == "$HISTORICAL_COMMIT" ]] || { echo "it is not for the historical commit $HISTORICAL_COMMIT"; return 1; } # guard:G15-manifest-release
    value="$(manifest_value "$file" run_token_fingerprint)" && [[ "$value" == "$(token_fingerprint "$(state_token "$ROOT")")" ]] || { echo "it is not this run's (its run token fingerprint differs)"; return 1; } # guard:G15-manifest-run
    with_data="$(manifest_names "$file" tables_with_data)"
    schema_only="$(manifest_names "$file" tables_schema_only)"
    snapshot_names="$(cut -d' ' -f1 <<<"$2" | LC_ALL=C sort)"
    [[ "$(LC_ALL=C sort <<<"$with_data"$'\n'"$schema_only" | grep -v '^$')" == "$snapshot_names" ]] || { echo "its table inventory is not the snapshot's"; return 1; } # guard:G15-manifest-inventory
    [[ "$schema_only" == "$(sorted_list "${TRANSIENT_TABLES[@]}")" && "$with_data" == "$(durable_inventory)" ]] || { echo "its durable and schema-only tables are not the contract's"; return 1; } # guard:G15-manifest-classes
    ring="$(manifest_value "$file" keyring_fingerprint)" || { echo "it has no keyring fingerprint"; return 1; }
    [[ "$ring" == "$(keyring_fingerprint "$(manifest_value "$file" current_key_fingerprint)" "$(manifest_names "$file" previous_key_fingerprints)")" ]] || { echo "its keyring fingerprint is not that of the fingerprints it lists"; return 1; } # guard:G15-manifest-keyring
    return 0
}

# dump_matches_manifest FILE MANIFEST: the dump's byte count and SHA-256 are the ones the manifest recorded. Nothing is loaded from a dump
# that has not just passed this.
dump_matches_manifest() {
    local dump="$1" manifest="$2" sha bytes
    sha="$(sha256sum <"$dump" | cut -c1-64)"
    bytes="$(wc -c <"$dump" | tr -d ' ')"
    [[ "$sha" == "$(manifest_value "$manifest" dump_sha256)" ]] || { echo "its SHA-256 is not the manifest's"; return 1; } # guard:G15-dump-sha
    [[ "$bytes" == "$(manifest_value "$manifest" dump_bytes)" ]] || { echo "its size is not the manifest's"; return 1; } # guard:G15-dump-bytes
    return 0
}

# require_backup: the backup an earlier stage took is present, intact and this run's. Sets SNAPSHOT_TEXT, SNAPSHOT_TABLES (the ORIGINAL state's
# table lines) and SNAPSHOT_STRUCTURE (the 16 tables' structure digests).
require_backup() {
    local why
    read_state_file SNAPSHOT_TEXT "$SNAPSHOT_FILE"
    capture SNAPSHOT_TABLES 'validating the snapshot' table_lines table "$SNAPSHOT_TEXT"
    capture SNAPSHOT_STRUCTURE 'validating the snapshot structure' structure_lines "$SNAPSHOT_TEXT" "$(historical_inventory)"
    { state_file_ok "$DUMP_FILE" && state_file_ok "$MANIFEST_FILE"; } || refuse "there is no complete backup in the state directory (take-backup has not finished). Nothing was changed." # guard:G13-backup-required
    why="$(manifest_problem "$ROOT/$MANIFEST_FILE" "$SNAPSHOT_TABLES")" || refuse "the manifest cannot be relied on: $why."
    why="$(dump_matches_manifest "$ROOT/$DUMP_FILE" "$ROOT/$MANIFEST_FILE")" || refuse "the dump cannot be relied on: $why."
    why="$(dump_problem "$ROOT/$DUMP_FILE")" || refuse "the dump cannot be relied on: $why."
}

# --- the current profile: the 34-table dump, the snapshot of it, and the recovery unit (slice 2.2C-3, WU4) ---------------------------------------------------

current_durable_inventory() { set_minus "$(upgraded_inventory)" "$(sorted_list "${TRANSIENT_TABLES[@]}")"; }

# all_table_lines TEXT: the `all-table <table> <rows> <digest>` lines of `snapshot-all`, validated and sorted, as `<table> <rows> <digest>`. Exactly one for each of the 34
# tables or a refusal: a snapshot with a table missing, repeated or malformed is not a smaller one and is not equal to anything. (The historical snapshot's `table`
# lines and table_lines are not touched; the kind is a different one so that nothing reads one as the other.)
all_table_lines() {
    local text="$1" k name rows digest extra lines='' names
    while IFS=' ' read -r k name rows digest extra; do
        [[ "$k" == all-table ]] || continue
        [[ -z "$extra" && "$name" =~ ^[a-z0-9_]+$ && "$rows" =~ ^(0|[1-9][0-9]{0,11})$ && "$digest" =~ ^[0-9a-f]{64}$ ]] || refuse "an 'all-table' line of the snapshot is malformed." # guard:G25-all-line-shape
        lines+="$name $rows $digest"$'\n'
    done <<<"$text"
    lines="${lines%$'\n'}"
    names="$(cut -d' ' -f1 <<<"$lines" | LC_ALL=C sort)"
    [[ "$names" == "$(upgraded_inventory)" ]] || refuse "the snapshot's 'all-table' lines are not exactly one for each of the 34 tables of the current schema (a failed query or an absent table is a refusal, never a smaller snapshot)." # guard:G25-all-complete
    printf '%s' "$(LC_ALL=C sort <<<"$lines")"
}

# snapshot_value TEXT KEY: the one value of a `KEY=value` line of a helper's output, or nothing (and a failure) when it is absent or repeated.
snapshot_value() {
    local found
    found="$(sed -n "s/^$2=\(.*\)\$/\1/p" <<<"$1")"
    [[ -n "$found" && "$found" != *$'\n'* ]] || return 1
    printf '%s' "$found"
}

# dump_set_kind LINE: the kind of one conditional `SET` line of the dump, or a failure for any other line. Exact text, case included; the table is the whole list.
dump_set_kind() {
    case "$1" in
        '/*!40101 SET NAMES utf8mb4 */;') echo names ;;
        "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;") echo mode-pin ;;
        '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;') echo mode-restore ;;
        '/*!40101 SET @saved_cs_client     = @@character_set_client */;') echo cs-table-save ;;
        '/*!40101 SET character_set_client = utf8mb4 */;') echo cs-table-set ;;
        '/*!40101 SET character_set_client = @saved_cs_client */;') echo cs-table-restore ;;
        '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;') echo cs-restore-client ;;
        '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;' | '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;' \
            | '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;' | '/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;' \
            | '/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;' \
            | '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;' | "/*!40103 SET TIME_ZONE='+00:00' */;" | '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;' \
            | '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;' | '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;' \
            | '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;' | '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;' \
            | '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;' | '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;') echo plain ;;
        *) return 1 ;;
    esac
}

# current_dump_problem FILE TABLE_LINES: what the backup contract requires of the dump of the CURRENT schema, judged against what the database held when it was
# taken (TABLE_LINES: `<table> <rows> <digest>` for each of the 34). Unlike dump_problem, which reads a v0.3.0 dump for table names alone, every line of the dump
# must be one of the forms named by the DUMP_*_RE patterns, and a line of any other form is a refusal, never skipped:
#   comments, blank lines, the sandbox comment, a conditional `SET` of the variables mariadb-dump sets, `DROP TABLE IF EXISTS `t`;`, a `CREATE TABLE `t` (` with a body of
#   columns and keys of the forms the schema uses and its closing `) ENGINE=InnoDB ...;`, `LOCK TABLES `t` WRITE;`, `/*!40000 ALTER TABLE `t` DISABLE|ENABLE KEYS */;`,
#   `INSERT INTO `t` VALUES` and then one line for each row, `(...),` and the last `(...);`, and `UNLOCK TABLES;`.
# Context is checked as well as form: a table named by a LOCK, an ALTER, an INSERT, a DROP, a CREATE or a foreign key must be one of the 34; a CREATE follows its DROP; a
# LOCK is for a durable table that has been created, once, and ends in an UNLOCK; the ALTER and the INSERT are for the table that is locked, and DISABLE comes before ENABLE;
# nothing else is between a LOCK and its UNLOCK. Then: exactly the 34 tables are created and dropped, once each; no transient table has an INSERT; each durable table has as
# many row lines as the database held rows (so a durable table with no rows has none, and is not "missing"); and no table outside the 34 has anything.
# What this is not: a SQL parser, and not a proof that arbitrary SQL is safe. It decides whether a file is made only of the line forms the two-pass dump writes, with the
# rows' values held to the literal forms of a row; the row COUNT is checked against the measurement, a row's CONTENT is not (nothing here reads a row's values: that is
# what the restored database's own digests are for, in WU5). A row that mariadb-dump wrote over several lines is refused, not miscounted. The forms were checked against ONE live
# two-pass dump of the 34 tables (docs, "WU4"); a form that dump did not show, and this list lacks, would refuse a good dump, which is the safe way to be wrong.
current_dump_problem() {
    local file="$1" measured="$2" line state=top table='' lineno=0 name rows creates='' drops='' inventory op want new ref ok seq=0 kind
    local locked='' keys=off body=0 comma=no
    local modestate=none csclient=orig tsaved=no # the SQL mode and the client character set, as the dump's own SET lines have left them (see dump_set_kind)
    local -A rowcount=() dropidx=() createidx=() lockdone=() known=()
    local LC_ALL=C
    [[ -f "$file" && ! -L "$file" ]] || { echo "it is not a regular file"; return 1; }
    [[ "$(tr -d '\0' <"$file" | sha256sum)" == "$(sha256sum <"$file")" ]] || { echo "it holds a NUL byte"; return 1; } # guard:G25-dump-nul
    inventory="$(upgraded_inventory)"
    while read -r name; do known[$name]=1; done <<<"$inventory"
    while IFS= read -r line || [[ -n "$line" ]]; do
        lineno=$((lineno + 1))
        if [[ "$state" == create ]]; then
            if [[ "$line" =~ $DUMP_CREATE_END_RE ]]; then
                [[ "$body" -ge 1 && "$comma" == no ]] || { echo "a CREATE TABLE of the dump has no column, or its last line has a comma (line $lineno)"; return 1; } # guard:G25-dump-create-last
                state=top
                continue
            fi
            [[ "$body" == 0 || "$comma" == yes ]] || { echo "a CREATE TABLE of the dump has a line after one that has no comma (line $lineno)"; return 1; } # guard:G25-dump-create-commas
            ok=no
            [[ "$line" =~ $DUMP_COLUMN_RE || "$line" =~ $DUMP_PRIMARY_RE || "$line" =~ $DUMP_KEY_RE ]] && ok=yes
            if [[ "$ok" == no && "$line" =~ $DUMP_FOREIGN_RE ]]; then
                ok=yes
                [[ "$line" =~ $DUMP_REFERENCES_RE ]] && ref="${BASH_REMATCH[1]}" || ref=''
                [[ -n "$ref" && -n "${known[$ref]:-}" ]] || { echo "a foreign key of the dump references a table that is not one of the 34 (line $lineno)"; return 1; } # guard:G25-dump-create-reference
            fi
            [[ "$ok" == yes ]] || { echo "a CREATE TABLE of the dump holds a line that is not a column or key of the forms the schema uses (line $lineno)"; return 1; } # guard:G25-dump-create-body
            body=$((body + 1))
            [[ "$line" == *, ]] && comma=yes || comma=no
            continue
        fi
        if [[ "$state" == insert ]]; then
            [[ "$line" =~ $DUMP_ROW_RE ]] || { echo "an INSERT of the dump holds a line that is not a row of literal values (line $lineno)"; return 1; } # guard:G25-dump-row-form
            rowcount[$table]=$((${rowcount[$table]:-0} + 1))
            [[ "$line" != *';' ]] || state=top
            continue
        fi
        if [[ -z "$line" || ("$line" =~ $DUMP_COMMENT_RE && "$line" != *"$BS"*) ]]; then continue; fi
        if [[ "$line" == 'UNLOCK TABLES;' ]]; then
            [[ -n "$locked" && "$keys" != disabled ]] || { echo "an UNLOCK TABLES of the dump is not for a table it locked, or leaves the keys of one disabled (line $lineno)"; return 1; } # guard:G25-dump-unlock-context
            locked=''
            continue
        fi
        if [[ "$line" =~ $DUMP_LOCK_RE ]]; then
            name="${BASH_REMATCH[1]}"
            [[ -n "${known[$name]:-}" ]] || { echo "a LOCK TABLES of the dump names a table that is not one of the 34 (line $lineno)"; return 1; } # guard:G25-dump-lock-known
            ! in_array "$name" "${TRANSIENT_TABLES[@]}" || { echo "a LOCK TABLES of the dump names the transient table $name, which the data pass ignores (line $lineno)"; return 1; } # guard:G25-dump-lock-transient
            [[ -z "$locked" && -z "${lockdone[$name]:-}" && -n "${createidx[$name]:-}" ]] || { echo "a LOCK TABLES of the dump is nested, repeated, or for a table not created before it (line $lineno)"; return 1; } # guard:G25-dump-lock-context
            locked="$name" keys=off
            lockdone[$name]=1
            continue
        fi
        if [[ "$line" =~ $DUMP_KEYS_RE ]]; then
            name="${BASH_REMATCH[1]}" op="${BASH_REMATCH[2]}"
            [[ -n "${known[$name]:-}" ]] || { echo "an ALTER TABLE of the dump names a table that is not one of the 34 (line $lineno)"; return 1; } # guard:G25-dump-keys-known
            if [[ "$op" == DISABLE ]]; then want=off new=disabled; else want=disabled new=enabled; fi
            [[ "$locked" == "$name" && "$keys" == "$want" ]] || { echo "an ALTER TABLE ... $op KEYS of the dump is not for the table that is locked, or is out of order (line $lineno)"; return 1; } # guard:G25-dump-keys-context
            keys="$new"
            continue
        fi
        if [[ "$line" =~ $DUMP_INSERT_RE ]]; then
            table="${BASH_REMATCH[1]}"
            [[ -n "${known[$table]:-}" ]] || { echo "it carries rows for a table that is not one of the 34"; return 1; } # guard:G25-dump-unknown-table
            ! in_array "$table" "${TRANSIENT_TABLES[@]}" || { echo "it carries rows for the transient table $table"; return 1; } # guard:G25-dump-transient-empty
            [[ "$locked" == "$table" && "$keys" != enabled ]] || { echo "an INSERT of the dump is not for the table that is locked (line $lineno)"; return 1; } # guard:G25-dump-insert-context
            [[ "$modestate" == pinned ]] || { echo "an INSERT of the dump comes where the SQL mode is not the one the dump pins (line $lineno)"; return 1; } # guard:G25-dump-insert-mode
            [[ "$csclient" == safe ]] || { echo "an INSERT of the dump comes where the client character set is not the one the dump sets (line $lineno)"; return 1; } # guard:G25-dump-insert-charset
            state=insert
            continue
        fi
        [[ -z "$locked" ]] || { echo "a statement between a LOCK TABLES and its UNLOCK is not one of the table's own (line $lineno)"; return 1; } # guard:G25-dump-locked-only
        if [[ "$line" =~ $DUMP_SANDBOX_RE ]]; then continue; fi
        if kind="$(dump_set_kind "$line")"; then
            case "$kind" in
                names) csclient=safe ;;
                cs-table-set) csclient=safe ;;
                cs-table-save)
                    [[ "$csclient" == safe ]] || { echo "the dump saves the client character set while it is not the one the dump sets (line $lineno)"; return 1; } # guard:G25-dump-cs-table-save
                    tsaved=yes ;;
                cs-table-restore)
                    [[ "$tsaved" == yes ]] || { echo "the dump restores a client character set it did not save (line $lineno)"; return 1; } # guard:G25-dump-cs-table-restore
                    tsaved=no ;;
                cs-restore-client)
                    [[ "$csclient" == safe && "$tsaved" == no ]] || { echo "the dump restores the client character set that it did not set, or in the middle of a table (line $lineno)"; return 1; } # guard:G25-dump-cs-restore
                    csclient=orig ;;
                mode-pin)
                    [[ "$modestate" == none ]] || { echo "the dump pins the SQL mode while it is pinned already (line $lineno)"; return 1; } # guard:G25-dump-mode-pin
                    modestate=pinned ;;
                mode-restore)
                    [[ "$modestate" == pinned ]] || { echo "the dump restores an SQL mode it did not pin (line $lineno)"; return 1; } # guard:G25-dump-mode-restore
                    modestate=none ;;
            esac
            continue
        fi
        if [[ "$line" =~ $DUMP_DROP_RE ]]; then
            drops+="${BASH_REMATCH[1]}"$'\n'
            seq=$((seq + 1))
            [[ -n "${dropidx[${BASH_REMATCH[1]}]:-}" ]] || dropidx[${BASH_REMATCH[1]}]=$seq
            continue
        fi
        if [[ "$line" =~ $DUMP_CREATE_RE ]]; then
            creates+="${BASH_REMATCH[1]}"$'\n'
            seq=$((seq + 1))
            [[ -n "${createidx[${BASH_REMATCH[1]}]:-}" ]] || createidx[${BASH_REMATCH[1]}]=$seq
            state=create body=0 comma=no
            continue
        fi
        echo "it has a statement this check does not recognise (line $lineno, begins '${line%% *}')"; return 1 # guard:G25-dump-unrecognised
    done <"$file"
    [[ "$state" == top && -z "$locked" ]] || { echo "it ends inside a statement"; return 1; } # guard:G25-dump-complete
    [[ "$modestate" == none && "$csclient" == orig && "$tsaved" == no ]] || { echo "it ends with the SQL mode or the client character set still changed: its closing restore lines are missing"; return 1; } # guard:G25-dump-set-balanced
    [[ "$(printf '%s' "$creates" | LC_ALL=C sort)" == "$inventory" ]] || { echo "it does not create exactly the 34 tables of the current schema, once each"; return 1; } # guard:G25-dump-creates
    [[ "$(printf '%s' "$drops" | LC_ALL=C sort)" == "$inventory" ]] || { echo "it does not drop exactly the 34 tables it creates, once each"; return 1; } # guard:G25-dump-drops
    while read -r name; do
        [[ "${dropidx[$name]}" -lt "${createidx[$name]}" ]] || { echo "it creates the table $name before it drops it"; return 1; } # guard:G25-dump-create-order
    done <<<"$inventory"
    while read -r name rows _; do
        [[ -n "$name" ]] || continue
        ! in_array "$name" "${TRANSIENT_TABLES[@]}" || continue
        [[ "${rowcount[$name]:-0}" == "$rows" ]] || { echo "it holds ${rowcount[$name]:-0} row(s) for the durable table $name, and the database held $rows"; return 1; } # guard:G25-dump-rows
    done <<<"$measured"
    return 0
}

# --- the recovery manifest ---------------------------------------------------------------------------------------------------------------------------

# recovery_manifest_text: the one document that binds a paired backup, written from the RM_* values (RM_TAKEN, RM_FP, RM_COMMIT, RM_SERVER, RM_DUMP_SHA, RM_DUMP_BYTES,
# RM_MIG_SHA, RM_STRUCT_SHA, RM_FM_SHA, RM_FM_BYTES, RM_AR_SHA, RM_AR_BYTES, RM_FILES, RM_ROWS, RM_INV_SHA and RM_TABLES, the `<table> <rows> <row digest> <structure
# digest>` lines) and the keyring (CURRENT_FP, PREVIOUS_FP, RING_FP). It reuses the formats proven in L2 and L3b by REFERENCE: the Resource-files manifest is the file
# resources.manifest.json as it is, bound here by its SHA-256 and size, and the database part uses the historical manifest's field names. One fact to a line, so that a
# changed, missing, repeated or reordered line is found. No key, password, token or secret: fingerprints, names, counts and digests.
recovery_manifest_text() {
    local with_data=() schema_only=() name rows row_sha struct_sha class list=''
    mapfile -t with_data < <(current_durable_inventory)
    mapfile -t schema_only < <(sorted_list "${TRANSIENT_TABLES[@]}")
    while read -r name rows row_sha struct_sha; do
        [[ -n "$name" ]] || continue
        class=durable
        ! in_array "$name" "${TRANSIENT_TABLES[@]}" || class=transient
        list+="    { \"name\": \"$name\", \"class\": \"$class\", \"rows\": $rows, \"row_sha256\": \"$row_sha\", \"structure_sha256\": \"$struct_sha\" },"$'\n'
    done <<<"$RM_TABLES"
    list="${list%,$'\n'}"
    cat <<MANIFEST
{
  "recovery_manifest_version": $RECOVERY_MANIFEST_VERSION,
  "backup_id": "${RM_TAKEN//[-:]/}",
  "taken_at": "$RM_TAKEN",
  "reason": "rehearsal",
  "run_token_fingerprint": "$RM_FP",
  "release": {
    "version": "$CURRENT_RELEASE_VERSION",
    "commit": "$RM_COMMIT"
  },
  "database": {
    "connection": "mariadb",
    "name": "$APP_DB",
    "server_version": "$RM_SERVER"
  },
  "keyring": {
    "current_key_fingerprint": "$CURRENT_FP",
    "previous_key_fingerprints": ["$PREVIOUS_FP"],
    "keyring_fingerprint": "$RING_FP",
    "previous_keys_configured": true
  },
  "database_dump_file": "$CURRENT_DUMP_FILE",
  "database_dump_sha256": "$RM_DUMP_SHA",
  "database_dump_bytes": $RM_DUMP_BYTES,
  "migrations_count": $CURRENT_LEDGER_COUNT,
  "migrations_last": "$CURRENT_LAST_MIGRATION",
  "migrations_sha256": "$RM_MIG_SHA",
  "structure_sha256": "$RM_STRUCT_SHA",
  "tables_with_data": $(json_list "${with_data[@]}"),
  "tables_schema_only": $(json_list "${schema_only[@]}"),
  "resources_manifest_file": "$FILES_MANIFEST_FILE",
  "resources_manifest_sha256": "$RM_FM_SHA",
  "resources_manifest_bytes": $RM_FM_BYTES,
  "resources_archive_file": "$FILES_ARCHIVE_FILE",
  "resources_archive_sha256": "$RM_AR_SHA",
  "resources_archive_bytes": $RM_AR_BYTES,
  "resources_files_count": $RM_FILES,
  "resources_asset_rows": $RM_ROWS,
  "resources_inventory_sha256": "$RM_INV_SHA",
  "tables": [
$list
  ]
}
MANIFEST
}

# join_tables TABLE_LINES STRUCTURE_LINES: `<table> <rows> <row digest> <structure digest>`, one line for each table. Both inputs are already validated to name the same 34 tables.
join_tables() {
    awk 'NR == FNR { s[$1] = $2; next } { print $1 " " $2 " " $3 " " s[$1] }' <(printf '%s\n' "$2") <(printf '%s\n' "$1")
}

# structure_aggregate TABLE_LINES (`<table> <rows> <row digest> <structure digest>`): one digest of every table's structure digest, in table order.
structure_aggregate() { awk '{ print $1 " " $4 }' <<<"$1" | sha256sum | cut -c1-64; }

# recovery_unit_problem MANIFEST DUMP FILES_MANIFEST ARCHIVE: the four files are ONE recovery unit of this run, or the reason they are not. It takes the paths (the
# temporary files before they are put in place, the final ones afterwards) and reads nothing from the database: it is what a restore may rely on before it touches
# anything. Needs load_keyring. What it establishes, in three groups, and what it does not:
#   ARTIFACT INTEGRITY: the dump and the Resource-files archive are the files the manifest names, by SHA-256 and size; the Resource-files manifest is consistent with the
#     archive (the L3b rules) and is the one the recovery manifest binds; the recovery manifest is of this run (the token's fingerprint) and of the commit the source was prepared from.
#   STRUCTURAL CONSISTENCY: the dump is made only of the line forms current_dump_problem accepts, creates and drops exactly the 34 tables, and holds as many row LINES for each
#     durable table as the manifest says the database held; the manifest's own facts agree with each other (the table list, the classes, the 27 and 7, the structure
#     aggregate, the ledger, the keyring, the file count, the asset rows) and the whole document is exactly what recovery_manifest_text writes from what it says.
#   NOT ESTABLISHED: that any table's rows in the dump are the rows the manifest's row digest was taken of (no row's value is read from the dump, and no digest is derived from
#     it: a dump with the same number of rows and other content is accepted if its checksum is re-recorded); that the recorded structure digests describe the dump's structures;
#     that the recorded digests are the database's (they are the first measurement's, recorded, not authenticated: the state directory is private to the run and a manifest is
#     as trustworthy as the directory). Those are properties of the RESTORED database, and a restore establishes them by measuring it with snapshot-all and comparing with the
#     manifest (docs, "WU4" and "the contract for WU5"). A unit that passes this check is intact and consistent, not proven to restore to the recorded content.
recovery_unit_problem() {
    local m="$1" dump="$2" fm="$3" archive="$4" value why missing name rows row_sha struct_sha class line lines='' with_data schema_only fm_state
    RM_TAKEN='' RM_FP='' RM_COMMIT='' RM_SERVER='' RM_TABLES=''
    value="$(manifest_value "$m" recovery_manifest_version)" && [[ "$value" == "$RECOVERY_MANIFEST_VERSION" ]] || { echo "its recovery_manifest_version is not $RECOVERY_MANIFEST_VERSION"; return 1; } # guard:G25-rm-version
    RM_FP="$(manifest_value "$m" run_token_fingerprint)" && [[ "$RM_FP" == "$(token_fingerprint "$(state_token "$ROOT")")" ]] || { echo "it is not this run's (its run token fingerprint differs)"; return 1; } # guard:G25-rm-run
    RM_TAKEN="$(manifest_value "$m" taken_at)" && [[ "$RM_TAKEN" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || { echo "its taken_at is not a UTC time"; return 1; } # guard:G25-rm-taken
    RM_COMMIT="$(manifest_value "$m" commit)" && [[ "$RM_COMMIT" =~ ^[0-9a-f]{40}$ && "$RM_COMMIT" == "$(sed -n 's/^current=//p' "$ROOT/$PREPARED_FILE")" ]] || { echo "it is not for the current commit the source volume was prepared from"; return 1; } # guard:G25-rm-release
    value="$(manifest_value "$m" version)" && [[ "$value" == "$CURRENT_RELEASE_VERSION" ]] || { echo "its release version is not '$CURRENT_RELEASE_VERSION'"; return 1; } # guard:G25-rm-release-version
    RM_SERVER="$(manifest_value "$m" server_version)" && [[ "$RM_SERVER" =~ $SERVER_VERSION_RE && "$(manifest_value "$m" connection)" == mariadb && "$(manifest_value "$m" name)" == "$APP_DB" ]] || { echo "its database is not the rehearsal's MariaDB 10.11.18"; return 1; } # guard:G25-rm-database
    value="$(manifest_value "$m" keyring_fingerprint)" || value=''
    [[ "$value" == "$(keyring_fingerprint "$(manifest_value "$m" current_key_fingerprint)" "$(manifest_names "$m" previous_key_fingerprints)")" ]] || { echo "its keyring fingerprint is not that of the fingerprints it lists"; return 1; } # guard:G25-rm-keyring
    missing="$(keyring_missing "$(printf '%s\n%s\n' "$(manifest_value "$m" current_key_fingerprint)" "$(manifest_names "$m" previous_key_fingerprints)")" "$(printf '%s\n%s\n' "$CURRENT_FP" "$PREVIOUS_FP")")"
    [[ -z "$missing" ]] || { echo "the live keyring lacks a key fingerprint the backup requires"; return 1; } # guard:G25-rm-keyring-live
    value="$(manifest_value "$m" database_dump_file)" && [[ "$value" == "$CURRENT_DUMP_FILE" ]] || { echo "its dump is not $CURRENT_DUMP_FILE"; return 1; } # guard:G25-rm-dump-name
    [[ "$(sha256sum <"$dump" | cut -c1-64)" == "$(manifest_value "$m" database_dump_sha256)" ]] || { echo "the dump's SHA-256 is not the manifest's"; return 1; } # guard:G25-rm-dump-sha
    [[ "$(wc -c <"$dump" | tr -d ' ')" == "$(manifest_value "$m" database_dump_bytes)" ]] || { echo "the dump's size is not the manifest's"; return 1; } # guard:G25-rm-dump-bytes
    [[ "$(manifest_value "$m" migrations_count)" == "$CURRENT_LEDGER_COUNT" && "$(manifest_value "$m" migrations_last)" == "$CURRENT_LAST_MIGRATION" && "$(manifest_value "$m" migrations_sha256)" =~ ^[0-9a-f]{64}$ ]] || { echo "its migration ledger is not the current schema's $CURRENT_LEDGER_COUNT migrations"; return 1; } # guard:G25-rm-migrations
    while IFS= read -r line; do
        [[ "$line" =~ $RM_TABLE_RE ]] || continue
        name="${BASH_REMATCH[1]}" class="${BASH_REMATCH[2]}" rows="${BASH_REMATCH[3]}" row_sha="${BASH_REMATCH[4]}" struct_sha="${BASH_REMATCH[5]}"
        if in_array "$name" "${TRANSIENT_TABLES[@]}"; then value=transient; else value=durable; fi
        [[ "$class" == "$value" ]] || { echo "its class for $name is not the contract's ($value)"; return 1; } # guard:G25-rm-class
        lines+="$name $rows $row_sha $struct_sha"$'\n'
    done <"$m"
    RM_TABLES="${lines%$'\n'}"
    [[ "$(cut -d' ' -f1 <<<"$RM_TABLES")" == "$(upgraded_inventory)" ]] || { echo "its table list is not exactly the 34 tables of the current schema, in order"; return 1; } # guard:G25-rm-tables
    with_data="$(manifest_names "$m" tables_with_data)"
    schema_only="$(manifest_names "$m" tables_schema_only)"
    [[ "$schema_only" == "$(sorted_list "${TRANSIENT_TABLES[@]}")" && "$with_data" == "$(current_durable_inventory)" ]] || { echo "its durable and schema-only tables are not the contract's 27 and 7"; return 1; } # guard:G25-rm-lists
    [[ "$(manifest_value "$m" structure_sha256)" == "$(structure_aggregate "$RM_TABLES")" ]] || { echo "its structure digest is not that of the structure digests it lists"; return 1; } # guard:G25-rm-structure
    why="$(current_dump_problem "$dump" "$(awk '{ print $1 " " $2 " " $3 }' <<<"$RM_TABLES")")" || { echo "the dump is not consistent with the manifest (its statement forms, table inventory or row counts): $why"; return 1; } # guard:G25-rm-dump-content
    why="$(files_manifest_problem "$fm")" || { echo "the Resource-files manifest cannot be relied on: $why"; return 1; } # guard:G25-rm-files-manifest
    [[ "$(manifest_value "$m" resources_manifest_file)" == "$FILES_MANIFEST_FILE" && "$(sha256sum <"$fm" | cut -c1-64)" == "$(manifest_value "$m" resources_manifest_sha256)" && "$(wc -c <"$fm" | tr -d ' ')" == "$(manifest_value "$m" resources_manifest_bytes)" ]] || { echo "the Resource-files manifest is not the one this manifest binds (its name, SHA-256 or size differs)"; return 1; } # guard:G25-rm-files-manifest-bound
    [[ "$(manifest_value "$m" taken_at)" == "$(manifest_value "$fm" taken_at)" ]] || { echo "the Resource-files manifest was not taken at the same time"; return 1; } # guard:G25-rm-taken-same
    fm_state="$(manifest_value "$fm" resources_directory)"
    files_manifest_entries "$fm"
    [[ "$fm_state" == present && "$MANIFEST_LINES" -ge 1 ]] || { echo "the Resource files are not a directory holding files, which is what a paired backup of a database with Resource-file records is"; return 1; } # guard:G25-rm-files-present
    why="$(files_archive_matches_manifest "$archive" "$fm")" || { echo "the Resource-files archive cannot be relied on: $why"; return 1; } # guard:G25-rm-archive
    [[ "$(manifest_value "$m" resources_archive_file)" == "$FILES_ARCHIVE_FILE" && "$(manifest_value "$m" resources_archive_sha256)" == "$(manifest_value "$fm" resource_files_sha256)" && "$(manifest_value "$m" resources_archive_bytes)" == "$(manifest_value "$fm" resource_files_bytes)" ]] || { echo "the archive this manifest binds is not the one the Resource-files manifest describes"; return 1; } # guard:G25-rm-archive-bound
    [[ "$(manifest_value "$m" resources_files_count)" == "$MANIFEST_LINES" && "$(manifest_value "$m" resources_asset_rows)" == "$MANIFEST_LINES" && "$(awk '$1 == "resource_assets" { print $2 }' <<<"$RM_TABLES")" == "$MANIFEST_LINES" ]] || { echo "the files, the asset rows the manifest records and the resource_assets rows it measured are not one number"; return 1; } # guard:G25-rm-assets
    [[ "$(manifest_value "$m" resources_inventory_sha256)" == "$(printf '%s' "$MANIFEST_SPEC" | sha256sum | cut -c1-64)" ]] || { echo "its Resource-file inventory digest is not that of the files the Resource-files manifest lists"; return 1; } # guard:G25-rm-inventory
    RM_DUMP_SHA="$(manifest_value "$m" database_dump_sha256)" RM_DUMP_BYTES="$(manifest_value "$m" database_dump_bytes)" RM_MIG_SHA="$(manifest_value "$m" migrations_sha256)"
    RM_STRUCT_SHA="$(manifest_value "$m" structure_sha256)" RM_FM_SHA="$(manifest_value "$m" resources_manifest_sha256)" RM_FM_BYTES="$(manifest_value "$m" resources_manifest_bytes)"
    RM_AR_SHA="$(manifest_value "$m" resources_archive_sha256)" RM_AR_BYTES="$(manifest_value "$m" resources_archive_bytes)" RM_FILES="$(manifest_value "$m" resources_files_count)"
    RM_ROWS="$(manifest_value "$m" resources_asset_rows)" RM_INV_SHA="$(manifest_value "$m" resources_inventory_sha256)"
    [[ "$(<"$m")" == "$(recovery_manifest_text)" ]] || { echo "it is not exactly what this script writes (an extra, missing or reordered line)"; return 1; } # guard:G25-rm-exact
    return 0
}

# require_recovery_unit: the paired backup an earlier stage took is present, complete, this run's and consistent in every part, or nothing may rely on it. The
# recovery manifest is the commit point: the files beside it are not a recovery unit without it, and the historical backup's files (historical.*) are never one.
require_recovery_unit() {
    local why
    load_keyring
    { state_file_ok "$RECOVERY_MANIFEST_FILE" && state_file_ok "$CURRENT_DUMP_FILE" && state_file_ok "$FILES_MANIFEST_FILE" && state_file_ok "$FILES_ARCHIVE_FILE"; } || refuse "there is no complete recovery unit in the state directory ($RECOVERY_MANIFEST_FILE, $CURRENT_DUMP_FILE, $FILES_MANIFEST_FILE and $FILES_ARCHIVE_FILE, each a private file of this script's). Nothing was changed." # guard:G25-unit-required
    why="$(recovery_unit_problem "$ROOT/$RECOVERY_MANIFEST_FILE" "$ROOT/$CURRENT_DUMP_FILE" "$ROOT/$FILES_MANIFEST_FILE" "$ROOT/$FILES_ARCHIVE_FILE")" || refuse "the recovery unit cannot be relied on: $why."
}

# --- the stages ----------------------------------------------------------------------------------------------------------------------------

cmd_baseline() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local existing migrated fixture_out table_text upgrade_text struct_text why content
    capture existing 'reading the table inventory' db_inventory
    [[ -z "$existing" ]] || refuse "the rehearsal database already holds $(grep -c . <<<"$existing") table(s). The baseline builds the v0.3.0 schema in an EMPTY database. The rehearsal is disposable: run cleanup, then up and prepare, then baseline." # guard:G13-empty-target
    arm_stage baseline on_stage_exit
    say "Building the v0.3.0 schema with the v0.3.0 application (its 15 migrations) ..."
    run_helper historical migrate migrated
    printf '%s\n' "$migrated"
    require_inventory "$(historical_inventory)" 'the 16 tables of the v0.3.0 schema' # guard:G13-baseline-inventory
    say "ok   the database holds exactly the 16 v0.3.0 tables"
    say "Loading the synthetic v0.3.0 fixture (one transaction) ..."
    run_helper historical helper:fixture-load fixture_out
    grep -Fxq 'fixture:complete=ok' <<<"$fixture_out" || refuse "the fixture helper did not report fixture:complete=ok." # guard:G13-fixture-reported
    capture table_text 'validating the snapshot' table_lines table "$fixture_out"
    capture upgrade_text 'validating the expected-upgrade digests' table_lines upgrade "$fixture_out"
    capture struct_text 'validating the structure digests' structure_lines "$fixture_out" "$(historical_inventory)"
    why="$(fixture_problem "$table_text")" || refuse "the fixture is incomplete: $why."
    load_keyring
    why="$(keys_problem "$fixture_out")" || refuse "$why."
    content="snapshot-v2"$'\n'"$(grep -E '^key:(current|previous)=[0-9a-f]{16}$' <<<"$fixture_out")"$'\n'
    content+="$(awk '{ print "table " $0 }' <<<"$table_text")"$'\n'"$(awk '{ print "upgrade " $0 }' <<<"$upgrade_text")"$'\n'"$(awk '{ print "structure " $0 }' <<<"$struct_text")"
    write_state_file "$SNAPSHOT_FILE" "$content"
    say "ok   the fixture is complete: $(grep -c . <<<"$table_text") tables, $FIXTURE_PEOPLE people"
    say "ok   the encryption keys the application used are the ones this run generated (fingerprints $CURRENT_FP current, $PREVIOUS_FP previous)"
    say "Baseline done: snapshot written to the state directory (digests only; no row, key or secret)."
}

cmd_take_backup() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    local snapshot table_text dump_tmp version taken sha bytes why
    require_prepared_source
    read_state_file snapshot "$SNAPSHOT_FILE"
    capture table_text 'validating the snapshot' table_lines table "$snapshot"
    [[ ! -e "$ROOT/$DUMP_FILE" && ! -e "$ROOT/$MANIFEST_FILE" ]] || refuse "a backup already exists in the state directory and is never overwritten. The rehearsal is disposable: run cleanup, then up, prepare, baseline and take-backup." # guard:G15-no-overwrite
    require_inventory "$(historical_inventory)" 'the 16 tables of the v0.3.0 schema' # guard:G13-backup-inventory
    require_database_matches_snapshot historical "$table_text"
    load_keyring
    why="$(keys_problem "$snapshot")" || refuse "$why."

    say "Taking the two-pass dump as the application account (structure of every table, rows of the durable tables only) ..."
    mutation_begins # the dump's temporary file is the first thing written to the state directory
    dump_tmp="$(umask 077 && mktemp -- "$ROOT/.dump.XXXXXX")"
    take_dump "$dump_tmp" || { rm -f -- "$dump_tmp"; exit 1; }
    why="$(dump_problem "$dump_tmp")" || { rm -f -- "$dump_tmp"; refuse "the dump is not what the backup contract requires: $why. It was removed."; } # guard:G15-dump-checked
    capture version 'reading the server version' server_version_text "$CID"
    [[ "$version" =~ $SERVER_VERSION_RE ]] || { rm -f -- "$dump_tmp"; refuse "the server reports version '$version', not 10.11.18."; }
    sha="$(sha256sum <"$dump_tmp" | cut -c1-64)"
    bytes="$(wc -c <"$dump_tmp" | tr -d ' ')"
    taken="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    mv -- "$dump_tmp" "$ROOT/$DUMP_FILE"
    write_state_file "$MANIFEST_FILE" "$(manifest_text "$sha" "$bytes" "$version" "$taken" "$(token_fingerprint "$(state_token "$ROOT")")")"
    why="$(manifest_problem "$ROOT/$MANIFEST_FILE" "$table_text")" || refuse "the manifest just written is not consistent with the snapshot: $why."
    why="$(dump_matches_manifest "$ROOT/$DUMP_FILE" "$ROOT/$MANIFEST_FILE")" || refuse "the dump just written does not match its manifest: $why."
    say "ok   the dump creates and drops exactly the 16 v0.3.0 tables and carries rows for the 9 durable tables only"
    say "ok   dump $bytes bytes, sha256 ${sha:0:16}...; manifest agrees with the snapshot on all 16 tables"
    say "ok   keyring: current $CURRENT_FP, previous $PREVIOUS_FP, ring $RING_FP (fingerprints only)"
    say "Backup done: $DUMP_FILE and $MANIFEST_FILE are in the state directory."
}

cmd_upgrade() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    local migrated now_out now_table now_struct upgrade_lines diff marked
    require_prepared_source
    require_backup
    require_inventory "$(historical_inventory)" 'the 16 tables of the v0.3.0 schema' # guard:G13-upgrade-from-historical
    require_database_matches_snapshot historical "$SNAPSHOT_TABLES"
    say "ok   a verified backup exists and the database still equals its snapshot"
    arm_stage upgrade on_stage_exit
    say "Upgrading with the current application's migrations (the 14 after v0.3.0) ..."
    run_helper current migrate migrated
    printf '%s\n' "$migrated"
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the current schema' # guard:G13-upgrade-inventory
    say "ok   the database holds exactly the 34 tables of the current schema (16 historical, 18 newer)"
    run_snapshot current now_out
    capture now_table 'validating the post-upgrade snapshot' table_lines table "$now_out"
    capture now_struct 'validating the post-upgrade structure' structure_lines "$now_out" "$(upgraded_inventory)"
    capture upgrade_lines 'validating the expected-upgrade digests' table_lines upgrade "$SNAPSHOT_TEXT"
    diff="$(digest_differences "$upgrade_lines" "$now_table")"
    [[ -z "$diff" ]] || refuse "the upgrade did not leave the data as expected (changed other than as expected: $(tr '\n' ' ' <<<"$diff"))." # guard:G13-upgrade-data
    say "ok   every v0.3.0 table is as expected after the upgrade (role keys renamed, the 14 migrations recorded, everything else untouched)"
    write_state_file "$UPGRADED_FILE" "upgraded-structure-v1"$'\n'"$(awk '{ print "structure " $0 }' <<<"$now_struct")"
    say "ok   the structure of the 34 tables is recorded, for the re-upgrade after a restore to equal"
    run_helper current helper:post-upgrade-mark marked
    grep -Fxq 'marker:written=ok' <<<"$marked" || refuse "the marker helper did not report marker:written=ok." # guard:G13-marker
    say "ok   one synthetic post-upgrade Person and CRM profile written (so recovery can tell the upgraded state from the historical one)"
    say "Upgrade done."
}

# The state the WP3-01 demonstration starts from and must find: the post-upgrade database (14 Persons, one marker profile that has its
# Person, 29 migrations). Four counts, tab-separated.
readonly COUNTS_SQL='SELECT (SELECT COUNT(*) FROM people), (SELECT COUNT(*) FROM contact_profiles), (SELECT COUNT(*) FROM migrations), (SELECT COUNT(*) FROM contact_profiles cp LEFT JOIN people p ON p.id = cp.person_id WHERE p.id IS NULL);'

cmd_reproduce_wp3_01() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    local counts people profiles ledger orphans diff inventory_after after_out after_table bad_tables why
    require_prepared_source
    require_backup
    load_keyring
    why="$(keyring_problem)" || refuse "$why. Nothing was changed." # guard:G16-keyring-present
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the upgraded schema (this demonstration starts from an upgraded database)' # guard:G16-upgraded-inventory
    capture counts 'reading the upgraded state' app_query "$COUNTS_SQL"
    IFS=$'\t' read -r people profiles ledger orphans <<<"$counts"
    [[ "$people" == $((FIXTURE_PEOPLE + 1)) && "$profiles" == 1 && "$ledger" == 29 && "$orphans" == 0 ]] || refuse "the database is not the post-upgrade state this demonstration starts from (people $people, contact_profiles $profiles, migrations $ledger, profiles without a Person $orphans; expected $((FIXTURE_PEOPLE + 1)), 1, 29, 0). Has it already been run?" # guard:G16-upgraded-state

    arm_stage reproduce-wp3-01 on_stage_exit
    say "WP3-01: loading the historical dump over the UPGRADED database WITHOUT clearing it first."
    say "This is the defect, not a restoration: nothing here claims the database was recovered."
    why="$(dump_matches_manifest "$ROOT/$DUMP_FILE" "$ROOT/$MANIFEST_FILE")" || refuse "the dump is not the one the manifest describes ($why); it is not loaded." # guard:G16-import-verified-dump
    guarded_app_sql "$ROOT" "$APP_DB" <"$ROOT/$DUMP_FILE" >/dev/null || fail "the dump could not be loaded"

    capture inventory_after 'reading the table inventory after the load' db_inventory
    diff="$(inventory_difference "$(historical_inventory)" "$inventory_after")"
    [[ -n "$diff" ]] || fail "UNEXPECTED: after loading the historical dump the database holds exactly the 16 historical tables, so the WP3-01 defect did not reproduce. The premise of the rehearsal is not holding; report this."
    [[ "$diff" == "$(inventory_difference "$(historical_inventory)" "$(upgraded_inventory)")" ]] || fail "UNEXPECTED: the table-inventory equality failed, but not as WP3-01 describes (differences: $(tr '\n' ';' <<<"$diff")); expected exactly the 18 newer tables to remain." # guard:G16-defect-reproduced
    say "ok   the table-inventory equality assertion FAILED, as the audit predicted: all 18 newer tables remain and no historical table is missing"
    capture counts 'reading the state after the load' app_query "$COUNTS_SQL"
    IFS=$'\t' read -r people profiles ledger orphans <<<"$counts"
    [[ "$people" == "$FIXTURE_PEOPLE" && "$profiles" == 1 && "$ledger" == 15 && "$orphans" == 1 ]] || fail "UNEXPECTED: after the load the state is people $people, contact_profiles $profiles, migrations $ledger, profiles without a Person $orphans; WP3-01 predicts $FIXTURE_PEOPLE, 1, 15, 1."
    say "ok   the post-upgrade CRM profile survives with no Person behind it, and the ledger says 15 migrations while 34 tables exist"
    run_snapshot current after_out
    capture after_table 'validating the snapshot after the load' table_lines table "$after_out"
    bad_tables="$(restore_state_differences "$SNAPSHOT_TABLES" "$after_table")"
    [[ -z "$bad_tables" ]] || fail "UNEXPECTED: the historical tables are not as the dump should leave them (differ: $(tr '\n' ' ' <<<"$bad_tables"))."
    say "ok   and yet the 16 historical tables match the pre-upgrade snapshot (durable tables identical, transient tables empty): a check of those alone would pass"
    say "WP3-01 reproduced. The database is now in the defective state; the corrected restore (slice 2.2B) starts from here, or run cleanup."
}

# --- slice 2.2B: the corrected restore, the sign-in, and the re-upgrade ------------------------------------------------------------------------

# --- slice 2.2C-1: the half-failed upgrade ------------------------------------------------------------------------------------------------------

# partial_inventory: the 32 tables of a half-failed upgrade, one per line, sorted: the 16 historical, the 15 the completed work leaves, the stub.
partial_inventory() { sorted_list "${HISTORICAL_TABLES[@]}" "${PARTIAL_NEWER_TABLES[@]}" "$STUB_TABLE"; }

# partial_ledger: the ledger of that state as `SELECT migration, batch FROM migrations ORDER BY id` prints it: v0.3.0's 15 (batch 1), then 10 (batch 2).
partial_ledger() {
    local name
    for name in "${HISTORICAL_MIGRATIONS[@]}"; do printf '%s\t1\n' "$name"; done
    for name in "${PARTIAL_MIGRATIONS[@]}"; do printf '%s\t2\n' "$name"; done
}

readonly LEDGER_SQL='SELECT migration, batch FROM migrations ORDER BY id;'

# counts_sql TABLE... : the one statement that counts each table, named from this script's own lists (a name that is not a plain table name is refused).
counts_sql() {
    local out='' table
    for table in "$@"; do
        [[ "$table" =~ ^[a-z0-9_]+$ ]] || return 1
        out+="${out:+, }(SELECT COUNT(*) FROM \`$table\`)"
    done
    printf 'SELECT %s;\n' "$out"
}

# rows_sum_sql TABLE... : the same, as one sum (0 if and only if every table is empty).
rows_sum_sql() {
    local out='' table
    for table in "$@"; do
        [[ "$table" =~ ^[a-z0-9_]+$ ]] || return 1
        out+="${out:+ + }(SELECT COUNT(*) FROM \`$table\`)"
    done
    printf 'SELECT %s;\n' "$out"
}

# The durable tables other than the ledger, sorted: the eight whose row counts DURABLE_ROWS holds.
durable_data_tables() { durable_inventory | grep -vx migrations; }

# stub_sql: reads the stub back as the server describes it (compared with STUB_EXPECTED).
stub_sql() {
    printf "SELECT t.engine, t.table_collation, t.table_comment, t.table_type, (SELECT GROUP_CONCAT(CONCAT(c.column_name, ':', c.column_type, ':', c.is_nullable) ORDER BY c.ordinal_position SEPARATOR ',') FROM information_schema.columns c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name), (SELECT COUNT(*) FROM information_schema.statistics s WHERE s.table_schema = t.table_schema AND s.table_name = t.table_name), (SELECT COUNT(*) FROM information_schema.key_column_usage k WHERE k.table_schema = t.table_schema AND (k.table_name = t.table_name OR k.referenced_table_name = t.table_name)), (SELECT COUNT(*) FROM \`%s\`) FROM information_schema.tables t WHERE t.table_schema = '%s' AND t.table_name = '%s';\n" "$STUB_TABLE" "$APP_DB" "$STUB_TABLE"
}

# The schema as the server reports it: tables, columns, indexes, foreign keys and their rules, five fixed statements about the one database. A digest of
# their output is what `half-fail-upgrade` records and `restore-historical` compares: SQL alone, so nothing is started against the partial schema.
schema_sql() { # N (1 to 5)
    local db="'$APP_DB'"
    case "$1" in
        1) printf 'SELECT table_name, table_type, engine, row_format, table_collation, table_comment, create_options FROM information_schema.tables WHERE table_schema = %s ORDER BY table_name;\n' "$db" ;;
        2) printf "SELECT table_name, ordinal_position, column_name, column_type, is_nullable, IFNULL(column_default, '<null>'), IFNULL(character_set_name, ''), IFNULL(collation_name, ''), column_key, extra FROM information_schema.columns WHERE table_schema = %s ORDER BY table_name, ordinal_position;\n" "$db" ;;
        3) printf 'SELECT table_name, index_name, seq_in_index, column_name, non_unique, IFNULL(sub_part, 0), index_type FROM information_schema.statistics WHERE table_schema = %s ORDER BY table_name, index_name, seq_in_index;\n' "$db" ;;
        4) printf 'SELECT table_name, constraint_name, ordinal_position, column_name, referenced_table_name, referenced_column_name FROM information_schema.key_column_usage WHERE table_schema = %s AND referenced_table_name IS NOT NULL ORDER BY table_name, constraint_name, ordinal_position;\n' "$db" ;;
        5) printf 'SELECT table_name, constraint_name, referenced_table_name, update_rule, delete_rule FROM information_schema.referential_constraints WHERE constraint_schema = %s ORDER BY table_name, constraint_name;\n' "$db" ;;
        *) return 1 ;;
    esac
}

# schema_digest VAR: the sha256 of the five statements' output, in order and separated, in VAR.
schema_digest() {
    local part text='' n
    for n in 1 2 3 4 5; do
        capture part "reading the schema (part $n)" app_query "$(schema_sql "$n")"
        text+="--- $n"$'\n'"$part"$'\n'
    done
    printf -v "$1" '%s' "$(printf '%s' "$text" | sha256sum | cut -c1-64)"
}

# security_digest VAR: three MD5 digests (tab-separated) of the text of every row of role_assignments, accounts and security_events: who holds which
# authority, in what state, and what was recorded about it. Computed by the server, so it needs no application.
security_digest() {
    local sql out
    sql="SELECT (SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, person_id, role_key, IFNULL(granted_by_account_id, ''), granted_at) ORDER BY id SEPARATOR ';'), '')) FROM role_assignments), (SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, person_id, email_canonical, status, security_generation, IFNULL(disabled_at, '')) ORDER BY id SEPARATOR ';'), '')) FROM accounts), (SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|', id, occurred_at, type, IFNULL(actor_account_id, ''), IFNULL(subject_person_id, ''), outcome, IFNULL(context, '')) ORDER BY id SEPARATOR ';'), '')) FROM security_events);"
    capture out 'reading the security tables' app_query "$sql"
    [[ "$out" =~ ^[0-9a-f]{32}$'\t'[0-9a-f]{32}$'\t'[0-9a-f]{32}$ ]] || refuse "the security tables could not be digested (the server answered something else)."
    printf -v "$1" '%s' "$out"
}

# G20. The SQL-only recognition of a half-failed upgrade, used by `half-fail-upgrade` on what it just made and by `restore-historical` on what it is
# asked to clear. The 32 tables and the plain-table, trigger, routine and event checks come before it (require_inventory, require_restore_start).
# Refuses, saying what is not as it must be, and changes nothing.
require_partial_state() {
    local ledger stub rows durable tables
    capture ledger 'reading the migration ledger' app_query "$LEDGER_SQL"
    [[ "$ledger" == "$(partial_ledger)" ]] || refuse "the migration ledger is not exactly v0.3.0's 15 migrations (batch 1) followed by the 10 that complete before the Relationships migration fails (batch 2), in that order. Nothing was changed." # guard:G20-partial-ledger
    capture stub 'reading the stub table' app_query "$(stub_sql)"
    [[ "$stub" == "$STUB_EXPECTED" ]] || refuse "$STUB_TABLE is not the stub this script places (a base table, InnoDB, utf8mb4_unicode_ci, comment '$STUB_COMMENT', one column stub CHAR(1) NOT NULL, no index, no foreign key, no row). Nothing was changed." # guard:G20-stub-identity
    capture rows 'counting the rows of the newer tables' app_query "$(rows_sum_sql "${PARTIAL_NEWER_TABLES[@]}" "$STUB_TABLE")"
    [[ "$rows" == 0 ]] || refuse "a table the upgrade made, or the stub, holds rows ($rows); a half-failed upgrade leaves every one of them empty. Nothing was changed." # guard:G20-partial-empty
    mapfile -t tables < <(durable_data_tables)
    capture durable 'counting the rows of the durable tables' app_query "$(counts_sql "${tables[@]}")"
    [[ "$durable" == "$DURABLE_ROWS" ]] || refuse "the durable tables do not hold the fixture's rows (${durable//$'\t'/, }; expected ${DURABLE_ROWS//$'\t'/, } for ${tables[*]}). Nothing was changed." # guard:G20-partial-durable
}

# G20. The schema and the security tables are what `half-fail-upgrade` recorded and verified (against the snapshot and the first upgrade's structure),
# and the record is intact: a private file of exactly the shape that stage writes.
require_partial_record() {
    local recorded schema security shape
    state_file_ok "$HALF_FILE" || refuse "'$HALF_FILE' is missing from the state directory, or is not a private file of this script's: this schema was not left and checked by this script's half-fail-upgrade stage, and restore-historical does not clear a partial schema it did not make. Nothing was changed." # guard:G20-partial-recorded
    recorded="$(<"$ROOT/$HALF_FILE")"
    shape="^halfmigrated-v1"$'\n'"schema [0-9a-f]{64}"$'\n'"security [0-9a-f]{32}"$'\t'"[0-9a-f]{32}"$'\t'"[0-9a-f]{32}\$"
    [[ "$recorded" =~ $shape ]] || refuse "'$HALF_FILE' is not exactly what half-fail-upgrade writes. Nothing was changed." # guard:G20-partial-recorded-exact
    schema_digest schema
    [[ "$schema" == "$(sed -n 's/^schema //p' <<<"$recorded")" ]] || refuse "the schema is not the one half-fail-upgrade recorded (a table, column, index or foreign key differs). Nothing was changed." # guard:G20-partial-schema
    security_digest security
    [[ "$security" == "$(sed -n 's/^security //p' <<<"$recorded")" ]] || refuse "the security tables (role assignments, accounts, security events) are not as half-fail-upgrade recorded them. Nothing was changed." # guard:G20-partial-security
}

# G21. Called after a restore that cleared a database carrying the revocation `revoke-after-backup` made. It reads the database itself and
# requires the revocation and its event to be gone, then says what that means: authority revoked after the backup came back.
report_reversal() {
    local observed
    capture observed 'reading the security tables after the restore' app_query "$REVOKE_SQL"
    [[ "$observed" == "$REVOKE_BEFORE" ]] || refuse "the restore reported success but the security tables are not what the backup held: Person $REVOKE_PERSON's role assignment and the event of its revocation are not as they were (role assignments, security events, the assignment present, role.revoked events, the event of Person $REVOKE_PERSON's revocation: ${observed//$'\t'/, }; expected ${REVOKE_BEFORE//$'\t'/, })." # guard:G21-reversed
    say "ok   SECURITY STATE REVERSED. Before the restore the database held a revocation made after the backup: Person $REVOKE_PERSON's role assignment ($REVOKE_ASSIGNMENT) was removed and that Person's role.revoked event (id $REVOKE_EVENT) recorded it (11 role assignments, 9 security events)."
    say "     After it the assignment is back and the event is gone (12 role assignments, 8 security events), because the backup predates both."
    say "     An apparently successful restore therefore RESURRECTS authority that was revoked after the backup, and erases the record that it was revoked. Nothing here reconciles"
    say "     that, replays the event or repairs it. If this were production: keep the service in maintenance, treat the security state as not established, and escalate."
}

# G17. What the corrected restore may start from, and nothing else: plain tables only, with no view, trigger, routine or event, in one of THREE
# states, each with the table set and the counts that go with it and no other pairing. Two are the exact 34-table schema: the upgraded database
# (14 Persons, the marker profile, 29 migrations) and what `reproduce-wp3-01` makes of it (13 Persons, the marker profile left without its Person,
# 15 migrations). The third (slice 2.2C-1, G20) is the exact 32-table schema a half-failed upgrade leaves, recognised by `require_partial_state`.
# It is established by SQL alone: a database in the second or third state has newer tables and an older ledger, a state no application can be
# expected to boot against, so nothing here starts one. Sets RESTORE_FROM to `upgraded` (the first two) or `partial`.
RESTORE_FROM=''
require_restore_start() {
    local counts kinds objects actual
    capture actual 'reading the table inventory' db_inventory
    RESTORE_FROM=upgraded
    [[ "$actual" != "$(partial_inventory)" ]] || RESTORE_FROM=partial
    [[ "$RESTORE_FROM" == partial ]] || require_inventory "$(upgraded_inventory)" 'the 34 tables of the upgraded schema or the 32 tables a half-failed upgrade leaves (the corrected restore starts from the upgraded, the WP3-01 or the half-failed state and from nothing else)' # guard:G17-start-inventory
    capture kinds 'reading the table types' app_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$APP_DB' AND table_type <> 'BASE TABLE';"
    [[ "$kinds" == 0 ]] || refuse "the rehearsal database holds $kinds object(s) that are not plain tables (a view, a sequence or a system-versioned table). The restore clears only what it can name, and does not widen that to them. Nothing was changed." # guard:G17-object-types
    capture objects 'reading the triggers, routines and events' app_query "SELECT (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '$APP_DB'), (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = '$APP_DB'), (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = '$APP_DB');"
    [[ "$objects" == $'0\t0\t0' ]] || refuse "the rehearsal database holds a trigger, a routine or an event (triggers, routines, events: ${objects//$'\t'/, }). The restore clears tables only. Nothing was changed." # guard:G17-no-other-objects
    capture counts 'reading the state to restore from' app_query "$COUNTS_SQL"
    if [[ "$RESTORE_FROM" == partial ]]; then
        [[ "$counts" == "$FIXTURE_PEOPLE"$'\t0\t25\t0' ]] || refuse "the 32 tables are those of a half-failed upgrade, but the counts are not (Persons, profiles, migrations, profiles without a Person: ${counts//$'\t'/, }; expected $FIXTURE_PEOPLE, 0, 25, 0). Nothing was changed." # guard:G20-partial-counts
        require_partial_state
        require_partial_record
    else
        [[ "$counts" == "$((FIXTURE_PEOPLE + 1))"$'\t1\t29\t0' || "$counts" == "$FIXTURE_PEOPLE"$'\t1\t15\t1' ]] || refuse "the database is neither the post-upgrade state nor the state reproduce-wp3-01 leaves (Persons, profiles, migrations, profiles without a Person: ${counts//$'\t'/, }; expected $((FIXTURE_PEOPLE + 1)), 1, 29, 0 or $FIXTURE_PEOPLE, 1, 15, 1). Nothing was changed." # guard:G17-start-state
    fi
}

# clear_list STATE: the tables the restore clears from STATE (`upgraded`: the 34; `partial`: the 32), one per line, from this script's own lists.
clear_list() {
    case "$1" in
        upgraded) upgraded_inventory ;;
        partial) partial_inventory ;;
        *) return 1 ;;
    esac
}

# The statement that clears the application schema: DROP TABLE for exactly the tables of the verified inventory (34 or 32), named from this script's
# own lists (never from what the database returned and never from the caller), with foreign keys off for the one session that runs it. There is no
# IF EXISTS, so a table that is not there is a failure, and no DROP DATABASE: the database, its grants and its sentinel are not touched.
clear_statement() { # STATE
    local names='' table list
    list="$(clear_list "$1")" || return 1
    for table in $list; do names+="${names:+, }\`$table\`"; done
    printf 'SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE %s;\nSET FOREIGN_KEY_CHECKS=1;\n' "$names"
}

# verify_restored_state: the database is exactly what a restore of the dump must leave, judged by the historical application's own snapshot
# against the ORIGINAL snapshot (see emptied_transients for the three states): the 16 tables and nothing else, the migration ledger the 15
# migrations of v0.3.0, every durable table identical row for row, every transient table present and empty, the structure of every table the
# one it had, and the post-upgrade marker gone. Needs require_backup.
verify_restored_state() {
    local out table_text struct_text diff name
    require_inventory "$(historical_inventory)" 'the 16 tables of the v0.3.0 schema, with no table of the upgrade left' # guard:G17-restored-inventory
    run_snapshot historical out
    capture table_text 'validating the restored snapshot' table_lines table "$out"
    capture struct_text 'validating the restored structure' structure_lines "$out" "$(historical_inventory)"
    diff="$(restore_state_differences "$SNAPSHOT_TABLES" "$table_text")"
    [[ -z "$diff" ]] || refuse "the database is not what the dump should have restored (differs from the snapshot: $(tr '\n' ' ' <<<"$diff"))." # guard:G17-restored-data
    diff="$(digest_differences "$SNAPSHOT_STRUCTURE" "$struct_text")"
    [[ -z "$diff" ]] || refuse "the structure of the restored tables is not the original's (differs: $(tr '\n' ' ' <<<"$diff"))." # guard:G17-restored-structure
    [[ "$(awk '$1 == "migrations" { print $2 }' <<<"$table_text")" == 15 ]] || refuse "the migration ledger is not the 15 migrations v0.3.0 shipped." # guard:G17-restored-ledger
    [[ "$(awk '$1 == "people" { print $2 }' <<<"$table_text")" == "$FIXTURE_PEOPLE" ]] || refuse "the Persons are not the fixture's $FIXTURE_PEOPLE: the post-upgrade marker Person is still there." # guard:G17-marker-gone
    for name in "${HISTORICAL_TABLES[@]}"; do
        in_array "$name" "${TRANSIENT_TABLES[@]}" || [[ "$name" == migrations ]] || note "$name: $(awk -v n="$name" '$1 == n { print $2 }' <<<"$table_text") rows, identical to the snapshot"
    done
}

# verify_signin: the restored v0.3.0 application signs the administrator in over its real HTTP stack, with the password and then the TOTP code
# (whose secret was enrolled under the PREVIOUS key), and the database afterwards is exactly what it was. Needs require_backup.
verify_signin() {
    local out lines diff expected
    run_helper historical helper:sign-in out
    for expected in signin:enrolment-needs-previous-key=ok signin:password=ok signin:second-factor=ok signin:me=ok signin:rolled-back=ok signin:complete=ok; do
        grep -Fxq "$expected" <<<"$out" || refuse "the sign-in helper did not report $expected." # guard:G18-signin-lines
    done
    capture lines 'validating the table digests after the sign-in' table_lines table "$out"
    diff="$(restore_state_differences "$SNAPSHOT_TABLES" "$lines")"
    [[ -z "$diff" ]] || refuse "signing in changed the database (differs from the restored state: $(tr '\n' ' ' <<<"$diff"))." # guard:G18-signin-unchanged
    say "ok   the restored v0.3.0 application accepted the administrator's password, then an authenticator code whose secret needs the previous key, then showed /me"
    say "ok   the sign-in was rolled back, and the database after it is exactly what it was before"
}

cmd_restore_historical() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local why
    require_backup
    load_keyring
    why="$(keyring_problem)" || refuse "$why. Nothing was changed." # guard:G17-keyring-present
    local observed statement count revoked=0
    require_restore_start
    why="$(dump_matches_manifest "$ROOT/$DUMP_FILE" "$ROOT/$MANIFEST_FILE")" || refuse "the dump is not the one the manifest describes ($why). Nothing was changed." # guard:G17-dump-verified
    require_failed_state_preserved # R-preserve (WU3): Resource records or files are cleared only after a failed-state preservation that equals them
    if [[ "$RESTORE_FROM" == partial ]]; then
        say "ok   every precondition holds: this run's sentinel and account, the verified backup and keyring, and the 32 plain tables, the 25 migrations, the stub and the recorded schema and security tables of a half-failed upgrade"
    else
        say "ok   every precondition holds: this run's sentinel and account, the verified backup and keyring, and a schema of exactly the 34 plain tables"
    fi
    if [[ "$RESTORE_FROM" == upgraded ]]; then # is the revocation `revoke-after-backup` makes in the database that is about to be cleared?
        capture observed 'reading the security tables' app_query "$REVOKE_SQL"
        [[ "$observed" != "$REVOKE_AFTER" ]] || revoked=1
    fi
    capture statement 'building the clearing statement' clear_statement "$RESTORE_FROM"
    count="$(grep -c . <<<"$(clear_list "$RESTORE_FROM")")"

    arm_stage restore-historical on_stage_exit
    say "Clearing the application schema (DROP TABLE for exactly the $count tables, nothing else) ..."
    guarded_app_sql "$ROOT" "$APP_DB" <<<"$statement" >/dev/null || fail "the application schema could not be cleared" # (a here-string, not a pipe: a refusal inside a pipe would end only the pipe's subshell)
    require_inventory '' 'empty: the intended tables are all gone' # guard:G17-cleared
    say "ok   the rehearsal database holds no table"
    why="$(dump_matches_manifest "$ROOT/$DUMP_FILE" "$ROOT/$MANIFEST_FILE")" || refuse "the dump is not the one the manifest describes ($why); it is not loaded." # guard:G17-import-verified-dump
    say "Loading the verified historical dump ..."
    guarded_app_sql "$ROOT" "$APP_DB" <"$ROOT/$DUMP_FILE" >/dev/null || fail "the dump could not be loaded"
    verify_restored_state
    say "ok   the 16 tables of v0.3.0 and no other; the ledger is the 15 migrations of v0.3.0; the post-upgrade marker Person and profile are gone"
    say "ok   every durable table equals the pre-upgrade snapshot (the administrator, role assignments, MFA factors, recovery codes and membership grants included), and every transient table is present and empty"
    say "ok   the structure of all 16 tables is the original's"
    if ((revoked == 1)); then report_reversal; fi
    say "Restored. Next: restore-rehearsal.sh verify-signin"
}

cmd_verify_signin() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    require_backup
    verify_restored_state
    say "ok   the database is exactly the restored historical state"
    verify_signin
    say "Sign-in verified. Next: restore-rehearsal.sh reupgrade"
}

cmd_reupgrade() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local migrated out now_table now_struct upgraded_structure expected_lines diff counts
    require_backup
    read_state_file upgraded_structure "$UPGRADED_FILE"
    capture upgraded_structure 'validating the recorded upgrade structure' structure_lines "$upgraded_structure" "$(upgraded_inventory)"
    verify_restored_state
    verify_signin
    say "ok   the database is the verified restored historical state, and its application signs in"

    arm_stage reupgrade on_stage_exit
    say "Upgrading again with the current application's migrations (the 14 after v0.3.0) ..."
    run_helper current migrate migrated
    printf '%s\n' "$migrated"
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the current schema' # guard:G19-inventory
    say "ok   the database holds exactly the 34 tables of the current schema (the duplicate-table failure of WP3-01 did not occur)"
    run_snapshot current out
    capture now_table 'validating the re-upgrade snapshot' table_lines table "$out"
    capture now_struct 'validating the re-upgrade structure' structure_lines "$out" "$(upgraded_inventory)"
    capture expected_lines 'validating the expected-upgrade digests' table_lines upgrade "$SNAPSHOT_TEXT"
    diff="$(digest_differences "$(emptied_transients "$expected_lines")" "$now_table")"
    [[ -z "$diff" ]] || refuse "the re-upgrade did not leave the data as expected (changed other than as expected: $(tr '\n' ' ' <<<"$diff"))." # guard:G19-data
    say "ok   every v0.3.0 table is as expected: role keys renamed, the ledger the 29 migrations, every durable record preserved, transient tables empty"
    diff="$(digest_differences "$upgraded_structure" "$now_struct")"
    [[ -z "$diff" ]] || refuse "the structure after the re-upgrade is not the structure after the first upgrade (differs: $(tr '\n' ' ' <<<"$diff"))." # guard:G19-structure
    say "ok   the structure of all 34 tables equals the structure after the first upgrade"
    capture counts 'reading the re-upgraded state' app_query "$COUNTS_SQL"
    [[ "$counts" == "$FIXTURE_PEOPLE"$'\t0\t29\t0' ]] || refuse "the re-upgraded database is not the expected one (Persons, profiles, migrations, profiles without a Person: ${counts//$'\t'/, }; expected $FIXTURE_PEOPLE, 0, 29, 0): the post-upgrade marker came back." # guard:G19-marker-absent
    say "ok   no post-upgrade marker: $FIXTURE_PEOPLE Persons, no CRM profile"
    say "Re-upgraded. The cycle is complete: baseline, backup, upgrade, WP3-01 reproduced, restore, sign-in, re-upgrade."
}

# lines_named TEXT NAMES: the lines of TEXT (`<table> ...`, one per line) whose first word is one of NAMES (one per line).
lines_named() {
    awk -v names="$(printf '\n%s\n' "$2")" 'BEGIN { n = split(names, a, "\n"); for (i = 1; i <= n; i++) if (a[i] != "") want[a[i]] = 1 } $1 in want' <<<"$1"
}

# half_line OUTPUT KEY: the value of the one `half-migrate:KEY=` line the helper printed (nothing if there is none or more than one).
half_line() {
    local found
    found="$(sed -n "s/^half-migrate:$2=//p" <<<"$1")"
    [[ -n "$found" && "$found" != *$'\n'* ]] || return 0
    printf '%s' "$found"
}

cmd_half_fail_upgrade() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local upgraded_structure out snap_out now_table now_struct diff attempt expected schema security committed
    require_backup
    [[ ! -e "$ROOT/$HALF_FILE" && ! -L "$ROOT/$HALF_FILE" ]] || refuse "a half-failed state is already recorded in the state directory and is never overwritten. The rehearsal is disposable: run cleanup, then up, prepare and the stages again. Nothing was changed." # guard:G20-no-overwrite
    read_state_file upgraded_structure "$UPGRADED_FILE"
    capture upgraded_structure 'validating the recorded upgrade structure' structure_lines "$upgraded_structure" "$(upgraded_inventory)"
    say "Checking that the database is the verified restored v0.3.0 database (the stub is placed in nothing else) ..."
    verify_restored_state
    say "ok   the database is exactly the restored historical state, and the first upgrade's structure is recorded"

    arm_stage half-fail-upgrade on_stage_exit
    say "Placing the stub: one table named $STUB_TABLE, the third table the Relationships migration creates (no migration is changed) ..."
    guarded_app_sql "$ROOT" "$APP_DB" <<<"$STUB_SQL" >/dev/null || fail "the stub could not be created"
    require_inventory "$(sorted_list "${HISTORICAL_TABLES[@]}" "$STUB_TABLE")" 'the 16 tables of v0.3.0 and the stub' # guard:G20-stub-placed
    say "Running the current migrations twice; both must fail ..."
    run_helper current helper:half-migrate out
    for expected in half-migrate:first-ledger=25 half-migrate:first-tables=32 half-migrate:second-ledger=25 half-migrate:second-tables=32 half-migrate:complete=ok; do
        grep -Fxq "$expected" <<<"$out" || refuse "the half-migrate helper did not report $expected." # guard:G20-helper-reported
    done
    for attempt in first second; do
        [[ "$(half_line "$out" "$attempt-failure-class")" == 'Illuminate\Database\QueryException' && "$(half_line "$out" "$attempt-failure-code")" == 42S01 && "$(half_line "$out" "$attempt-failure-driver-code")" == 1050 ]] || refuse "the $attempt migration did not fail as a duplicate table does (expected Illuminate\\Database\\QueryException, SQLSTATE 42S01, error 1050; got '$(half_line "$out" "$attempt-failure-class")', '$(half_line "$out" "$attempt-failure-code")', '$(half_line "$out" "$attempt-failure-driver-code")')." # guard:G20-failure-class
    done
    [[ "$(half_line "$out" first-failure-table)" == "$STUB_TABLE" ]] || refuse "the first migration failed creating '$(half_line "$out" first-failure-table)', not the stub $STUB_TABLE." # guard:G20-failure-table
    [[ "$(half_line "$out" second-failure-table)" == person_relationships ]] || refuse "the repeated migration failed creating '$(half_line "$out" second-failure-table)', not person_relationships, the first table the migration creates; it did not refuse as an unrepaired partial schema must." # guard:G20-repeat-refuses
    say "ok   both runs failed as a duplicate table does: QueryException, SQLSTATE 42S01, error 1050; the first on the stub, the second on person_relationships (the first table of that migration, which now exists)"

    require_inventory "$(partial_inventory)" 'the 32 tables of a half-failed upgrade' # guard:G20-partial-built
    require_partial_state
    say "ok   the database holds exactly the 32 tables: v0.3.0's 16, the 13 the ten completed migrations made, the first two Relationships tables and the stub; the ledger is 25 migrations and does not hold the Relationships migration"
    run_snapshot current snap_out
    capture now_table 'validating the half-failed snapshot' table_lines table "$snap_out"
    capture now_struct 'validating the half-failed structure' structure_lines "$snap_out" "$(partial_inventory)"
    diff="$(restore_state_differences "$SNAPSHOT_TABLES" "$now_table")"
    [[ "$diff" == migrations ]] || refuse "the historical rows are not as the restored database held them (differs from the snapshot: $(tr '\n' ' ' <<<"$diff"); only the migration ledger may differ)." # guard:G20-historical-preserved
    say "ok   every historical row is as it was; only the migration ledger differs (25 records, not 15)"
    diff="$(digest_differences "$SNAPSHOT_STRUCTURE" "$(lines_named "$now_struct" "$(historical_inventory)")")"
    [[ -z "$diff" ]] || refuse "the structure of the historical tables changed (differs: $(tr '\n' ' ' <<<"$diff"))." # guard:G20-structure-historical
    committed="$(sorted_list "${PARTIAL_UNCHANGED_TABLES[@]}")"
    diff="$(digest_differences "$(lines_named "$upgraded_structure" "$committed")" "$(lines_named "$now_struct" "$committed")")"
    [[ -z "$diff" ]] || refuse "the 14 tables the completed work made, which nothing later changes, are not the structure the first upgrade made (differs: $(tr '\n' ' ' <<<"$diff"))." # guard:G20-structure-committed
    [[ "$(lines_named "$now_struct" "$PROVENANCE_TABLE")" != "$(lines_named "$upgraded_structure" "$PROVENANCE_TABLE")" ]] || refuse "the structure of $PROVENANCE_TABLE is the fully upgraded table's: the later provenance migration has run, which a half-failed upgrade never reaches." # guard:G20-provenance-not-yet
    [[ "$(half_line "$out" contact-methods-structure)" == ok ]] || refuse "$PROVENANCE_TABLE is not the structure its creating migration left (the helper reported: '$(half_line "$out" contact-methods-structure)')." # guard:G20-structure-contact-methods
    [[ "$(lines_named "$now_struct" "$STUB_TABLE")" != "$(lines_named "$upgraded_structure" "$STUB_TABLE")" ]] || refuse "the structure of $STUB_TABLE is the real table's: it is not the stub." # guard:G20-stub-is-a-stub
    say "ok   the structure of the 16 historical tables is the original's, of the 14 committed tables nothing later changes the first upgrade's, of $PROVENANCE_TABLE what its creating migration left (without the later provenance column), and the stub is not the real $STUB_TABLE"
    schema_digest schema
    security_digest security
    write_state_file "$HALF_FILE" "halfmigrated-v1"$'\n'"schema $schema"$'\n'"security $security"
    say "ok   the schema and the security tables are recorded, for restore-historical to compare the database with"
    say "Half-failed. The database is in the one partial state restore-historical will clear. Next: restore-rehearsal.sh restore-historical"
}

cmd_revoke_after_backup() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local counts observed out expected
    require_backup
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the upgraded schema (the revocation is made in the upgraded database)' # guard:G21-start-inventory
    capture counts 'reading the upgraded state' app_query "$COUNTS_SQL"
    [[ "$counts" == "$((FIXTURE_PEOPLE + 1))"$'\t1\t29\t0' ]] || refuse "the database is not the post-upgrade state this stage starts from (Persons, profiles, migrations, profiles without a Person: ${counts//$'\t'/, }; expected $((FIXTURE_PEOPLE + 1)), 1, 29, 0). Nothing was changed." # guard:G21-start-state
    capture observed 'reading the security tables' app_query "$REVOKE_SQL"
    [[ "$observed" == "$REVOKE_BEFORE" ]] || refuse "the security tables are not as the upgrade left them (role assignments, security events, the assignment to revoke present, role.revoked events, the event of Person $REVOKE_PERSON's revocation: ${observed//$'\t'/, }; expected ${REVOKE_BEFORE//$'\t'/, }). Has it already been run? Nothing was changed." # guard:G21-before-state

    arm_stage revoke-after-backup on_stage_exit
    say "Revoking one role assignment AFTER the backup, as the application's RevokeRole does: remove it and record the event, in one transaction ..."
    run_helper current helper:revoke out
    for expected in revoke:role-removed=ok revoke:event-recorded=ok revoke:complete=ok; do
        grep -Fxq "$expected" <<<"$out" || refuse "the revoke helper did not report $expected." # guard:G21-helper-reported
    done
    capture observed 'reading the security tables after the revocation' app_query "$REVOKE_SQL"
    [[ "$observed" == "$REVOKE_AFTER" ]] || refuse "the security tables are not what the revocation leaves (role assignments, security events, the assignment present, role.revoked events, the event of Person $REVOKE_PERSON's revocation: ${observed//$'\t'/, }; expected ${REVOKE_AFTER//$'\t'/, })." # guard:G21-after-state
    say "ok   Person $REVOKE_PERSON's role assignment is gone (11 role assignments) and the role.revoked event $REVOKE_EVENT about that Person records it (9 security events); the backup holds neither change"
    say "Revoked. Next: restore-rehearsal.sh restore-historical, which will say whether the restore reversed it."
}

# --- slice 2.2C-2: the Resource files --------------------------------------------------------------------------------------------------------

# files_action_policy ACTION: what each files action may do. The three read-only actions get the files volume read-only; the three that write get
# it writable; a name not listed is refused. Sets FILES_RW (true or false) and FILES_STDIN (1 when the action reads an archive from standard input).
files_action_policy() {
    FILES_RW='' FILES_STDIN=0
    case "$1" in
        check | archive | verify-archive | inspect-aside) FILES_RW=false ;;
        seed | aside | restore) FILES_RW=true ;;
        *) ;;
    esac
    case "$1" in
        verify-archive | restore) FILES_STDIN=1 ;;
        *) ;;
    esac
    [[ -n "$FILES_RW" ]] || refuse "'$1' is not a files action the rehearsal runs." # guard:G22-f-allowlist
}

# The files volume, proven to be the rehearsal's before every use. It is CREATED only by the first files action of `seed-files` or `backup-files`: the caller
# says so with an explicit argument (CREATE is 1) to that one call, never through the environment (an inherited variable grants nothing, and FILES_* is
# refused when the script starts), and only while the state directory holds no record that it was ever established
# (`files.volume`, written once the volume has been created and verified, or found and verified). A volume that is missing after that, or missing when no
# command may create it, is a refusal: a new, empty volume is never silently put where an established one was. Creating a volume that exists changes
# nothing, so a foreign volume of this name is found by the identity check, not overwritten by the create.
files_volume_ready() { # TOKEN CREATE: CREATE is 1 only for the first files action of seed-files or backup-files; anything else, or nothing, may not create
    local token="$1" create="${2:-0}" why recorded=0 expected
    expected="files-volume-v1"$'\n'"$(token_fingerprint "$token")"
    if [[ -e "$ROOT/$FILES_VOLUME_MARKER" || -L "$ROOT/$FILES_VOLUME_MARKER" ]]; then
        { state_file_ok "$FILES_VOLUME_MARKER" && [[ "$(<"$ROOT/$FILES_VOLUME_MARKER")" == "$expected" ]]; } || refuse "the record of the files volume in the state directory is not this run's." # guard:G22-f-marker
        recorded=1
    fi
    if ! dkr volume inspect "$FILES_VOLUME_NAME" >/dev/null 2>&1; then
        [[ $recorded == 0 ]] || refuse "the files volume $FILES_VOLUME_NAME was established earlier in this run and is now missing. It is never recreated: its files are gone. Run cleanup and start again." # guard:G22-f-volume-gone
        [[ "$create" == 1 ]] || refuse "there is no files volume, and only the first files action of seed-files or backup-files may create it. Run one of those first, or cleanup and start again." # guard:G22-f-volume-not-established
        mutation_begins # creating the volume is a change
        dkr volume create --label "$LABEL_NS_KEY=$LABEL_NS_VALUE" --label "$LABEL_NS_KEY.role=files-data" --label "$LABEL_NS_KEY.run-token=$token" "$FILES_VOLUME_NAME" >/dev/null || fail "the files volume could not be created"
    fi
    why="$(files_volume_identity_problem "$FILES_VOLUME_NAME" "$token" '')" || refuse "the files volume is not the rehearsal's: $why." # guard:G22-f-volume
    [[ $recorded == 1 ]] || write_state_file "$FILES_VOLUME_MARKER" "$expected"
}

# run_files [--create] ACTION [ARGUMENT]: one fixed action of files.php in ONE disposable files container, after the checks. The container has no network and
# no credential; its output and exit status are the action's. The ARGUMENT is built by this script from values it has validated (a file list, or a stamp).
# Standard input goes to the container for the two actions that read an archive. --create lets this call create the files volume, and only seed-files's
# `seed` and backup-files's `check` (each the first files action of its command) may say so: no other action can acquire that permission.
run_files() {
    local create=0 action argument token why ids id interactive=() ro_opt=''
    if [[ "${1:-}" == --create ]]; then
        create=1
        shift
    fi
    action="$1" argument="${2:-}"
    files_action_policy "$action"
    [[ $create == 0 || "$action" == seed || "$action" == check ]] || refuse "'$action' may not create the files volume; only the first files action of seed-files or backup-files may." # guard:G22-f-create-scope
    [[ "$FILES_RW" == false ]] || mutation_begins # seed, aside and restore write to the files volume
    ((FILES_STDIN == 0)) || interactive=(-i)
    resolve_project_container "$ROOT"
    token="$(state_token "$ROOT")"
    inventory ids 'the labelled containers' labelled_containers
    [[ "$ids" == "$CID" ]] || refuse "a rehearsal container other than the database exists (an interrupted run). Run: restore-rehearsal.sh cleanup --dry-run, then cleanup." # guard:G22-f-no-leftover
    why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" '')" || refuse "the source volume is not the rehearsal's (has 'prepare' been run?): $why." # guard:G22-f-src-volume
    files_volume_ready "$token" "$create"
    resolve_php_image
    [[ "$FILES_RW" == true ]] || ro_opt=',readonly' # guard:G22-f-readonly-mount
    # Docker sets up a container's mounts when it is CREATED, and creates a named volume that is missing then (empty, unlabelled), whether the action reads or
    # writes. So from the moment the create is attempted a failure may have changed the files volume, and the change record says so first. Every refusal above
    # this line came before any of that, and a failure to make the record stops here, before Docker is asked for anything.
    mutation_begins # guard:G23-create-mutation
    id="$(create_verified "$ROOT" "$token" files none plain_docker -- \
        --network none \
        --mount "type=volume,source=$SRC_VOLUME_NAME,target=$FILES_SRC_MOUNT,readonly" \
        --mount "type=volume,source=$FILES_VOLUME_NAME,target=$FILES_MOUNT$ro_opt" \
        --tmpfs /tmp:rw,noexec,nosuid,size=64m,uid=33,gid=33 \
        ${interactive[@]+"${interactive[@]}"} \
        "$PHP_IMAGE_ID" php "$FILES_SRC_MOUNT/harness/files.php" "$action" ${argument:+"$argument"})" || exit "$?"
    files_mounts_recheck "$id" "$token"
    start_and_remove "$id" ${interactive[@]+"${interactive[@]}"}
}

# files_mounts_recheck ID TOKEN: the identity of the files volume and of the source volume was proven BEFORE the container was created, and Docker creates a
# named volume that is missing when it sets up a mount (empty, unlabelled, plain local). So once the create has returned both are proven again, with the new
# container as the only consumer allowed: a volume that vanished and was replaced in between, or that gained another user, is found here, before anything
# starts (refuse_after_create says what is then done and not done).
files_mounts_recheck() {
    local id="$1" token="$2" problem='' why
    why="$(files_volume_identity_problem "$FILES_VOLUME_NAME" "$token" "$id")" || problem="the files volume is no longer the rehearsal's: $why" # guard:G22-f-recheck-files-volume
    if [[ -z "$problem" ]]; then
        why="$(source_volume_identity_problem "$SRC_VOLUME_NAME" "$token" "$id")" || problem="the source volume is no longer the rehearsal's: $why" # guard:G22-f-recheck-source-volume
    fi
    [[ -z "$problem" ]] || refuse_after_create "$id" "$token" files none "$problem"
}

# refuse_after_create ID TOKEN ROLE NETMODE PROBLEM: the refusal both rechecks end with. Nothing starts. This script touches no volume, the replacement included: it is
# evidence. Only the container this script has just created is removed, and only if it is still what create_verified verified; a container that is not is left where
# it is, and the refusal says so.
refuse_after_create() {
    local id="$1" token="$2" role="$3" netmode="$4" problem="$5" removable=0 outcome
    ephemeral_identity_problem "$id" "$token" "$role" "$netmode" >/dev/null 2>&1 && removable=1 # guard:G22-f-recheck-removable
    if ((removable == 1)); then
        if dkr rm "$id" >/dev/null 2>&1; then
            outcome="The $role container ${id:0:12}, which this script had just created, was removed and not started."
        else
            outcome="The $role container ${id:0:12}, which this script had just created, could not be removed and was not started: remove it with cleanup."
        fi
    else
        outcome="The container ${id:0:12} is no longer what this script created, so it was left where it is, and was not started."
    fi
    refuse "after the $role container was created, $problem. $outcome This script removed no volume; Docker may have made a named volume again while it created the container, and the volume that is there now has been left as it is, for investigation. Run: restore-rehearsal.sh cleanup --dry-run." # guard:G22-f-recheck-refuses
}

# files_report TEXT: validates the files helper's state, count and file lines and sets FILES_STATE (absent|present), FILES_COUNT and FILES_SPEC
# (`key:bytes:sha256,...` in key order, or `empty`). A line that is malformed, a key out of order or repeated, a count that is not the number of lines, or
# files in an absent directory is a refusal: nothing is read from a helper's output that was not checked.
files_report() { # TEXT [PREFIX]: the lines are the ones that begin with PREFIX (`before:` or `after:` for the two inventories `aside` prints), or with none
    local text="$1" prefix="${2:-}" line state='' states=0 count='' counts=0 n=0 previous='' spec='' key bytes sha extra
    while IFS= read -r line; do
        case "$line" in
            "${prefix}files:state="*) state="${line#"${prefix}files:state="}" states=$((states + 1)) ;;
            "${prefix}files:count="*) count="${line#"${prefix}files:count="}" counts=$((counts + 1)) ;;
            "${prefix}file "*)
                IFS=' ' read -r _ key bytes sha extra <<<"${line#"$prefix"}"
                [[ -z "$extra" && "$key" =~ $KEY_RE && "$bytes" =~ ^(0|[1-9][0-9]{0,11})$ && "$sha" =~ ^[0-9a-f]{64}$ ]] || refuse "a file line of the files helper is malformed." # guard:G22-f-protocol-line
                [[ -z "$previous" || "$key" > "$previous" ]] || refuse "the files helper listed a key out of order or twice." # guard:G22-f-protocol-order
                previous="$key" n=$((n + 1)) spec+="${spec:+,}$key:$bytes:$sha"
                ;;
            *) ;;
        esac
    done <<<"$text"
    [[ $states == 1 && ( "$state" == absent || "$state" == present ) ]] || refuse "the files helper did not report exactly one state, absent or present." # guard:G22-f-protocol-state
    [[ $counts == 1 && "$count" == "$n" ]] || refuse "the files helper's count is not the number of files it listed." # guard:G22-f-protocol-count
    [[ "$state" == present || $n == 0 ]] || refuse "the files helper listed files in a directory it reported absent." # guard:G22-f-protocol-absent
    FILES_STATE="$state" FILES_COUNT="$n" FILES_SPEC="${spec:-empty}"
}

# files_db_rows: what the database says about the files, as the files helper takes it: DB_ROWS_SPEC is `none` (no resource_assets table, as at v0.3.0), `empty`
# or `key:bytes:sha256,...`; DB_ROWS_COUNT is the number of rows (empty when there is no table). Two fixed read-only statements; a row that is not a Resources
# storage key, a size and a SHA-256 is a refusal, never skipped.
files_db_rows() {
    local has rows key bytes sha extra spec='' n=0 previous=''
    capture has 'checking for the resource_assets table' app_query "$FILES_TABLE_SQL"
    if [[ "$has" == 0 ]]; then
        DB_ROWS_SPEC=none DB_ROWS_COUNT=''
        return 0
    fi
    [[ "$has" == 1 ]] || refuse "the database did not say whether resource_assets exists ('${has:0:20}')." # guard:G22-rows-table-answer
    capture rows 'reading the resource_assets rows' app_query "$FILES_ROWS_SQL"
    while IFS=$'\t' read -r key bytes sha extra; do
        [[ -n "$key$bytes$sha$extra" ]] || continue
        [[ -z "$extra" && "$key" =~ $KEY_RE && "$bytes" =~ ^(0|[1-9][0-9]{0,11})$ && "$sha" =~ ^[0-9a-f]{64}$ ]] || refuse "an asset row is not a Resources storage key, a size and a SHA-256, so the files cannot be compared with it." # guard:G22-rows-shape
        [[ -z "$previous" || "$key" > "$previous" ]] || refuse "the asset rows are not in key order, or repeat a key." # guard:G22-rows-order
        previous="$key" n=$((n + 1)) spec+="${spec:+,}$key:$bytes:$sha"
    done <<<"$rows"
    DB_ROWS_SPEC="${spec:-empty}" DB_ROWS_COUNT=$n
}

# files_insert_sql SPEC: the one transaction that gives each seeded file its asset row, from values already validated and re-validated here, because
# they are interpolated: the id and the storage key are the same ULID (StorageKey::for), the rest are fixed.
files_insert_sql() {
    local entry entries=() key bytes sha i=0 values=''
    IFS=',' read -ra entries <<<"$1"
    for entry in "${entries[@]}"; do
        IFS=: read -r key bytes sha <<<"$entry"
        [[ "$key" =~ $KEY_RE && "$bytes" =~ ^(0|[1-9][0-9]{0,11})$ && "$sha" =~ ^[0-9a-f]{64}$ ]] || refuse "a seeded file's facts are not a key, a size and a SHA-256; nothing was inserted." # guard:G22-seed-sql-values
        i=$((i + 1))
        values+="${values:+,}('$key','$key','synthetic-$i.bin','application/octet-stream',$bytes,'$sha','$FILES_SEED_PERSON','2026-01-01 00:00:00')"
    done
    printf 'START TRANSACTION;\nINSERT INTO resource_assets (id, storage_key, original_filename, media_type, byte_size, sha256, uploaded_by_person_id, created_at) VALUES %s;\nCOMMIT;\n' "$values"
}

# --- the files manifest: the runbook's Resource fields (deployment runbook, "The manifest"), and the per-file facts the archive is held to ----------------
# One fact per line, like the database manifest. No key, no password, no token: the run token's fingerprint only.

# files_manifest_text STATE ARCHIVE_SHA256 ARCHIVE_BYTES ROWS SPEC TAKEN_AT TOKEN_FINGERPRINT (ROWS is the number of asset rows, or `none`)
files_manifest_text() {
    local state="$1" sha="$2" bytes="$3" rows="$4" spec="$5" taken="$6" fp="$7"
    local archive=null archive_sha=null archive_bytes=null rows_json=null list='' entry entries=() key size digest count=0
    if [[ "$state" == present ]]; then archive="\"$FILES_ARCHIVE_FILE\"" archive_sha="\"$sha\"" archive_bytes="$bytes"; fi
    [[ "$rows" == none ]] || rows_json="$rows"
    if [[ "$spec" != empty ]]; then
        IFS=',' read -ra entries <<<"$spec"
        for entry in "${entries[@]}"; do
            IFS=: read -r key size digest <<<"$entry"
            list+="    { \"key\": \"$key\", \"bytes\": $size, \"sha256\": \"$digest\" },"$'\n'
            count=$((count + 1))
        done
        list="${list%,$'\n'}"
    fi
    printf '{\n  "files_manifest_version": 1,\n  "taken_at": "%s",\n  "run_token_fingerprint": "%s",\n  "resources_directory": "%s",\n' "$taken" "$fp" "$state"
    printf '  "resource_files_archive": %s,\n  "resource_files_sha256": %s,\n  "resource_files_bytes": %s,\n  "resource_files_count": %d,\n  "resource_assets_rows": %s,\n  "files": [\n' "$archive" "$archive_sha" "$archive_bytes" "$count" "$rows_json"
    [[ -z "$list" ]] || printf '%s\n' "$list"
    printf '  ]\n}\n'
}

# files_manifest_entries FILE: sets MANIFEST_SPEC (`key:bytes:sha256,...` in file order, or `empty`) and MANIFEST_LINES from the file lines of a manifest.
files_manifest_entries() {
    local line spec='' n=0
    while IFS= read -r line; do
        if [[ "$line" =~ $FILE_LINE_RE ]]; then
            spec+="${spec:+,}${BASH_REMATCH[1]}:${BASH_REMATCH[2]}:${BASH_REMATCH[3]}"
            n=$((n + 1))
        fi
    done <"$1"
    MANIFEST_SPEC="${spec:-empty}" MANIFEST_LINES=$n
}

# files_manifest_problem FILE: the manifest is this run's, internally consistent, and holds nothing the script would not have written. A manifest is
# never accepted because it records a state: an absent directory records no archive, a directory records its archive and a count that is the number of
# files listed, and the asset-row count, where there is a table, is that same number.
files_manifest_problem() {
    local file="$1" value state archive sha bytes count rows taken fp spec lines
    value="$(manifest_value "$file" files_manifest_version)" && [[ "$value" == 1 ]] || { echo "its files_manifest_version is not 1"; return 1; } # guard:G22-manifest-version
    fp="$(manifest_value "$file" run_token_fingerprint)" && [[ "$fp" == "$(token_fingerprint "$(state_token "$ROOT")")" ]] || { echo "it is not this run's (its run token fingerprint differs)"; return 1; } # guard:G22-manifest-run
    taken="$(manifest_value "$file" taken_at)" && [[ "$taken" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || { echo "its taken_at is not a UTC time"; return 1; } # guard:G22-manifest-taken
    state="$(manifest_value "$file" resources_directory)" && [[ "$state" == absent || "$state" == present ]] || { echo "its resources_directory is neither absent nor present"; return 1; } # guard:G22-manifest-state
    archive="$(manifest_value "$file" resource_files_archive)" || archive=''
    sha="$(manifest_value "$file" resource_files_sha256)" || sha=''
    bytes="$(manifest_value "$file" resource_files_bytes)" || bytes=''
    count="$(manifest_value "$file" resource_files_count)" || count=''
    rows="$(manifest_value "$file" resource_assets_rows)" || rows=''
    files_manifest_entries "$file"
    spec="$MANIFEST_SPEC" lines="$MANIFEST_LINES"
    [[ "$count" =~ ^[0-9]+$ && "$count" == "$lines" ]] || { echo "its resource_files_count is not the number of files it lists"; return 1; } # guard:G22-manifest-count
    if [[ "$state" == absent ]]; then
        [[ "$archive/$sha/$bytes" == null/null/null && "$count" == 0 ]] || { echo "an absent directory records an archive, a checksum or files"; return 1; } # guard:G22-manifest-zero
    else
        [[ "$archive" == "$FILES_ARCHIVE_FILE" ]] || { echo "its archive is not $FILES_ARCHIVE_FILE"; return 1; } # guard:G22-manifest-archive-name
        [[ "$sha" =~ ^[0-9a-f]{64}$ && "$bytes" =~ ^[1-9][0-9]*$ ]] || { echo "its archive checksum or size is not a SHA-256 and a positive byte count"; return 1; } # guard:G22-manifest-archive-facts
    fi
    [[ "$rows" == null || "$rows" == "$count" ]] || { echo "its resource_assets_rows is not its file count"; return 1; } # guard:G22-manifest-rows
    [[ "$rows" != null || "$count" == 0 ]] || { echo "it records files but no resource_assets table for them"; return 1; } # guard:G22-manifest-no-table
    [[ "$(<"$file")" == "$(files_manifest_text "$state" "$sha" "$bytes" "${rows/null/none}" "$spec" "$taken" "$fp")" ]] || { echo "it is not exactly what this script writes (an extra, missing or reordered line)"; return 1; } # guard:G22-manifest-exact
    return 0
}

# files_archive_matches_manifest ARCHIVE MANIFEST: the archive's byte count and SHA-256 are the ones the manifest recorded. Nothing is extracted from an
# archive that has not just passed this.
files_archive_matches_manifest() {
    local sha bytes
    sha="$(sha256sum <"$1" | cut -c1-64)"
    bytes="$(wc -c <"$1" | tr -d ' ')"
    [[ "$sha" == "$(manifest_value "$2" resource_files_sha256)" ]] || { echo "its SHA-256 is not the manifest's"; return 1; } # guard:G22-archive-sha
    [[ "$bytes" == "$(manifest_value "$2" resource_files_bytes)" ]] || { echo "its size is not the manifest's"; return 1; } # guard:G22-archive-bytes
    return 0
}

# files_verified_ok TEXT COUNT: the verify-archive action reported the archive verified, with the directory entry and COUNT files.
files_verified_ok() {
    grep -Fxq 'archive:verified=ok' <<<"$1" && grep -Fxq "archive:entries=$(($2 + 1))" <<<"$1"
}

# say_inventory LABEL SPEC: evidence of a validated inventory (SPEC is `key:bytes:sha256,...` or `empty`, from files_report or the database, never from
# a caller): up to FILES_EVIDENCE_MAX files as their keys, sizes and SHA-256 values, in key order; more as the count and one SHA-256 over the whole list, so a
# log stays bounded. Only keys, sizes and digests are printed: never a file's content, a token, a secret or a row. A spec that is not in the validated shape is
# a refusal here too, so nothing unvalidated is printed.
readonly FILES_EVIDENCE_MAX=16
say_inventory() {
    local label="$1" spec="$2" entry entries=() key bytes sha extra
    if [[ "$spec" == empty ]]; then
        say "       $label: no files"
        return 0
    fi
    IFS=',' read -ra entries <<<"$spec"
    for entry in "${entries[@]}"; do
        IFS=: read -r key bytes sha extra <<<"$entry"
        [[ -z "$extra" && "$key" =~ $KEY_RE && "$bytes" =~ ^(0|[1-9][0-9]{0,11})$ && "$sha" =~ ^[0-9a-f]{64}$ ]] || refuse "an inventory to be reported is not a list of keys, sizes and SHA-256 values; nothing was printed." # guard:G22-evidence-shape
    done
    if ((${#entries[@]} <= FILES_EVIDENCE_MAX)); then
        say "       $label: ${#entries[@]} file(s), by key, size and SHA-256:"
        for entry in "${entries[@]}"; do
            IFS=: read -r key bytes sha <<<"$entry"
            say "         $key  $bytes bytes  $sha"
        done
    else
        say "       $label: ${#entries[@]} files; SHA-256 of the list of their keys, sizes and SHA-256 values: $(printf '%s' "$spec" | sha256sum | cut -c1-64)"
    fi
}

FILES_TMP=''
on_files_exit() {
    local status=$?
    [[ -z "$FILES_TMP" ]] || rm -f -- "$FILES_TMP"
    if ((status != 0)); then stage_trailer 'The rehearsal files and database'; fi
    stage_record_remove
}

# --- the four commands ------------------------------------------------------------------------------------------------------------------------

# seed-files: from the UPGRADED database, simulate uploads made after the upgrade: the four synthetic files, then one asset row for each, so that the files
# and the rows agree and can be backed up, archived and held to one another. It starts only from the exact post-upgrade state with no asset row and no
# Resources directory, and writes nothing else.
cmd_seed_files() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local counts seeded checked sql
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the upgraded schema (the files are seeded as uploads made after the upgrade)' # guard:G22-seed-inventory
    capture counts 'reading the upgraded state' app_query "$COUNTS_SQL"
    [[ "$counts" == "$((FIXTURE_PEOPLE + 1))"$'\t1\t29\t0' ]] || refuse "the database is not the post-upgrade state this stage starts from (Persons, profiles, migrations, profiles without a Person: ${counts//$'\t'/, }; expected $((FIXTURE_PEOPLE + 1)), 1, 29, 0). Nothing was changed." # guard:G22-seed-state
    files_db_rows
    [[ "$DB_ROWS_SPEC" == empty ]] || refuse "resource_assets is not an existing, empty table, so there is nothing to seed. Nothing was changed." # guard:G22-seed-assets-empty

    arm_stage seed-files on_files_exit
    say "Seeding $FILES_SEED_COUNT synthetic files as uploads made after the upgrade, then their asset rows ..."
    capture seeded 'seeding the Resources directory' run_files --create seed
    grep -Fxq 'files:seed=ok' <<<"$seeded" || refuse "the files helper did not report files:seed=ok." # guard:G22-seed-reported
    files_report "$seeded"
    [[ "$FILES_STATE" == present && "$FILES_COUNT" == "$FILES_SEED_COUNT" ]] || refuse "the files helper did not seed exactly $FILES_SEED_COUNT files." # guard:G22-seed-count
    sql="$(files_insert_sql "$FILES_SPEC")" || exit "$?"
    printf '%s' "$sql" | guarded_app_sql "$ROOT" "$APP_DB" >/dev/null || fail "the asset rows could not be inserted"
    files_db_rows
    [[ "$DB_ROWS_SPEC" == "$FILES_SPEC" ]] || refuse "the asset rows read back are not the files just written." # guard:G22-seed-rows-match
    capture checked 'checking the seeded files against their rows' run_files check "$DB_ROWS_SPEC"
    grep -Fxq 'files:check=ok' <<<"$checked" || refuse "the files helper did not report files:check=ok for the seeded files." # guard:G22-seed-agrees
    say "ok   $FILES_COUNT files in the volume's resources directory and $DB_ROWS_COUNT resource_assets rows agree on every key, size and SHA-256"
    say "Seeded. Next: restore-rehearsal.sh backup-files."
}

# backup-files: the Resource files half of a backup (deployment runbook §5, "Resource files"): from the 16-table or the 34-table database, hold the Resources
# directory to the asset rows (an absent directory is a zero-file backup only with no rows), and then either record the zero-file state or archive the directory
# with the runbook's command, read the archive back, and write the manifest. Never overwrites a backup. Refuses any state that is not clean.
cmd_backup_files() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    [[ ! -e "$ROOT/$FILES_ARCHIVE_FILE" && ! -e "$ROOT/$FILES_MANIFEST_FILE" ]] || refuse "a Resource-files backup already exists in the state directory and is never overwritten. The rehearsal is disposable: run cleanup, then start again." # guard:G22-backup-no-overwrite
    local inventory_now checked verified taken fp sha='' bytes='' why rows_field agrees=0
    capture inventory_now 'reading the table inventory' db_inventory
    [[ "$inventory_now" == "$(historical_inventory)" || "$inventory_now" == "$(upgraded_inventory)" ]] || refuse "the database is neither the 16 tables of v0.3.0 nor the 34 of the upgraded schema, so the asset rows cannot be taken as they should be. Nothing was changed." # guard:G22-backup-db-state
    files_db_rows

    arm_stage backup-files on_files_exit
    say "Checking the Resources directory against the database ($( [[ "$DB_ROWS_SPEC" == none ]] && echo 'there is no resource_assets table' || echo "resource_assets: ${DB_ROWS_COUNT} row(s)" )) ..."
    capture checked 'checking the Resources directory' run_files --create check "$DB_ROWS_SPEC"
    grep -Fxq 'files:check=ok' <<<"$checked" || refuse "the files helper did not report files:check=ok." # guard:G22-backup-check-reported
    files_report "$checked"
    if [[ "$DB_ROWS_SPEC" == none || "$DB_ROWS_SPEC" == empty ]]; then
        [[ "$FILES_COUNT" == 0 ]] && agrees=1
    else
        [[ "$FILES_SPEC" == "$DB_ROWS_SPEC" ]] && agrees=1
    fi
    [[ $agrees == 1 ]] || refuse "the files the helper listed are not the asset rows the database holds." # guard:G22-backup-agrees
    taken="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    fp="$(token_fingerprint "$(state_token "$ROOT")")"

    if [[ "$FILES_STATE" == present ]]; then
        say "Archiving the $FILES_COUNT file(s) with the runbook's command (tar -C <private> -czf <archive> resources), then reading the archive back ..."
        mutation_begins # the archive's temporary file is the first thing written to the state directory
        FILES_TMP="$(umask 077 && mktemp -- "$ROOT/.files-archive.XXXXXX")"
        run_files archive "$DB_ROWS_SPEC" >"$FILES_TMP"
        [[ -s "$FILES_TMP" ]] || refuse "the archive is empty." # guard:G22-backup-archive-nonempty
        capture verified 'verifying the archive' run_files verify-archive "$FILES_SPEC" <"$FILES_TMP"
        files_verified_ok "$verified" "$FILES_COUNT" || refuse "the archive was not verified against the files it was made from." # guard:G22-backup-archive-verified
        say "ok   the archive was read back and holds $((FILES_COUNT + 1)) entries (the Resources directory and $FILES_COUNT file(s)); each file is the size and SHA-256 recorded"
        sha="$(sha256sum <"$FILES_TMP" | cut -c1-64)"
        bytes="$(wc -c <"$FILES_TMP" | tr -d ' ')"
        mv -- "$FILES_TMP" "$ROOT/$FILES_ARCHIVE_FILE"
        FILES_TMP=''
    else
        say "There is no Resources directory and no asset row: a zero-file backup. No archive is made."
    fi
    rows_field=none
    [[ "$DB_ROWS_SPEC" == none ]] || rows_field="$DB_ROWS_COUNT"
    write_state_file "$FILES_MANIFEST_FILE" "$(files_manifest_text "$FILES_STATE" "$sha" "$bytes" "$rows_field" "$FILES_SPEC" "$taken" "$fp")"
    why="$(files_manifest_problem "$ROOT/$FILES_MANIFEST_FILE")" || refuse "the manifest just written is not consistent: $why."
    if [[ "$FILES_STATE" == present ]]; then
        why="$(files_archive_matches_manifest "$ROOT/$FILES_ARCHIVE_FILE" "$ROOT/$FILES_MANIFEST_FILE")" || refuse "the archive just written does not match its manifest: $why."
        say "ok   $FILES_COUNT file(s), archive $bytes bytes, sha256 ${sha:0:16}...; every file in it has the size and SHA-256 the database and the directory agree on"
    else
        say "ok   zero-file manifest: count 0, no checksum, no archive"
    fi
    say "Files backup done: $FILES_MANIFEST_FILE$([[ "$FILES_STATE" == present ]] && echo " and $FILES_ARCHIVE_FILE") in the state directory."
}

# aside-files: step 2 of the runbook's file restore: the live Resources directory is RENAMED to resources.before-restore-<UTC time>, inside the files volume.
# Nothing is deleted, merged or overwritten. It starts only when a verified Resource-files backup exists (there must be something to restore to before the live
# files are moved), and it MEASURES the preservation: the helper reads the directory immediately before the rename and the renamed directory immediately
# after, and prints both inventories; this script validates each, requires them to be exactly the same keys, sizes and SHA-256 values, and requires the
# original place to be empty. A failure after the rename leaves the moved directory where it is: nothing is renamed back or removed.
cmd_aside_files() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local stamp out checked want reported=0 before_state before_spec after_state after_spec moved
    files_backup_required # guard:G22-aside-backup-required
    [[ ! -e "$ROOT/$ASIDE_RECORD_FILE" && ! -L "$ROOT/$ASIDE_RECORD_FILE" ]] || refuse "$ASIDE_RECORD_FILE is already in the state directory, and evidence is never overwritten. Nothing was moved. Run cleanup and start again." # guard:G26-aside-record-no-overwrite
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    [[ "$stamp" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || refuse "the time of day did not come out as a UTC stamp ('${stamp:0:30}')." # guard:G22-aside-stamp
    arm_stage aside-files on_files_exit
    capture out 'moving the Resources directory aside' run_files aside "$stamp"
    want="$FILES_STORE_DIR.before-restore-$stamp"
    if [[ "$(grep -c '^aside:name=' <<<"$out" || true)" == 1 ]] && grep -Fxq "aside:name=$want" <<<"$out" && grep -Fxq 'aside:complete=ok' <<<"$out"; then reported=1; fi
    [[ $reported == 1 ]] || refuse "the files helper did not report moving the directory to $want. The directory, wherever it now is, was not touched further." # guard:G22-aside-reported
    files_report "$out" before:
    before_state="$FILES_STATE" before_spec="$FILES_SPEC"
    files_report "$out" after:
    after_state="$FILES_STATE" after_spec="$FILES_SPEC" moved="$FILES_COUNT"
    [[ "$before_state" == present && "$after_state" == present && "$before_spec" == "$after_spec" ]] || refuse "what was moved aside is not what was there: the inventory of the renamed directory is not the one read just before the rename. The moved directory $want was left exactly where it is; nothing was renamed back or removed." # guard:G22-aside-preserved
    capture checked 'checking that the Resources directory is gone from its place' run_files check empty
    files_report "$checked"
    [[ "$FILES_STATE" == absent ]] || refuse "the Resources directory is still where it was after being moved aside." # guard:G22-aside-result
    say "ok   $FILES_STORE_DIR moved to $want (renamed; nothing deleted): $moved file(s), read before the rename and again after it, identical in every key, size and SHA-256; the Resources directory is now absent"
    say_inventory 'read immediately before the rename' "$before_spec"
    say_inventory 'read in the renamed directory' "$after_spec"
    AR_TAKEN="$(date -u +%Y-%m-%dT%H:%M:%SZ)" AR_FP="$(token_fingerprint "$(state_token "$ROOT")")" AR_STAMP="$stamp" AR_NAME="$want" AR_PRESERVE=none AR_COUNT="$moved" AR_SPEC="$after_spec"
    AR_INV_SHA="$(printf '%s' "$after_spec" | sha256sum | cut -c1-64)"
    ! state_file_ok "$PRESERVE_RECORD_FILE" || AR_PRESERVE="$(sha256sum <"$ROOT/$PRESERVE_RECORD_FILE" | cut -c1-64)" # the preservation this aside belongs to, if one was made
    write_state_file_once "$ASIDE_RECORD_FILE" "$(aside_record_text)"
    aside_record_problem "$ROOT/$ASIDE_RECORD_FILE" || refuse "the aside record just written is not consistent: $AR_WHY. The moved directory $want was left exactly where it is; nothing was renamed back, removed or repaired." # guard:G26-aside-record-checked
    say "ok   $ASIDE_RECORD_FILE records the measured aside: $moved file(s) by key, size and SHA-256, the stamp $stamp, and the preservation record it belongs to ($([[ "$AR_PRESERVE" == none ]] && echo none || echo "sha256 ${AR_PRESERVE:0:16}..."))"
}

# files_backup_required: the Resource-files backup an earlier stage took is present, this run's, consistent, and its archive (if it has one) is the one the
# manifest describes. Sets FILES_MANIFEST_STATE and MANIFEST_SPEC.
files_backup_required() {
    local why
    state_file_ok "$FILES_MANIFEST_FILE" || refuse "there is no Resource-files backup in the state directory (backup-files has not finished). Nothing was changed." # guard:G22-restore-backup-required
    why="$(files_manifest_problem "$ROOT/$FILES_MANIFEST_FILE")" || refuse "the Resource-files manifest cannot be relied on: $why. Nothing was changed."
    FILES_MANIFEST_STATE="$(manifest_value "$ROOT/$FILES_MANIFEST_FILE" resources_directory)"
    files_manifest_entries "$ROOT/$FILES_MANIFEST_FILE"
    if [[ "$FILES_MANIFEST_STATE" == present ]]; then
        state_file_ok "$FILES_ARCHIVE_FILE" || refuse "the manifest records an archive but '$FILES_ARCHIVE_FILE' is missing from the state directory or is not a private file of this script's. Nothing was changed." # guard:G22-restore-archive-required
        why="$(files_archive_matches_manifest "$ROOT/$FILES_ARCHIVE_FILE" "$ROOT/$FILES_MANIFEST_FILE")" || refuse "the archive cannot be relied on: $why. Nothing was changed."
    else
        [[ ! -e "$ROOT/$FILES_ARCHIVE_FILE" && ! -L "$ROOT/$FILES_ARCHIVE_FILE" ]] || refuse "the manifest records a zero-file backup, but an archive exists beside it. Nothing was changed." # guard:G22-restore-zero-no-archive
    fi
}

# restore-files: the file half of the restore (runbook, "Restoring files with the database", steps 3 and 4), WITHOUT the database restore or the prune check,
# which are the next slice's. A zero-file backup extracts nothing and requires the directory to be absent; otherwise the archive is verified against the
# manifest FIRST, and extracted only into a volume with no Resources directory (move it aside first); the result is held to the manifest twice.
cmd_restore_files() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local verified out checked
    files_backup_required
    arm_stage restore-files on_files_exit
    if [[ "$FILES_MANIFEST_STATE" == absent ]]; then
        capture checked 'checking the Resources directory' run_files check empty
        files_report "$checked"
        [[ "$FILES_STATE" == absent ]] || refuse "the backup is a zero-file one, which extracts nothing, but a Resources directory exists. It is not removed: run aside-files first, then restore-files." # guard:G22-restore-zero-absent
        say "ok   zero-file backup: nothing to extract, and the Resources directory is absent"
        return 0
    fi
    say "Verifying the archive against the manifest before anything is written ..."
    capture verified 'verifying the archive' run_files verify-archive "$MANIFEST_SPEC" <"$ROOT/$FILES_ARCHIVE_FILE"
    files_verified_ok "$verified" "$MANIFEST_LINES" || refuse "the archive was not verified against the manifest. Nothing was extracted." # guard:G22-restore-verified-first
    say "ok   the archive was read before anything was written and holds $((MANIFEST_LINES + 1)) entries (the Resources directory and $MANIFEST_LINES file(s)); each file is the size and SHA-256 the manifest records"
    say "Extracting into a volume with no Resources directory ..."
    capture out 'extracting the archive' run_files restore "$MANIFEST_SPEC" <"$ROOT/$FILES_ARCHIVE_FILE"
    grep -Fxq 'restore:extracted=ok' <<<"$out" || refuse "the files helper did not report restore:extracted=ok." # guard:G22-restore-reported
    files_report "$out"
    [[ "$FILES_STATE" == present && "$FILES_SPEC" == "$MANIFEST_SPEC" ]] || refuse "what was extracted is not what the manifest records." # guard:G22-restore-result
    capture checked 'reading the restored directory again' run_files check "$MANIFEST_SPEC"
    files_report "$checked"
    [[ "$FILES_STATE" == present && "$FILES_SPEC" == "$MANIFEST_SPEC" ]] || refuse "a second, read-only look at the restored directory does not match the manifest." # guard:G22-restore-recheck
    say "ok   $FILES_COUNT file(s) restored to the Resources directory and read back: every key, size and SHA-256 is the manifest's"
    say "Restored files only. The database restore, the prune dry-run check and the reopening are the next slice's."
}

# --- backup-paired (slice 2.2C-3, WU4) -----------------------------------------------------------------------------------------------------------------------

# paired_measure: ONE measurement of everything the paired backup is of, so that the two taken around the artifacts are the same kind of thing: the asset rows the
# database holds (the same two reads backup-files makes), the Resources directory held to them by the files container, and the application's own `snapshot-all` (the
# 34 tables' rows and structure as digests, the ledger, the keys the application used). Sets MEAS_TABLES (`<table> <rows> <digest>`), MEAS_STRUCT (`<table> <digest>`),
# MEAS_LEDGER (`<count> <last> <digest>`), DB_ROWS_SPEC and DB_ROWS_COUNT (files_db_rows), FILES_STATE, FILES_COUNT and FILES_SPEC (files_report). Every part must be
# there and agree with the others, or it is a refusal: the two readers of the asset rows (the SQL client and the application's snapshot) must give one number.
paired_measure() {
    local checked assets
    files_db_rows
    [[ "$DB_ROWS_SPEC" != none && "$DB_ROWS_SPEC" != empty && "$DB_ROWS_COUNT" == "$FILES_SEED_COUNT" ]] || refuse "resource_assets does not hold the $FILES_SEED_COUNT synthetic asset rows seed-files writes, so there are no Resource-file records for a paired backup to bind. Nothing was changed." # guard:G25-paired-assets
    capture checked 'checking the Resources directory' run_files check "$DB_ROWS_SPEC"
    grep -Fxq 'files:check=ok' <<<"$checked" || refuse "the files helper did not report files:check=ok." # guard:G25-paired-check-reported
    files_report "$checked"
    [[ "$FILES_STATE" == present && "$FILES_SPEC" == "$DB_ROWS_SPEC" ]] || refuse "the Resources directory and the asset rows the database holds do not agree on every key, size and SHA-256. Nothing was changed." # guard:G25-paired-agree
    measure_database
    assets="$(awk '$1 == "resource_assets" { print $2 }' <<<"$MEAS_TABLES")"
    [[ "$assets" == "$DB_ROWS_COUNT" ]] || refuse "the application's snapshot counts $assets resource_assets rows and the query for the asset rows $DB_ROWS_COUNT." # guard:G25-assets-two-readers
}

# measure_database: the application's own `snapshot-all` (WU4), validated: sets MEAS_TABLES (`<table> <rows> <digest>`), MEAS_STRUCT (`<table> <digest>`) and MEAS_LEDGER
# (`<count> <last> <digest>`) for the 34 tables, and refuses an output that is incomplete, that names other keys than this run's, or whose ledger is not the current schema's.
measure_database() {
    local out ledger_count ledger_last ledger_digest why
    run_helper current helper:snapshot-all out
    grep -Fxq 'snapshot-all:complete=ok' <<<"$out" || refuse "the snapshot helper did not report snapshot-all:complete=ok: its output is incomplete, and an incomplete snapshot is refused, never read as smaller." # guard:G25-snapshot-all-reported
    capture MEAS_TABLES 'validating the current snapshot' all_table_lines "$out"
    capture MEAS_STRUCT 'validating the current structure' structure_lines "$out" "$(upgraded_inventory)"
    ledger_count="$(snapshot_value "$out" ledger:count)" || ledger_count=''
    ledger_last="$(snapshot_value "$out" ledger:last)" || ledger_last=''
    ledger_digest="$(snapshot_value "$out" ledger:digest)" || ledger_digest=''
    [[ "$ledger_count" == "$CURRENT_LEDGER_COUNT" && "$ledger_last" == "$CURRENT_LAST_MIGRATION" && "$ledger_digest" =~ ^[0-9a-f]{64}$ ]] || refuse "the snapshot's migration ledger is not the $CURRENT_LEDGER_COUNT migrations of the current schema, ending at $CURRENT_LAST_MIGRATION." # guard:G25-ledger-evidence
    MEAS_LEDGER="$ledger_count $ledger_last $ledger_digest"
    why="$(keys_problem "$out")" || refuse "$why."
}

PAIRED_TMPS=()
PAIRED_COMMITTED=()
PAIRED_PENDING='' PAIRED_PENDING_TMP='' # the name commit_state_file is placing, and the temporary file it is linked from
PAIRED_VERIFIED=no # set when require_recovery_unit has accepted the files this stage put in place
# paired_temp VAR: a private temporary file in the state directory for an artifact that is not yet a part of the backup, removed when the stage ends (on_paired_exit).
paired_temp() {
    local tmp
    mutation_begins # the first thing written to the state directory
    tmp="$(umask 077 && mktemp -- "$ROOT/.paired.XXXXXX")" || fail "a temporary file could not be made in the state directory"
    PAIRED_TMPS+=("$tmp")
    printf -v "$1" '%s' "$tmp"
}
# commit_state_file TEMP NAME: the finished temporary file under its final name, by a hard link that fails if the name is taken, so that nothing is ever put over a file that is there.
commit_state_file() {
    PAIRED_PENDING="$2" PAIRED_PENDING_TMP="$1" # what the link below is about to place, so that an interruption between the link and the next line does not hide it from the note
    ln -- "$1" "$ROOT/$2" 2>/dev/null || refuse "$2 is in the state directory already, and a backup is never put over a file. $(paired_partial_note)" # guard:G25-commit-no-clobber
    PAIRED_COMMITTED+=("$2")
    PAIRED_PENDING=''
}
# paired_partial_note: what is true of the state directory, in words, when the stage stops. Three phases are told apart by what the stage itself did (PAIRED_COMMITTED, the
# names it put in place, and PAIRED_VERIFIED, set only once require_recovery_unit accepted them), never by assumption: (1) nothing was put in place; (2) some files were, and
# the recovery manifest was not; (3) the recovery manifest was put in place, and the stage then failed, in the final verification or after it. In the third phase the files
# may exist and may be a recovery unit, and nothing here says which: only require_recovery_unit accepting them makes them one. Nothing is removed, renamed or repaired.
paired_partial_note() {
    local part wrote=no placed=() other=''
    # A signal can arrive after the link made a name and before PAIRED_COMMITTED learned of it. The name then is, and only then, the very file this stage was linking (same inode as
    # its temporary file, which is still there at this point): it counts as placed. A name that is another file's (the link failed because it was taken) is never counted.
    if [[ -n "$PAIRED_PENDING" && "$ROOT/$PAIRED_PENDING" -ef "$PAIRED_PENDING_TMP" ]] && ! in_array "$PAIRED_PENDING" ${PAIRED_COMMITTED[@]+"${PAIRED_COMMITTED[@]}"}; then PAIRED_COMMITTED+=("$PAIRED_PENDING"); fi
    for part in ${PAIRED_COMMITTED[@]+"${PAIRED_COMMITTED[@]}"}; do
        if [[ "$part" == "$RECOVERY_MANIFEST_FILE" ]]; then wrote=yes; else placed+=("$part"); fi
    done
    if [[ "$wrote" == yes && "$PAIRED_VERIFIED" == yes ]]; then
        printf '%s was put in place last, with %s, and require_recovery_unit accepted them; the stage failed after that. Nothing was removed. Whether they may be relied on is for require_recovery_unit to say again, not for this message.' "$RECOVERY_MANIFEST_FILE" "${placed[*]}"
    elif [[ "$wrote" == yes ]]; then
        printf 'All four files were put in place (%s last) but the stage failed before it had verified them as put in place. They may be a recovery unit and may not: only require_recovery_unit accepting them makes them one, and nothing may rely on them until it does. Nothing was removed or repaired.' "$RECOVERY_MANIFEST_FILE"
    else
        if [[ -e "$ROOT/$RECOVERY_MANIFEST_FILE" || -L "$ROOT/$RECOVERY_MANIFEST_FILE" ]]; then other=" A file named $RECOVERY_MANIFEST_FILE is in the state directory that this stage did not write, and it is not part of this backup."; fi
        if ((${#placed[@]} > 0)); then
            printf 'These parts of the backup were already in place: %s. %s was not written, so they are NOT a recovery unit, whatever they hold.%s' "${placed[*]}" "$RECOVERY_MANIFEST_FILE" "$other"
        else
            printf 'Nothing of this backup was put in place.%s' "$other"
        fi
    fi
}
on_paired_exit() {
    local status=$? tmp note=''
    if ((status != 0)); then note="$(paired_partial_note)"; fi # before the temporary files go: it compares them with the names that were linked from them
    for tmp in ${PAIRED_TMPS[@]+"${PAIRED_TMPS[@]}"}; do rm -f -- "$tmp"; done
    if ((status != 0)); then
        stage_trailer 'The rehearsal files and database'
        warn "$note"
    fi
    stage_record_remove
}

# backup-paired: ONE recovery unit of the current schema: the two-pass dump of the 34-table database and the Resource files archive, taken from a database and a
# directory that were measured to agree, measured again afterwards to be exactly as they were, and bound by one manifest.
#
# What "one recovery unit" does and does not claim. A dump and an archive are two commands, not one atomic snapshot, and both succeeding proves nothing about when each
# saw the data. What supports the claim is this, all of it: (1) the rehearsal database is private, reached only through this script's own containers one at a time under the
# one-run lock, and no other rehearsal container exists (checked by every container it creates), so nothing else writes between the steps; (2) the database (34 tables'
# rows and structure, the ledger) and the Resources directory (every key, size and SHA-256, held to the asset rows) are measured BEFORE and AFTER both artifacts are
# captured, and any difference at all is a refusal; (3) the dump holds, for every durable table, exactly the rows the first measurement counted, and nothing for the
# transient ones; (4) the archive is read back and verified against the files measured. That is detection by before/after equality, not isolation: a change made and exactly
# reversed between the two measurements is not noticed (the same state has the same digest), and the dump's own pass 2 is one transaction (`--single-transaction`) but pass 1 and
# pass 2 are two. The claim is "no change was observed", never "an atomic snapshot".
#
# Nothing is put in place until everything has been verified, and then in this order: the dump, the archive, the Resource-files manifest, and last the recovery manifest, by a
# hard link that fails if the name is taken. The recovery manifest is the commit point. An interruption or a failure before it leaves no recovery unit, said so, and the
# parts that were already in place named; the next backup-paired refuses (it never overwrites) and the rehearsal is cleaned up and started again.
cmd_backup_paired() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local name counts version taken fp dump_tmp archive_tmp fm_tmp rm_tmp verified dump_sha dump_bytes archive_sha archive_bytes changed
    local m1_tables m1_struct m1_ledger m1_spec m1_rows_spec m1_count
    for name in "$CURRENT_DUMP_FILE" "$FILES_ARCHIVE_FILE" "$FILES_MANIFEST_FILE" "$RECOVERY_MANIFEST_FILE"; do
        [[ ! -e "$ROOT/$name" && ! -L "$ROOT/$name" ]] || refuse "'$name' is already in the state directory, and a backup is never overwritten. The rehearsal is disposable: run cleanup, then start again." # guard:G25-paired-no-overwrite
    done
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the current schema (a paired backup is taken from the upgraded database)' # guard:G25-paired-inventory
    capture counts 'reading the upgraded state' app_query "$COUNTS_SQL"
    [[ "$counts" == "$((FIXTURE_PEOPLE + 1))"$'\t1\t29\t0' ]] || refuse "the database is not the post-upgrade state this stage starts from (Persons, profiles, migrations, profiles without a Person: ${counts//$'\t'/, }; expected $((FIXTURE_PEOPLE + 1)), 1, 29, 0). Nothing was changed." # guard:G25-paired-state
    load_keyring

    arm_stage backup-paired on_paired_exit
    say "Measuring the database and the Resources directory, before anything is taken ..."
    paired_measure
    m1_tables="$MEAS_TABLES" m1_struct="$MEAS_STRUCT" m1_ledger="$MEAS_LEDGER" m1_spec="$FILES_SPEC" m1_rows_spec="$DB_ROWS_SPEC" m1_count="$DB_ROWS_COUNT"
    say "ok   34 tables, $m1_count Resource files held to $m1_count asset rows, and a ledger of $CURRENT_LEDGER_COUNT migrations: measured"

    say "Taking the two-pass dump of the 34 tables as the application account ..."
    paired_temp dump_tmp
    take_dump "$dump_tmp" || exit 1
    verified="$(current_dump_problem "$dump_tmp" "$m1_tables")" || refuse "the dump is not what the backup contract requires: $verified. It was removed." # guard:G25-paired-dump-checked
    capture version 'reading the server version' server_version_text "$CID"
    [[ "$version" =~ $SERVER_VERSION_RE ]] || refuse "the server reports version '$version', not 10.11.18."
    dump_sha="$(sha256sum <"$dump_tmp" | cut -c1-64)"
    dump_bytes="$(wc -c <"$dump_tmp" | tr -d ' ')"

    say "Archiving the $m1_count file(s) with the runbook's command, then reading the archive back ..."
    paired_temp archive_tmp
    run_files archive "$m1_rows_spec" >"$archive_tmp"
    [[ -s "$archive_tmp" ]] || refuse "the archive is empty." # guard:G25-paired-archive-nonempty
    capture verified 'verifying the archive' run_files verify-archive "$m1_spec" <"$archive_tmp"
    files_verified_ok "$verified" "$m1_count" || refuse "the archive was not verified against the files it was made from." # guard:G25-paired-archive-verified
    archive_sha="$(sha256sum <"$archive_tmp" | cut -c1-64)"
    archive_bytes="$(wc -c <"$archive_tmp" | tr -d ' ')"

    say "Measuring again, now that both artifacts are taken ..."
    paired_measure
    changed="$(digest_differences "$m1_tables" "$MEAS_TABLES")"
    [[ "$m1_struct" == "$MEAS_STRUCT" ]] || changed+=" structure"
    [[ "$m1_ledger" == "$MEAS_LEDGER" ]] || changed+=" ledger"
    [[ "$m1_spec" == "$FILES_SPEC" && "$m1_rows_spec" == "$DB_ROWS_SPEC" ]] || changed+=" resource-files"
    [[ -z "${changed// /}" ]] || refuse "the database or the Resources directory changed while the backup was being taken (changed: $(tr '\n' ' ' <<<"$changed")). Nothing was put in place; the artifacts taken are removed, and the backup is not one recovery unit." # guard:G25-paired-quiescent
    say "ok   measured again afterwards: every table's rows and structure, the ledger, every Resource file and every asset row are exactly as before"

    taken="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    fp="$(token_fingerprint "$(state_token "$ROOT")")"
    paired_temp fm_tmp
    printf '%s\n' "$(files_manifest_text present "$archive_sha" "$archive_bytes" "$m1_count" "$m1_spec" "$taken" "$fp")" >"$fm_tmp"
    verified="$(files_manifest_problem "$fm_tmp")" || refuse "the Resource-files manifest just made is not consistent: $verified." # guard:G25-paired-files-manifest-checked
    verified="$(files_archive_matches_manifest "$archive_tmp" "$fm_tmp")" || refuse "the archive just made does not match its manifest: $verified."
    RM_TAKEN="$taken" RM_FP="$fp" RM_COMMIT="$(sed -n 's/^current=//p' "$ROOT/$PREPARED_FILE")" RM_SERVER="$version"
    RM_DUMP_SHA="$dump_sha" RM_DUMP_BYTES="$dump_bytes" RM_MIG_SHA="${m1_ledger##* }"
    RM_TABLES="$(join_tables "$m1_tables" "$m1_struct")"
    RM_STRUCT_SHA="$(structure_aggregate "$RM_TABLES")"
    RM_FM_SHA="$(sha256sum <"$fm_tmp" | cut -c1-64)" RM_FM_BYTES="$(wc -c <"$fm_tmp" | tr -d ' ')"
    RM_AR_SHA="$archive_sha" RM_AR_BYTES="$archive_bytes" RM_FILES="$m1_count" RM_ROWS="$m1_count" RM_INV_SHA="$(printf '%s' "$m1_spec" | sha256sum | cut -c1-64)"
    paired_temp rm_tmp
    printf '%s\n' "$(recovery_manifest_text)" >"$rm_tmp"
    verified="$(recovery_unit_problem "$rm_tmp" "$dump_tmp" "$fm_tmp" "$archive_tmp")" || refuse "the recovery unit just made is not consistent: $verified. Nothing was put in place." # guard:G25-paired-unit-checked

    commit_state_file "$dump_tmp" "$CURRENT_DUMP_FILE"
    commit_state_file "$archive_tmp" "$FILES_ARCHIVE_FILE"
    commit_state_file "$fm_tmp" "$FILES_MANIFEST_FILE"
    commit_state_file "$rm_tmp" "$RECOVERY_MANIFEST_FILE" # the commit point
    require_recovery_unit
    PAIRED_VERIFIED=yes
    say "ok   the dump creates and drops exactly the 34 tables, once each, and holds exactly the rows the first measurement counted for the 27 durable tables (nothing for the 7 transient ones): $dump_bytes bytes, sha256 ${dump_sha:0:16}..."
    say "ok   $m1_count Resource files archived and read back ($archive_bytes bytes, sha256 ${archive_sha:0:16}...), each the key, size and SHA-256 the asset rows and the directory agree on"
    say "ok   keyring: current $CURRENT_FP, previous $PREVIOUS_FP, ring $RING_FP (fingerprints only)"
    say "ok   $RECOVERY_MANIFEST_FILE binds the dump, the archive and the Resource-files manifest by SHA-256 and size, and records the 34 tables' rows and structure, the ledger and the keyring; the files as put in place were verified for integrity and structural consistency, and the restored CONTENT is not verified by this stage (a restore measures it)"
    say "Paired backup done: no change was observed between the two measurements around both artifacts. This is not an atomic snapshot; see the development note, 'WU4'."
}


# --- failed-state preservation, the aside record and their verification (slice 2.2C-3, WU3) --------------------------------------------------------------------------------

# What this adds, and what it is not. `preserve-failed-state` keeps EVIDENCE of the database and the Resource files as they are before a restore: a full export of the 34 tables
# (structure of all of them, rows of the 27 durable ones: the two-pass dump's contract), the application's own `snapshot-all` measurements, the asset rows and the Resources
# directory's inventory, bound to this run. It reads the database and the files and changes neither; it is not a rollback, a restore or a backup. `restore-historical` then
# refuses to clear a database that holds Resource asset rows or a Resources directory with files unless that record exists, is this run's, is intact, and equals what the
# database and the directory are NOW (R-preserve). `aside-files` persists what it measured (aside.record), and `verify-aside` reads the aside back, read-only, and holds it
# to that record and to the preservation record. Nothing here moves, deletes, restores or repairs a file or a row.

# durable_lines TEXT: the lines of TEXT (whose first field is a table name) for the durable tables only; a transient table is measured with its rows but is not preserved.
durable_lines() {
    local line name
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        name="${line%% *}"
        ! in_array "$name" "${TRANSIENT_TABLES[@]}" || continue
        printf '%s\n' "$line"
    done <<<"$1"
}

# state_measure: ONE measurement of the database and the Resource files as they are now, whatever the asset rows are (a restore may find none, or several): the rows the
# database holds, the Resources directory held to them by the files container, and `snapshot-all`. Sets MEAS_TABLES, MEAS_STRUCT, MEAS_LEDGER (measure_database), DB_ROWS_SPEC,
# DB_ROWS_COUNT, FILES_STATE, FILES_COUNT and FILES_SPEC. The rows and the files must agree on every key, size and SHA-256, or it is a refusal: a state in which they do not is
# not one that can be preserved or compared. Needs the files volume (a files action has run) and the 34 tables.
state_measure() {
    local checked assets agrees=0
    files_db_rows
    capture checked 'checking the Resources directory' run_files check "$DB_ROWS_SPEC"
    grep -Fxq 'files:check=ok' <<<"$checked" || refuse "the files helper did not report files:check=ok." # guard:G26-measure-check-reported
    files_report "$checked"
    if [[ "$DB_ROWS_SPEC" == none || "$DB_ROWS_SPEC" == empty ]]; then
        [[ "$FILES_COUNT" == 0 ]] && agrees=1
    else
        [[ "$FILES_STATE" == present && "$FILES_SPEC" == "$DB_ROWS_SPEC" ]] && agrees=1
    fi
    [[ $agrees == 1 ]] || refuse "the Resources directory and the asset rows the database holds do not agree on every key, size and SHA-256, so the state cannot be preserved or compared. Nothing was changed." # guard:G26-measure-agree
    measure_database
    assets="$(awk '$1 == "resource_assets" { print $2 }' <<<"$MEAS_TABLES")"
    [[ "$assets" == "${DB_ROWS_COUNT:-0}" ]] || refuse "the application's snapshot counts $assets resource_assets rows and the query for the asset rows ${DB_ROWS_COUNT:-0}." # guard:G26-measure-two-readers
}

# --- the preservation record ----

# preserve_record_text: the whole record, from the PR_* values: PR_TAKEN, PR_FP, PR_COMMIT, PR_SERVER, PR_CURRENT_FP, PR_PREVIOUS_FP, PR_RING_FP, PR_DUMP_SHA, PR_DUMP_BYTES, PR_LEDGER,
# PR_STRUCT, PR_FILES_STATE, PR_FILES_COUNT, PR_ROWS_COUNT, PR_INV_SHA, PR_TABLES (`<table> <rows> <row digest> <structure digest>`), PR_FILES_SPEC and PR_ROWS_SPEC
# (`key:bytes:sha256,...` or `empty`). One fact to a line, so that a changed, missing, repeated or reordered line is found. No key, password, token or row: fingerprints,
# names, counts, sizes and digests. It is read back by preserve_record_problem, which requires the file to be exactly this text.
preserve_record_text() {
    local name rows row_sha struct_sha entry entries=() key bytes sha
    printf '%s\n' "$PRESERVE_RECORD_VERSION"
    printf 'taken_at=%s\nrun_token_fingerprint=%s\ncommit=%s\nserver_version=%s\n' "$PR_TAKEN" "$PR_FP" "$PR_COMMIT" "$PR_SERVER"
    printf 'key_current=%s\nkey_previous=%s\nkeyring=%s\n' "$PR_CURRENT_FP" "$PR_PREVIOUS_FP" "$PR_RING_FP"
    printf 'export_file=%s\nexport_sha256=%s\nexport_bytes=%s\n' "$PRESERVE_DUMP_FILE" "$PR_DUMP_SHA" "$PR_DUMP_BYTES"
    printf 'ledger=%s\nstructure_sha256=%s\n' "$PR_LEDGER" "$PR_STRUCT"
    printf 'resources_directory=%s\nresource_files=%s\nasset_rows=%s\ninventory_sha256=%s\n' "$PR_FILES_STATE" "$PR_FILES_COUNT" "$PR_ROWS_COUNT" "$PR_INV_SHA"
    while read -r name rows row_sha struct_sha; do
        [[ -n "$name" ]] || continue
        printf 'table %s %s %s %s\n' "$name" "$rows" "$row_sha" "$struct_sha"
    done <<<"$PR_TABLES"
    if [[ "$PR_FILES_SPEC" != empty ]]; then
        IFS=',' read -ra entries <<<"$PR_FILES_SPEC"
        for entry in "${entries[@]}"; do IFS=: read -r key bytes sha <<<"$entry"; printf 'file %s %s %s\n' "$key" "$bytes" "$sha"; done
    fi
    if [[ "$PR_ROWS_SPEC" != empty ]]; then
        IFS=',' read -ra entries <<<"$PR_ROWS_SPEC"
        for entry in "${entries[@]}"; do IFS=: read -r key bytes sha <<<"$entry"; printf 'row %s %s %s\n' "$key" "$bytes" "$sha"; done
    fi
    printf 'preserve:complete\n'
}

# preserve_record_problem RECORD EXPORT: the record is this run's and intact, and the export it names is the file it says, or the reason it is not, in PR_WHY (this runs in the
# caller's shell and sets the PR_* values, so it is not called inside a command substitution). What it establishes, and what it does not:
#   ARTIFACT INTEGRITY: the export is the file the record names, by SHA-256 and size; the record is this run's (the run token's fingerprint), for the commit the source volume was
#     prepared from and for the keys this run generated, and is exactly the text this script writes.
#   STRUCTURAL CONSISTENCY: the export is made only of the line forms current_dump_problem accepts, creates and drops exactly the 34 tables, and holds as many row lines for each
#     durable table as the record says the database held; the record's own facts agree with each other (the 34 tables, the structure aggregate, the ledger, the files and the asset rows).
#   NOT ESTABLISHED: that the export's rows are the rows the recorded digests were taken of (no digest is derived from the export), or that the record is the database's NOW: the
#     second is what preserve_state_differences measures, when a destructive restore asks.
preserve_record_problem() {
    local file="$1" dumpfile="$2" line n=0 first='' name rows row_sha struct_sha key bytes sha extra previous='' table_text='' files_spec='' rows_spec='' files_n=0 rows_n=0 value
    local -A seen=()
    PR_WHY='' PR_TAKEN='' PR_FP='' PR_COMMIT='' PR_SERVER='' PR_CURRENT_FP='' PR_PREVIOUS_FP='' PR_RING_FP='' PR_DUMP_SHA='' PR_DUMP_BYTES='' PR_LEDGER='' PR_STRUCT=''
    PR_FILES_STATE='' PR_FILES_COUNT='' PR_ROWS_COUNT='' PR_INV_SHA='' PR_TABLES='' PR_FILES_SPEC='' PR_ROWS_SPEC=''
    while IFS= read -r line; do
        n=$((n + 1))
        case "$line" in
            preserve-v1) first="$line" ;;
            taken_at=*) PR_TAKEN="${line#taken_at=}" ;;
            run_token_fingerprint=*) PR_FP="${line#run_token_fingerprint=}" ;;
            commit=*) PR_COMMIT="${line#commit=}" ;;
            server_version=*) PR_SERVER="${line#server_version=}" ;;
            key_current=*) PR_CURRENT_FP="${line#key_current=}" ;;
            key_previous=*) PR_PREVIOUS_FP="${line#key_previous=}" ;;
            keyring=*) PR_RING_FP="${line#keyring=}" ;;
            export_sha256=*) PR_DUMP_SHA="${line#export_sha256=}" ;;
            export_bytes=*) PR_DUMP_BYTES="${line#export_bytes=}" ;;
            ledger=*) PR_LEDGER="${line#ledger=}" ;;
            structure_sha256=*) PR_STRUCT="${line#structure_sha256=}" ;;
            resources_directory=*) PR_FILES_STATE="${line#resources_directory=}" ;;
            resource_files=*) PR_FILES_COUNT="${line#resource_files=}" ;;
            asset_rows=*) PR_ROWS_COUNT="${line#asset_rows=}" ;;
            inventory_sha256=*) PR_INV_SHA="${line#inventory_sha256=}" ;;
            'table '*)
                IFS=' ' read -r _ name rows row_sha struct_sha extra <<<"$line"
                [[ -z "$extra" && "$name" =~ ^[a-z0-9_]+$ && "$rows" =~ ^(0|[1-9][0-9]{0,11})$ && "$row_sha" =~ ^[0-9a-f]{64}$ && "$struct_sha" =~ ^[0-9a-f]{64}$ ]] || { PR_WHY="a table line is malformed"; return 1; }
                table_text+="$name $rows $row_sha $struct_sha"$'\n'
                ;;
            'file '* | 'row '*)
                IFS=' ' read -r value key bytes sha extra <<<"$line"
                [[ -z "$extra" && "$key" =~ $KEY_RE && "$bytes" =~ ^(0|[1-9][0-9]{0,11})$ && "$sha" =~ ^[0-9a-f]{64}$ ]] || { PR_WHY="a $value line is not a Resources storage key, a size and a SHA-256"; return 1; }
                if [[ "$value" == file ]]; then
                    [[ -z "${seen[f$key]:-}" ]] || { PR_WHY="a file is listed twice"; return 1; }
                    seen[f$key]=1 files_n=$((files_n + 1)) files_spec+="${files_spec:+,}$key:$bytes:$sha"
                else
                    [[ -z "${seen[r$key]:-}" ]] || { PR_WHY="an asset row is listed twice"; return 1; }
                    seen[r$key]=1 rows_n=$((rows_n + 1)) rows_spec+="${rows_spec:+,}$key:$bytes:$sha"
                fi
                ;;
            export_file=* | preserve:complete) ;;
            *) PR_WHY="line $n is not one this script writes"; return 1 ;;
        esac
    done <"$file"
    PR_TABLES="${table_text%$'\n'}" PR_FILES_SPEC="${files_spec:-empty}" PR_ROWS_SPEC="${rows_spec:-empty}"
    [[ "$first" == "$PRESERVE_RECORD_VERSION" && "$(head -n 1 "$file")" == "$PRESERVE_RECORD_VERSION" ]] || { PR_WHY="its version is not $PRESERVE_RECORD_VERSION"; return 1; }
    [[ "$PR_FP" == "$(token_fingerprint "$(state_token "$ROOT")")" ]] || { PR_WHY="it is not this run's (its run token fingerprint differs)"; return 1; } # guard:G26-pr-run
    [[ "$PR_TAKEN" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || { PR_WHY="its taken_at is not a UTC time"; return 1; } # guard:G26-pr-taken
    [[ "$PR_COMMIT" =~ ^[0-9a-f]{40}$ && "$PR_COMMIT" == "$(sed -n 's/^current=//p' "$ROOT/$PREPARED_FILE")" ]] || { PR_WHY="it is not for the current commit the source volume was prepared from"; return 1; } # guard:G26-pr-source
    [[ "$PR_SERVER" =~ $SERVER_VERSION_RE ]] || { PR_WHY="its server is not the rehearsal's MariaDB 10.11.18"; return 1; } # guard:G26-pr-server
    [[ "$PR_CURRENT_FP" == "$CURRENT_FP" && "$PR_PREVIOUS_FP" == "$PREVIOUS_FP" && "$PR_RING_FP" == "$RING_FP" ]] || { PR_WHY="its keyring is not the keys this run generated (the fingerprints differ)"; return 1; } # guard:G26-pr-keyring
    [[ "$(sed -n 's/^export_file=//p' "$file")" == "$PRESERVE_DUMP_FILE" ]] || { PR_WHY="its export is not $PRESERVE_DUMP_FILE"; return 1; }
    [[ "$PR_DUMP_SHA" =~ ^[0-9a-f]{64}$ && "$PR_DUMP_BYTES" =~ ^[1-9][0-9]{0,11}$ ]] || { PR_WHY="its export checksum or size is malformed"; return 1; }
    [[ "$(sha256sum <"$dumpfile" | cut -c1-64)" == "$PR_DUMP_SHA" ]] || { PR_WHY="the export's SHA-256 is not the record's"; return 1; } # guard:G26-pr-export-sha
    [[ "$(wc -c <"$dumpfile" | tr -d ' ')" == "$PR_DUMP_BYTES" ]] || { PR_WHY="the export's size is not the record's"; return 1; } # guard:G26-pr-export-bytes
    [[ "$(cut -d' ' -f1 <<<"$PR_TABLES")" == "$(upgraded_inventory)" ]] || { PR_WHY="its table list is not exactly the 34 tables of the current schema, in order"; return 1; } # guard:G26-pr-tables
    [[ "$PR_STRUCT" == "$(structure_aggregate "$PR_TABLES")" ]] || { PR_WHY="its structure digest is not that of the structure digests it lists"; return 1; } # guard:G26-pr-structure
    [[ "$PR_LEDGER" =~ ^"$CURRENT_LEDGER_COUNT"\ "$CURRENT_LAST_MIGRATION"\ [0-9a-f]{64}$ ]] || { PR_WHY="its migration ledger is not the current schema's $CURRENT_LEDGER_COUNT migrations ending at $CURRENT_LAST_MIGRATION"; return 1; } # guard:G26-pr-ledger
    [[ "$PR_FILES_STATE" == present || ( "$PR_FILES_STATE" == absent && "$files_n" == 0 ) ]] || { PR_WHY="its Resources directory is neither present nor absent, or is absent and lists files"; return 1; } # guard:G26-pr-files-state
    [[ "$PR_FILES_SPEC" == "$PR_ROWS_SPEC" && "$PR_FILES_COUNT" == "$files_n" && "$PR_ROWS_COUNT" == "$rows_n" ]] || { PR_WHY="its Resource files, its asset rows and their counts do not agree with one another"; return 1; } # guard:G26-pr-files-agree
    [[ "$PR_INV_SHA" == "$(printf '%s' "$PR_FILES_SPEC" | sha256sum | cut -c1-64)" ]] || { PR_WHY="its inventory digest is not that of the files it lists"; return 1; } # guard:G26-pr-inventory
    [[ "$(awk '$1 == "resource_assets" { print $2 }' <<<"$PR_TABLES")" == "$rows_n" ]] || { PR_WHY="the asset rows it lists are not the number of resource_assets rows it records"; return 1; } # guard:G26-pr-assets-table
    value="$(current_dump_problem "$dumpfile" "$(awk '{ print $1 " " $2 " " $3 }' <<<"$PR_TABLES")")" || { PR_WHY="the export is not consistent with the record (its statement forms, table inventory or row counts): $value"; return 1; } # guard:G26-pr-export-content
    [[ "$(<"$file")" == "$(preserve_record_text)" ]] || { PR_WHY="it is not exactly what this script writes (an extra, missing or reordered line)"; return 1; } # guard:G26-pr-exact
    return 0
}

# require_preserve_record: the failed-state preservation an earlier stage made is present, complete, this run's and intact (preserve_record_problem), or nothing may rely on it.
# The record is the commit point: an export without it is not a preservation, whatever it holds. Sets the PR_* values.
require_preserve_record() {
    load_keyring
    { state_file_ok "$PRESERVE_RECORD_FILE" && state_file_ok "$PRESERVE_DUMP_FILE"; } || refuse "there is no complete failed-state preservation in the state directory ($PRESERVE_RECORD_FILE and $PRESERVE_DUMP_FILE, private files of this script's). Run: restore-rehearsal.sh preserve-failed-state. Nothing was changed." # guard:G26-preserve-required
    preserve_record_problem "$ROOT/$PRESERVE_RECORD_FILE" "$ROOT/$PRESERVE_DUMP_FILE" || refuse "the failed-state preservation record cannot be relied on: $PR_WHY. Nothing was changed." # guard:G26-preserve-intact
}

# preserve_state_differences: what the database and the Resource files are NOW (state_measure's values) compared with what the record holds, as the names of what differs: the
# durable tables' rows and digests, every table's structure, the ledger, the Resources directory's inventory and the asset rows. Transient tables are not compared: their rows are
# not preserved (the export holds their structure only) and a session or a cache entry changes them without any change to what matters. Needs require_preserve_record and state_measure.
preserve_state_differences() {
    local out=''
    out+="$(digest_differences "$(durable_lines "$PR_TABLES")" "$(durable_lines "$MEAS_TABLES")")"$'\n' # guard:G26-differ-durable
    out+="$(digest_differences "$(awk '{ print $1 " " $4 }' <<<"$PR_TABLES")" "$MEAS_STRUCT")"$'\n' # guard:G26-differ-structure
    [[ "$PR_LEDGER" == "$MEAS_LEDGER" ]] || out+="ledger"$'\n' # guard:G26-differ-ledger
    [[ "$PR_FILES_STATE" == "$FILES_STATE" && "$PR_FILES_SPEC" == "$FILES_SPEC" ]] || out+="resource-files"$'\n' # guard:G26-differ-files
    [[ "$PR_ROWS_SPEC" == "${DB_ROWS_SPEC}" ]] || out+="resource-asset-rows"$'\n' # guard:G26-differ-rows
    printf '%s' "$out" | grep -v '^$' | LC_ALL=C sort -u || true
}

# --- preserve-failed-state ----

# preserve_partial_note: what is true of the state directory when the stage stops, told from the files themselves: a name is this stage's own only if it is the very file the
# stage linked from its temporary one (the same inode), so a file that appeared under the name by another hand is never counted, and a signal between a link and the next line does not
# hide a file the stage did put in place. The record is last. Nothing is removed, renamed back or repaired.
PRESERVE_DUMP_TMP='' PRESERVE_RECORD_TMP=''
preserve_partial_note() {
    local dump=no record=no other=''
    [[ -z "$PRESERVE_DUMP_TMP" || ! "$ROOT/$PRESERVE_DUMP_FILE" -ef "$PRESERVE_DUMP_TMP" ]] || dump=yes
    [[ -z "$PRESERVE_RECORD_TMP" || ! "$ROOT/$PRESERVE_RECORD_FILE" -ef "$PRESERVE_RECORD_TMP" ]] || record=yes
    if [[ "$record" == no && ( -e "$ROOT/$PRESERVE_RECORD_FILE" || -L "$ROOT/$PRESERVE_RECORD_FILE" ) ]]; then other=" A file named $PRESERVE_RECORD_FILE is in the state directory that this stage did not write, and it is not part of this preservation."; fi
    if [[ "$record" == yes ]]; then
        printf '%s and %s were put in place (%s last) and the stage failed after that. They may be a preservation and may not: only require_preserve_record accepting them makes them one, and nothing may rely on them until it does. Nothing was removed or repaired.' "$PRESERVE_DUMP_FILE" "$PRESERVE_RECORD_FILE" "$PRESERVE_RECORD_FILE"
    elif [[ "$dump" == yes ]]; then
        printf '%s was put in place and %s was not written, so it is NOT a failed-state preservation, whatever it holds. Nothing was removed or repaired.%s' "$PRESERVE_DUMP_FILE" "$PRESERVE_RECORD_FILE" "$other"
    else
        printf 'Nothing of the failed-state preservation was put in place.%s' "$other"
    fi
}
on_preserve_exit() {
    local status=$? tmp note=''
    if ((status != 0)); then note="$(preserve_partial_note)"; fi # before the temporary files go: it compares them with the names that were linked from them
    for tmp in ${PAIRED_TMPS[@]+"${PAIRED_TMPS[@]}"}; do rm -f -- "$tmp"; done
    if ((status != 0)); then
        stage_trailer 'The rehearsal files'
        warn "$note"
    fi
    stage_record_remove
}

# preserve-failed-state: from the database as it is (34 tables) and its Resource files, measure, export, measure again, and put the evidence in the state directory: the export
# first, the record last (the commit point), neither over a file that is there. It reads the database and the files and changes neither: the export is the existing guarded
# two-pass dump (take_dump, as the application account, fixed options), judged by current_dump_problem against the first measurement, so a failed or incomplete export is a
# refusal; the measurements are `snapshot-all` and the files container's read-only `check`. It is evidence, not a rollback, and nothing reads the export back as a restore source.
cmd_preserve_failed_state() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local name dump_tmp rec_tmp verified changed m1_tables m1_struct m1_ledger m1_spec m1_rows_spec m1_state m1_count m1_rows
    for name in "$PRESERVE_DUMP_FILE" "$PRESERVE_RECORD_FILE"; do
        [[ ! -e "$ROOT/$name" && ! -L "$ROOT/$name" ]] || refuse "'$name' is already in the state directory, and evidence of the failed state is never overwritten. The rehearsal is disposable: run cleanup, then start again." # guard:G26-preserve-no-overwrite
    done
    require_inventory "$(upgraded_inventory)" 'the 34 tables of the current schema (a failed state is preserved from the 34-table database)' # guard:G26-preserve-inventory
    load_keyring
    arm_stage preserve-failed-state on_preserve_exit
    say "Measuring the database and the Resources directory, before anything is taken ..."
    state_measure
    m1_tables="$MEAS_TABLES" m1_struct="$MEAS_STRUCT" m1_ledger="$MEAS_LEDGER" m1_spec="$FILES_SPEC" m1_rows_spec="$DB_ROWS_SPEC" m1_state="$FILES_STATE" m1_count="$FILES_COUNT" m1_rows="${DB_ROWS_COUNT:-0}"
    say "ok   34 tables, $m1_count Resource file(s) held to $m1_rows asset row(s), and a ledger of $CURRENT_LEDGER_COUNT migrations: measured"

    say "Exporting the failed state as the application account (the two-pass dump of the 34 tables) ..."
    paired_temp dump_tmp
    PRESERVE_DUMP_TMP="$dump_tmp"
    take_dump "$dump_tmp" || exit 1
    verified="$(current_dump_problem "$dump_tmp" "$(awk '{ print $1 " " $2 " " $3 }' <<<"$m1_tables")")" || refuse "the export is not what the dump contract requires: $verified. It was removed." # guard:G26-preserve-export-checked
    capture PR_SERVER 'reading the server version' server_version_text "$CID"
    [[ "$PR_SERVER" =~ $SERVER_VERSION_RE ]] || refuse "the server reports version '$PR_SERVER', not 10.11.18."
    PR_DUMP_SHA="$(sha256sum <"$dump_tmp" | cut -c1-64)" PR_DUMP_BYTES="$(wc -c <"$dump_tmp" | tr -d ' ')"

    say "Measuring again, now that the export is taken ..."
    state_measure
    changed="$(digest_differences "$m1_tables" "$MEAS_TABLES")"
    [[ "$m1_struct" == "$MEAS_STRUCT" ]] || changed+=" structure"
    [[ "$m1_ledger" == "$MEAS_LEDGER" ]] || changed+=" ledger"
    [[ "$m1_spec" == "$FILES_SPEC" && "$m1_rows_spec" == "$DB_ROWS_SPEC" && "$m1_state" == "$FILES_STATE" ]] || changed+=" resource-files"
    [[ -z "${changed// /}" ]] || refuse "the database or the Resources directory changed while the failed state was being exported (changed: $(tr '\n' ' ' <<<"$changed")). Nothing was put in place; the export is removed, and what was exported is not the state it was measured as." # guard:G26-preserve-quiescent
    say "ok   measured again afterwards: every table's rows and structure, the ledger, every Resource file and every asset row are exactly as before"

    PR_TAKEN="$(date -u +%Y-%m-%dT%H:%M:%SZ)" PR_FP="$(token_fingerprint "$(state_token "$ROOT")")" PR_COMMIT="$(sed -n 's/^current=//p' "$ROOT/$PREPARED_FILE")"
    PR_CURRENT_FP="$CURRENT_FP" PR_PREVIOUS_FP="$PREVIOUS_FP" PR_RING_FP="$RING_FP"
    PR_LEDGER="$m1_ledger" PR_TABLES="$(join_tables "$m1_tables" "$m1_struct")"
    PR_STRUCT="$(structure_aggregate "$PR_TABLES")" PR_FILES_STATE="$m1_state" PR_FILES_COUNT="$m1_count" PR_ROWS_COUNT="$m1_rows" PR_FILES_SPEC="$m1_spec" PR_ROWS_SPEC="$m1_rows_spec"
    PR_INV_SHA="$(printf '%s' "$m1_spec" | sha256sum | cut -c1-64)"
    paired_temp rec_tmp
    PRESERVE_RECORD_TMP="$rec_tmp"
    printf '%s\n' "$(preserve_record_text)" >"$rec_tmp"
    preserve_record_problem "$rec_tmp" "$dump_tmp" || refuse "the record just made is not consistent: $PR_WHY. Nothing was put in place." # guard:G26-preserve-record-checked

    preserve_commit "$dump_tmp" "$PRESERVE_DUMP_FILE"
    preserve_commit "$rec_tmp" "$PRESERVE_RECORD_FILE" # the commit point
    require_preserve_record
    say "ok   the export has the 34 tables' structure and the 27 durable tables' rows: $PR_DUMP_BYTES bytes, sha256 ${PR_DUMP_SHA:0:16}..., the line forms and counts checked as a backup's dump is"
    say "ok   $PRESERVE_RECORD_FILE binds the export by SHA-256 and size, and records the 34 tables' rows and structure, the ledger, the $m1_count Resource file(s) and $m1_rows asset row(s) (key, size, SHA-256), and this run's token fingerprint, commit and keyring fingerprints"
    say_inventory 'the Resource files preserved' "$m1_spec"
    say "Failed state preserved (evidence only: not a rollback and not a restore). Nothing in the database or the Resources directory was changed. Next: restore-rehearsal.sh restore-historical"
}

# preserve_commit TEMP NAME: the finished temporary file under its final name, by a hard link that fails if the name is taken, so that evidence is never put over a file.
preserve_commit() {
    ln -- "$1" "$ROOT/$2" 2>/dev/null || refuse "$2 is in the state directory already, and evidence is never put over a file. $(preserve_partial_note)" # guard:G26-preserve-commit-no-clobber
}

# --- R-preserve: the gate in front of the destructive restore ----

# require_failed_state_preserved: called by restore-historical BEFORE it clears anything. It is decided by what is OBSERVED, never by a flag: if the database has resource_assets
# rows, or the files volume holds a Resources directory with files, the clear is refused unless a preservation record exists, is intact (require_preserve_record) and equals the
# database and the files as they are now (preserve_state_differences). With no asset row and no file (every earlier scenario) nothing is required and no record is made.
require_failed_state_preserved() {
    local checked differs needed volume_listing
    files_db_rows
    FILES_COUNT=0
    if [[ -e "$ROOT/$FILES_VOLUME_MARKER" || -L "$ROOT/$FILES_VOLUME_MARKER" ]]; then
        capture checked 'reading the Resources directory' run_files check "$DB_ROWS_SPEC"
        grep -Fxq 'files:check=ok' <<<"$checked" || refuse "the files helper did not report files:check=ok. Nothing was changed." # guard:G26-gate-check-reported
        files_report "$checked"
    else # no record of the volume is "nothing was ever made" only if the daemon agrees: a volume of the rehearsal's name that exists without its record is never assumed empty
        capture volume_listing 'looking for the files volume' dkr volume ls --filter "name=$FILES_VOLUME_NAME" --format '{{.Name}}'
        ! in_list "$FILES_VOLUME_NAME" "$volume_listing" || refuse "the files volume $FILES_VOLUME_NAME exists, but the state directory has no record that this run established it, so whether it holds Resource files cannot be told. Nothing was changed. Run cleanup and start again." # guard:G26-gate-volume-unrecorded
    fi
    needed=yes
    { [[ "$DB_ROWS_SPEC" == none || "$DB_ROWS_SPEC" == empty ]] && ((FILES_COUNT == 0)); } && needed=no # guard:G26-gate-needed
    if [[ "$needed" == no ]]; then
        say "ok   no resource_assets row and no Resource file: there is nothing of the Resources to preserve before the clear"
        return 0
    fi
    say "The database holds Resource records or the Resources directory holds files: the failed state must be preserved first ..."
    require_preserve_record
    state_measure
    differs="$(preserve_state_differences)"
    [[ -z "$differs" ]] || refuse "the failed-state preservation record does not describe the state that is about to be cleared (differs: $(tr '\n' ' ' <<<"$differs")). The database and the Resource files changed after they were preserved, so the preservation is stale. Run cleanup and start again; nothing was changed." # guard:G26-gate-matches
    say "ok   a failed-state preservation record exists, is this run's and intact, and equals the database (every durable table's rows and digest, every structure, the ledger) and the $FILES_COUNT Resource file(s) and asset row(s) as they are now"
}

# --- the aside record, verify-aside, and the zero-state check ----

# write_state_file_once NAME CONTENT: a private file that was not there, put in place by a hard link that fails if the name is taken: evidence is never written over a file.
write_state_file_once() {
    local name="$1" content="$2" tmp
    mutation_begins
    tmp="$(umask 077 && mktemp -- "$ROOT/.${name}.XXXXXX")"
    (
        umask 077
        printf '%s\n' "$content" >"$tmp"
    )
    chmod 600 -- "$tmp"
    ln -- "$tmp" "$ROOT/$name" 2>/dev/null || { rm -f -- "$tmp"; refuse "$name is in the state directory already, and evidence is never written over a file."; } # guard:G26-evidence-no-clobber
    rm -f -- "$tmp"
}

# aside_record_text: the record `aside-files` persists, from the AR_* values (AR_TAKEN, AR_FP, AR_STAMP, AR_NAME, AR_PRESERVE, AR_COUNT, AR_INV_SHA, AR_SPEC): what the helper
# measured when it renamed the Resources directory (the keys, sizes and SHA-256 values of the renamed directory, identical to those read just before the rename), under which
# validated stamp and name, and the SHA-256 of the preservation record that was in the state directory at the time (`none` if there was none). One fact to a line.
aside_record_text() {
    local entry entries=() key bytes sha
    printf '%s\n' "$ASIDE_RECORD_VERSION"
    printf 'taken_at=%s\nrun_token_fingerprint=%s\nstamp=%s\naside_name=%s\npreserve_record_sha256=%s\naside_files=%s\ninventory_sha256=%s\n' "$AR_TAKEN" "$AR_FP" "$AR_STAMP" "$AR_NAME" "$AR_PRESERVE" "$AR_COUNT" "$AR_INV_SHA"
    if [[ "$AR_SPEC" != empty ]]; then
        IFS=',' read -ra entries <<<"$AR_SPEC"
        for entry in "${entries[@]}"; do IFS=: read -r key bytes sha <<<"$entry"; printf 'file %s %s %s\n' "$key" "$bytes" "$sha"; done
    fi
    printf 'aside:complete\n'
}

# aside_record_problem RECORD: the record is this run's, names the fixed aside (the prefix and a validated stamp, a sibling of the Resources directory: no path, no slash), and is
# internally consistent; the reason in AR_WHY, and the AR_* values set (called in the caller's shell). It establishes nothing about the directory: verify-aside measures that.
aside_record_problem() {
    local file="$1" line n=0 first='' key bytes sha extra spec='' files_n=0 previous=''
    AR_WHY='' AR_TAKEN='' AR_FP='' AR_STAMP='' AR_NAME='' AR_PRESERVE='' AR_COUNT='' AR_INV_SHA='' AR_SPEC=''
    while IFS= read -r line; do
        n=$((n + 1))
        case "$line" in
            aside-v1) first="$line" ;;
            taken_at=*) AR_TAKEN="${line#taken_at=}" ;;
            run_token_fingerprint=*) AR_FP="${line#run_token_fingerprint=}" ;;
            stamp=*) AR_STAMP="${line#stamp=}" ;;
            aside_name=*) AR_NAME="${line#aside_name=}" ;;
            preserve_record_sha256=*) AR_PRESERVE="${line#preserve_record_sha256=}" ;;
            aside_files=*) AR_COUNT="${line#aside_files=}" ;;
            inventory_sha256=*) AR_INV_SHA="${line#inventory_sha256=}" ;;
            'file '*)
                IFS=' ' read -r _ key bytes sha extra <<<"$line"
                [[ -z "$extra" && "$key" =~ $KEY_RE && "$bytes" =~ ^(0|[1-9][0-9]{0,11})$ && "$sha" =~ ^[0-9a-f]{64}$ ]] || { AR_WHY="a file line is not a Resources storage key, a size and a SHA-256"; return 1; }
                [[ -z "$previous" || "$key" > "$previous" ]] || { AR_WHY="a key is out of order or listed twice"; return 1; }
                previous="$key" files_n=$((files_n + 1)) spec+="${spec:+,}$key:$bytes:$sha"
                ;;
            aside:complete) ;;
            *) AR_WHY="line $n is not one this script writes"; return 1 ;;
        esac
    done <"$file"
    AR_SPEC="${spec:-empty}"
    [[ "$first" == "$ASIDE_RECORD_VERSION" && "$(head -n 1 "$file")" == "$ASIDE_RECORD_VERSION" ]] || { AR_WHY="its version is not $ASIDE_RECORD_VERSION"; return 1; }
    [[ "$AR_FP" == "$(token_fingerprint "$(state_token "$ROOT")")" ]] || { AR_WHY="it is not this run's (its run token fingerprint differs)"; return 1; } # guard:G26-ar-run
    [[ "$AR_TAKEN" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || { AR_WHY="its taken_at is not a UTC time"; return 1; }
    [[ "$AR_STAMP" =~ ^[0-9]{8}T[0-9]{6}Z$ && "$AR_NAME" == "$FILES_STORE_DIR.before-restore-$AR_STAMP" ]] || { AR_WHY="its aside is not the fixed name (resources.before-restore- and a UTC stamp, a sibling of the Resources directory)"; return 1; } # guard:G26-ar-name
    [[ "$AR_PRESERVE" == none || "$AR_PRESERVE" =~ ^[0-9a-f]{64}$ ]] || { AR_WHY="its preservation identity is neither a SHA-256 nor none"; return 1; } # guard:G26-ar-preserve
    [[ "$AR_COUNT" == "$files_n" && "$AR_INV_SHA" == "$(printf '%s' "$AR_SPEC" | sha256sum | cut -c1-64)" ]] || { AR_WHY="its count or inventory digest is not that of the files it lists"; return 1; } # guard:G26-ar-inventory
    [[ "$(<"$file")" == "$(aside_record_text)" ]] || { AR_WHY="it is not exactly what this script writes (an extra, missing or reordered line)"; return 1; } # guard:G26-ar-exact
    return 0
}

# verify-aside: READ-ONLY. The Resources directory that `aside-files` moved aside is where the record says, is exactly what the record and the failed-state preservation say it is,
# and the active Resources directory is absent (the historical zero-file restoration extracted nothing). It reads the preservation record, the aside record and the Resource-files
# backup's manifest, and the aside through the files container's `inspect-aside` (read-only mount; the name is built from the validated stamp, a sibling of `resources`, so it is
# outside the application's Resources disk and its prune cannot see it); it changes nothing and moves nothing back.
#
# "Recoverable", precisely: the preserved Resource files (the aside directory) and the saved database evidence (the preservation record's asset rows and the export) are a
# CONSISTENT PAIR of the failed state, read from the disk now: every key, size and SHA-256 of the aside is the aside record's, the preservation's file inventory and its asset rows;
# no file is missing, extra or changed. It does not say that anything was restored, and nothing here puts the files back: that is a later, separate decision.
cmd_verify_aside() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local inspected want aside_state aside_spec aside_count active_state
    require_preserve_record
    state_file_ok "$ASIDE_RECORD_FILE" || refuse "there is no aside record in the state directory (aside-files has not finished), or it is not a private file of this script's. Nothing was changed." # guard:G26-aside-record-required
    aside_record_problem "$ROOT/$ASIDE_RECORD_FILE" || refuse "the aside record cannot be relied on: $AR_WHY. Nothing was changed." # guard:G26-aside-record-intact
    [[ "$AR_PRESERVE" == "$(sha256sum <"$ROOT/$PRESERVE_RECORD_FILE" | cut -c1-64)" ]] || refuse "the aside record was made against another preservation record (or against none) than the one in the state directory. Nothing was changed." # guard:G26-verify-binds-preserve
    files_backup_required
    [[ "$FILES_MANIFEST_STATE" == absent ]] || refuse "the Resource-files backup holds files, so a restore extracts them again and the active Resources directory is not absent: this verification is of the historical zero-file restoration. Nothing was changed." # guard:G26-verify-zero-file
    capture inspected 'reading the aside directory' run_files inspect-aside "$AR_STAMP"
    want="$FILES_STORE_DIR.before-restore-$AR_STAMP"
    { [[ "$(grep -c '^aside:name=' <<<"$inspected" || true)" == 1 ]] && grep -Fxq "aside:name=$want" <<<"$inspected" && grep -Fxq 'aside:inspected=ok' <<<"$inspected"; } || refuse "the files helper did not report reading $want. Nothing was changed." # guard:G26-verify-reported
    files_report "$inspected" aside:
    aside_state="$FILES_STATE" aside_spec="$FILES_SPEC" aside_count="$FILES_COUNT"
    files_report "$inspected" active:
    active_state="$FILES_STATE"
    [[ "$aside_state" == present ]] || refuse "the aside directory $want is not there. Nothing was changed." # guard:G26-verify-aside-present
    [[ "$active_state" == absent ]] || refuse "the active Resources directory exists. In the historical zero-file restoration it must be absent; it is not touched here. Nothing was changed." # guard:G26-verify-active-absent
    [[ "$aside_spec" == "$AR_SPEC" ]] || refuse "the aside directory is not what aside.record says it is (a file is missing, extra, changed, or another size). Nothing was changed." # guard:G26-verify-aside-record
    [[ "$aside_spec" == "$PR_FILES_SPEC" && "$aside_spec" == "$PR_ROWS_SPEC" ]] || refuse "the aside directory is not the failed state's preserved Resource files and asset rows (a file is missing, extra, changed, or another size). Nothing was changed." # guard:G26-verify-aside-preserved
    say "ok   $want is a sibling of the active Resources directory (outside the application's Resources disk), the active Resources directory is absent, and nothing was moved or changed"
    say "ok   the aside holds $aside_count file(s): every key, size and SHA-256 is the one aside.record holds, the one the failed-state preservation holds for the Resource files, and the one it holds for the asset rows"
    say_inventory 'the preserved Resource files, as read now' "$aside_spec"
    say "Recoverable: the preserved Resource files and the saved database evidence ($PRESERVE_RECORD_FILE, $PRESERVE_DUMP_FILE) are a consistent pair of the failed state. Nothing was restored and nothing is put back by this stage."
}

# verify-zero-resources: READ-ONLY. The restored historical state has no Resource asset row (no resource_assets table at v0.3.0, or an empty one) and no active Resources directory.
# The files container's own `check empty` (an absent directory, or one with no file no row refers to) and the fixed row count are the whole of it: the application's prune is never
# run against a directory that is not there. The nonempty restored paired backup is another slice's verification.
cmd_verify_zero_resources() {
    [[ $# -eq 0 ]] || { usage >&2; exit 64; }
    preflight
    require_prepared_source
    local inventory_now checked
    capture inventory_now 'reading the table inventory' db_inventory
    [[ "$inventory_now" == "$(historical_inventory)" || "$inventory_now" == "$(upgraded_inventory)" ]] || refuse "the database is neither the 16 tables of v0.3.0 nor the 34 of the upgraded schema. Nothing was changed." # guard:G26-zero-inventory
    files_db_rows
    [[ "$DB_ROWS_SPEC" == none || "$DB_ROWS_SPEC" == empty ]] || refuse "resource_assets holds ${DB_ROWS_COUNT} row(s): this is not a state without Resource records. Nothing was changed." # guard:G26-zero-rows
    [[ -e "$ROOT/$FILES_VOLUME_MARKER" ]] || refuse "no files action has run in this rehearsal, so there is no files volume to read the Resources directory of. Nothing was changed." # guard:G26-zero-volume
    capture checked 'checking the Resources directory' run_files check empty
    grep -Fxq 'files:check=ok' <<<"$checked" || refuse "the files helper did not report files:check=ok." # guard:G26-zero-check-reported
    files_report "$checked"
    [[ "$FILES_STATE" == absent && "$FILES_COUNT" == 0 ]] || refuse "the active Resources directory exists. Nothing was changed." # guard:G26-zero-files
    say "ok   no resource_assets row ($([[ "$DB_ROWS_SPEC" == none ]] && echo 'there is no resource_assets table' || echo 'the table is empty')) and no active Resources directory"
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
        baseline) shift; cmd_baseline "$@" ;;
        take-backup) shift; cmd_take_backup "$@" ;;
        upgrade) shift; cmd_upgrade "$@" ;;
        reproduce-wp3-01) shift; cmd_reproduce_wp3_01 "$@" ;;
        restore-historical) shift; cmd_restore_historical "$@" ;;
        verify-signin) shift; cmd_verify_signin "$@" ;;
        reupgrade) shift; cmd_reupgrade "$@" ;;
        half-fail-upgrade) shift; cmd_half_fail_upgrade "$@" ;;
        revoke-after-backup) shift; cmd_revoke_after_backup "$@" ;;
        seed-files) shift; cmd_seed_files "$@" ;;
        backup-files) shift; cmd_backup_files "$@" ;;
        aside-files) shift; cmd_aside_files "$@" ;;
        restore-files) shift; cmd_restore_files "$@" ;;
        backup-paired) shift; cmd_backup_paired "$@" ;;
        preserve-failed-state) shift; cmd_preserve_failed_state "$@" ;;
        verify-aside) shift; cmd_verify_aside "$@" ;;
        verify-zero-resources) shift; cmd_verify_zero_resources "$@" ;;
        help | -h | --help) usage ;;
        *) usage >&2; exit 64 ;;
    esac
}

# Sourcing this file (scripts/tests/rehearsal.sh does) defines the functions and runs nothing.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
