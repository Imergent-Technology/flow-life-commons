# Runbook: G10 release readiness

- **Purpose:** what must be true, and what an operator must do on the real host, before a release containing G10 ([ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)) serves real people. It is a checklist and a pointer to the procedures, not a procedure of its own.
- **Owner:** the product owner decides whether to release. A Platform Administrator carries out the host steps.
- **Last tested:** never on a real host. Every item below that names a command has been exercised only against development and test databases.
- **Status (2026-10-13):** **not approved for staging or production deployment.** The G10 WP7 closeout review has not yet happened, and the open items in [Before release](#before-release) are owner decisions or operator tasks.

## What a G10 release carries

Production runs **v0.3.0**. `main` is ahead of it by everything since: CRM / People, Guardian Discussions, Resources (G5) and G10. A release cut from `main` therefore deploys all of them together, with these migrations (listed by `./flow release migrations`):

| Migration | Milestone | Effect on existing data |
| --- | --- | --- |
| `2026_10_01_*`, `2026_10_02_*` | CRM, Discussions | New tables only |
| `2026_10_04_*` | Resources | New tables only; Resource files live outside the database ([backup and restore](backup-and-restore.md#resource-files)) |
| `2026_10_09_000001_create_person_relationships_tables` | G10 | New tables only. **No relationship is created** for anyone |
| `2026_10_09_000002_rename_guardian_role_key` | G10 | **Renames every stored `guardian` assignment to `guardian-full`.** Same capabilities; refuses to run (changing nothing) if a `guardian-full` row already exists for the same Person |
| `2026_10_09_000003_create_sourced_role_grants_table` | G10 | New table only |
| `2026_10_11_000001_add_provenance_to_contact_methods` | G10 | Adds a nullable column; existing rows stay `NULL` |

**The release is `restore-required`** ([ADR 0027](../adr/0027-release-and-deployment-model.md), [deployment §3](deployment.md#restore-required--the-database-must-go-back-too)). After the role keys move, rolling back only the code fails closed: the previous catalog does not know `guardian-full`, so every Guardian-role user would lose that bundle. Recovery is the pre-migration backup.

## Before release

1. **The G10 closeout review.** An independent Opus closeout review of the WP7 branch, against the full acceptance gates, has not happened yet. Do not release before it, and before its findings are resolved.
2. **Rehearse the `restore-required` path.** The deployment runbook records that this path **has never been exercised end to end** and must be completed and rehearsed before the first later release classified `restore-required` ([deployment §3](deployment.md#restore-required--the-database-must-go-back-too), [backup and restore](backup-and-restore.md)). A G10 release is that release. It is an owner decision whether to rehearse first or to accept the risk explicitly. This runbook does not make that decision.
3. **Resources' host prerequisites.** Resources has never been deployed. Its production-readiness items apply in full ([production readiness §4c](production-readiness.md)): Resource files in the authoritative backup pair, the upload limits, and the private file store.
4. **Configuration.** G10 adds no environment variable, no queue, no schedule and no external service. The relationship definitions are in code (`app/Modules/Relationships/Definitions`). `security:production-check` is unchanged and must pass as before.
5. **Know what the release changes for people.**
   - Every current Guardian-role holder keeps exactly what they had, under the key `guardian-full`. Administrators are untouched.
   - **No one becomes a Guardian, and no one reads Resources through audience delivery, until an operator adopts Guardians and Volunteers.** Until then, every current Console user still reads the whole library through `resources.view` (policy A: every audience and Draft). Holders of `guardian-initiate` or `console-participant` (none exist until an operator grants one) read only what their relationships and Membership make them eligible for.
   - Members are eligible for Member Resources in the Console only if they can sign in to the Console. Nothing delivers Resources outside the Console yet (`/my/` and WordPress are out of G10).

## On the host (the operator's steps, in order)

None of these has been carried out. Follow the referenced runbooks; this list adds only what G10 needs.

1. **Release and deploy** as [deployment §4–§6](deployment.md) says, classifying the release `restore-required`. Take the backup inside the maintenance window, before migrations. It is the only recovery for the role-key migration.
2. **Verify the role-key migration**, still in maintenance:
   ```bash
   php artisan tinker --execute="echo DB::table('role_assignments')->where('role_key','guardian')->count(), ' ', DB::table('role_assignments')->where('role_key','guardian-full')->count();"
   ```
   Expect `0 <n>`, where `<n>` is the number of `guardian` assignments before the release (record it before migrating). Sign in as a former Guardian-role user: `/me` lists the same capabilities as before.
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
