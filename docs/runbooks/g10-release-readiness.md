# Runbook: next-release readiness (`main` since v0.3.0)

- **Purpose:** what must be true, and what an operator must do on the real host, before the next release from `main` serves real people. That release carries everything since v0.3.0: CRM / People (G1), Guardian Discussions (G2), Resources (G5) and organizational relationships with the Resources audience (G10, [ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)). This page is a checklist and a pointer to the procedures, not a procedure of its own. (It began as the G10 release-readiness runbook and keeps its file name so that existing links still work.)
- **Owner:** the product owner decides whether to release. A Platform Administrator carries out the host steps.
- **Last tested:** never on a real host. Every item below that names a command has been exercised only against development and test databases.
- **Status (2026-10-15):** **not approved for staging or production deployment.**
  - The four milestones are complete as development and integration on `main`. G10's last package, WP7, was squash-merged as `9a79b38`, with post-merge CI green.
  - None of the owner decisions in the [next-release checklist](#next-release-checklist) is made.
  - Under the G6 ruling D10, nothing is deployed until G6 WP1–WP3's critical findings are resolved or accepted ([G6 contract](../audits/g6/README.md)).
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
   - G6 WP3 documents a read-only query against the v0.3.0 schema; running it on the host is this item.
5. **`restore-required` recovery preparation — Open.**
   - The deployment runbook records that this path **has never been exercised end to end** and must be completed and rehearsed before the first later release classified `restore-required` ([deployment §3](deployment.md#restore-required--the-database-must-go-back-too), [backup and restore](backup-and-restore.md)). This release is that release.
   - It is the owner's decision whether to rehearse on the host first or to accept the risk explicitly.
   - G6 WP3c's local rehearsal (synthetic data, MariaDB 10.11.18) is reusable evidence and a procedure. It does not replace that decision.
   - The backup table lists and the restore-together table do not yet name the CRM, Discussions, Relationships or `sourced_role_grants` tables. G6 WP3 corrects them.
6. **Resources storage and backup prerequisites — Open.**
   - Resources has never been deployed, so its production-readiness items apply in full ([production readiness §4c](production-readiness.md)): Resource files in the authoritative backup pair, the upload limits, and the private file store.
   - Whether cPanel's own backups cover `~/commons/shared` is not confirmed.
7. **Production mail — Open.** Production runs `MAIL_MAILER=log` ([production readiness §5](production-readiness.md)), so invitations and password-reset messages do not reach people. Guardian adoption that invites people depends on it.
8. **Administrator continuity — Open.** Production has one administrator, by deliberate choice. A second is recommended ([deployment step 16](deployment.md#16-create-a-second-administrator)).
9. **Host resource and compatibility checks — Open.**
   - PHP 8.3.33 and the configuration are checked by `security:production-check`. Production's `memory_limit` and `max_execution_time` have **never been measured**: development runs at 512M, and the backend test suite needs 1G.
   - Production's database is MariaDB 10.11.18.
10. **Correct the host-execution steps in this runbook before using them — Open.**
    - Its commands use bare `php artisan`. The deployment runbook requires `/usr/local/bin/php <path>/artisan` and never a bare `php artisan` ([deployment §7](deployment.md#7-health-and-release-verification)).
    - Host step 2 asks for a sign-in and `GET /api/v1/me` while the application is still in maintenance. No maintenance bypass exists ([ADR 0027](../adr/0027-release-and-deployment-model.md)), so that check can only follow `artisan up`.
11. **Guardian and Volunteer adoption plan — Open.** Decide who adopts whom, in what order, and with which Initiate decisions, before release ([Guardian adoption](guardian-adoption.md); host step 5).
12. **Separate authorization to deploy — Open.** Given by the owner for a named release, after the items above.

**For reference, not decisions:**
- **Configuration.** G10 adds no environment variable, no queue, no schedule and no external service. The relationship definitions are in code (`app/Modules/Relationships/Definitions`). `security:production-check` is unchanged and must pass as before.
- **Know what the release changes for people.**
   - Every current Guardian-role holder keeps the key's holders and **gains nine capabilities** (the table above). Under the name `guardian-full` they hold the release's bundle, not the one v0.3.0 gave them. Administrators are untouched.
   - **No one becomes a Guardian, and no one reads Resources through audience delivery, until an operator adopts Guardians and Volunteers.** Until then, every current Console user still reads the whole library through `resources.view` (policy A: every audience and Draft). Holders of `guardian-initiate` or `console-participant` (none exist until an operator grants one) read only what their relationships and Membership make them eligible for.
   - Members are eligible for Member Resources in the Console only if they can sign in to the Console. Nothing delivers Resources outside the Console yet (`/my/` and WordPress are out of G10).

## On the host (the operator's steps, in order)

None of these has been carried out. Follow the referenced runbooks; this list adds only what G10 needs.

1. **Release and deploy** as [deployment §4–§6](deployment.md) says, classifying the release `restore-required`. Take the backup inside the maintenance window, before migrations. It is the only recovery for the role-key migration. Record the number of `guardian` assignments first (step 2): once the migrations have run, the key is gone.
2. **Record, then verify, the role-key migration.** *Correction pending ([checklist item 10](#next-release-checklist)): run each command as `/usr/local/bin/php <path>/artisan`, and make the sign-in and `/me` check only after `artisan up`.* Before migrating, record how many `guardian` assignments there are, and which Persons (the owner's decision above rests on this number):
   ```bash
   php artisan tinker --execute="echo DB::table('role_assignments')->where('role_key','guardian')->count();"
   ```
   After migrating, still in maintenance:
   ```bash
   php artisan tinker --execute="echo DB::table('role_assignments')->where('role_key','guardian')->count(), ' ', DB::table('role_assignments')->where('role_key','guardian-full')->count();"
   ```
   Expect `0 <n>`, where `<n>` is the number recorded. (MariaDB matches the key without regard to case, so a wrongly cased `Guardian` row, which grants nothing, would be counted too: if the two numbers differ, list the rows before concluding anything.) Then sign in as a former Guardian-role holder who is not an administrator: `GET /api/v1/me` lists **the ten capabilities above**, not the single `console.access` it listed before. If it lists fewer, the migration or the catalog is wrong; if it lists more, stop. A list identical to the old one would mean the old code is still running.
3. **Check the relationships are consistent** (read-only; it should report nothing on a fresh release):
   ```bash
   php artisan relationships:check
   ```
4. **Leave maintenance** and verify over HTTP as the deployment runbook says.
5. **Adopt Guardians, then Volunteers**, deliberately and one Person at a time, following [Guardian adoption](guardian-adoption.md). Start with the read-only report (`php artisan relationships:guardian-adoption-report`). Remember: **adoption is not the migration**. Holding `guardian-full` is not evidence of being a Guardian, and each Guardian Initiate decision is the operator's. Volunteers are recorded under People → Relationships → Volunteers. No Volunteer gets a role.
6. **End the adoption pass with a clean `relationships:check`.**

**Never run the demo seeders on a real host.** `RelationshipsDemoSeeder`, `ResourcesDemoSeeder`, `E2eAccountSeeder` and `E2eSessionSeeder` refuse any environment but `local` and `testing` before they write anything, and none is part of `DatabaseSeeder`. A clean demo proves nothing about production data: production's Guardians are not adopted until an operator adopts them.

## Rollback

As [deployment §3](deployment.md#restore-required--the-database-must-go-back-too) for `restore-required`. Adoption itself writes only through reversible use cases ([Guardian adoption, rollback](guardian-adoption.md#rollback)), so it needs no database restore.

## Evidence

The G10 evidence for each acceptance row, the browser and accessibility results and the known deferred items are in [ADR 0038's WP7 implementation notes](../adr/0038-organizational-relationships-and-resource-viewing-authority.md#implementation-notes-wp7-2026-10-13) and its [closeout](../adr/0038-organizational-relationships-and-resource-viewing-authority.md#g10-closeout-2026-10-15). Known issues G6 tracks are in the [G6 debt register](../audits/g6/debt-register.md). A green local suite is evidence for the code. It is not approval to release.
