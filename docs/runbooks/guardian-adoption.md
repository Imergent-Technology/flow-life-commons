# Runbook: Guardian adoption

- **Purpose:** record who the Guardians are, one Person at a time, after the release that carries the Guardian relationship ([ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md), Part M).
- **Owner:** a Platform Administrator. Adoption needs `guardians.manage` and, to offer Guardian Initiate access, `access.roles.assign`; in G10 only that role holds either.
- **Last tested:** by the automated tests only (`GuardianProvisioningTest`, `RelationshipsRaceTest`) on MariaDB and PostgreSQL. It has **not** been carried out on a real host.
- **Status:** the Relationships Console (G10 WP5) now offers every step below under People > Relationships: "Record a Guardian" for the lookup, the existing-Person confirmation and the Initiate choice, and the record page for status changes, the default-role decisions and deletion. The steps name the API operations underneath them, which are the same ones the Console sends. The Console has not been used for adoption on a real host.

## Adoption is not the role-key migration

Two things happen in G10, and they are different:

| | Role-key migration (`guardian` → `guardian-full`) | Guardian adoption |
| --- | --- | --- |
| What it is | Renames a permission bundle | Records organizational recognition |
| When | Automatically, inside the release's maintenance window | Manually, after release |
| Who | The release | A Platform Administrator, one Person at a time |
| Changes anyone's permissions | The rename itself changes none: it moves each `guardian` assignment to `guardian-full` and the G10 catalog defines that bundle ([ADR 0038, A3](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)). **Measured against production v0.3.0, existing Guardian-role holders gain nine capabilities** when the release is deployed ([G10 release readiness](g10-release-readiness.md#what-changes-for-people-who-hold-the-guardian-role-today)) | Only if the operator chooses Guardian Initiate access for that Person |
| Creates a relationship | **Never** | Yes, for the Person the operator chose |

The comparison baseline matters. Relative to the development line at the time ADR 0038 was accepted, the migration preserved each holder's capability set, and that is what its tests prove. Relative to production **v0.3.0**, whose `guardian` role grants `console.access` only, every current holder gains nine capabilities (People, Guardian discussions, Resources including Drafts, Guardians and Volunteers). That expansion reaches production only with the product owner's explicit authorization, which has not been given; the [G10 release readiness](g10-release-readiness.md#what-changes-for-people-who-hold-the-guardian-role-today) runbook lists the nine and the owner's decision. Neither the migration nor the role catalog is changed by adoption.

Nothing creates a Guardian relationship because a Person holds a Guardian-named role, holds `console.access`, or has an administrative Account. Holding `guardian-full` is not evidence that someone is a Guardian, and many Console participants are not.

## Before you start

1. The release is deployed and its migrations have run.
2. You are signed in as a Platform Administrator, with a freshly verified session (recording, status changes, default-role decisions and deletion all need recent verification).
3. Run the report. It writes nothing:

   ```bash
   php artisan relationships:guardian-adoption-report
   ```

   It lists, with ids and names only:
   - Persons who hold `console.access` and have no Guardian relationship. **This is a list to review, not a list of Guardians.** The report suggests nothing and converts nothing;
   - Guardian relationships whose Person has no Account, and those whose Person lacks `console.access`. Both are valid;
   - the output of `relationships:check`.

   It exits non-zero if `relationships:check` has a finding. Resolve findings before adopting anyone (see *When the check reports a finding*).

## Steps, for each Guardian

1. **Look the Person up** (`GET /api/v1/admin/relationships/guardians/candidates?q=`, at least three characters; a name, or an exact email address or phone number) and confirm it is the right Person. If the Person has no record yet, send `new_person` (a display name and optionally one email and one phone) instead of `person_id` in step 2: CRM gives its duplicate advice (`possible_duplicate`; review the candidates, then repeat with `confirm_distinct: true` only if the Person is distinct), and registers the Person with the relationship in one transaction.
2. **Record the relationship** (`POST /api/v1/admin/relationships/guardians`):
   - `person_id` with `confirm_existing_person: true`;
   - `status`: `active`, or `inactive` to record a former Guardian;
   - optional `fields` (`recognized_on`, `stewardship`);
   - for `active`, **`default_role` is required**: `grant`, `decline` or `defer`. For `inactive` it is refused (`422 default_role_not_applicable`).
3. **Choose the Guardian Initiate decision:**

   | Choice | Effect |
   | --- | --- |
   | `grant` | Also needs `access.roles.assign`. Records the decision and one sourced grant on the **relationship's Person**. If that Person has no Account, nothing is created for them and the grant confers nothing until an operator invites them and they accept |
   | `decline` | Records your refusal, replacing any stored decision, and withdraws any grant from this relationship |
   | `defer` | Decides nothing. It never erases a stored `declined`. It removes a stored `granted` and withdraws its grant |

   Existing `guardian-full` holders are still offered Initiate access. Granting it is harmless overlap and declining it is equally valid; nothing compares the two roles.
4. **Check the response.** `default_role.decision` and `default_role.effective` show what was recorded and whether the grant is in effect.

## Afterwards

- **Changing a decision later:** `PUT /api/v1/admin/relationships/guardians/{person}/default-role` with `{relationship_id, revision, default_role}`. `grant` is accepted only while the relationship is Active (`409 relationship_not_active` otherwise).
- **Stepping down:** `PUT .../status` to `inactive`, with no `default_role`. The grant is withdrawn in the same transaction, whatever the decision and whether or not you hold `access.roles.assign`. Sending `default_role` here is `422 default_role_not_applicable` and nothing changes.
- **Returning:** `PUT .../status` to `active` with a required `default_role`. A stored `granted` is restored only by an explicit, authorized `grant`; a stored `declined` stays unless you send `grant`.
- **Deleting:** `DELETE .../{person}?relationship_id=&revision=` withdraws the relationship's grants, deletes its decision and records `relationship.deleted`. The Person, their other relationships and independent role assignments are untouched.
- Run the report again. An adoption pass ends with a clean `relationships:check`.

## Verification

```bash
php artisan relationships:check
```

Exit `0` and "Relationships are consistent with their definitions." The check is read-only. It reports a sourced grant when its relationship is missing, not Active, not `granted`, for a different role than the type's default, or for a Person other than the relationship's; and a `granted` Active relationship with no matching grant.

## When the check reports a finding

A finding means a state the use cases cannot produce: a manual database edit, a restore of mismatched backups, or a defect. Nothing is repaired automatically, because a correction is an authority decision.

1. Do not edit the tables by hand to make the check pass.
2. Note the relationship or grant id the finding names. Findings hold ids and types, never field values, names or contact details.
3. An orphan grant is honoured by the `Authorizer` until it is removed, because Access deliberately does not consult Relationships. Treat it as live until resolved.
4. Resolve it through the use cases where one exists (for example, `decline` or a status change withdraws a grant that should not stand), or through a reviewed migration. Then run the check again.

## Rollback

Adoption writes only through the use cases above, each of which is reversible by its counterpart: deletion removes a mistaken relationship and its grants; inactivation or `decline` withdraws a grant. Each withdrawal records `role.revoked` with its actor and source, and the history of what was granted stays in the audit trail.
