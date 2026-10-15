# Runbook: G10 release readiness

- **Purpose:** what must be true, and what an operator must do on the real host, before a release containing G10 ([ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)) serves real people. It is a checklist and a pointer to the procedures, not a procedure of its own.
- **Owner:** the product owner decides whether to release. A Platform Administrator carries out the host steps.
- **Last tested:** never on a real host. Every item below that names a command has been exercised only against development and test databases.
- **Status (2026-10-14):** **not approved for staging or production deployment.** The independent WP7 closeout review (2026-10-13) found this runbook wrong about what existing Guardian-role holders gain; that is corrected below. The review's remediation is implemented and awaits focused independent re-verification. The owner decisions in [Before release](#before-release) are not made.

## What a G10 release carries

Production runs **v0.3.0**. `main` is ahead of it by everything since: CRM / People, Guardian Discussions, Resources (G5) and G10. A release cut from `main` therefore deploys all of them together, with these migrations (listed by `./flow release migrations`):

| Migration | Milestone | Effect on existing data |
| --- | --- | --- |
| `2026_10_01_*`, `2026_10_02_*` | CRM, Discussions | New tables only |
| `2026_10_04_*` | Resources | New tables only; Resource files live outside the database ([backup and restore](backup-and-restore.md#resource-files)) |
| `2026_10_09_000001_create_person_relationships_tables` | G10 | New tables only. **No relationship is created** for anyone |
| `2026_10_09_000002_rename_guardian_role_key` | G10 | **Renames every stored `guardian` assignment to `guardian-full`**, one row for one row, keeping each assignment's Person, grantor and time. It does not change what the role grants: the catalog does, and the catalog's `guardian-full` bundle is larger than v0.3.0's `guardian` ([below](#what-changes-for-people-who-hold-the-guardian-role-today)). It **refuses to run, changing nothing, if a `guardian-full` row already exists for anyone** (not only for the same Person), so it cannot be run twice |
| `2026_10_09_000003_create_sourced_role_grants_table` | G10 | New table only |
| `2026_10_11_000001_add_provenance_to_contact_methods` | G10 | Adds a nullable column; existing rows stay `NULL` |

**The release is `restore-required`** ([ADR 0027](../adr/0027-release-and-deployment-model.md), [deployment §3](deployment.md#restore-required--the-database-must-go-back-too)). After the role keys move, rolling back only the code fails closed: the previous catalog does not know `guardian-full`, so every Guardian-role user would lose that bundle. Recovery is the pre-migration backup.

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

Each row has an accepted ADR as its source, but those decisions were made package by package against the development line. **No one has yet decided, for the production host, that all nine reach every current Guardian-role holder at once.** That is the owner's decision to take, with the number of holders in front of them (the operator records it before the migration: [host step 2](#on-the-hosts-the-operators-steps-in-order)). It is also a reason the release is classified `restore-required`: a code-only rollback cannot take back what the role-key rename did.

**What `/me` must show afterwards.** For a former Guardian-role holder (not an administrator), `GET /api/v1/me` must list exactly these ten, in this order, and nothing else (pinned by `CurrentAccountCapabilitiesTest`, "reports a guardian's Console access and nothing else"):

```
console.access, crm.people.manage, crm.people.view, discussions.participate, discussions.view,
guardians.view, resources.manage, resources.view, volunteers.manage, volunteers.view
```

For comparison, before the release the same call lists `console.access` alone. The difference between the two lists is the table above. It is **expected** and is not a failed check.

## Before release

1. **The G10 closeout review, and its re-verification.** The independent closeout review has happened (2026-10-13). It found nothing wrong in the authorization code and listed bounded defects and gaps. They have been remediated on the WP7 branch (see ADR 0038's WP7 remediation notes). A focused independent re-verification of that remediation, then pull-request CI and post-merge CI, are still to come. Do not release before them.
2. **The owner authorizes the Guardian capability expansion** ([above](#what-changes-for-people-who-hold-the-guardian-role-today)): nine capabilities for every holder of the `guardian` role, at once. **That authorization has not been given.** It is a product decision, not an engineering one, and nothing in this runbook or in a green suite makes it.
3. **Rehearse the `restore-required` path.** The deployment runbook records that this path **has never been exercised end to end** and must be completed and rehearsed before the first later release classified `restore-required` ([deployment §3](deployment.md#restore-required--the-database-must-go-back-too), [backup and restore](backup-and-restore.md)). A G10 release is that release. It is an owner decision whether to rehearse first or to accept the risk explicitly. This runbook does not make that decision.
4. **Resources' host prerequisites.** Resources has never been deployed. Its production-readiness items apply in full ([production readiness §4c](production-readiness.md)): Resource files in the authoritative backup pair, the upload limits, and the private file store.
5. **Configuration.** G10 adds no environment variable, no queue, no schedule and no external service. The relationship definitions are in code (`app/Modules/Relationships/Definitions`). `security:production-check` is unchanged and must pass as before.
6. **Know what the release changes for people.**
   - Every current Guardian-role holder keeps the key's holders and **gains nine capabilities** (the table above). Under the name `guardian-full` they hold the release's bundle, not the one v0.3.0 gave them. Administrators are untouched.
   - **No one becomes a Guardian, and no one reads Resources through audience delivery, until an operator adopts Guardians and Volunteers.** Until then, every current Console user still reads the whole library through `resources.view` (policy A: every audience and Draft). Holders of `guardian-initiate` or `console-participant` (none exist until an operator grants one) read only what their relationships and Membership make them eligible for.
   - Members are eligible for Member Resources in the Console only if they can sign in to the Console. Nothing delivers Resources outside the Console yet (`/my/` and WordPress are out of G10).

## On the host (the operator's steps, in order)

None of these has been carried out. Follow the referenced runbooks; this list adds only what G10 needs.

1. **Release and deploy** as [deployment §4–§6](deployment.md) says, classifying the release `restore-required`. Take the backup inside the maintenance window, before migrations. It is the only recovery for the role-key migration. Record the number of `guardian` assignments first (step 2): once the migrations have run, the key is gone.
2. **Record, then verify, the role-key migration.** Before migrating, record how many `guardian` assignments there are, and which Persons (the owner's decision above rests on this number):
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

The G10 evidence for each acceptance row, the browser and accessibility results and the known deferred items are in [ADR 0038's WP7 implementation notes](../adr/0038-organizational-relationships-and-resource-viewing-authority.md#implementation-notes-wp7-2026-10-13). A green local suite is evidence for the code. It is not approval to release.
