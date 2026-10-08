# ADR 0038: Volunteer relationships, scoped Volunteer management, and Resource viewing authority

- **Status:** Proposed (the G10 design gate, WP0; awaiting approval; nothing implemented)
- **Date:** 2026-10-08
- **Supersedes:** none
- **Superseded by:** none
- **Amends:** [ADR 0037](0037-resources-are-audience-targeted-packs-of-cards.md), decisions 42–46, 49–51, 66 and 69 (who reads what in the Console, the `volunteer` audience, and when Membership becomes a dependency of Resources). Every other ADR 0037 decision is unchanged
- **Related:** [ADR 0005](0005-mariadb-with-postgresql-portability.md), [ADR 0007](0007-versioned-rest-api-openapi.md), [ADR 0015](0015-identity-owns-person.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0019](0019-security-event-auditing-seam.md), [ADR 0021](0021-cross-module-referential-integrity.md), [ADR 0024](0024-privileged-operator-administration.md), [ADR 0025](0025-account-security-generation.md), [ADR 0028](0028-membership-grants-derived-at-query-time.md), [ADR 0033](0033-service-identity-and-delegated-human-authority-are-distinct.md), [ADR 0034](0034-crm-enriches-identity-person.md), [ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md)

## Context

G5 (Resources) is complete and merged. G10 is next on the [roadmap](../roadmap.md). It has two parts. The first is the authoritative Volunteer relationship: the minimal Volunteering domain that [ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md) requires to exist before anything is delivered to Volunteers. The second is the `volunteer` audience in Resources, which [ADR 0037](0037-resources-are-audience-targeted-packs-of-cards.md) (decision 44) reserved.

The product owner held an interactive planning session first, as the preferred development pattern requires. It agreed fourteen decisions, which are recorded below as approved. One of them changes a G5 rule rather than extending it. In G5, `resources.view` meant "may read the Guardian audience's published projection". It now means "may read every Resource, in every audience and every publication state". That change is the riskiest part of this gate and gets the most attention below. The second risk is letting someone who manages Volunteers maintain a Person's basic details without receiving the CRM's broad access.

This ADR is the design gate. It is written before a `Volunteering` module exists, so that the module is built inside it. It states the contract precisely enough that the work packages need no further product decision.

### Repository facts this design rests on

Checked on `main` at `3c62eb3`, with G5 WP0–WP6 merged and post-merge CI green.

**Identity and the Actor**
- **An Account always has exactly one Person.** `accounts.person_id` is NOT NULL, unique (`accounts_person_id_unique`) and a `RESTRICT` foreign key to `people.id` (`database/migrations/2026_09_19_000002_create_accounts_table.php:26,41,43`).
- A Person may have no Account ([ADR 0015](0015-identity-owns-person.md)). People without Accounts are created only by `Identity\Application\RegisterPerson`.
- `Identity\Application\InviteAccountForPerson` gives an existing Person an Account later.
- There is no path that moves an Account to a different Person.
- **The Actor carries the Person.**
  - `Shared\Domain\Actor` holds `accountId`, `personId` and the authentication method, and no capabilities (`app/Shared/Domain/Actor.php:20-33`).
  - `Identity\Application\ResolveActor` re-reads the Account on every request and yields no Actor unless it can authenticate (Active, with a password).
  - A disabled Account's sessions are revoked, and its security generation advanced, in the same transaction ([ADR 0025](0025-account-security-generation.md); `DisableAccount`, enforced per request by `EnforceSecurityGeneration`).
- **Capabilities are read live.**
  - `Access\Application\Authorizer` re-resolves the Actor and reads `role_assignments` on every check.
  - An unknown role key grants nothing (`Authorizer.php:51-72`).
  - Only two roles exist: `platform_administrator`, which holds every capability, and `guardian` (`Role.php:33-42`).
  - Capabilities are a code-owned enum named `area[.thing].verb` (`Capability.php`).

**People / CRM**
- **People hold almost nothing.** The `people` table has only an id, `display_name` and timestamps. There is no preferred name anywhere in the platform.
- **Renaming is Identity's job.** `RenamePerson` authorizes nothing: "Identity never learns which capability CRM checked". It locks the row and records `person.renamed` (`RenamePerson.php`).
- **CRM keeps contact data in its own tables.** `contact_profiles`, keyed by `person_id`, doubles as the per-Person write lock. `contact_methods` holds email and phone with a primary per kind: a `primary_kind` column with `unique(person_id, primary_kind)`.
- **Contact data is separate from login data.** CRM contact emails are never an Account's login email (ADR 0034, decision 20), so editing a contact method cannot touch sign-in.
- **CRM has no optimistic concurrency.** It has no revision column, If-Match or ETag. The Console avoids most overwrites by sending only the fields that changed (ADR 0034 WP4 notes).
- **CRM detects possible duplicates.** `RegisterContact` refuses with `409 possible_duplicate` when an exact CRM email or an exact name (ignoring case) matches. It goes ahead on `confirm_distinct`.
- **Each CRM use case asks for exactly one capability.** `crm.people.view` and `crm.people.manage` are the only CRM capabilities, and `CrmBoundariesTest` (lines 254–299) pins every CRM use case that takes an Actor to exactly one of them.
- **CRM is closed to other modules.** Nothing outside CRM may use CRM (`CrmBoundariesTest.php:82`).

**Membership**
- `Membership\Application\GetCurrentMembership(PersonId)` answers a Person's current membership, derived at query time. It authorizes nothing, because its caller resolves the Person from the session (`GetCurrentMembership.php`).
- The relationship's history is its non-deleting grant rows. Grants record no security events ([ADR 0028](0028-membership-grants-derived-at-query-time.md), "History and auditing").

**Resources**
- **One projection rule drives audience delivery.**
  - `ResourceProjection::visibleCards(Pack, outlines, AudienceSet $viewer, bool $asIfPublished)` shows a Card when the Pack is Published (unless `asIfPublished`), the Card is Published, and the Card's effective audiences intersect the viewer's (`Domain/ResourceProjection.php:25-38`).
  - Unknown stored audience keys are dropped as rows are read (`Infrastructure/Rows.php:61-77`), so they match no viewer.
- **The Console is pinned to the Guardian audience.** `DeliverySurface::guardianConsole()` returns `{guardian}` (`Application/DeliverySurface.php:19-22`), and it is the only source of a viewer audience set outside preview. The library's `GuardianDelivery`, `BrowseResourceLibrary`, `GetResourcePack` and `DownloadResourceFile` all use it.
- **Preview** passes the chosen audience with `asIfPublished: true`, under `resources.manage` (`PreviewPack.php:39-53`).
- **One 404 hides everything the viewer may not see.** Every hidden thing on the delivery routes answers `404 resource_pack_not_found`.
- **Delivery carries no management data.** No state, audiences, revision or provenance appear in it (`ResourcesPresenter::delivered`, lines 176–201).
- **Files.**
  - Files are served only by cookie-session routes. There are no signed or temporary URLs.
  - `Cache-Control: private, no-store` is set on files.
  - The global security headers set `no-store` on every `/api/*` response (`BrowserSecurityPolicy.php:39-45`).
- **Membership is forbidden to Resources.** `ResourcesBoundariesTest.php:44-46` forbids a Membership dependency.
- **No other relationship may be named.** `ResourcesBoundariesTest.php:387-401` forbids any Volunteer, Partner, Vendor or Artist identifier and pins `Audience` to `['guardian', 'member']`.
- **G5 is not released or deployed** (roadmap). Its HTTP contract has no consumer except the Guardian Console in the same repository.

**Architecture and test pins**
- **The module graph is frozen in a test** (`AccessBoundariesTest.php:248-260`). A new module or a new edge fails until the test is changed deliberately.
- **Security-event callers are pinned.** Only Identity, Access, Audit and the two Resources deletion use cases may call `RecordSecurityEvent` (`MembershipTrustBoundariesTest.php:227-240`).
- **Routine mutations without step-up are pinned.** Each capability whose routine mutations skip recent verification is pinned to its module's `Http` namespace (`AdministrationRoutesTest.php:22-26`): `crm.people.manage`, `discussions.participate` and `resources.manage`.
- **The role catalog pins a manage-implies-view invariant** for CRM and for Resources (`CatalogTest.php:148,189`).
- **One capability per admin route.** Every admin route carries `stateful`, `auth:web`, `can:console.access` and exactly one other capability (`AdministrationRoutesTest.php:35-62`).

## The approved product decisions

Agreed in the G10 planning session and binding on this design. The numbers are the session's and are used throughout.

| # | Decision | Where this ADR implements it |
| --- | --- | --- |
| 1 | A Volunteer relationship belongs to a Person and is independent of Accounts. It never grants roles or Console capabilities | V1–V3, V25 |
| 2 | Lifecycle: Pending, Active, Inactive, changed directly by authorized Guardians. No applications, approvals or onboarding | V5–V8 |
| 3 | Only an Active relationship qualifies for the `volunteer` audience. Other relationships are independent of it | R10–R12 |
| 4 | One ongoing relationship per Person, reactivatable, with auditable status history and no assignments system | V4, V9–V12 |
| 5 | The Person record is the relationship's home, plus a lightweight Volunteer directory. No duplicate Volunteer identity | V24, C1–C5 |
| 6 | `volunteers.view` and `volunteers.manage` are independent of the CRM capabilities and granted through roles, never hardcoded | V13–V16 |
| 7 | Volunteer access never implies broad CRM access, and the backend enforces that | V17–V23 |
| 8 | Scoped Person management for Volunteer managers: lookup, minimal creation, relationship, basic contact upkeep | V18–V23 |
| 9 | `volunteers.view` reads basic contact information for Persons who have a Volunteer relationship, in any state | V17 |
| 10 | Multiple audiences are combined by OR, Pack/Card narrowing is preserved, and there are no Boolean expressions | R1–R4 |
| 11 | `resources.view` gives broad reading authority across all audiences. `resources.manage` is independent. Audience eligibility is separate from capabilities | R5–R9 |
| 12 | `resources.view` reads unpublished Packs and Cards. Audience delivery still respects publication | R5–R7, R13 |
| 13 | Drafts are discoverable in the Resource Library, with state indicators and filters. The library is not a second management UI | R8, C6–C9 |
| 14 | Viewers read the latest saved content. No revision history, comparison, review comments or approval workflow | R7, Deferred |

No decision is reopened. One G5 behaviour conflicts with decisions 11–13: the Console library served only the `guardian` audience's published projection (ADR 0037, decisions 42, 46, 51, 66 and 69). Those decisions are amended below (R5–R9), deliberately and visibly. ADR 0037's protections are not weakened for anyone who does not hold `resources.view`.

## Decision

### Part V — The Volunteering domain

**Ownership**

V1. **A new `Volunteering` module owns the Volunteer relationship**: its state, its history and the answer to "is this Person an Active Volunteer now".
- It owns no identity or contact data.
- Persons stay Identity's ([ADR 0015](0015-identity-owns-person.md)). Contact methods and profiles stay CRM's ([ADR 0034](0034-crm-enriches-identity-person.md)).
- The product word is *Volunteer*. The module is *Volunteering*, as ADR 0036 and the module map already name it.

V2. **A relationship belongs to a Person, never to an Account.**
- A Person with no Account may be a Volunteer.
- Giving that Person an Account later (`InviteAccountForPerson`) changes nothing in Volunteering, because both hang off the same `person_id`.
- Becoming a Volunteer creates no Account, invitation, role assignment, Membership grant or capability.
- Nothing in Volunteering calls Access to grant anything.

V3. **A relationship is never a role** (ADR 0036, decisions 4–5). `CatalogTest`'s rule that no role is named `volunteer` stands. Being an Active Volunteer grants neither Volunteer capability and no Console access.

**Persistence**

V4. **Schema.** One ongoing row per Person, and a history of its status changes. Two tables, both in Volunteering.

`volunteer_relationships`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `person_id` | `char(26)` | Foreign key to `people.id`, `RESTRICT` (a cross-module invariant, ADR 0021: a relationship to a Person who does not exist is a defect). **Unique, `volunteer_relationships_person_id_unique`**: the database enforces one relationship per Person (decision 4) |
| `status` | `string(16)` | `pending`, `active`, `inactive`. A validated string, not a database enum (ADR 0005). Indexed for the directory filter |
| `revision` | integer | Starts at 1. Increments on every status change (V8) |
| `status_changed_at` | `dateTime` | UTC. When the current status began ("Active since") |
| `created_at`, `updated_at` | `dateTime` | UTC. `created_at` is when the relationship was established ("Volunteer record since") |
| `created_by_person_id`, `status_changed_by_person_id` | `char(26)` | Provenance, no foreign key (ADR 0021), as Resources and Discussions record it: a Person, resolved to a name by `FindPeople` when shown |

`volunteer_status_changes`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `relationship_id` | `char(26)` | Foreign key to `volunteer_relationships.id`, `RESTRICT` (within the module). Index `(relationship_id, changed_at, id)` |
| `from_status` | `string(16)`, nullable | `NULL` only on the row that records the relationship's creation |
| `to_status` | `string(16)` | |
| `changed_at` | `dateTime` | UTC |
| `changed_by_person_id` | `char(26)` | Provenance, no foreign key |

Notes on the schema:
- Deliberately absent: a reason or note column (V11), any contact or name column (decision 8), an Account id, assignments, teams, periods, or a soft-delete flag.
- The migration runs after Identity's, which ADR 0021 requires for a table that references `people`.
- Both unique indexes are named, so a violation can be recognised by name, as the accounts and CRM migrations do.

**Lifecycle**

V5. **Three states: `pending`, `active` and `inactive`** (decision 2).
- A relationship is created as `pending` or `active`, as the creator chooses. `inactive` is not a starting state.

V6. **Transitions are direct and unrestricted between distinct states.** All six are allowed:
- `pending → active`, `pending → inactive`
- `active → inactive`, `active → pending`
- `inactive → active` (reactivation), `inactive → pending`

Decision 2 says authorized Guardians change the state directly, and every one of these transitions has a real use, for example putting someone back to Pending while they redo an orientation. Restricting transitions would be an approval rule in disguise ([ADR 0009](0009-authorization-separate-from-approval.md)).

Asking for the state the relationship is already in succeeds and changes nothing: no history row, no revision bump. The idempotence matches Resources' publish.

V7. **Reactivation reuses the row** (decision 4). An Inactive relationship moved back to Active keeps its id, `created_at` and history. A second relationship for the same Person can never exist, because the unique index makes it unrepresentable.

V8. **Status changes use optimistic concurrency and a row lock.** This is the Resources pattern (ADR 0037, decisions 56–57); the CRM has none.
- A status change states the `revision` it was based on.
- The use case locks the relationship row (`SELECT ... FOR UPDATE`), compares revisions, and only then writes:
  - the row's new status, `status_changed_at`, `status_changed_by_person_id` and `revision + 1`;
  - one `volunteer_status_changes` row with the true `from_status`.
- All of this happens in one transaction.
- A mismatch is `409 stale_revision`, carrying the current relationship.

Why both mechanisms:
- **The revision** stops a coordinator from unknowingly reversing a colleague's decision. A status change is a judgement made on what the coordinator saw, unlike an idempotent publish.
- **The lock** makes the history's `from_status` true under concurrency.

**History and audit**

V9. **Status history is Volunteering's business history, not a security event.** This follows Membership's ruling (ADR 0028, "History and auditing") and CRM's (ADR 0034, decision 17):
- `security_events` is for identity and access occurrences.
- A Volunteer status grants no capability. It changes eligibility for one content audience, as a Membership grant does, and Membership grants record no security event.
- Volunteering therefore does not call `RecordSecurityEvent`, and `MembershipTrustBoundariesTest`'s list of callers does not change.

V10. **History is complete by construction.** Every creation and every effective status change writes exactly one history row, in the same transaction as the change. The relationship row and its history therefore commit together or not at all. Each row records:
- the previous state,
- the new state,
- the time,
- the acting Person.

The acting Account is not recorded. Every other business table in the platform (Resources, Discussions, CRM interactions) records the human, and the Actor provides both. History rows are never updated or deleted.

V11. **No reason field in G10.** A free-text reason is optional by nature, and its likeliest content (why someone stopped volunteering) is the most sensitive thing this module could hold. Everyone who holds `volunteers.view` reads the history. A narrative belongs in CRM notes, which have their own audience and warning (ADR 0034, decision 17). A nullable `reason` column can be added later without migrating data.

V12. **Retention and deletion.** G10 has no operation that deletes a relationship or a history row.
- A relationship created by mistake is set to Inactive.
- Both foreign keys are `RESTRICT`, so a Person with a relationship cannot be deleted out from under it. No Person deletion exists today anyway.
- Erasure and anonymisation remain the platform-wide open question in [data ownership](../architecture/data-ownership.md). This adds to it and does not answer it.

**Capabilities**

V13. **Two capabilities: `volunteers.view` and `volunteers.manage`.**
- The names follow the two-part form of `discussions.view` and `resources.view`. The module's one concept needs no middle segment.
- They are added to `Access\Application\Capability` by the package that first checks them (ADR 0017).

| Capability | May |
| --- | --- |
| `volunteers.view` | List the Volunteer directory, and read any Volunteer relationship: its status, dates and history, and the Person's basic details (name, primary email, primary phone), for Persons with a relationship in any state (decision 9). Changes nothing |
| `volunteers.manage` | Look up Persons for intake (V19), create a minimal Person for intake (V20), establish a relationship (V21), change its status (V8), and maintain the basic details of Persons who have a relationship (V22). Routine work: no recent verification (V16) |

V14. **Independent of every other capability.**
- Neither Volunteer capability implies any CRM capability, and no CRM capability implies either.
- Each use case asks for exactly one capability, through `AuthorizeAction`. A `VolunteeringBoundariesTest` pins this the way `CrmBoundariesTest` pins CRM's: reads ask for `volunteers.view`, everything else for `volunteers.manage`.
- The Console likewise infers none of them from another.

V15. **Initial role grants.**
- The Guardian role receives both Volunteer capabilities, listed and not derived, as CRM's, Discussions' and Resources' are. Guardians are peers.
- The Platform Administrator holds them by derivation.
- A role-catalog test pins that every role granting `volunteers.manage` also grants `volunteers.view`, for the reason ADR 0034's WP2 notes give: a management write returns what it wrote, so a manage-only role would read through its writes.
- A future Volunteer Coordinator role is a role-catalog change and nothing more. It could hold the two Volunteer capabilities and `console.access` without any CRM capability, and every boundary below is designed so that role is safe.
- Volunteering's code never names a role.

V16. **Routine Volunteer management asks for no recent verification.**
- The step-up exemption list (`AdministrationRoutesTest`) gains a fourth entry: `can:volunteers.manage`, pinned to `Volunteering\Http`.
- Why: its mutations change no one's software authority. A Volunteer status confers no capability, and a basic-details edit changes no login or security state, because Account email is not a contact method.
- This is the reason CRM maintenance is exempt (ADR 0034).
- Membership's operator surface does require recent verification. It is an operator-only entitlement surface under [ADR 0024](0024-privileged-operator-administration.md), held by no Guardian, and Volunteer management is not that.

**Scoped access to People**

V17. **What `volunteers.view` may read about a Person.** The read scope is narrow and fixed:
- **Which Persons:** only those who have a Volunteer relationship, in any state.
- **What it reads about them:**
  - the display name;
  - the value of the primary email, if the Person has one;
  - the value of the primary phone, if the Person has one;
  - the relationship's status, dates and history.
- **What it never reads:** other contact methods, their labels, profile fields, tags, notes and interactions, Membership, Account or security data. Those stay behind their own capabilities (decision 7).
- **Preferred name:** the platform has none (no column exists), so none is shown. Adding one is a CRM or Identity decision, not G10's.

V18. **CRM gains a narrow, caller-authorized seam: `Crm\Application\Delegated`.** This is the new People authorization concept this gate introduces, and it is named here rather than left in a controller.

The problem it solves:
- Volunteering must read and write a small part of CRM-owned data for Persons the caller may not see through the CRM.
- CRM's own use cases each check a CRM capability, and `CrmBoundariesTest` pins that.

Rejected alternatives (see *Alternatives considered*):
- CRM accepting `volunteers.manage` itself. CRM would have to ask Volunteering who is in scope, closing a dependency cycle and teaching CRM about every future relationship.
- Volunteering reading `contact_*` tables directly. This breaks write ownership (ADR 0021, rule 1).
- Copying contact fields into Volunteering. Decision 8 forbids it.

The chosen shape follows the precedent `RenamePerson` set: an Application service that authorizes nothing and leaves authorization to the caller, whose class comment says so. `Crm\Application\Delegated` holds five such services:

| Service | Does | Reuses |
| --- | --- | --- |
| `ReadPersonBasics(list<PersonId>)` | Batched: id, display name, primary email value, primary phone value. Nothing else | `FindPeople`, `ContactMethodRepository` |
| `FindPersonCandidates(string $query)` | The bounded lookup of V19 | `SearchPeople`, the contact-method search CRM's directory uses |
| `RegisterMinimalPerson(name, ?email, ?phone, bool $confirmDistinct, Actor $by)` | A new Person with at most one email and one phone, each primary. The same `possible_duplicate` advice as `RegisterContact` | `RegisterPerson`, `ContactMethodWriter`, the profile lock, and `RegisterContact`'s candidate logic, moved into one internal class both use, so the rules cannot drift |
| `PeopleMatchingContact(string $text, list<PersonId> $within)` | The ids, among `$within`, whose CRM email contains the text or whose phone digits contain its digits (3 or more), as the CRM directory matches. For the Volunteer directory's search (C2) | The contact-method search CRM's directory uses |
| `UpdatePersonBasics(PersonId, BasicsChanges, Actor $by)` | Compare-and-set of the name, primary email and primary phone (V22) | `RenamePerson`, `UpdateContactMethod`'s rules, `ContactMethodWriter`, the profile lock |

How the seam is guarded:
- **One caller.** Architecture tests pin that `Crm\Application\Delegated` is used only by `App\Modules\Volunteering\Application`, and that nothing in it names a capability. `CrmBoundariesTest`'s one-capability rule exempts that namespace explicitly, and only that namespace.
- **No HTTP.** No CRM route or controller reaches a delegated service.
- **Volunteering checks first.** The Volunteering use case authorizes `volunteers.view` or `volunteers.manage` and checks scope (V23) before calling.
- **A deliberate edge.** The module graph gains `Volunteering → Crm`. It is acyclic, because CRM depends on Access and Identity only. A future relationship domain that needs the same seam is added to the allowed callers by its own gate.

V19. **Limited Person lookup (`volunteers.manage` only).** Intake needs to find a Person who has no relationship yet. The lookup returns enough to tell people apart and no more:

**Query** — trimmed, at least 3 characters, at most 255. It is read in one of three ways:
- **Contains `@`:** an exact match of the lower-cased address against CRM contact emails (`search_value`). Never Account login emails: ADR 0034, decision 20 applies unchanged.
- **Contains at least 7 digits, with only phone punctuation besides:** an exact match of its digits, with a leading `+` kept, against CRM contact phones, as CRM normalises them.
- **Otherwise:** a case-insensitive "contains" match on display name through Identity's `SearchPeople`.

**Result**
- At most **10** candidates, ordered by name then id, with a `truncated` flag when there are more. A truncated result is a reason to refine the search; there is no paging.
- Each candidate carries:
  - the Person's id,
  - the display name,
  - `matched_on`: `name`, `email` or `phone`,
  - their Volunteer status, if they already have a relationship.
- A candidate carries no contact value, no profile, no tags and no Account information.

**Why it is exact by contact and fuzzy only by name.** A coordinator who knows a volunteer's address finds exactly that Person and learns nothing about anyone else. A partial-address search would turn the lookup into a directory of who is recorded at a domain.

**Enumeration.** The residual exposure is a name list in pages of 10, available to people who already hold a Console session with second factor and `volunteers.manage`. Every current holder is a Guardian with `crm.people.view`, which shows more. No rate limit is added. Should a coordinator-only role ever be created, the role catalog change is the moment to revisit it.

V20. **Minimal Person creation for intake (`volunteers.manage`).**
- When the lookup finds no one, intake may create a Person through `RegisterMinimalPerson`: a display name, plus optionally one email and one phone.
- The same duplicate advice as CRM applies: an exact CRM email, or the exact name ignoring case, refuses with `409 possible_duplicate` and the candidates (id, name, what matched).
- The coordinator either selects a candidate (V21, existing Person) or confirms the Person is distinct (`confirm_distinct`).
- Nothing is merged or adopted (ADR 0034, decision 10).
- Creating the Person and establishing the relationship happen in **one transaction** (V21). There is therefore never a moment when a Volunteer manager has created a Person who is outside their scope.

V21. **Establishing a relationship (intake).**
- **Request:** `POST /admin/volunteers`, with either `person_id` (an existing Person) or `new_person` (V20), and a `status` of `pending` or `active`.
- **One transaction:**
  - the Person is created if asked for;
  - the relationship row is inserted;
  - its creation history row is written (`from_status = NULL`).
- **Outcomes:**
  - An existing relationship for that Person refuses with `409 volunteer_exists`, carrying the Person id so the Console can open it.
  - A concurrent second creation hits the unique index, and the use case maps that by index name to the same `409 volunteer_exists`. Its transaction rolls back, including a Person it had just created.
  - An unknown `person_id` is `422 unknown_person`.

V22. **Scoped basic-detail maintenance (`volunteers.manage`).**

**Editable fields** — exactly three:
- the display name, through Identity's `RenamePerson`, which records `person.renamed` as today;
- the value of the primary email;
- the value of the primary phone.

**Not editable here:** other methods, labels, primaries other than these, profile fields, tags, notes, and anything Membership, Account or security owns.

**Request shape.** Each changed field is sent as `{from, to}`. `from` is the value the coordinator saw (`null` for "had none").

**How the change is applied**
- Under CRM's per-Person profile lock, CRM compares each `from` with the current value. If any differs, nothing is written and the answer is `409 stale_person_basics` with the current values.
- The Person row's own lock covers the name: `RenamePerson` gains an optional expected current name, which it compares under its existing row lock.
- **Replacing a primary** updates that contact method's value, with CRM's own normalisation, validation and duplicate rule. The new value equal to another of the Person's methods of the same kind is `409 duplicate_contact_method`.
- **Setting a primary where none exists** adds a method, which becomes primary because it is the first of its kind (CRM's rule).
- **Clearing a primary is not offered.** Removing contact data stays a CRM action.
- CRM's existing provenance applies: `contact_profiles.updated_by_account_id`, and `person.renamed` for the name.

**Why compare-and-set.** It gives the "never silently overwrite a concurrent CRM change" guarantee without adding a revision to CRM's tables, which CRM's own screens would not send. It is stronger than CRM's own partial-update behaviour, and it changes nothing for CRM's screens.

V23. **The scope rule, enforced in the Application layer and pinned by tests.** A Volunteering use case that reads or writes a Person's basic details first verifies that the Person has a Volunteer relationship, in any state. The verification is done by the use case itself, from Volunteering's own table, on the server.
- **A missing relationship is a 404.** A Person id with no relationship answers `404 volunteer_not_found`, whether or not the Person exists, so the routes are no oracle for Person existence.
- **The only route that touches a Person without a relationship is intake.** It creates the relationship in the same transaction. It edits no existing Person's details: selecting an existing Person establishes the relationship and nothing else.
- **Scope does not lapse.** A Person stays in scope once the relationship exists, because relationships are not deleted (V12). Decision 9 explicitly includes Inactive Volunteers.

**Why scope comes from creating a relationship, and what that costs.** Because intake can make anyone a Volunteer, a Volunteer manager can bring any Person into scope by recording them as Pending. That is inherent in decision 8, and it is accepted with these guards:
- It is visible. The relationship and its creator are permanent history.
- What it gains is narrow: three fields, never login, security, notes or Membership.
- A display name change is also a security event (`person.renamed`), as it is from CRM.

**Eligibility read**

V24. **The Person record and the directory are the two homes** (decision 5). Volunteering owns the relationship and composes Identity's name and CRM's basics beside it through the seams above. It holds no copy of any of them.

V25. **The eligibility read for other modules** is `Volunteering\Application\IsActiveVolunteer(PersonId): bool`.
- It authorizes nothing, like `GetCurrentMembership`, because its caller passes a Person it resolved from the session.
- It reads the current row on every call. Nothing is cached.
- Only `active` answers true (decision 3).
- The module exposes no other read of relationship state to other modules.

### Part R — Resources: the `volunteer` audience and three access paths

**Audiences (decision 10)**

R1. **`volunteer` joins the code-owned catalog.**
- `Resources\Domain\Audience` gains `case Volunteer = 'volunteer'`, after `member`. The catalog's order is the order the Console lists audiences in.
- **No schema change.** Audiences are stored as `string(32)` in `resource_pack_audiences` and `resource_card_audiences`.
- **No data migration.** Every existing row is still valid.
- `AudiencesRequest`'s maximum follows `Audience::cases()` automatically.

R2. **Unknown keys still fail closed.**
- A write naming an unknown audience is `422`.
- A stored key the catalog does not know is dropped on read (`Rows.php`), so it matches no viewer.
- The `ResourcesBoundariesTest` scan that forbids invented relationship identifiers is revised to allow `volunteer`, the audience and the eligibility seam. It still forbids Partner, Vendor and Artist identifiers.

R3. **OR, narrowing and publication are unchanged.**
- A Pack's audiences are combined by OR (ADR 0037, decision 39).
- A Card inherits, or narrows to a non-empty subset that is never broader (decisions 40–41), and the subset rule is held under the Pack lock.
- A Published Pack still needs an audience and a Published Card (decisions 13–14).
- `volunteer` may be chosen for a Pack and narrowed to on a Card, exactly as `member` may.
- There is no hierarchy: holding `member` never satisfies `volunteer`, and the reverse is equally false.

R4. **Preview** (`resources.manage`) accepts `?audience=volunteer`, as it accepts `member`. One change, which also corrects a G5 inconsistency: preview's file `download_path` points at the **management** file route, not the library route.
- **Why:** a manage-only previewer must be able to open what preview shows, and a preview may show a Draft Card's file.

**The three access paths (decisions 11–12)**

Three paths reach Resource content, with different authority. They are implemented as separate, non-interchangeable Application services. None of them takes its authority from a request parameter.

| Path | Who | What they read | Publication | Audiences | Where |
| --- | --- | --- | --- | --- | --- |
| **A. Viewing** | An Actor holding `resources.view` | Every existing Category, Pack and Card, including Drafts | Ignored; shown as state | Ignored for access; shown as data | The Console library, `/admin/resource-library` |
| **B. Management** | An Actor holding `resources.manage` | Everything, plus every change and preview | As management does today | As management does today | `/admin/resources`, unchanged except R4 |
| **C. Audience delivery** | Any authenticated Actor, by relationship | Only what the Actor's Person is eligible for | Pack **and** Card Published | Effective audiences intersect the Person's eligible set (OR) | Application use cases only in G10; no route (R14) |

R5. **Path A is a read model of its own, not the audience projection with a wider set.**
- It is `Resources\Application\ViewerLibrary`, with the use cases `BrowseLibrary`, `ReadLibraryPack` and `DownloadLibraryFile`.
- **No audience argument.** It does not call `ResourceProjection::visibleCards` and is not handed an audience set. There is no "all audiences" value that could be passed into the delivery path by mistake, and no flag on the delivery path that turns publication off.
- **One authority.** Each use case asks for `resources.view` and nothing else.

Why not one engine with a mode:
- A shared engine with a "privileged" mode would make the safety of audience delivery depend on every caller passing the right mode.
- Separate code makes a mistake visible in a diff and checkable by an architecture test (R15).
- **What is shared** is the pure pieces: ordering, Card outlines, content loading, the presenter's Card shape, search matching and the file opener. The visibility rule is not shared.

R6. **What a viewer reads.**
- **Content:** every Pack and every Card, at its **latest saved content** (decision 14). It is read straight from the tables at request time, and the editor's unsaved state never reaches the server.
- **Draft Packs and Draft Cards** are included and marked.
- **Ordering:** Categories, Packs and Cards are in the same `(position, id)` order as everywhere.
- **Card numbering:** Cards are numbered `1..n` across all of a Pack's Cards. A Series' *n of m* counts them all, Drafts included, so a reviewer sees the Series as it would stand.
- **Uncategorized Packs:** Draft Packs may have no Category. They are listed in a final group, *Uncategorized* (`category: null`), so that no Draft is undiscoverable.
- **Empty Packs:** a Pack with no Cards is listed too; there is still something to read.
- **Empty Categories:** a Category with no Pack is not listed, because there is nothing in it to read.

R7. **What a viewer's response carries.** It carries more than delivery, because it is not delivery (ADR 0037, decision 50, applies to path C):
- the Pack's `state` and its `audiences`;
- each Card's `state`, `audience_mode` and **effective** audiences;
- `updated_at` and the last editor's display name for the Pack and each Card. This is provenance for reviewing a colleague's work, resolved by `FindPeople`.

It never carries: `revision`, stored positions, the creator, or anything that offers an action. There is no revision history to carry (decision 14).

R8. **Library filters**, applied on the server after authorization:

| Filter | Values | Meaning |
| --- | --- | --- |
| `category` | Category id | Unchanged |
| `q` | Text | Search over Pack titles and summaries, and the titles and summaries of **all** its Cards, case-insensitive, in PHP. Content is not searched (decision 48 unchanged in kind) |
| `publication` | `published` | Published Packs |
| `publication` | `draft` | Draft Packs, **and** Published Packs that hold at least one Draft Card, so that "not yet published" work is found |
| `audience` | Catalog audience | Packs whose audiences include it |

Absent means all.
- These are **filters, not authority**. They can only narrow what a viewer may already read.
- A filter value the catalog does not know is `422`, never "match everything".

R9. **Errors and files on path A.**
- **A Pack that does not exist** is `404 resource_pack_not_found`, which the Console already handles.
- **A Card that is not in the Pack**, on the file route, gets the same answer. Path A has nothing to hide, but one code keeps the client simple.
- **The file route** (`/admin/resource-library/packs/{pack}/cards/{card}/file`) serves any File Card of an existing Pack, Draft or not, to `resources.view`. It is streamed with `private, no-store` and is never a signed or long-lived URL, as today.
- **A missing file** is `404 asset_unavailable`.

**Audience-based eligibility (decisions 3, 10, 12)**

R10. **`Resources\Application\AudienceEligibility` is the one producer of a viewer's audience set on path C.**
- **Input:** an `Actor`. It takes the Person from `Actor::$personId`, which is never a request field.
- **Output:** an `AudienceSet` built from the owners' current answers:
  - `member` when `Membership\Application\GetCurrentMembership($person)->active`;
  - `volunteer` when `Volunteering\Application\IsActiveVolunteer($person)`.
- **Never `guardian`.** There is no Guardian relationship (ADR 0036, decision 7). Guardian-directed content is read by path A, and a community surface does not serve the `guardian` audience (ADR 0037, decision 42: a surface decides what it serves). A later Guardian relationship would add it here.
- **Never from capabilities.** Holding `resources.view` or `resources.manage` adds nothing to this set (ADR 0037, decision 43, unchanged).
- **Read per call.** Nothing is cached across requests, in the session or anywhere else, so a status change takes effect on the next request.

R11. **Path C's use cases** are `BrowseDeliveredResources(Actor, ?category, ?q)`, `GetDeliveredPack(Actor, PackId)` and `DownloadDeliveredFile(Actor, PackId, CardId)`. They are G5's library use cases, kept rather than rewritten:
- `DeliverySurface::guardianConsole()` is removed.
- `GuardianDelivery` becomes `AudienceDelivery` and asks `AudienceEligibility` for the set.

Everything else is ADR 0037's projection exactly:
- Pack and Card must be Published.
- Effective audiences must intersect.
- Non-disclosure is complete: one `404 resource_pack_not_found` for missing, Draft, ineligible or empty.
- Visible Cards are numbered `1..n`.
- Search covers visible text only.
- The file route is resolved through the projection.

**Authorization.** The use cases need no capability, because the question they answer is about the Actor's own relationships. They authorize by deriving everything from the Actor, as `GetCurrentMembership` does for `/my/membership`.

R12. **The cases the resolver must answer, all from the Actor and current state:**

| Situation | Path C answer |
| --- | --- |
| Account resolves to Person P (always so: `accounts.person_id` is NOT NULL and unique) | P's set |
| Disabled Account, ended or superseded session | No Actor: `401` before any use case (`ResolveActor`, ADR 0025) |
| A Person with no Account | Cannot be an Actor; eligibility exists but nothing asks for it until they hold an Account |
| Account later given to an existing Volunteer (`InviteAccountForPerson`) | The relationship applies at once: same `person_id` |
| P is an Active Volunteer | `volunteer` ∈ set |
| P is a Pending or Inactive Volunteer | `volunteer` ∉ set |
| P is an Active Member and an Inactive Volunteer | `{member}`: each owner answers independently |
| P is both Active | `{member, volunteer}`; a Card targeted at either is visible (OR) |
| Account holds `resources.view` and/or `resources.manage` | No effect on path C; those are paths A and B |
| Status changes between requests | The next request sees it; nothing was cached |

R13. **Active → Inactive while reading.** Path C has no signed URL, temporary link, cached grant or token: every Pack, search and file request re-runs eligibility from the session. Once the status change commits, the next request for a Volunteer-only Pack or file is `404`. A download already streaming finishes; that is accepted. Every API response, files included, already carries `no-store`, so nothing is served from a cache.

R14. **Implemented in G10, and what waits for the WordPress companion.**

**Built and tested in G10:**
- `AudienceEligibility`, the path C use cases, and their tests on both engines.
- The Resources dependency on `Membership\Application` and on `Volunteering\Application`. This brings forward ADR 0037 decision 44's "at the same time as the first surface". The product owner asked that eligibility exist and be proved before delivery does.

**Not built in G10:**
- **No route reaches path C.** Path C is not reachable over HTTP in G10, and an architecture test pins that no `Http` class uses it.
- **Nothing outside the Console.** No `/my/` Resources surface, no WordPress plugin, no service or delegated authentication ([ADR 0033](0033-service-identity-and-delegated-human-authority-are-distinct.md)), and no public or anonymous delivery.

**What the seam provides.** When the companion arrives (G7/G9), its authenticated adapter resolves an `Actor` for a real Person through the delegated-authority design ADR 0033 will need, and calls path C. That means:
- WordPress never states an audience;
- it never re-implements publication or eligibility;
- it never uses a management route;
- it never sees a storage path.

The HTML egress and the sanitizer (ADR 0037, decision 30) are the companion's to add.

**Where the separation is enforced**

R15. **Pinned by architecture tests, on top of behaviour tests:**
- `ResourceProjection::visibleCards` is called only by `AudienceDelivery` and `PreviewPack`.
- An `AudienceSet` reaching `AudienceDelivery` is produced only by `AudienceEligibility`.
- The path A use cases (`ViewerLibrary`) do not call `ResourceProjection` or `AudienceEligibility`. Each asks for `resources.view` alone.
- No `Http` class uses the path C use cases.
- No request class in `Resources\Http` for the library or delivery accepts an `audience` or a person id, except the library's `audience` filter. That filter is validated against the catalog and only narrows path A.
- The Resources route table still pins `/admin/resource-library` to `resources.view` and `/admin/resources` to `resources.manage`, GET-only for the library.

### Part C — Guardian Console

C1. **Navigation.**
- A new **Volunteers** section holds **All volunteers** (`/volunteers`, `volunteers.view`, detail `/volunteers/:personId`) and **Record a volunteer** (`/volunteers/new`, `volunteers.manage`).
- Each item is filtered by its own capability. The section disappears for someone who holds neither.
- Volunteer status grants no navigation, because the Console reads only `/me`.

C2. **The Volunteer directory (`/volunteers`).** It follows `PeoplePage`'s conventions:
- **Paging and search:** server-side, 25 a page.
- **Search:** submitted, not run per keystroke, over names and primary contact values **within Volunteers**.
- **Status filter:** a `Select` for All, Pending, Active and Inactive.
- **Columns:** name (a link to `/volunteers/:personId`), status (`StatusBadge`, a word and a shape, never colour alone), primary email, primary phone, and "Status since".
- **States:** loading skeleton; empty ("No volunteers recorded yet."); no match (with "Clear search and filter"); and error with "Try again".
- **Narrow screens:** `DataTable`'s stacked layout.
- **Server side:** the directory pages through Identity's `SearchPeople` with `restrictToIds` set to the matching relationships' Person ids, the way CRM's tag filter composes. Contact-text matches come from the delegated `PeopleMatchingContact` as `includeIds`, and the order is name then id. CRM's 10,000-id bound and its `422` apply.

C3. **The Volunteer record (`/volunteers/:personId`).** A scoped detail page, not a clone of the Person record:
- **Basics panel:** name, primary email, primary phone, read-only for `volunteers.view`.
- **Relationship panel:** status, "Volunteer record since", "Status since", and a **Change status** control (`volunteers.manage`) that sends the `revision`.
  - On `stale_revision` the panel re-reads and tells the user who changed it and when, following the `PackDetailsSection` pattern. It never re-sends on their behalf.
- **History panel:** each change is shown as *from → to, when, by whom*. The creation row reads "Recorded as Pending/Active".
- **Edit basics** (`volunteers.manage`): a form for the three fields that sends `{from, to}` only for the fields touched.
  - On `stale_person_basics` it shows the current values beside the user's and lets them re-apply.
  - Clearing a field is not offered.
- **CRM record link:** "Open full record" to `/people/:personId`, shown only with `crm.people.view`.
- **What the page never asks for:** CRM's record, notes, tags, Membership or Account endpoints. A source test pins that the page's API client names only `/admin/volunteers` endpoints.

C4. **Intake (`/volunteers/new`)** is a short wizard, not an application form:
1. **Search** (V19). Candidates show name, what matched, and any existing Volunteer status. A candidate who is already a Volunteer links to their record instead of offering selection.
2. **Choose a path.** Select a candidate, or "Add someone new" (name, optional email, optional phone).
3. **Choose a starting status**, Pending or Active, and confirm.

How refusals are handled:
- `409 possible_duplicate` shows the candidates as CRM's registration screen does, with "This is a different person", which resends with `confirm_distinct`.
- `409 volunteer_exists` links to the existing record.
- After success, the Console opens the new Volunteer record.

C5. **The Person record (`/people/:personId`).**
- It gains a **Volunteer** panel, shown only with `volunteers.view`. It reads `GET /admin/volunteers/{person}`.
- **When there is a relationship**, the panel shows status and dates, links to the Volunteer record, and offers **Change status** with `volunteers.manage`.
- **When there is none (404)**, it shows "Not recorded as a volunteer", and with `volunteers.manage` offers **Record as volunteer**, which runs intake for this Person.
- `PersonDetailPage`'s comment that relationships do not belong there is revised for Volunteering only. Membership, Account and security data still do not appear.
- Someone with `volunteers.view` but no `crm.people.view` never needs this page: the Volunteer record (C3) is their home.

C6. **Resource Library, viewer mode.** `/resource-library` keeps its routes, guard (`resources.view`) and read-only character.

**Filters, beside the Category filter, all applied by the server (R8):**
- **Publication:** All, Published, Drafts. The default is All, so that Drafts are discoverable without looking for them (decision 13).
- **Audience:** Any, Guardians, Members, Volunteers.

**Markers on the home page**
- A Pack shows a **Draft** badge (word and shape) when it is a Draft.
- A Published Pack holding Draft Cards shows "*n* draft Cards".
- Each Pack shows its audiences as plain labels.
- The *Uncategorized* group is last.

**Markers inside a Pack**
- The Card navigation marks each Draft Card with the word "Draft".
- A Draft Pack or Draft Card shows a quiet note above its content, for example "Draft — not published to any audience" or "Draft Card — not published".
- A narrowed Card names its effective audiences.
- The page states the last editor and time.

**What it does not do**
- **No approval language.** No wording speaks of review, approval, submission or sign-off (ADR 0009). Draft is publication state only.
- **No management.** The library offers no edit, publish, audience, delete or preview action, and imports nothing of management or the editor. The WP5 boundary tests stand, extended to the new fields.

C7. **Resources management.**
- `AUDIENCES` gains `volunteer`, labelled "Volunteers".
- The Pack and Card audience fields, the management list's audience filter and the preview's audience select all offer it.
- The delivery note becomes "Nothing delivers Resources to Members or Volunteers outside the Console yet; Guardians with Resource viewing access can read them in the Resource Library", or equivalent wording.

C8. **The `guardian` audience in the Console.** Since path A shows everything, `guardian` no longer limits what a Console reader sees. It remains a targeting fact: it is filterable, previewable, required where a Pack is meant for Guardians, and the audience a future Guardian surface or relationship would serve. The wording says "For Guardians", never "visible only to Guardians".

C9. **Accessibility and layout.** Every new route and state joins the axe pass (both themes, colour contrast), the sideways-scroll pass at 320, 375 and 1280 px, and the route matrix. Every flow is keyboard-complete: intake, status change, edit basics, and the library filters.

### Part H — HTTP contract

Volunteering, under `/api/v1/admin`, behind `stateful`, `auth:web` and `can:console.access`, one capability per route, described in `openapi/openapi.yaml`.

| Method and path | Capability | Notes |
| --- | --- | --- |
| `GET /admin/volunteers?status=&q=&page=&per_page=` | view | Directory. `per_page` ≤ 100. Each row: Person id, name, primary email, primary phone, status, `created_at`, `status_changed_at` |
| `GET /admin/volunteers/{person}` | view | The basics, relationship (status, `revision`, dates) and history (from, to, at, by name). `404 volunteer_not_found` for no relationship or no Person |
| `GET /admin/volunteer-candidates?q=` | manage | V19. Its own prefix, so that the `{person}` pattern never shadows it. `422` under 3 characters |
| `POST /admin/volunteers` | manage | V21. `201` with the record. `409 volunteer_exists`, `409 possible_duplicate`, `422 unknown_person`, `422 invalid_volunteer_input` |
| `PUT /admin/volunteers/{person}/status` | manage | `{status, revision}`. `200` with the record. `409 stale_revision` with `current`. Same status: `200`, unchanged |
| `PATCH /admin/volunteers/{person}/basics` | manage | V22. `{display_name?, primary_email?, primary_phone?}`, each `{from, to}`. `409 stale_person_basics` with `current`, `409 duplicate_contact_method`, `422` per field |

Refusals are checked in this order:
1. No capability: `403`.
2. No relationship (scope): `404`.
3. Request shape: `422`.
4. The domain's `409`s.

None of these routes carries `security.verified` (V16). The `{person}` parameter uses the platform's lowercase-ULID route pattern, so a malformed id is a plain `404`.

Resources: the three `/admin/resource-library` routes keep their paths, methods and capability, and change their **responses** to path A's (R6–R9). The browse route gains the `publication` and `audience` filters. Preview gains `volunteer` and returns management file paths (R4). No route is added or removed.

**Compatibility of the library change ([ADR 0007](0007-versioned-rest-api-openapi.md)).** ADR 0007 asks for a new version rather than a silent breaking change. This change is neither silent nor a break of a released contract:
- G5's library contract has never been released or deployed.
- Its only consumer is the Console, in the same repository and the same release.
- The OpenAPI document changes in the same package, and the drift test enforces that it does.

The new fields are additive. The widened content is the purpose of the change. A client that assumed "everything here is published" exists nowhere. Introducing `/v2` or a parallel path for an unreleased admin contract would leave a dead Guardian-only endpoint behind.

### Part T — Concurrency, transactions, portability

T1. **Volunteering's races, each proved by a two-process race test on both engines and mutation-checked:**

| Race | Decided by |
| --- | --- |
| Two creations for one Person | The unique index; the loser maps to `409 volunteer_exists`, and a Person it created rolls back. Mutation: drop the index and the race test duplicates |
| Two status changes from the same revision | Row lock plus revision. Mutation: drop the lock and the history `from_status` lies |
| A basics edit against a CRM edit of the same method | CRM's profile lock plus compare-and-set. Mutation: compare outside the lock |
| A rename from Volunteering against a rename from CRM | `RenamePerson`'s row lock plus expected name |

**Where to pause.** Pauses go between the read and the write, as `tests/Support/ResourcesPauses.php` does: a pause after the write cannot detect a missing lock, because the write takes its own row lock. Reads after a lock are locking reads, which avoids the MariaDB REPEATABLE READ stale-snapshot trap Resources WP1 measured.

T2. **Transactions.** `$database->transaction(fn, 3)` through an injected `ConnectionInterface`, as everywhere. Intake nests CRM's work as a savepoint, the precedent being `RegisterPersonWithMembershipAccess`.

T3. **Portability ([ADR 0005](0005-mariadb-with-postgresql-portability.md)).** No exception is needed:
- ULIDs are generated in code.
- States are validated strings.
- Times are UTC `dateTime`.
- Indexes are plain and unique: no partial or functional index, no CHECK constraint, no generated column.
- The only lock is `SELECT ... FOR UPDATE`.
- No JSON column.
- Name search uses Identity's existing portable search, and contact matching uses CRM's existing normalised `search_value` (the accent-folding difference CRM pins applies unchanged).

Every Volunteering test, the eligibility tests and the Resources tests run on MariaDB and PostgreSQL. MariaDB is production. PostgreSQL is the guardrail, not a migration project.

## Threat review

| # | Threat | Enforcement | Proof |
| --- | --- | --- | --- |
| 1 | A Volunteer coordinator reads unrelated CRM records | Volunteer capabilities reach no CRM route. Delegated reads return three fields for in-scope Persons only (V17, V23) | Feature tests with `crm.people.*` removed by `Gate::before` (the `resourcesGate` pattern): every CRM route is `403`. Response-shape tests: exactly the documented keys at every depth |
| 2 | Person lookup used for enumeration | `volunteers.manage` only; exact-match contact search; names in tens; no contact values (V19) | Tests: view-only is `403`; a partial email matches no one; at most 10 results; no contact values in the result |
| 3 | Duplicate Persons created | CRM's duplicate advice, server-side at request time; `confirm_distinct` explicit (V20) | Tests for name and email candidates; nothing created without confirmation |
| 4 | Editing an unrelated Person | Scope check from Volunteering's table before any delegated call; `404` for out-of-scope (V23) | Tests with a real Person who has no relationship: `404`, unchanged, no event |
| 5 | Changing another Person's status without permission | `volunteers.manage` in route and use case | Access-control matrix over every route and use case |
| 6 | Racing creations | Unique index (V4, T1) | Two-process race, both engines, mutation-checked |
| 7 | Active → Inactive during Resource access | Eligibility per request; no signed URLs; `no-store` (R13) | Use-case test: a file is served, the status changes, the same call is `404` |
| 8 | Forged audience membership | Path C takes no audience or person input; the set comes from the Actor (R10) | Architecture test (R15); no request field exists to forge |
| 9 | Guessing unpublished Resource ids | Path C: one `404` for everything hidden. Path A requires `resources.view` | G5's non-disclosure suite re-pointed at path C, unchanged in strength |
| 10 | Guessing hidden File Card ids | Path C resolves files through the projection; path A through `resources.view` | File tests on both paths, including Draft and narrowed Cards |
| 11 | An audience viewer selecting privileged mode | No mode parameter exists. Path A is a different service behind a different capability | Architecture test; an Actor without `resources.view` is `403` on every library route |
| 12 | A `resources.view` holder mutating | Library routes are GET-only and pinned. Management needs `resources.manage` | Route-table test; `Gate::before` view-only is `403` on all 25 management operations |
| 13 | `resources.manage`-only receives viewing | Library use cases ask for `resources.view` alone. The Console infers neither from the other | The existing "manage is not view" test, kept |
| 14 | Unknown audience keys treated permissively | Write `422`; read dropped; filter `422`; eligibility emits only catalog members | Unit and feature tests; a stored unknown key matches no one on path C and shows as nothing on path A |
| 15 | Privileged responses cached for ordinary users | Path A exists only on session-authenticated `/admin` routes; global `no-store`; path C has no route | Header tests on the library and file routes |
| 16 | Stale permissions or relationships | Capabilities and relationships are read live; disabled Accounts lose their sessions (ADR 0025) | Tests: revoke a role and the next request is `403`; inactivate and the next path C call excludes `volunteer` |

Also considered:
- **A Volunteer manager making someone a Volunteer to gain edit scope.** Accepted and bounded (V23).
- **A Volunteer status change as a stealth grant of software authority.** Impossible: it grants no capability (V3), and a test pins that `/me` is unchanged by status.

## Migration and compatibility

- **New tables:** `volunteer_relationships` and `volunteer_status_changes`. They have no backfill, because no Volunteer data exists anywhere. The "Volunteer Interest" CRM tag is a label and is not migrated (ADR 0034, decision 13).
- **Resources:** no schema change. Existing Packs, Cards and audience rows are untouched.
- **Capabilities:** two new ones, granted to the Guardian role. Every suite that pins the Guardian's exact capability list changes in the same package: `CatalogTest`, `AuthorizerTest`, `RoleAdministrationTest`, `CurrentAccountCapabilitiesTest`, `EnrollmentTest` and `ChallengeTest`. So does `AdministrationRoutesTest`'s exemption list.
- **Architecture tests revised deliberately, each naming this ADR:**
  - the module graph (`Volunteering → Access, Identity, Crm`; `Resources → … Membership, Volunteering`);
  - `CrmBoundariesTest` (the delegated namespace and its one caller);
  - `ResourcesBoundariesTest` (the Membership edge, the `volunteer` identifier, the three paths);
  - the step-up exemption list.
- **Identity:** `RenamePerson` gains an optional expected name. Existing callers are unaffected.
- **Console:** the library's API types gain the path A fields. The library's file-path allowlist is unchanged.

## Testing strategy

**Backend**
- Pest, on both engines, in the existing layout: `Feature/Modules/Volunteering`, `Unit/Modules/Volunteering`, `Architecture/VolunteeringBoundariesTest`, and `Concurrency/VolunteeringRaceTest`.
- **Capability-separated tests** use `Gate::before` to remove a single capability, because no real role holds one without the other. The same Actor is run as:
  - volunteers-view-only,
  - volunteers-manage-only,
  - CRM-only,
  - both,
  - neither.
- **Response shapes** are asserted to exact keys at every depth.
- **Mutation checks** for the properties that matter:
  - drop the unique index;
  - drop the status lock;
  - skip the scope check;
  - widen the delegated read;
  - let the library call the projection;
  - feed path C a capability-derived audience;
  - let `pending` qualify.

**Frontend**
- Vitest with `fakeApi`: each page and state, including stale-revision and stale-basics.
- Boundary tests:
  - the Volunteer pages ask only `/admin/volunteers*`;
  - the library still imports no management client or editor.
- Typecheck, lint and format.

**Browser**
- Playwright against the real platform, `--workers=4`:
  - the Volunteer directory, intake (existing Person, new Person, duplicate advice), a status change and a conflicting one, basics edit, and the Person-record panel;
  - the library's Draft and audience filters, markers, and the Draft and Volunteer-only demo Packs readable to a Guardian;
  - management authoring and preview of a Volunteer Pack;
  - capability states by `/me` interception, as G5 did. The server's refusals are proved by the backend matrix, because no real role lacks one capability.
- axe in both themes, the sideways-scroll pass, and keyboard paths.

**Not run in the browser.** Path C has no route, so its proof is backend-only. A route-table test proves no external delivery exists.

## Verification matrix

| Area | Property | Test |
| --- | --- | --- |
| Volunteer domain | No duplicate relationship | Unique index (feature on both engines) and two-process race; mutation drops the index |
| | No Account required | Intake for a Person with no Account; the relationship survives a later `InviteAccountForPerson` unchanged |
| | Three states; any distinct transition; same-state no-op | Unit (domain) and feature tests over all nine pairs |
| | Reactivation reuses the row | Inactive → Active keeps id, `created_at` and history; row count stays 1 |
| | History complete and transactional | One row per creation and per change; a forced failure after the write leaves neither row nor history |
| | Concurrency | Stale revision `409`; race test; mutation drops the lock |
| | No security event, no role, no capability from status | The seam's caller list unchanged; `/me` identical before and after Active |
| People access | View-only | Directory and record readable; every manage route `403`; every CRM route `403` without CRM capabilities |
| | Manage-only (role invariant aside) | Intake, status and basics allowed; reads behind view `403` |
| | CRM-only | Every Volunteer route `403`; CRM unchanged |
| | Both / neither | All allowed / all `403` |
| | Lookup | Under 3 characters `422`; exact email and phone; partial email matches nothing; at most 10; no contact values; view-only `403` |
| | Minimal creation | Duplicate advice by name and email; `confirm_distinct`; Person and relationship commit together; relationship failure rolls back the Person |
| | Scoped editing | Three fields only; others refused by shape; compare-and-set `409`; CRM's validation and duplicate rule; `person.renamed` recorded |
| | Cross-Person negatives | A Person with no relationship `404` on read and edit, identical to a non-existent id; Account login email never matched or changed |
| Resources | `resources.view` reads Published, all audiences | Library lists Guardian, Member, Volunteer and multi-audience Packs |
| | `resources.view` reads Drafts, all audiences | Draft Pack, Draft Card in a Published Pack, uncategorised Draft, empty Pack, Draft File Card's file |
| | `resources.view` cannot mutate | Route table GET-only; view-only `403` on all management operations |
| | `resources.manage` keeps management and preview | Existing suite; preview with `volunteer`; preview file paths use the management route |
| | Active Volunteer qualifies (path C) | Volunteer-targeted Published Pack delivered |
| | Pending / Inactive do not | Same Pack `404`; search does not find it; file `404` |
| | Member and Volunteer independent | Member-only, Volunteer-only, both: each sees exactly its own |
| | OR | A `{member, volunteer}` Pack seen by each alone |
| | Narrowing | A Card narrowed to `volunteer` is invisible to a Member in the same Pack; the subset and conflict rules for `volunteer` |
| | Drafts never in path C | G5's non-disclosure suite, re-pointed, for every audience |
| | Files follow the same policy | Path A and path C file tests, including inactivation between calls |
| | Unknown audiences fail closed | Write `422`, filter `422`, stored unknown key matches no one |
| Frontend | Directory, record, intake, Person panel, library markers and filters, navigation by capability | Vitest per page and state; Playwright journeys |
| | Keyboard and responsive | Playwright keyboard paths; axe both themes; 320/375/1280 px |
| Integration | Real server | `./flow test e2e --workers=4` green |
| | Both engines | `./flow check all --pgsql` green |
| | No premature external delivery | No route reaches path C (architecture); no `/my/` or public Resources route (route table) |

## Work packages

Sequence and acceptance criteria. Status lives in the [roadmap](../roadmap.md). Every package is gated on `./flow check all --pgsql`, and browser packages on `./flow test e2e --workers=4`.

| WP | Scope | Depends on | Acceptance | Build / audit |
| --- | --- | --- | --- | --- |
| **WP1** | **Volunteer relationship backend.** The `Volunteering` module, both tables, the lifecycle and history, `volunteers.view`/`manage` with Guardian grants and the role invariant, the fourth step-up exemption, directory, record, status change, intake **for an existing Person**, the lookup, `IsActiveVolunteer`, the delegated `ReadPersonBasics`, `PeopleMatchingContact` and `FindPersonCandidates`, and OpenAPI | WP0 | The Volunteer domain and the read rows of the People-access matrix above; race tests for creation and status on both engines, mutation-checked; boundary tests (module graph, delegated namespace caller, one capability per use case) | Opus build, Opus audit (authorization boundary) |
| **WP2** | **Scoped People writes.** `RegisterMinimalPerson` (intake of a new Person with duplicate advice, in the intake transaction), `UpdatePersonBasics` with compare-and-set, `RenamePerson`'s expected name, and the shared candidate logic extracted from `RegisterContact` | WP1 | The minimal-creation, scoped-editing and cross-Person rows; the basics and rename races on both engines; CRM's existing suite unchanged | Opus build, Opus audit (writes to another module's data) |
| **WP3** | **Resources authorization transition.** `volunteer` in the catalog; path A (`ViewerLibrary`) behind the existing library routes, with filters and new response fields; path C (`AudienceEligibility`, `AudienceDelivery`, the three use cases) with no route; the Membership and Volunteering edges; preview's management file paths; the revised boundary tests; OpenAPI | WP1 (`IsActiveVolunteer`); independent of WP2 | Every Resources row of the matrix; G5's non-disclosure suite passes against path C; R15's architecture tests, each mutation-checked | Opus build, **Opus deep audit** (highest risk: it changes who reads what) |
| **WP4** | **Volunteer Console.** Navigation, directory, Volunteer record, status change, edit basics, intake, and the Person-record panel | WP1, WP2 | Vitest per page and state, including both conflict flows; boundary source test; axe and layout passes | Sonnet build, Opus audit (focused) |
| **WP5** | **Resources Console.** The `volunteer` audience in authoring, filter and preview; the library's publication and audience filters, Draft and audience markers, the uncategorised group, provenance line; no management affordance | WP3 | Vitest; the library boundary tests extended; axe and layout | Sonnet build, Opus audit (focused) |
| **WP6** | **End-to-end proof, demo and closeout.** A `VolunteeringDemoSeeder` (opt-in, `local`/`testing` only, through the use cases) with Pending, Active and Inactive Volunteers, one with no Account and one who is also a Member; Volunteer-targeted and Member-and-Volunteer Packs added to the Resources demo; browser journeys for the whole G10 workflow; closeout notes and checklist | WP4, WP5 | `./flow test e2e --workers=4` green; the frontend and integration rows of the matrix; the G10 checklist | Sonnet build, Opus review of the closeout |

**Why this shape**
- **Creation is split from existing-Person intake.** Creating and editing People on another module's behalf (WP2) is a separate security surface from the relationship itself (WP1), so each gets a focused audit.
- **The Resources transition is a package of its own** (WP3). It is the riskiest change and deserves an audit that looks at nothing else.
- **Parallelism.** WP3 needs only WP1's eligibility read, so WP2 and WP3 can proceed in either order.

## Deferred

Not designed here, and nothing is built for any of it:
- **Volunteer work:** onboarding, training and orientation tracking, applications, approval chains, shifts and schedules, teams and assignments, event staffing, hours, compensation, volunteering periods, a reason on status changes (V11).
- **Volunteer data:** relationship deletion or erasure (V12, with the platform's open retention question), a preferred-name field, a Volunteer Coordinator role (a role-catalog change when wanted).
- **Resources:** approval workflows, reviewer assignment, review comments, revision history and comparison (decision 14).
- **Delivery and audiences:** notifications; the WordPress companion and any Member- or Volunteer-facing Resources surface (G7/G9); Partner, Artist, Vendor, contextual and Boolean audiences; a Guardian relationship.
- **Discussions:** no Resources-to-Discussions coupling is introduced.

## Implementation-time observations (not G10 scope)

Recorded for the G6 audit:
- Resources parses a stored `state` and `audience_mode` with `::from` (`DatabaseCardRepository.php:392,422,432`, `DatabasePackRepository.php:252`), so a corrupt value is a `500`, not a fail-closed omission.
- The library's file shape still has no `available` field (ADR 0037 follow-ups).

## Consequences

- Flow Life can record who volunteers, change their standing, and keep their basic contact details current, without anyone gaining CRM access to do it. The Person stays the one human anchor, and contact data stays in CRM.
- A future Volunteer Coordinator role is a role-catalog change, not a redesign: every boundary is already capability-based and server-enforced.
- CRM gains its first caller-authorized seam. It is small, named and pinned to one caller, but it is a seam: each future relationship domain that wants it must be added deliberately.
- Every Guardian (as `resources.view` holder) now reads Member-only, Volunteer-only and Draft Resources in the Console. That is the decision (11–13). The `guardian` audience becomes a targeting fact rather than a Console limit, and the Console's library is a reading and review surface rather than a delivery surface.
- Audience delivery exists and is proved before any surface uses it, so the WordPress companion arrives at an eligibility contract instead of designing one.
- Resources depends on Membership and Volunteering a milestone earlier than ADR 0037 planned, through their Application layers only.
- More personal data, and a new history of it, joins the platform's open erasure question.

## Alternatives considered

- **A Volunteer flag, tag or role.** Rejected by ADR 0036 and decision 1: a role does not lapse and is not a relationship, and a tag is a label.
- **One row per volunteering period (Membership's shape).** Rejected by decision 4. Membership's grants are time-bounded entitlement with terms; a Volunteer has one ongoing standing whose history is its status changes.
- **Status history in `security_events`.** Rejected (V9): a status confers no software authority, and Membership's grants, the nearest precedent, are not security events.
- **A generic relationships table or framework.** Rejected by ADR 0036. Volunteering is the second real relationship and has a different shape from the first.
- **Copying name, email and phone into the relationship.** Rejected by decision 8: two truths about one human.
- **CRM authorizes `volunteers.manage` itself.** Rejected: CRM would depend on Volunteering to know scope, creating a cycle with Volunteering's dependency on CRM, and it would learn every future relationship.
- **Volunteering queries `contact_*` directly.** Rejected by ADR 0021, rule 1, write ownership.
- **Scoped role assignments (Guardian of the Volunteers).** Rejected for G10: a large Access change (ADR 0017 defers scope) that would still need the same per-Person scope rule.
- **A revision column on CRM records.** Rejected for G10: CRM's own screens would not send it, so it would protect only Volunteering's edits while changing CRM's contract. Compare-and-set gives the same guarantee for the fields Volunteering touches.
- **Only Pending → Active → Inactive, with reactivation.** Rejected: decision 2 says Guardians change state directly, and a restricted graph is approval in disguise.
- **Privileged viewing as the audience projection with "all audiences" and "include Drafts" flags.** Rejected (R5): it would make audience delivery's safety depend on every caller's flags.
- **New `/admin/resource-review` endpoints beside an unchanged Guardian-only library.** Rejected: under decision 11 nothing in the Console would use the Guardian-only answer, and the contract has never been released.
- **Infer `resources.view` from `resources.manage`.** Rejected, as in G5: they stay independent.
- **Deliver path C on `/my/` now.** Rejected: decision scope excludes a Member- or Volunteer-facing library, and ADR 0037 decision 70 intends one community surface.
- **Signed file URLs for delivery.** Rejected: a long-lived link would survive inactivation (R13), and every request is already authenticated.
