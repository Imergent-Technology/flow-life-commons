# Runbook: next-release readiness (`main` since v0.3.0)

- **Purpose:** what must be true, and what an operator must do on the real host, before the next release from `main` serves real people. That release carries everything since v0.3.0: CRM / People (G1), Guardian Discussions (G2), Resources (G5) and organizational relationships with the Resources audience (G10, [ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)). This page is a checklist and a pointer to the procedures, not a procedure of its own. (It began as the G10 release-readiness runbook and keeps its file name so that existing links still work.)
- **Owner:** the product owner decides whether to release. A Platform Administrator carries out the host steps.
- **Last tested:** never on a real host. Every item below that names a command has been exercised only against development and test databases.
- **Status (2026-10-15):** **not approved for staging or production deployment.**
  - The four milestones are complete as development and integration on `main`. G10's last package, WP7, was squash-merged as `9a79b38`, with post-merge CI green.
  - None of the owner decisions in the [next-release checklist](#next-release-checklist) is made.
  - Under the G6 ruling D10, nothing is deployed until G6 WP1–WP3's critical findings are resolved or accepted ([G6 contract](../audits/g6/README.md)).
- **G6 WP3c, as of 2026-11-22:**
  - The operator recovery contract is corrected in the runbooks ([backup and restore](backup-and-restore.md)): the schema is cleared before the dump is loaded, the inventory is verified, the failed state is preserved and the security state is established before the application reopens, and an operator decision table says what to look at when `migrate` stops part-way (guidance, with no repair authorized).
  - **Rehearsed locally only**, on synthetic data against MariaDB 10.11.18 in an isolated Docker environment (2026-11-14 to 2026-11-21): clearing and loading, the inventory and data checks, a restore from a half-failed upgrade, the Resource files restored with the database, preserving the failed state (in the rehearsal's own variant), the sign-in with a second factor after the historical restore, and a successful re-upgrade; the paired recovery was verified against the backup's recorded content. **Not rehearsed:** security reconciliation, this contract's own `mysqldump` export of the failed state, a sign-in and `relationships:check` after the paired restore, and anything on the host ([what is and is not verified](backup-and-restore.md#what-is-established-what-was-rehearsed-and-what-is-not)).
  - The read-only pre-flight ([below](#pre-flight-read-only-queries-against-the-v030-database)) is written and tested against a synthetic fixture. **It has not been run on production or staging.**
  - None of this authorizes a restore or a deployment.
- **Release track and G6.** This release track is separate from G6, the core audit and stabilization milestone. G6 contributes evidence to it ([contract §9](../audits/g6/README.md#9-release-track-outside-g6)) but resolves none of its items, and never deploys.

## What the next release carries

Production runs **v0.3.0** (release id `20260927T072528-2f385b7`, confirmed by the product owner with `release:show`; see [deployment §11](deployment.md#11-later-deployments)). `main` is ahead of it by everything since. A release cut from `main` therefore deploys all of it together, with these 14 migrations (listed by `./flow release migrations`):

| Migration | Milestone | Effect on existing data |
| --- | --- | --- |
| `2026_10_01_*`, `2026_10_02_*` | CRM, Discussions | New tables only |
| `2026_10_04_*` | Resources | New tables only; Resource files live outside the database ([backup and restore](backup-and-restore.md#resource-files)) |
| `2026_10_09_000001_create_person_relationships_tables` | G10 | New tables only. **No relationship is created** for anyone |
| `2026_10_09_000002_rename_guardian_role_key` | G10 | **Renames every stored `guardian` assignment to `guardian-full`**, one row for one row, keeping each assignment's Person, grantor and time. It does not change what the role grants: the catalog does, and the catalog's `guardian-full` bundle is larger than v0.3.0's `guardian` ([below](#what-changes-for-people-who-hold-the-guardian-role-today)). It **refuses to run, changing nothing, if a `guardian-full` row already exists for anyone** (not only for the same Person), so it cannot be run twice |
| `2026_10_09_000003_create_sourced_role_grants_table` | G10 | New table only |
| `2026_10_11_000001_add_provenance_to_contact_methods` | G10 | Adds a nullable column; existing rows stay `NULL` |

**The release is `restore-required`** ([ADR 0027](../adr/0027-release-and-deployment-model.md), [deployment §3](deployment.md#restore-required--the-database-must-go-back-too)). After the role keys move, rolling back only the code fails closed: the previous catalog does not know `guardian-full`, so every Guardian-role user would lose that bundle. Recovery is the pre-migration backup.

**Building it.**
1. First confirm with `release:show` on the host that production still runs v0.3.0.
2. Then build with `./flow release build --ref <tag> --previous v0.3.0 --schema-rollback restore-required` ([deployment §8](deployment.md#8-flow-release-the-developer-side-tooling)). `--previous` names the release on the host, not the previous tag ([why](deployment.md#--previous-is-the-release-on-the-host-not-the-previous-tag)).

## What changes for people who hold the Guardian role today

**This is an expansion of access, not a rename.** The rename preserves the bundle the release's catalog gives `guardian-full`. It does not preserve the bundle production has today. In v0.3.0 (`Role::Guardian`) the `guardian` role grants **`console.access` only**. In the release, `guardian-full` grants **ten** capabilities (`Role::organizationalBundle()`), and the migration gives every holder of `guardian` that role. Compared against the v0.3.0 tag:

| Capability | v0.3.0 `guardian` | Release `guardian-full` | Where it was decided |
| --- | --- | --- | --- |
| `console.access` | yes | yes | Unchanged |
| `crm.people.view` | no | **yes** | [ADR 0034](../adr/0034-crm-enriches-identity-person.md), decision 21 (CRM) |
| `crm.people.manage` | no | **yes** | ADR 0034, decision 21 |
| `discussions.view` | no | **yes** | [ADR 0035](../adr/0035-guardian-discussions-are-durable-asynchronous-threads.md) |
| `discussions.participate` | no | **yes** | ADR 0035 |
| `resources.view` | no | **yes** | [ADR 0037](../adr/0037-resources-are-audience-targeted-packs-of-cards.md) |
| `resources.manage` | no | **yes** | ADR 0037 |
| `guardians.view` | no | **yes** | [ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md), A3 |
| `volunteers.view` | no | **yes** | ADR 0038, A3 |
| `volunteers.manage` | no | **yes** | ADR 0038, A3 |
| `guardians.manage`, `access.roles.assign`, `identity.*`, `membership.*` | no | no | Platform Administrator only |

So, on the day this release is deployed and its migrations run, **every Person who holds the `guardian` role gains nine capabilities**: they may see and change People (names, contact methods, tags), take part in Guardian discussions, read and manage every Resource (Drafts included), see Guardians, and see and manage Volunteers. The administrators are unaffected (they already hold everything). Nobody gains the power to recognise Guardians, assign roles, invite, or change Memberships.

Each row has an accepted ADR as its source, but those decisions were made package by package against the development line. **No one has yet decided, for the production host, that all nine reach every current Guardian-role holder at once.** That is the owner's decision to take, with the number of holders in front of them (the operator records it before the migration: [host step 2](#on-the-host-the-operators-steps-in-order)). It is also a reason the release is classified `restore-required`: a code-only rollback cannot take back what the role-key rename did.

**Sessions, and people who are not active.** Sessions hold no capabilities: the Authorizer derives them again on every request. So a Guardian-role holder who is **signed in when `artisan up` runs gains the nine capabilities without signing in again**. Holders whose Account is invited, or who have no Account, are renamed too and gain the bundle if they ever become active; a disabled Account has no authority, whatever it holds. Administrators are unaffected. The [pre-flight](#pre-flight-read-only-queries-against-the-v030-database) counts these groups before the migration.

**What `/me` must show afterwards.** For a former Guardian-role holder (not an administrator), `GET /api/v1/me` must list exactly these ten, in this order, and nothing else (pinned by `CurrentAccountCapabilitiesTest`, "reports a guardian's Console access and nothing else"):

```
console.access, crm.people.manage, crm.people.view, discussions.participate, discussions.view,
guardians.view, resources.manage, resources.view, volunteers.manage, volunteers.view
```

For comparison, before the release the same call lists `console.access` alone. The difference between the two lists is the table above. It is **expected** and is not a failed check.

## Next-release checklist

Each item is **Open** until its evidence is recorded here. Nothing in this runbook approves a release or performs a production operation, and a green suite approves nothing either.

1. **Development completion and CI — Satisfied (2026-10-15).**
   - The G10 closeout review (2026-10-13) required remediation. Sonnet carried it out, together with the e2e stabilization and the built-assets browser acceptance.
   - A focused, independent Opus verification then found three final issues. They were corrected at `cb34707`, with **no further independent re-audit of those three corrections**.
   - Pull-request CI (run `38656550748`, on `cb34707`) and post-merge CI (run `38668506906`, on `9a79b38`) passed.
   - The full history is in [ADR 0038's G10 closeout](../adr/0038-organizational-relationships-and-resource-viewing-authority.md#g10-closeout-2026-10-15).
2. **The G6 gate — Open.** WP1–WP3's critical findings must be resolved, or accepted under the G6 remediation standard, before this release is deployed (G6 ruling D10, [contract](../audits/g6/README.md)).
3. **The owner authorizes the Guardian capability expansion — Open** ([above](#what-changes-for-people-who-hold-the-guardian-role-today)): nine capabilities for every holder of the `guardian` role, at once. **That authorization has not been given.** It is a product decision, not an engineering one.
4. **Review the current production Guardian-role holders — Open.**
   - Before the owner decides, count and identify the holders of the `guardian` role on production, read-only. The decision in item 3 rests on this number.
   - Record the count again immediately before migrating ([host step 2](#on-the-host-the-operators-steps-in-order)).
   - The read-only query is the [pre-flight below](#pre-flight-read-only-queries-against-the-v030-database), prepared and tested by G6 WP3c. **Running it on the host is this item, and it has not been done.**
5. **`restore-required` recovery preparation — Open.**
   - The deployment runbook records that this path **has been rehearsed end to end only locally, on synthetic data, and never on the host**, and that it must be rehearsed on the host or its risk explicitly accepted before the first later release classified `restore-required` ([deployment §3](deployment.md#restore-required--the-database-must-go-back-too), [backup and restore](backup-and-restore.md)). This release is that release.
   - It is the owner's decision whether to rehearse on the host first or to accept the risk explicitly.
   - G6 WP3c's local rehearsal (synthetic data, MariaDB 10.11.18) is reusable evidence and a procedure. It does not replace that decision, and **a successful local rehearsal does not authorize a production restore or a deployment**.
   - The restore contract is corrected (2026-11-15, status refreshed 2026-11-22): the complete 34-table inventory and the CRM, Discussions, Relationships and `sourced_role_grants` rows are in the lists, the schema is cleared before the load, and the failed state and the security state are handled before the application reopens ([backup and restore](backup-and-restore.md)). It has been rehearsed locally, in part, with the Resource files and a half-failed upgrade included, never on the host ([the list](backup-and-restore.md#what-is-established-what-was-rehearsed-and-what-is-not)); the operator decision table for a failed `migrate` is [written](backup-and-restore.md#when-migrate-stops-part-way-which-state-is-this). **Still open for this item:** the host decision above, the security reconciliation (a manual requirement), a gzipped load, the real database's table inventory, and the owner's release authorization.
6. **Resources storage and backup prerequisites — Open.**
   - Resources has never been deployed, so its production-readiness items apply in full ([production readiness §4c](production-readiness.md)): Resource files in the authoritative backup pair, the upload limits, and the private file store.
   - The Resource-file backup and restore contract is written ([backup and restore](backup-and-restore.md#resource-files)): the zero-file backup, an archive whose exit status is checked, newer files moved aside, and a full agreement check. **It has been rehearsed locally with synthetic files and a database restore** (L3b and WU6), and is not a verified production procedure; the prune command cannot see content, subdirectories or links.
   - Whether cPanel's own backups cover `~/commons/shared` is not confirmed.
7. **Production mail — Open.** Production runs `MAIL_MAILER=log` ([production readiness §5](production-readiness.md)), so invitations and password-reset messages do not reach people. Guardian adoption that invites people depends on it.
8. **Administrator continuity — Open.** Production has one administrator, by deliberate choice. A second is recommended ([deployment step 16](deployment.md#16-create-a-second-administrator)).
9. **Host resource and compatibility checks — Open.**
   - PHP 8.3.33 and the configuration are checked by `security:production-check`. Production's `memory_limit` and `max_execution_time` have **never been measured**: development runs at 512M, and the backend test suite needs 1G.
   - Production's database is MariaDB 10.11.18.
10. **Correct the host-execution steps in this runbook before using them — Corrected 2026-11-15, never run on a host.**
    - Its commands used bare `php artisan`. The deployment runbook requires `/usr/local/bin/php <path>/artisan` and never a bare `php artisan` ([deployment §7](deployment.md#7-health-and-release-verification)). Host steps 2 and 3 now say so.
    - Host step 2 asked for a sign-in and `GET /api/v1/me` while the application is still in maintenance. No maintenance bypass exists ([ADR 0027](../adr/0027-release-and-deployment-model.md)), so that check can only follow `artisan up`. It now does.
11. **Guardian and Volunteer adoption plan — Open.** Decide who adopts whom, in what order, and with which Initiate decisions, before release ([Guardian adoption](guardian-adoption.md); host step 5).
12. **Dependency-audit gate — Open.** The release must pass its own dependency audit: `./flow audit` clean for the runtime Composer and npm packages, or each finding recorded as accepted by the owner. **It is not clean today:** nine Composer advisories on runtime packages (and one on a development package) were found on 2026-11-23, and the weekly `Security audit` workflow has failed since 2026-11-02. They are triaged, not fixed, in [G6-D26](../audits/g6/debt-register.md) and [the triage](../audits/g6/wp6b-dependency-advisories.md); production v0.3.0's tagged lock carries the same versions. A green `./flow check` does not satisfy this item, because it does not run the audit.
13. **Separate authorization to deploy — Open.** Given by the owner for a named release, after the items above.

**For reference, not decisions:**
- **Configuration.** G10 adds no environment variable, no queue, no schedule and no external service. The relationship definitions are in code (`app/Modules/Relationships/Definitions`). `security:production-check` is unchanged and must pass as before.
- **Know what the release changes for people.**
   - Every current Guardian-role holder keeps the key's holders and **gains nine capabilities** (the table above). Under the name `guardian-full` they hold the release's bundle, not the one v0.3.0 gave them. Administrators are untouched.
   - **No one becomes a Guardian, and no one reads Resources through audience delivery, until an operator adopts Guardians and Volunteers.** Until then, every current Console user still reads the whole library through `resources.view` (policy A: every audience and Draft). Holders of `guardian-initiate` or `console-participant` (none exist until an operator grants one) read only what their relationships and Membership make them eligible for.
   - Members are eligible for Member Resources in the Console only if they can sign in to the Console. Nothing delivers Resources outside the Console yet (`/my/` and WordPress are out of G10).

## Pre-flight: read-only queries against the v0.3.0 database

**Purpose.** Before the owner decides on the Guardian expansion ([checklist items 3 and 4](#next-release-checklist)), and again immediately before the migrations, answer from the real v0.3.0 database, read-only:

- exactly which role keys are stored, **by their bytes**, because MariaDB's unique key and plain `=` fold case, trailing spaces and accents, and the migration renames **only the exact key `guardian`** ([G6 WP3-02](../audits/g6/wp3-triage.md));
- whether any stored key would make the upgrade refuse or fail (an exact `guardian-full` anywhere, or a collation-equal variant of it on a Person who holds `guardian`);
- how many holders there are, by Account status, including Persons with no Account;
- the character sets and collations the new tables' foreign keys depend on ([G6 WP3-12](../audits/g6/wp3-triage.md)).

**Status.** The file below is **strictly read-only**: one `SET SESSION TRANSACTION READ ONLY`, after which the server itself refuses any write in that session, then nine `SELECT`s. It was run against the synthetic v0.3.0 fixture of the G6 WP3b upgrade tests (the real v0.3.0 schema, built from its 15 migrations) on MariaDB 10.11.19: it reports the fixture's case, space and accent variants, the invited, disabled and Account-less holders and the collations, it reports conflicts when they are planted, it changes no row, table or migration record, and its read-only mode is enforced. Seven planted defects in the SQL were each caught. **It has not been run on production or staging, and running it there is checklist item 4.** Not verified: that the host has a `mysql` client, how the host's `sql_mode` treats it, and what the host's collations are (that is what it is for).

**How to run it.**

1. Save the block below **exactly as it is** as `/home/<user>/commons/preflight-v030.sql`: outside the document root, outside `releases/`, in no directory the web serves. (A copy of this text is held equal to the tested file by `./flow check repo`, so a changed block fails the repository's checks.)
2. Read the credentials from `shared/.env` (`DB_DATABASE`, `DB_USERNAME`). Let `mysql` prompt for the password; never put it on the command line.
3. Run it against the v0.3.0 database, from a shell with `umask 077`:
   ```bash
   ( umask 077; mysql --default-character-set=utf8mb4 --table -u <db user> -p <db name> < /home/<user>/commons/preflight-v030.sql > /home/<user>/commons/preflight-v030.out )
   ```
   If the host has no `mysql` client, the statements are independent: run the `SELECT`s one at a time in the host's database tool. The first line is a safeguard, not a requirement: every other statement only reads. Say which tool was used when recording the result.
4. **Treat the output as confidential.** It lists Person ids and Account statuses. Keep it mode `0600`, do not paste it into a chat, ticket or email, and delete it when the numbers are recorded in the deploy log. The owner decides whether to keep it.

```sql preflight-v030
-- Flow Life Commons: read-only pre-flight for the v0.3.0 production schema (G6 WP3-10, WP3-12).
-- Run it against the v0.3.0 database BEFORE the migrations of the next release. It only reads.
-- The text, the expected output and what to do about it are in docs/runbooks/g10-release-readiness.md.
-- Every result starts with a `check_name` column. Hex is authoritative: the text columns can hide case, spaces and accents.
-- Exact comparisons use HEX(role_key) = HEX('...'). Plain comparisons (role_key = '...') follow the column's collation, as MariaDB's unique key does.

SET SESSION TRANSACTION READ ONLY;

SELECT '1 server and database' AS check_name,
       DATABASE() AS db,
       @@version AS server_version,
       s.default_character_set_name AS db_charset,
       s.default_collation_name AS db_collation
FROM information_schema.schemata s
WHERE s.schema_name = DATABASE();

SELECT '2 role keys by exact bytes' AS check_name,
       k.key_hex,
       k.key_text,
       k.kind,
       COUNT(*) AS assignments,
       COUNT(DISTINCT k.person_id) AS persons
FROM (
    SELECT person_id,
           HEX(role_key) AS key_hex,
           role_key AS key_text,
           CASE
               WHEN HEX(role_key) = HEX('guardian') THEN 'exact guardian'
               WHEN HEX(role_key) = HEX('guardian-full') THEN 'exact guardian-full'
               WHEN HEX(role_key) = HEX('platform_administrator') THEN 'exact platform_administrator'
               WHEN role_key = 'guardian' THEN 'variant of guardian'
               WHEN role_key = 'guardian-full' THEN 'variant of guardian-full'
               WHEN role_key = 'platform_administrator' THEN 'variant of platform_administrator'
               ELSE 'other'
           END AS kind
    FROM role_assignments
) k
GROUP BY k.key_hex, k.key_text, k.kind
ORDER BY k.key_hex;

SELECT '3 same-Person conflicts' AS check_name,
       g.person_id,
       g.id AS guardian_assignment_id,
       f.id AS other_assignment_id,
       HEX(f.role_key) AS other_key_hex,
       CASE
           WHEN HEX(f.role_key) = HEX('guardian-full') THEN 'exact guardian-full: the migration refuses to run'
           ELSE 'collation-equal variant: MariaDB rejects the rename with error 1062'
       END AS consequence
FROM role_assignments g
JOIN role_assignments f ON f.person_id = g.person_id AND f.id <> g.id AND f.role_key = 'guardian-full'
WHERE HEX(g.role_key) = HEX('guardian')
ORDER BY g.person_id, f.id;

SELECT '4 exact guardian-full on anyone' AS check_name,
       id AS assignment_id,
       person_id,
       granted_at
FROM role_assignments
WHERE HEX(role_key) = HEX('guardian-full')
ORDER BY id;

SELECT '5 guardian-role holders by Account status' AS check_name,
       h.key_kind,
       h.account_status,
       COUNT(DISTINCT h.person_id) AS persons,
       SUM(h.is_administrator) AS also_platform_administrator
FROM (
    SELECT ra.person_id,
           CASE
               WHEN HEX(ra.role_key) = HEX('guardian') THEN 'exact guardian: renamed by the migration'
               ELSE 'variant: not renamed, grants nothing'
           END AS key_kind,
           COALESCE(a.status, 'no Account') AS account_status,
           EXISTS (
               SELECT 1 FROM role_assignments x
               WHERE x.person_id = ra.person_id AND HEX(x.role_key) = HEX('platform_administrator')
           ) AS is_administrator
    FROM role_assignments ra
    LEFT JOIN accounts a ON a.person_id = ra.person_id
    WHERE ra.role_key = 'guardian'
) h
GROUP BY h.key_kind, h.account_status
ORDER BY h.key_kind, h.account_status;

SELECT '6 guardian-role holders' AS check_name,
       ra.person_id,
       ra.id AS assignment_id,
       HEX(ra.role_key) AS key_hex,
       COALESCE(a.status, 'no Account') AS account_status,
       ra.granted_at
FROM role_assignments ra
LEFT JOIN accounts a ON a.person_id = ra.person_id
WHERE ra.role_key = 'guardian'
ORDER BY ra.person_id;

SELECT '7a table collations' AS check_name,
       table_name,
       engine,
       table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
ORDER BY table_name;

SELECT '7b column character sets and collations' AS check_name,
       character_set_name,
       collation_name,
       COUNT(*) AS column_count
FROM information_schema.columns
WHERE table_schema = DATABASE() AND collation_name IS NOT NULL
GROUP BY character_set_name, collation_name
ORDER BY character_set_name, collation_name;

SELECT '7c columns the new foreign keys and the rename depend on' AS check_name,
       table_name,
       column_name,
       column_type,
       character_set_name,
       collation_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'people' AND column_name = 'id')
      OR (table_name = 'accounts' AND column_name IN ('id', 'person_id'))
      OR (table_name = 'role_assignments' AND column_name IN ('person_id', 'role_key'))
      OR (table_name = 'membership_grants' AND column_name = 'person_id')
  )
ORDER BY table_name, column_name;
```

**Reading the output.** Every result starts with `check_name`. The hex columns are authoritative; the text beside them can hide case, spaces and accents.

| Check | Expect | What it means, and what to do |
| --- | --- | --- |
| **1** server and database | The production database, MariaDB 10.11.x, `utf8mb4` | Confirms you are on the intended database. Record the version (production: 10.11.18; the tests ran 10.11.19). The `mysql` client's own connection collation is **not** the application's: the application's is `DB_COLLATION` in `shared/.env` (default `utf8mb4_unicode_ci`) |
| **2** role keys by exact bytes | Rows `exact platform_administrator` and `exact guardian` and **nothing else** | The v0.3.0 catalog can write only those two keys. A `variant of ...` or `other` row was edited by hand. A **variant of `guardian`** is **not** renamed and grants nothing; report it to the owner. An **`exact guardian-full`** must not exist (check 4) |
| **3** same-Person conflicts | **No rows** | A row is a Person who holds exact `guardian` **and** a `guardian-full` the migration cannot rename past. *exact guardian-full*: the migration refuses to run and changes nothing. *collation-equal variant*: MariaDB's unique key rejects the rename (error 1062) after the earlier migrations have been recorded; WP3b shows the stopped state is consistent and re-runnable once the offending row is gone, **not that a host recovers from it**. **Do not migrate.** What to do about the data is the owner's decision; this runbook does not prescribe an edit |
| **4** exact `guardian-full` on anyone | **No rows** | Any row makes the migration refuse to run, whoever holds it, **even a Person who holds no `guardian`**. Same instruction |
| **5** holders by Account status | The counts the owner's decision rests on | `exact guardian` rows are what the migration renames. **People who gain the nine capabilities** when the application is next up: status `active`, **minus** `also_platform_administrator` (administrators already hold everything). `invited` and `no Account` holders are renamed and gain them only if they ever become active; `disabled` holds no authority. Rows labelled *variant* are not renamed and grant nothing |
| **6** holders | The same Persons, by id | To identify them. Names are deliberately not printed; look the ids up in the Console. Compare with what the owner expects |
| **7a, 7b, 7c** collations | Every table `InnoDB` and `utf8mb4_unicode_ci`; every text column `utf8mb4_unicode_ci`, **plus one `utf8mb4_bin` column, `security_events.context`** (MariaDB stores its JSON type as `LONGTEXT` in that collation) | InnoDB requires a foreign key's two columns to share a character set and collation, and the new tables' `person_id` columns take the **application's** configured collation (`DB_CHARSET`, `DB_COLLATION`). Compare `people.id` in 7c with that setting. **A difference, or any other column outside the list, is a stop before migrating:** the new foreign keys may fail. That mechanism is the audit's reasoning, **not demonstrated here**, because the rehearsal cannot know the host's collations |

**Example, from the synthetic fixture (not production data).** Check 5, where Gus also holds `platform_administrator`, Ines is invited, Dora disabled, Nora has no Account, and four people hold a case, space or accent variant:

```
check_name                                 key_kind                                    account_status  persons  also_platform_administrator
5 guardian-role holders by Account status  exact guardian: renamed by the migration    active          2        1
5 guardian-role holders by Account status  exact guardian: renamed by the migration    disabled        1        0
5 guardian-role holders by Account status  exact guardian: renamed by the migration    invited         1        0
5 guardian-role holders by Account status  exact guardian: renamed by the migration    no Account      1        0
5 guardian-role holders by Account status  variant: not renamed, grants nothing        active          4        0
```

In that fixture two exact holders are active and one of them (Gus) is an administrator, so **one** person (Guy) would gain the nine capabilities by this reading. In production the same arithmetic is the owner's number.

## On the host (the operator's steps, in order)

None of these has been carried out. Follow the referenced runbooks; this list adds only what G10 needs.

1. **Release and deploy** as [deployment §4–§6](deployment.md) says, classifying the release `restore-required`. Take the backup inside the maintenance window, before migrations. It is the only recovery for the role-key migration. Record the number of `guardian` assignments first (step 2): once the migrations have run, the key is gone.
2. **Record, then verify, the role-key migration.** Run each command as `/usr/local/bin/php <path>/artisan` ([checklist item 10](#next-release-checklist)); `current` is the release serving at that moment. Before migrating, **run the [pre-flight](#pre-flight-read-only-queries-against-the-v030-database)**, which reports exactly how many `guardian` assignments there are and which Persons (the owner's decision above rests on this number), and then record the count:
   ```bash
   /usr/local/bin/php /home/<user>/commons/current/artisan tinker --execute="echo DB::table('role_assignments')->where('role_key','guardian')->count();"
   ```
   After migrating, still in maintenance:
   ```bash
   /usr/local/bin/php /home/<user>/commons/current/artisan tinker --execute="echo DB::table('role_assignments')->where('role_key','guardian')->count(), ' ', DB::table('role_assignments')->where('role_key','guardian-full')->count();"
   ```
   Expect `0 <n>`, where `<n>` is the number recorded. (MariaDB matches the key without regard to case, so a wrongly cased `Guardian` row, which grants nothing, would be counted too: if the two numbers differ, list the rows before concluding anything. The pre-flight's second check names those rows.) **The sign-in comes after `artisan up`**, in step 4: no maintenance bypass exists.
3. **Check the relationships are consistent** (read-only; it should report nothing on a fresh release):
   ```bash
   /usr/local/bin/php /home/<user>/commons/current/artisan relationships:check
   ```
4. **Leave maintenance** and verify over HTTP as the deployment runbook says. Then sign in as a former Guardian-role holder who is not an administrator: `GET /api/v1/me` lists **the ten capabilities above**, not the single `console.access` it listed before. If it lists fewer, the migration or the catalog is wrong; if it lists more, stop. A list identical to the old one would mean the old code is still running.
5. **Adopt Guardians, then Volunteers**, deliberately and one Person at a time, following [Guardian adoption](guardian-adoption.md). Start with the read-only report (`/usr/local/bin/php /home/<user>/commons/current/artisan relationships:guardian-adoption-report`). Remember: **adoption is not the migration**. Holding `guardian-full` is not evidence of being a Guardian, and each Guardian Initiate decision is the operator's. Volunteers are recorded under People → Relationships → Volunteers. No Volunteer gets a role.
6. **End the adoption pass with a clean `relationships:check`** (as in step 3).

**Never run the demo seeders on a real host.** `RelationshipsDemoSeeder`, `ResourcesDemoSeeder`, `E2eAccountSeeder` and `E2eSessionSeeder` refuse any environment but `local` and `testing` before they write anything, and none is part of `DatabaseSeeder`. A clean demo proves nothing about production data: production's Guardians are not adopted until an operator adopts them.

## Rollback

As [deployment §3](deployment.md#restore-required--the-database-must-go-back-too) for `restore-required`. Adoption itself writes only through reversible use cases ([Guardian adoption, rollback](guardian-adoption.md#rollback)), so it needs no database restore.

## Evidence

The G10 evidence for each acceptance row, the browser and accessibility results and the known deferred items are in [ADR 0038's WP7 implementation notes](../adr/0038-organizational-relationships-and-resource-viewing-authority.md#implementation-notes-wp7-2026-10-13) and its [closeout](../adr/0038-organizational-relationships-and-resource-viewing-authority.md#g10-closeout-2026-10-15). Known issues G6 tracks are in the [G6 debt register](../audits/g6/debt-register.md). A green local suite is evidence for the code. It is not approval to release.
