# ADR 0038: Organizational Relationships and Resource Viewing Authority

- **Status:** Proposed. This is the G10 design gate (WP0), remediated after an independent architecture audit and again after that remediation's focused verification, and awaiting acceptance. Decisions N1–N6 and the audit rulings AR1–AR10 are resolved by the product owner. Nothing is implemented.
- **Date:** 2026-10-08. Revised four times before acceptance, recorded under *Revision history*. The earlier versions are commits `9eeb4f9`, `30a5443` and `96a01ea`.
- **Supersedes:** none
- **Superseded by:** none
- **Amends:**
  - [ADR 0017](0017-capabilities-and-roles-in-code.md): assignments still are the only authorization data that persists, but they now persist in two Access tables. `role_assignments` keeps independent assignments exactly as today. A new `sourced_role_grants` table holds grants that an authorized operator made through a relationship, with their source (Part K). The role catalog grows from two roles to five, and the `guardian` role key becomes `guardian-full` (AR1).
  - [ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md):
    - decision 3: Volunteer is owned by the `Relationships` module, not by a separate Volunteering module;
    - decision 7: Guardian becomes a business relationship and is no longer represented only in Access;
    - the rejected alternative "a generic relationships table now": its trigger, a second real consumer, has arrived.
    - Decision 5 ("a relationship may result in Access grants") is applied, not changed: Part K is the first mechanism that applies it.
  - [ADR 0037](0037-resources-are-audience-targeted-packs-of-cards.md), decisions 38, 42–46, 48–52, 66 and 69. They change:
    - who reads what in the Console;
    - the `volunteer` audience is added to the catalog (38, as decision 44 foresaw);
    - Guardian eligibility comes from the Guardian relationship;
    - privileged viewing has its own search over every Card (48 still governs audience delivery);
    - the step-up exemption list grows from three entries to five (52);
    - Console users without `resources.view` can read what they are eligible for;
    - Membership becomes a dependency of Resources earlier than planned.
  - ADR 0037, decision 43 is **clarified**, not changed: privileged viewing (`resources.view`) reads every audience's content, but it never *satisfies* an audience. No role or capability makes anyone a Member, a Volunteer or a Guardian for delivery.
  - Every other decision of these ADRs is unchanged.
- **Related:** [ADR 0005](0005-mariadb-with-postgresql-portability.md), [ADR 0007](0007-versioned-rest-api-openapi.md), [ADR 0009](0009-authorization-separate-from-approval.md), [ADR 0015](0015-identity-owns-person.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0019](0019-security-event-auditing-seam.md), [ADR 0020](0020-administrator-bootstrap-and-last-administrator-invariant.md), [ADR 0021](0021-cross-module-referential-integrity.md), [ADR 0023](0023-multi-factor-authentication.md), [ADR 0024](0024-privileged-operator-administration.md), [ADR 0025](0025-account-security-generation.md), [ADR 0028](0028-membership-grants-derived-at-query-time.md), [ADR 0033](0033-service-identity-and-delegated-human-authority-are-distinct.md), [ADR 0034](0034-crm-enriches-identity-person.md)

## How to read this ADR

Every statement below is one of four kinds:

- **Approved requirement.** A product decision already made, and not reopened here. These are:
  - the fourteen G10 planning decisions (1–14);
  - the organizational direction (D1–D8);
  - the six rulings on the questions the previous revision raised (N1–N6);
  - the correction to configuration readiness (CR);
  - the rulings that followed the independent audit (AR1–AR10).
- **Proposed mechanism.** How this ADR meets those requirements. It becomes binding when the ADR is accepted.
- **Deferred.** Recorded so that the design leaves room for it. It is not designed here and nothing is built for it.
- **Future path.** What a later change would require, stated honestly. It is not a commitment.

## Context

G5 (Resources) is complete and merged. G10 is next on the [roadmap](../roadmap.md).

This ADR has gone through five versions:
1. **First version** (commit `9eeb4f9`). It designed G10 around the Volunteer relationship only.
2. **First revision.** It made Guardian an organizational relationship, independent of roles. It put both relationships on one shared foundation whose definitions are centralized and declarative. It raised six questions.
3. **Second revision** (commit `30a5443`). It recorded the product owner's rulings on those six questions, three of which refined or reversed the revision's recommendation. It also corrected how configuration readiness was described.
4. **Third revision** (commit `96a01ea`). An independent architecture audit of the second revision found no blocker and no high finding, five medium findings (M1–M5) and ten advisories. That revision records the product owner's rulings on them (AR1–AR10) and remediates every finding. The two largest changes are a five-role catalog with real restricted personas (AR1, M5) and relationship-managed provisioning of a default role (AR2, Part K).
5. **This version.** A focused, independent verification of the third revision confirmed M1–M5 and the advisories. It found one remaining medium issue and eight low ones, all corrected here without changing any approved decision (*Revision history*).

The rulings that changed the design:
- **Basic details of Guardians (N5).** A Volunteer manager may edit the approved basic details of a Person in their Volunteer scope even when that Person is a Guardian. The safeguard is a precise boundary on which operation, which fields and which scope is involved, together with a verified separation between contact data and login identity. Guardian affiliation is no longer an automatic block.
- **Console reading for people without `resources.view` (N6).** Console users who lack `resources.view` read the published Resources their relationships make them eligible for, in G10.
- **Independent capabilities (N1, CR).** Every capability pair is independently assignable. A manage-only holder gets only the narrow reads that managing needs.
- **Configuration readiness (CR).** The split between what is code-defined today and what may become configurable is not a permanent ban. It is a statement of what G10 builds and what a later, controlled change would need.

What carries over unchanged: everything else from the earlier versions. That includes the Volunteer decisions, the shared persistence, the scoped People seam, the separation of privileged viewing from audience delivery, and the threat mitigations.

The audit rulings that changed the design (AR1–AR10, below):
- **Roles.** `guardian` becomes `guardian-full`, and three roles join it: `guardian-initiate`, `guardian-senior` and `console-participant`. Two of them grant Console admission alone, so the restricted Console reader is a real persona (M5).
- **Relationship-managed provisioning.** Recognizing a Guardian can, with an authorized operator's explicit choice, grant `guardian-initiate` through Access. The grant is attributed to the relationship instance, and the relationship's lifecycle withdraws it (Part K). This replaces the earlier absolute rule that a relationship never results in a role, within the limit ADR 0036, decision 5 already set.
- **Volunteer self-scoping is accepted (M1).** The threat model now says plainly that a Volunteer manager brings a Person into scope by recording them, and states the safeguards that really exist.
- **Contact provenance is real (M2).** CRM gains `contact_methods.updated_by_account_id`.
- **The identifiers and the guards are reconciled (M3, M4).** The relationship type is a validated value object. Definitions arrive through one source seam. Catalog invariants are stated, and each architecture-test change is specified.

### Repository facts this design rests on

Checked on `main` at `3c62eb3` (G5 complete, post-merge CI green), and re-checked for each revision.

**Identity and the Actor**

- **An Account always has exactly one Person.**
  - `accounts.person_id` is NOT NULL, unique and a `RESTRICT` foreign key (`database/migrations/2026_09_19_000002_create_accounts_table.php:26,41,43`).
- **A Person may have no Account.**
  - Such Persons are created only by `Identity\Application\RegisterPerson`.
  - `InviteAccountForPerson` gives an existing Person an Account later.
  - Nothing moves an Account to another Person.
- **The Actor carries identity and nothing else.**
  - `Shared\Domain\Actor` holds `accountId`, `personId` and the authentication method (`app/Shared/Domain/Actor.php:20-33`).
  - `ResolveActor` yields no Actor for an Account that cannot authenticate.
  - Disabling an Account revokes its sessions and advances its security generation in one transaction ([ADR 0025](0025-account-security-generation.md)).
- **Login identity and contact data are separate stores.**
  - The Account's login email is `accounts.email`/`email_canonical`, owned by Identity.
  - CRM contact emails are `contact_methods` rows, owned by CRM.
  - No Identity, Access or Membership code references CRM (checked by search).
  - The only path from an existing Person to an Account is `InviteAccountForPerson(PersonId, string $email)`. It takes the address the operator types. The Console's form starts empty and is not prefilled from CRM (`apps/guardian-console/src/pages/admin/InviteToCommonsSection.tsx:82`).
  - Password reset uses the Account's own email.
  - So changing a CRM contact email cannot change, redirect or recover anyone's login.

**Roles, capabilities and Console admission**

- **Capabilities are read live.**
  - `Authorizer` re-reads `role_assignments` on every check, and an unknown role key grants nothing (`Authorizer.php:51-72`).
  - Each capability is checked independently. Nothing in the code derives one capability from another.
- **There are two roles** (`Role.php:33-42`):
  - `platform_administrator` holds every capability, present and future.
  - `guardian`, displayed as "Guardian", holds `console.access` and both capabilities of CRM, Discussions and Resources.
- **Guardian is currently a role, not a relationship.**
  - [ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md), decision 7: "being a Guardian is holding the Guardian role".
  - `Resources\Application\DeliverySurface` treats `resources.view` as Guardian eligibility (`DeliverySurface.php:10-22`).
- **Roles reach people only through these paths:**
  - `GrantRole` and `GrantRoleToAccount` (`access.roles.assign`, which records `role.granted`);
  - `InviteOperator`;
  - `BootstrapAdministrator`, rooted in server access ([ADR 0020](0020-administrator-bootstrap-and-last-administrator-invariant.md));
  - `ConsoleUserFixture`, in local and testing environments only.
- **How assignments are stored** (`2026_09_20_000003_create_role_assignments_table.php`, `Domain/RoleAssignment.php`):
  - `role_assignments(id, person_id, role_key, granted_by_account_id, granted_at)`, with `unique(person_id, role_key)` and an index on `role_key`.
  - A row's existence is an active grant. Revocation deletes the row, and the audit trail is the history.
  - **Grants belong to the Person, not the Account.** A grant to a Person whose Account cannot yet authenticate confers nothing until it can, because the `Authorizer` first re-resolves a live Actor. `InviteOperator` relies on this today: it grants roles to the Person of an Account that is only invited.
  - `RoleAssignment::KEY_SHAPE` accepts lowercase snake_case only (`^[a-z][a-z0-9_]{0,63}$`). The revoke route constrains `{key}` to the same shape.
  - `RevokeRole` removes the one `(person, role)` row. Revoking `platform_administrator` goes through `AdministratorContinuity`.
  - Nothing caches capabilities: not sessions, not `/me`, not the Authorizer.
- **The `guardian` role key appears only inside Access, in the development fixture `ConsoleUserFixture`, and in tests** (about 30 test files). The Console and OpenAPI name the `guardian` *audience*, never the role key. Roles reach the Console only through the role catalog API.
- **How a Person gets an Account.** Every path is operator-initiated or rooted in server access:
  - `InviteOperator` (a new Person);
  - `InviteExistingPerson` → `Identity\Application\InviteAccountForPerson`, under `identity.invitations.issue` and recent verification;
  - `BootstrapAdministrator`.

  The holder of the invitation token completes it (`AcceptInvitation`). There is no self-service Account creation, and nothing moves an Account to another Person.
- **Console admission is the capability `console.access`.**
  - It is required on every `/api/v1/admin` route.
  - Holding it makes the second factor mandatory.
  - Without it, the Console sends an Account to `/my`.
- **Administration route rules** (`AdministrationRoutesTest.php`):
  - Every admin route carries `stateful`, `auth:web`, `can:console.access` and exactly one other catalog capability (lines 35–62).
  - A mutation must carry `security.verified` after its capability, unless that capability is on the step-up exemption list.
  - The exemption list is `crm.people.manage`, `discussions.participate` and `resources.manage`, each pinned to its own module's `Http` (lines 22–26).
  - A route with an exempt capability may still carry `security.verified`. Resources' two deletions do.
- **Role-catalog pins** (`CatalogTest.php`):
  - line 34 pins the role list as exactly `platform_administrator` and `guardian`;
  - lines 148 and 189 pin that today's roles grant CRM manage only with CRM view, and Resources manage only with Resources view. These describe how the current roles are composed. The capabilities themselves are still checked independently.
  - lines 205–212 pin that no role is named `member` or `volunteer`.
- **The role-key literal guard** (`AccessBoundariesTest.php:143-178`) fails any PHP file outside Access that contains a role key as a string literal. Its one exemption is `Resources/Domain/Audience.php`, for the `guardian` *audience*. A positive control asserts that the exemption is needed. `MfaBoundariesTest.php:157-164` forbids role names inside Identity.

**People / CRM**

- **People are thin.** `people` holds an id, `display_name` and timestamps. There is no preferred name.
- **Renaming belongs to Identity.** `RenamePerson` authorizes nothing ("Identity never learns which capability CRM checked"), locks the row and records `person.renamed`.
- **Contact data belongs to CRM.**
  - `contact_profiles`, keyed by `person_id`, is also the per-Person write lock.
  - `contact_methods` keeps one primary per kind (`unique(person_id, primary_kind)`) and one value per kind per Person (`unique(person_id, kind, search_value)`).
  - `value` is what was entered, trimmed. `search_value` is a matching key only: an email lower-cased, a phone number reduced to its digits and a leading plus.
  - **Contact methods carry no actor provenance.** `contact_methods` has only `created_at` and `updated_at`. `contact_profiles.updated_by_account_id` is stamped only when profile fields (`how_we_know`, `affiliation`) change. `UpdateContactMethod` takes the profile lock but records no actor.
  - **Lock order.** `UpdatePerson` locks the `people` row (inside `RenamePerson`) and then `contact_profiles`. The contact-method use cases lock `contact_profiles` only.
  - CRM has no optimistic concurrency; its screens send only changed fields.
  - `RegisterContact` gives duplicate advice (`409 possible_duplicate` with candidates' id, display name and `matched_on`; `confirm_distinct` overrides it). The check runs before the transaction, as advice, and is not race-proof.
  - `DuplicateContactMethod` means "this Person already has that value", never another Person's.
- **CRM's boundaries are pinned.** Each CRM use case asks for exactly one capability (`CrmBoundariesTest.php:254-299`; the scan finds use cases by an `Actor $actor` parameter), and nothing outside CRM uses CRM (`CrmBoundariesTest.php:82`).

**Membership**

- `GetCurrentMembership(PersonId)` answers current membership, derived at query time. It authorizes nothing.
- Membership's history is its non-deleting grant rows ([ADR 0028](0028-membership-grants-derived-at-query-time.md)).
- ADR 0028 reserves the mechanism for software capability that follows a *time-bounded* relationship: Access defines a port, the owner implements it, and the `Authorizer` combines it on every call. It is not built.

**Deployment.** Migrations run against the new release inside the maintenance window, before the release swap ([deployment runbook](../runbooks/deployment.md)). No live traffic is served between a data migration and the code that expects it.

**Resources**

- **Visibility.** `ResourceProjection::visibleCards(Pack, outlines, AudienceSet $viewer, bool $asIfPublished)` shows a Card when all of these hold (`Domain/ResourceProjection.php:25-38`):
  - the Pack is Published (unless `asIfPublished` is set);
  - the Card is Published;
  - the Card's effective audiences intersect the viewer's.
- **Unknown audiences match no one.** An unknown stored audience key is dropped on read (`Infrastructure/Rows.php:61-77`).
- **The Console serves only the `guardian` audience.** `DeliverySurface::guardianConsole()` returns `{guardian}`, and that set drives the library, the Pack view and file delivery.
- **Preview** shows a chosen audience with `asIfPublished: true`, under `resources.manage`.
- **Non-disclosure.** Everything hidden answers `404 resource_pack_not_found`, and delivery carries no state, audiences, revision or provenance.
- **Files.**
  - Served only by cookie-session routes, with `private, no-store`.
  - Every `/api/*` response is `no-store` (`BrowserSecurityPolicy.php:39-45`).
  - There are no signed URLs.
- **Boundary pins** (`ResourcesBoundariesTest.php:44-46,387-401`):
  - no dependency on Membership;
  - no Volunteer, Partner, Vendor or Artist identifiers;
  - audiences exactly `guardian` and `member`.
- **Release state.** G5 has never been released or deployed. Its only consumer is the Console, in the same repository.

**Audit and module boundaries**

- **`security_events` columns:** `actor_account_id`, `subject_person_id`, `subject_account_id`, `type`, `outcome`, `occurred_at` and a flat `context`.
- **Who may record security events** (`MembershipTrustBoundariesTest.php:227-240`): Identity, Access, Audit, and Resources' two deletion use cases.
- **Resources' deletion precedent** (ADR 0037, decision 55):
  - the capability first, then recent verification;
  - then one event, in the deletion's own transaction;
  - the event holds ids and counts, never content.
- **The module graph is frozen in a test** (`AccessBoundariesTest.php:248-260`).
- **Modules collaborate only through each other's `Application` layer.**

## Approved requirements

### The G10 planning decisions (1–14)

Binding. Decision 2's phrase "authorized Guardians" is read as "authorized operators": authority comes from capabilities, not from affiliation (D3).

| # | Decision | Implemented by |
| --- | --- | --- |
| 1 | A Volunteer relationship belongs to a Person, is independent of Accounts, and never grants roles or Console capabilities | F1–F3, V1, A8 |
| 2 | Volunteer lifecycle: Pending, Active, Inactive, changed directly. No applications, approvals or onboarding | V2, F9 |
| 3 | Only Active Volunteers qualify for the `volunteer` audience. Other relationships are independent | V2, R10–R12 |
| 4 | One ongoing Volunteer relationship per Person, reactivatable, with auditable status history | F6, F9–F11 |
| 5 | The Person record is the relationship's home, plus a lightweight Volunteer directory. No duplicate identity | C2–C5 |
| 6 | `volunteers.view` and `volunteers.manage`, independent of CRM and granted through roles | A1–A4 |
| 7 | Volunteer access never implies broad CRM access, and the backend enforces that | P1–P7 |
| 8 | Scoped Person management for Volunteer managers: lookup, minimal creation, the relationship, upkeep of basic contact details | P1–P7 |
| 9 | `volunteers.view` reads basic contact information for Persons who have a Volunteer relationship, in any state | P2 |
| 10 | Multiple audiences combine by OR. Pack and Card narrowing is preserved. No Boolean expressions | R1–R2 |
| 11 | `resources.view` gives broad reading authority across all audiences. `resources.manage` is independent | R4–R9 |
| 12 | `resources.view` reads unpublished Packs and Cards. Audience delivery still respects publication | R5–R7, R11 |
| 13 | Drafts are discoverable in the Resource Library, with indicators and filters. The library is not a second management UI | R8, C7 |
| 14 | Viewers read the latest saved content. No revision history, comparison, review comments or approval workflow | R7, *Deferred* |

### The organizational direction (D1–D8)

| # | Requirement | Implemented by |
| --- | --- | --- |
| D1 | Relationships, roles and audiences are independent concepts. A role never establishes a relationship. A relationship is never itself a role, and it results in a role only through the authorized, attributed provisioning of AR2 (ADR 0036, decision 5) | F1, A8–A9, K1–K12, R10 |
| D2 | Guardian is a first-class relationship attached to a Person. It coexists with Volunteer, and later Member and Partner. It is never inferred from a role name or from Console capabilities | G1–G7, M1–M6 |
| D3 | Guardians may hold any level of platform access, assigned independently. Being a Guardian grants no administrative authority. Affiliation is never permission to modify other Guardians. No seniority levels | G2–G3, A1–A9 |
| D4 | Relationship definitions are centralized and declarative. They are code-defined in G10 and configuration-ready later. G10 has no operator configuration interface, executable configuration, rules engine or runtime migrations | F3–F5, *Configuration readiness* |
| D5 | Deactivation preserves the relationship. Reactivation restores the same one. Permanent deletion removes it and its metadata, with audit and safety rules | F9–F12 |
| D6 | The Person record presents relationships through a reusable contract that allows specialization. Identity and contact data stay with People/CRM. A panel never needs unrestricted CRM privileges | F13, C2–C5 |
| D7 | Guardian audience eligibility comes from the Guardian relationship. `resources.view` stays a capability and is never derived from a relationship | R4, R10 |
| D8 | Adding Member or Partner later must not mean rewriting the Person relationship presentation or the Resource eligibility foundations. Membership is not migrated now | F13, R10 |

### The rulings on N1–N6 and the configuration correction

All resolved. Each is implemented throughout this ADR, not only in this table.

| # | Ruling | Implemented by |
| --- | --- | --- |
| N1 | `guardians.view` and `guardians.manage` are introduced. Initially, `guardians.manage` goes to Platform Administrators and `guardians.view` to the `guardian` role (after AR1, `guardian-full` and `guardian-senior`). The capabilities do not depend on each other. Initial grants are not permanent rules, and future roles may receive either one independently. Guardian status never grants either. Sensitive Guardian changes (recognition, deactivation, reactivation, deletion) require recent verification. Manage-only holders get only the narrow reads management needs | A1–A6, G6 |
| N2 | The Guardian lifecycle is Active and Inactive. Junior or trainee Guardians are represented by restricted roles, not by a state. Adoption is deliberate and manual, supported by a read-only reconciliation report. There is no automatic creation from roles, Console access, Resource capabilities or administrative Accounts, and no automatic production migration. There must be a safe bootstrap path that works with no Guardian relationships | G2, M1–M5 |
| N3 | Permanent deletion removes the relationship, its metadata and its status history. A minimal security audit record remains. It is atomic and verified, and it never touches the Person or other relationships. Future relationship-linked records can constrain deletion, but never by cascading silently. Deactivation and reactivation stay non-destructive | F12 |
| N4 | Initial optional fields: Guardian `recognized_on` and `stewardship`; Volunteer `interests` and `availability`. They are descriptive and never assign roles, permissions or eligibility | F7, G4, V2 |
| N5 | **Revised.** No blanket prohibition for Guardians. A Volunteer manager may maintain the approved basic fields of a Person in their Volunteer scope even when that Person is a Guardian. Authorization is specific to the operation, the field and the scope. Login identity, credentials, roles, capabilities and Guardian relationship state remain out of reach | P5–P7 |
| N6 | **Revised.** G10 delivers audience-based reading of published Resources in the Console to Console users without `resources.view`, through a separate backend contract and the existing library interface. Console admission is still required independently. Future external delivery uses the same eligibility, and the eligibility is not coupled to the Console | R4, R10–R15, C7 |
| CR | Code-defined today is not immutable forever. The design keeps a practical, honest path to operator configuration, bounded by mandatory invariants | F4–F5, *Configuration readiness* |

### The rulings after the independent audit (AR1–AR10)

The independent audit of commit `30a5443` found no blocker or high finding, five medium findings and ten advisories. The product owner ruled as follows. Each ruling is implemented throughout this ADR.

| # | Ruling | Implemented by |
| --- | --- | --- |
| AR1 | **The role catalog.** Five roles: `platform_administrator` (unchanged); `guardian-initiate` (Guardian Initiate, minimal Console access); `guardian-full` (Guardian, today's `guardian` bundle); `guardian-senior` (Senior Guardian, initially the same bundle as Guardian, with no approval, financial, subscription or administrative authority); `console-participant` (Console Participant, minimal Console access). The `guardian` role key becomes `guardian-full` through a controlled migration that preserves every effective permission. Roles are additive, coexist on one Account, form no hierarchy and establish no relationship. The relationship type key stays `guardian` | A3, M6, Part K |
| AR2 | **Relationship-managed default role.** A definition may name one default role that an authorized operator may choose to provision. Guardian: `guardian-initiate`, on Active recognition or activation, with explicit confirmation, offered preselected when the operator may both manage Guardians and assign roles. Volunteer: none. Member and Partner: deferred. The choice decides whether a grant exists. Without role-assignment authority, no role is granted, by a non-escalating contract. Provisioning is never suppressed or replaced because of other roles. Grants keep durable attribution to their relationship instance and coexist with independent grants of the same role. A Person without an Account keeps the authorized intent, fulfilled only through a trusted Account linkage. Inactivation withdraws, reactivation restores only a previously authorized grant, and deletion removes grant and intent. Independent grants are never touched | Part K |
| AR3 | **M1, accepted.** A `volunteers.manage` holder may deliberately attach an existing Person and so bring them into basic-detail scope, Guardians included. The design states this consequence and the safeguards that exist. It adds no recent verification to ordinary Volunteer intake | P3, P8, threat review |
| AR4 | **M2, corrected.** CRM gains `contact_methods.updated_by_account_id`, written by ordinary and delegated contact-method edits alike. The expected-value contract, lock order and hidden-collision behaviour are specified, and they disclose nothing | P5, P9 |
| AR5 | **M3, corrected.** `guardian` stays the relationship type key and the audience key. Role keys move to the new catalog. Architecture tests are revised narrowly to separate authorization through Access services from identity inference | A8, A10 |
| AR6 | **M4, corrected.** The relationship type is a validated value object. Definitions reach the catalog through one source seam, used by production and tests alike. Catalog security invariants are stated, including that no capability serves two types | F2–F5 |
| AR7 | **M5, resolved by the role catalog.** `guardian-initiate` and `console-participant` give real restricted personas without `resources.view`. A role alone never establishes audience eligibility. Browser tests use real role assignments and relationships | A3, R12, *Verification matrix* |
| AR8 | **Stale-instance protection.** Every relationship mutation names the relationship instance id and its revision | F10, Part H |
| AR9 | **Identity freshness and client transitions.** Audience eligibility re-resolves the current Actor. A `403` from a privileged library route refreshes `/me` and moves the Console to the mode it now permits, without showing privileged data | R10, C1 |
| AR10 | **Member and Partner direction.** Recorded as direction only: initiation is not activation; prerequisites, subscriptions, agreements, approvals and authorized exceptions stay distinguishable; Zeffy is the preferred first billing provider, to be verified. Nothing is built in G10 | *Future architecture* |

## Decision

### Part F — The relationship foundation

**Ownership and shape**

F1. **A new `Relationships` module owns organizational relationships.**

It owns:
- the registry of relationship types and their definitions;
- relationship instances, their lifecycle, status history and metadata values;
- the eligibility read that other modules ask;
- the presentation contract the Console renders;
- the orchestration of relationship-scoped People operations (Part P);
- each type's default-role policy, and each relationship's recorded provisioning decision (Part K).

It does not own:
- Persons (Identity);
- contact data (CRM);
- Accounts, roles, role grants or capabilities (Identity, Access). A relationship-managed grant is Access's row; Relationships only asks Access to make or withdraw it;
- Membership, which keeps its own module, model and history (D8).

The product word is *relationship*. The Person record has a "Relationships" section, and the types appear as "Guardians" and "Volunteers".

**Why one module and not one per relationship.** Guardian and Volunteer share everything structural: one ongoing instance per Person, a small status lifecycle, history, descriptive metadata and deletion.
- Separate modules would need the same lifecycle engine, history, validation and concurrency code, either duplicated or placed in `Shared`. The brief forbids duplication. The charter keeps `Shared` a tiny kernel.
- One module still means one authoritative owner per relationship, as ADR 0036, decision 3, requires.

**Why ADR 0036's objection no longer applies.** ADR 0036 rejected "a generic relationships table now" because only Membership existed. Two similar consumers now exist. Membership stays where it is: its time-bounded grants are a different model (ADR 0028).

F2. **Relationship types form a registry, which is closed and code-defined in G10.** (AR6)
- **The type is a value object, not an enum.** `Relationships\Domain\RelationshipType` holds a key that matches `^[a-z][a-z0-9_]{1,31}$`. It is only ever obtained from the catalog (`RelationshipCatalog::type(string): ?RelationshipType`). A key the catalog does not hold yields no type. No PHP `enum` lists the types, so a type the catalog loads through its source seam (F3) is a first-class type with no code change anywhere else.
- **The registry is the catalog.** In production it holds exactly `guardian` and `volunteer`. Every consumer asks the catalog. No consumer assumes the set.
- **Storage.** A type is stored as a validated `string(32)`. A stored type the catalog does not know is ignored by every read, by every eligibility answer and by every route (fail closed). `relationships:check` reports it (F15).
- **Adding a type in G10** is a code change: a definition document, its capability pair in Access's catalog, and, if it should qualify for an audience, a row in Resources' mapping (R10). Routes are generated from the catalog.
- **Adding types through operator configuration later** is a deliberate future path. It is described under *Configuration readiness*, together with what it would require.

**Definitions: centralized and declarative**

F3. **Each type has one declarative definition, kept in one place, loaded through one seam.**
- Each definition is a data document: a PHP file returning a plain array, one file per type, in `app/Modules/Relationships/Definitions/`. A document holds data only: strings, numbers, booleans, lists and maps, plus the enum cases of Access's public `Capability` and `ProvisionableRole` vocabularies.
- **The source seam.** `Relationships\Application\DefinitionSource` is an interface returning the raw documents. The production binding, `Infrastructure\DirectoryDefinitionSource`, reads the `Definitions/` directory. `RelationshipCatalog` takes every bound source (a tagged container binding). It validates each document through `Relationships\Domain\DefinitionSchema`, checks the cross-definition invariants (F5), and turns each document into an immutable `RelationshipDefinition` value.
- **One path for every definition.** Production types and a test's types pass the same schema, the same invariants, the same route generation and the same API presenters. A test adds a source *before the application registers its routes*, through the test application's container. Nothing about a test type bypasses the catalog.
- **Fail closed, deterministically.** A document that fails the schema, or a set of documents that breaks a cross-definition invariant, is refused with an exception naming the document and the rule. In production this stops the application from booting, rather than silently dropping a type whose Persons might then appear to have no relationship. In tests, each rule has a refusal test.
- **Catalog lifetime.** The catalog is built once per application boot and is immutable. Routes are generated from it at registration. Nothing writes definitions at runtime.

Every layer reads definitions from the catalog and nowhere else: domain validation, use cases, API responses, and the Console through the API. A source test pins this: no repository, controller, presenter or React component holds a type's labels, states, fields or order.

Validation rules are not duplicated. The server validates against the definition. The Console's validation is generated from the same definition served by the API, it is advisory, and it never decides.

F4. **Every definition property is code-defined in G10, and each has a stated future path.** The schema records which class each property belongs to. This is a map of what G10 builds and what a later change would need. It is not a ban.

The three future paths:
- **Presentation.** Safe to make operator-editable first. It changes no stored meaning and no access.
- **Controlled.** It could become configurable through a controlled mechanism. That means versioned, validated and audited, possibly with a data migration, a new domain handler or explicit approval.
- **Code.** It requires new code whatever configuration exists, because it adds behaviour.

| Property | G10 | Future path |
| --- | --- | --- |
| Labels: singular, plural, description, help text | Code-defined | Presentation |
| States: label, description, tone (from the visual system's fixed set) | Code-defined | Presentation |
| Fields: label, help text, order, group | Code-defined | Presentation |
| Presentation: position among relationships | Code-defined | Presentation |
| Fields: loosening a constraint (longer maximum, added choice option, no longer required) | Code-defined | Presentation, once validated by the schema |
| Fields: tightening a constraint | Code-defined | Controlled: checked against stored values first (F8) |
| Adding a field of a supported value type | Code-defined | Controlled: additive, no migration |
| Retiring a field | Code-defined | Controlled: hidden, with values kept until an explicit cleanup |
| Changing a released field's key, value type or visibility | Code-defined | Controlled: needs a data migration, because stored values and access depend on it |
| A new value type | Code-defined | Code: a validator and a renderer |
| States and transitions | Code-defined | Controlled: versioned, with stored statuses checked; changes affecting eligibility need explicit approval (below) |
| Which state qualifies for eligibility (`qualifies`) | Code-defined | Controlled, security-sensitive: specially authorized, verified and audited; never silently |
| Which states may start a relationship | Code-defined | Controlled |
| Features (from the closed set `directory`, `people.lookup`, `people.create_person`, `people.read_basics`, `people.edit_basics`) | Code-defined | Enabling an implemented feature for a type: Controlled. A new feature: Code |
| Which operations need recent verification (A5) | Code-defined | Controlled, security-sensitive. It may only add verification without a code change; removing it needs a code change |
| Whether deletion is allowed | Code-defined | Controlled, security-sensitive |
| The default role (`default_role`, Part K) | Code-defined | Controlled, security-sensitive: specially authorized, verified, versioned and audited; limited to Access's `ProvisionableRole` set; a change must say what happens to existing grants, and never adds grants silently |
| The type's capabilities | Code-defined (Access's catalog) | Code until Access supports registry-defined capabilities (*Configuration readiness*) |
| A new relationship type | Code-defined | Controlled once the registry, routes, capabilities and audiences can read persisted entries (*Configuration readiness*); Code today |
| The audience a type satisfies (R10) | Code-defined (Resources' mapping) | Controlled once Resources' audience catalog can be extended by the registry; Code today |
| `type` key and `slug` of an existing type | Code-defined | Fixed once used: stored rows and routes depend on them; a rename is a migration |

F5. **Mandatory invariants.** No definition, today or under future operator configuration, may bypass these. They are enforced by code that a definition parameterizes and never replaces:
- authentication and identity integrity: a Person's identity, an Account's login identity and its credentials;
- capability authorization, on every operation;
- Person scope (Part P);
- audit integrity: status history written with the change, and the deletion event written with the deletion;
- Resource visibility and publication protections (Part R);
- type validation of every stored value;
- referential integrity: `RESTRICT` foreign keys and no silent cascade;
- safe schema and data migration: definitions never generate migrations at runtime;
- recent-verification requirements on security-sensitive operations;
- role provisioning only through Access's authorized services, only of a `ProvisionableRole`, and only by an operator's explicit, authorized decision (Part K).

**The catalog's security invariants** (AR6). `DefinitionSchema` and the catalog refuse a definition, or a set of them, unless every one of these holds. Each has a refusal test:
- **Types.** The type key and the slug match their shapes, and each is unique across all loaded definitions.
- **Capabilities.** Both are members of Access's `Capability` catalog. The view and manage capabilities differ. **No capability serves two types**, in either role. No type names a capability outside the relationship families (for example `console.access`, `access.roles.assign`, `identity.*` or `crm.*`).
- **Lifecycle.** At least one state exists. Every state key matches its shape. Initial states and transition targets are declared states, and no transition targets its own source. `qualifies` is a boolean on every state.
- **Fields.** Field keys are unique within the definition and match their shape. Each `value_type` is in the closed set (F7), and each constraint is valid for its type (a `max_length` within the type's ceiling; `choice` options unique and non-empty). `visibility` is `view` or `manage`.
- **Features and components.** Every feature is in the closed `features` set, and every Console `component` key is registered.
- **Verification.** Only the named operations appear: `intake`, `status`, `default_role` and `delete`. `delete` is always present when deletion is allowed.
- **Default role.** It is null or a case of Access's `ProvisionableRole`. A type that names one must list `intake`, `status` and `default_role` among its verified operations, because each of them can grant authority.
- **Unknown keys.** An unknown top-level or field-level key is refused, never ignored.

**What a definition can never contain.** Nothing in a definition is executable. Definitions are never a rules language. They name behaviour only through the closed `features` vocabulary and the Console's registered `component` keys.

The approved fields are shown in context in this abridged example, the Volunteer type:

```php
return [
    'type' => 'volunteer', 'slug' => 'volunteers', 'version' => 1,
    'labels' => ['singular' => 'Volunteer', 'plural' => 'Volunteers', 'description' => '…'],
    'states' => [
        'pending'  => ['label' => 'Pending',  'tone' => 'caution',  'qualifies' => false],
        'active'   => ['label' => 'Active',   'tone' => 'positive', 'qualifies' => true],
        'inactive' => ['label' => 'Inactive', 'tone' => 'neutral',  'qualifies' => false],
    ],
    'initial_states' => ['pending', 'active'],
    'transitions' => ['pending' => ['active', 'inactive'], 'active' => ['inactive', 'pending'], 'inactive' => ['active', 'pending']],
    'fields' => [
        'interests'    => ['value_type' => 'long_text', 'max_length' => 500, 'required' => false, 'visibility' => 'view', 'label' => 'Interests and skills', 'order' => 1],
        'availability' => ['value_type' => 'long_text', 'max_length' => 500, 'required' => false, 'visibility' => 'view', 'label' => 'Availability', 'order' => 2],
    ],
    'capabilities' => ['view' => Capability::ViewVolunteers, 'manage' => Capability::ManageVolunteers],
    'features' => ['directory', 'people.lookup', 'people.create_person', 'people.read_basics', 'people.edit_basics'],
    'verification' => ['delete'],
    'deletion' => true,
    'default_role' => null,
    'presentation' => ['position' => 20],
];
```

The Guardian definition differs where its policy differs: `'verification' => ['intake', 'status', 'default_role', 'delete']`, `'default_role' => ProvisionableRole::GuardianInitiate`, no `people.edit_basics`, and its own fields and states (G2, G4–G5). Capabilities and roles are referenced as Access's public enum cases, never as string literals, so no role key is ever written outside Access (A10).

**Persistence**

F6. **Four tables, all owned by `Relationships`:** an instance table, a history table, a metadata table and a provisioning-decision table (Part K). The relationship-managed role grants themselves are Access's table, `sourced_role_grants` (K4).

`person_relationships`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | The stable relationship identifier. Future relationship-linked records reference it |
| `person_id` | `char(26)` | Foreign key to `people.id`, `RESTRICT` (an invariant, [ADR 0021](0021-cross-module-referential-integrity.md)) |
| `relationship_type` | `string(32)` | F2. **`unique(person_id, relationship_type)`**, named `person_relationships_person_type_unique`: one relationship of each type per Person, enforced by the database, while a Person may hold several types |
| `status` | `string(16)` | A state key from the type's definition. A validated string, not a database enum. Index `(relationship_type, status)` |
| `revision` | integer | Starts at 1. Increments on every status or field change (F10) |
| `status_changed_at`, `status_changed_by_person_id` | `dateTime`, `char(26)` | When the current status began, and who set it (provenance, no foreign key) |
| `created_at`, `created_by_person_id`, `updated_at`, `updated_by_person_id` | | UTC. Provenance, no foreign key |

`person_relationship_status_changes`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `relationship_id` | `char(26)` | Foreign key to `person_relationships.id`, `RESTRICT`. Index `(relationship_id, changed_at, id)` |
| `from_status` | `string(16)`, nullable | `NULL` only on the row recording creation |
| `to_status` | `string(16)` | |
| `changed_at`, `changed_by_person_id` | | Provenance |

`person_relationship_field_values`

| Column | Type | Notes |
| --- | --- | --- |
| `relationship_id` | `char(26)` | Foreign key to `person_relationships.id`, `RESTRICT` |
| `field_key` | `string(64)` | A field of the type's definition. Primary key `(relationship_id, field_key)` |
| `value` | `text` | Canonical form for the field's value type (F7). Never empty: clearing a value deletes the row |
| `updated_at`, `updated_by_person_id` | | Provenance |

`person_relationship_role_provisions` (K3)

| Column | Type | Notes |
| --- | --- | --- |
| `relationship_id` | `char(26)` | Foreign key to `person_relationships.id`, `RESTRICT` |
| `role_key` | `string(64)` | The `ProvisionableRole` the decision is about: the type's default role when it was decided. Primary key `(relationship_id, role_key)` |
| `decision` | `string(16)` | `granted` or `declined`. A validated string |
| `decided_at`, `decided_by_person_id` | | The operator who made the decision, and when. Provenance, no foreign key |

A row exists only once an operator has decided. Its absence means "never decided".

**What the schema deliberately leaves out:**
- any contact or name column (decision 8);
- an Account id;
- a reason column (F10);
- a soft-delete flag;
- a JSON column;
- type-specific columns.

**Operational state.** If a type ever needs state that is operational or security-critical (a term end that controls eligibility, say), it gets a typed extension table keyed by `relationship_id`, with its own domain rules. Such state is never a metadata field. G10 has none.

**Migration order.** These migrations run after Identity's (ADR 0021).

**The alternatives that were evaluated:**

| Option | Verdict |
| --- | --- |
| Separate tables per type, with shared contracts | Rejected. Lifecycle, history, metadata and concurrency code would be duplicated for every type |
| One table with typed columns for every field of every type | Rejected. Every presentation field would be a migration |
| Metadata as a JSON column | Rejected. ADR 0037 measured `json()` diverging between engines, and a JSON blob invites unvalidated keys |
| **A shared instance table, typed and validated key-value metadata, and typed extension tables only for operational state** | **Chosen.** Uniqueness and lifecycle are relational. Fields need no migration. Validation lives in one place. Core queries stay plain SQL |

**Metadata**

F7. **Field value types are a closed set:**
- **`text`:** a single line, at most `max_length`, at most 255 characters.
- **`long_text`:** at most `max_length`, at most 2,000 characters.
- **`date`:** a calendar date with no time zone, stored as `YYYY-MM-DD`. Optional `not_after: today`, which refuses only a date later than the current date at UTC+14, the time zone furthest ahead. A date that is already "today" for an operator anywhere is accepted, and nothing depends on the server's or the browser's zone. The field is descriptive, so a one-day tolerance has no effect on authority.
- **`boolean`:** stored as `true` or `false`.
- **`choice`:** stored as an option key.

**Validation.** The domain validates every write against the current definition:
- **unknown key:** `422 unknown_relationship_field`;
- **wrong type, a constraint breach, or a missing required value:** `422 invalid_relationship_field`, naming the key;
- **text:** trimmed; control characters are refused, except line feeds in `long_text`;
- **visibility:** a field whose `visibility` is `manage` is returned only to holders of the type's manage capability;
- **type isolation:** a field always belongs to the stored relationship's own type, never to a type named in the request.

**The approved fields (N4).** All four are optional, have `view` visibility, and are descriptive: none of them affects roles, capabilities or eligibility.

| Type | Key | Value type and constraints | Label | Help |
| --- | --- | --- | --- | --- |
| Guardian | `recognized_on` | `date`, `not_after: today` | Guardian since | When this person became a Guardian, if known. The record's own date is when it was entered |
| Guardian | `stewardship` | `text`, at most 200 | Area of stewardship | |
| Volunteer | `interests` | `long_text`, at most 500 | Interests and skills | |
| Volunteer | `availability` | `long_text`, at most 500 | Availability | |

F8. **Applying a definition change safely.** These are the procedures that operator configuration would also follow.
- **Presentation properties:** free to change. The version number bumps.
- **Adding a field:** additive; existing relationships simply have no value.
- **Retiring a field:** it is hidden and no longer writable, and its values are kept until an explicit cleanup migration.
- **Changing a released field's key, value type or visibility:** a data migration in a release, with a test.
- **Tightening a constraint:** first run `relationships:check` (F15), which lists the stored values that would now fail. Those values stay readable, and any edit must satisfy the new rule.

Values carry no definition version. A field's key and value type change only through a migration, so a stored value's meaning cannot drift under it.

**Lifecycle and concurrency**

F9. **Each type defines its lifecycle; the rules around it are shared.**
- States, initial states and transitions come from the definition.
- Creation must use an initial state, and a change must be an allowed transition.
- Asking for the current state succeeds and changes nothing.
- **Deactivation** keeps the relationship's id, metadata, history, provenance and provisioning decision (D5), and withdraws its relationship-managed grants (K7).
- **Reactivation** moves the same row back. A duplicate is impossible because of the unique index. A grant is restored only by a fresh, authorized decision (K8).

F10. **Optimistic concurrency, instance identity and row locks.** This is the Resources pattern (ADR 0037, decisions 56–57), with the instance named as well (AR8).
- Every mutation of an existing relationship states the `relationship_id` it was shown and the `revision` it was based on. That covers status changes, field edits, default-role decisions and deletion.
- A basic-detail edit (P5) states the `relationship_id` that puts the Person in scope, but no revision. The details are CRM's data, guarded by their own `{from, to}` compare-and-set, and editing them does not change the relationship's revision.
- The use case then, in one transaction:
  - locks the row for `(person, type)` (`SELECT ... FOR UPDATE`);
  - compares the instance id, then the revision;
  - writes the change, its provenance and `revision + 1`;
  - for a status change, writes exactly one history row with the true `from_status`.
- **A mismatch writes nothing.** An instance id that is not the current one, or a stale revision, is `409 stale_revision` carrying the current state, including the current `relationship_id`. A relationship that was deleted and then recreated starts again at revision 1, so the id is what stops a mutation meant for the old instance from landing on the new one. With no relationship at all, the answer is `404 relationship_not_found`.
- Deletion follows the same rule: `DELETE` carries `relationship_id` and `revision`, and a mismatch deletes nothing.

**What history records.** The history is complete by construction: one row for creation and one for each status change, committed with the change itself.
- Field edits record provenance only, with no history of values. This follows CRM's precedent for descriptive data.
- There is no reason field. Its likeliest content is sensitive, and everyone with view access reads the history. A narrative belongs in CRM notes.

**History and audit**

F11. **Status history is business history, not a security event.**
- This follows Membership (ADR 0028) and CRM (ADR 0034, decision 17).
- Creating a relationship, changing its status and editing its fields call no audit seam of Relationships' own.
- The one exception in Relationships is permanent deletion (F12).
- **Grants are audited by their owner.** When a lifecycle change makes or withdraws a relationship-managed grant, Access records `role.granted` or `role.revoked` in the same transaction, with its source in the event's context (K10). That is Access's audit, not Relationships'.

**Permanent deletion (N3)**

F12. **Permanent deletion is deliberate, verified, atomic and audited.**

*Authorization.* The type's manage capability, then recent verification (`security.verified`). The definition must allow deletion; both G10 types do.

*Refusals.* A refusal deletes nothing and records nothing.
- **Dependents:** `409 relationship_in_use`.
  - The use case consults the module's dependents registry. Each future module whose records reference a relationship registers a check there, with its own retention rule, and holds a `RESTRICT` foreign key to `person_relationships.id` as the backstop.
  - Deletion never cascades into another module's data.
  - G10 registers no dependents.
  - Relationship-managed role grants are **not** dependents: deletion withdraws them, as step 2 says, because a grant must never outlive its source.
- **Missing relationship:** `404`.
- **Stale instance or revision:** `409 stale_revision` (F10).
- **Missing capability or verification:** the ordinary refusal.

*The transaction.* All of this happens in one transaction:
1. Lock the relationship row, and check the instance id and the revision.
2. Withdraw every grant sourced from this relationship, through `Access\Application\WithdrawSourcedRoles` (K6). Access deletes its `sourced_role_grants` rows and records `role.revoked` for each, in this transaction. Independent `role_assignments` are never touched.
3. Delete the provisioning decision, the field values, the status history and then the relationship itself. Children go first, because of the `RESTRICT` keys.
4. Record one security event, `relationship.deleted`, through `Audit\Application\RecordSecurityEvent`, with outcome `success`. It holds:
   - `actor_account_id`;
   - `subject_person_id`;
   - `occurred_at`, which is the seam's own time;
   - a context of `relationship_id`, `relationship_type`, the status at deletion, and the counts of history rows, field values and withdrawn grants removed.

   It never holds a field value, a label, a name or a contact detail. The ADR 0037, decision 55, precedent applies: the event records the act and copies none of the content.
5. If any step fails, including writing either event, nothing is deleted and no grant is withdrawn.

*What deletion never touches:*
- the `people` row;
- CRM data;
- the Person's other relationships, or the grants sourced from them;
- independent role assignments, and Accounts.

*Afterwards:*
- The Person may later receive a new relationship of the same type. That is a new instance and not a reactivation.
- Any scope that came from the deleted relationship (Part P) ends with it.

*Deletion is not deactivation.* Deactivation and reactivation stay non-destructive (F9).

*Audit wiring.* `MembershipTrustBoundariesTest`'s list of callers gains `Relationships\Application\DeleteRelationship` by name. A Relationships boundary test pins that this is its only use of `Audit`. Grant events are recorded by Access, which is already on the list.

**Presentation and eligibility contracts**

F13. **The presentation contract.** The server describes relationships in three shapes. All are built from definitions, filtered by the Actor's capabilities, and versioned.

- **`RelationshipTypeView`**, from `GET /admin/relationship-types`. It covers each type for which the Actor holds the view **or** the manage capability, and contains:
  - `type`, `slug`, labels and `definition_version`;
  - the states, initial states and transitions;
  - the fields the Actor may see;
  - `features` and `presentation`;
  - `default_role`: whether the type has one, and its display name from Access's role descriptor. Never a capability list;
  - `actions`: which of `view`, `manage`, `delete` and `provision_default_role` this Actor may attempt. `provision_default_role` is true only when the Actor holds the type's manage capability *and* Access's role-assignment authority (K5). This is a hint for the Console; the server still decides every request.
- **`RelationshipView`**, the record's read for the view capability. It contains:
  - the `relationship_id`, the type and `definition_version`;
  - the Person's id and display name, and their basic details where the type reads them (P2);
  - the status (key, since, by), the `revision` and when and by whom it was created;
  - the visible field values;
  - the history;
  - the default-role state, where the type has one: the decision (`granted`, `declined` or none), who decided and when, and whether a grant from this relationship is currently in effect. Never the Person's other roles.
- **`RelationshipManagementView`**, the management read for the manage capability (A4). It contains:
  - the `relationship_id`;
  - the Person's id and display name;
  - the basic details, only where the type edits them (P5);
  - the status, the `revision` and the allowed transitions;
  - every field value;
  - the default-role state, as above.

  It contains no history and no provenance beyond what management needs.

Provenance names are resolved by `FindPeople`.

**Extending to Member or Partner later (D8).**
- **Partner** is a new type: a registry entry, a definition, capabilities and routes.
- **Member** keeps Membership's model. A later gate may add a read-only adapter that produces `RelationshipView` from `GetCurrentMembership`, so that the Person record can show it through the same contract.

F14. **The eligibility read.** `Relationships\Application\QualifyingRelationships(PersonId): list<RelationshipType>` returns the catalog types whose current status `qualifies`.
- It authorizes nothing. Its caller passes a Person it has resolved from current authenticated identity (R10).
- It reads the current rows on every call. Nothing is cached.
- Rows of a type the catalog does not know are ignored.
- Its only consumer is Resources' `AudienceEligibility` (R10), and an architecture test pins that.

F15. **The integrity check.** `php artisan relationships:check` is read-only and exits non-zero on any finding. It reports:
- stored relationship types, states or field keys the catalog does not know (F2, F7);
- stored field values that fail their current definition (F8);
- relationship-managed grants without a matching cause, read through Access's `ListSourcedRoleGrants` and never from Access's table. A grant is reported when any of these holds:
  - its relationship is missing;
  - its relationship is not in a qualifying state;
  - its relationship's decision is not `granted`;
  - its role is not the type's current default role;
  - **its `person_id` is not its relationship's `person_id`**. A grant must never be effective for any Person but the subject of the relationship that is its source (K4, K11);
- a `granted` decision on an Active relationship whose Person has no matching grant.

The check joins Access's answer with Relationships' own rows in Relationships. `ListSourcedRoleGrants` returns each grant's `person_id`, role, source and attribution, and Access never asks Relationships anything. That is the existing `Relationships → Access` edge, with no new dependency and no cycle (A8).

It is part of the release checklist for any release that changes a definition, and of the adoption runbook (M4). It writes nothing; a correction is a reviewed operator action or a migration.

### Part G — The Guardian relationship

G1. **Guardian is a relationship type** (D2). This amends ADR 0036, decision 7.
- A Guardian is a Person with a `guardian` relationship. Nothing else in the platform says who is a Guardian.
- The relationship coexists with a Volunteer relationship and, later, Membership or Partner.
- The Guardian-named **roles** (`guardian-initiate`, `guardian-full`, `guardian-senior`; AR1) are bundles of capabilities and nothing more. After G10:
  - holding any of them is no evidence of being a Guardian;
  - lacking all of them is no evidence of not being one (Part M);
  - a `guardian-initiate` grant sourced from a Guardian relationship (Part K) is evidence of an operator's provisioning decision, not of current Guardian status. Nothing reads it as such: eligibility asks the relationship (R10).

G2. **The Guardian lifecycle** (N2):

| | |
| --- | --- |
| States | `active` (qualifies) and `inactive` (does not qualify) |
| Initial states | `active` (recognition), or `inactive` (recording a former Guardian, so that history can be complete from the start) |
| Transitions | `active` ↔ `inactive` |
| Deactivation | Stepping down. Fields and history are kept |
| Reactivation | Returning, on the same relationship |
| Deletion | Allowed, with verification (F12) |

- **No Pending state.** Nomination and approval belong to a deferred approval capability (ADR 0009).
- **No trainee, junior or seniority state.** A junior or trainee Guardian is an Active Guardian whose Account holds whatever restricted roles the operators assign, typically `guardian-initiate` (AR1). Any authorized combination of roles and capabilities is valid for an Active Guardian. Seniority is expressed by roles, never by a relationship state.

G3. **Affiliation is not authority** (D3).
- A Guardian relationship, by itself, grants nothing:
  - no capability;
  - no `console.access`;
  - no Resource viewing;
  - no Guardian management.
- The one path from the relationship to a role is an authorized operator's explicit decision to provision the default role (G7, Part K). That decision is made by someone with role-assignment authority, is attributed, and is withdrawn by the relationship's lifecycle. It is not a property of being a Guardian.
- Authorization never consults the Actor's own relationships (A9).
- A Guardian changes another Guardian's relationship only by holding `guardians.manage`, exactly as a non-Guardian operator would.

G4. **Guardian fields** (N4): `recognized_on` and `stewardship`, both optional and descriptive (F7). Guardian eligibility derives from the lifecycle state alone (R10).

G5. **Guardian features:**
- Enabled: `directory`, `people.lookup`, `people.create_person`, `people.read_basics`.
- Not enabled: `people.edit_basics`.
  - **What this means.** `guardians.manage` alone does not edit a Person's name or contact details. Those are maintained through CRM (`crm.people.manage`), or through a Volunteer scope where the Person is also a Volunteer (P5).
  - **Why.** Guardian management is about recognition, and no product decision asks Guardian managers to maintain contact data.
  - **How to change it later.** Enabling the feature for Guardians is a "Controlled" change (F4).

G6. **Guardian capabilities** (N1): `guardians.view` and `guardians.manage`, independently assignable (A1–A6). Recognition, deactivation, reactivation, default-role decisions and deletion require recent verification. Field edits do not (A5).

G7. **Guardian default role** (AR2): `guardian-initiate`, offered on Active recognition and on every transition into Active, and through the default-role decision while Active (K5–K8). It is never applied by an operator who lacks role-assignment authority, and never suppressed because the Person holds other roles (K9).

### Part V — The Volunteer relationship

V1. **Every approved Volunteer decision is preserved:**

| Approved | Where |
| --- | --- |
| Belongs to a Person; no Account required; grants no role or Console access (1) | F1–F2, F6, A8 |
| Pending, Active, Inactive; changed directly; no applications or approvals (2) | V2 |
| Only Active qualifies for `volunteer` (3) | V2, R10 |
| One ongoing relationship per Person; reactivation, not duplication; auditable transitions (4) | F6, F9–F11 |
| Person-record home and a Volunteer directory (5) | C2–C5 |
| `volunteers.view`/`volunteers.manage`, independent of CRM, assignable by role (6) | A1–A4 |
| No broad CRM access; scoped lookup, intake and basic-contact upkeep; viewers read the basics of Volunteers in any state (7–9) | Part P |

V2. **The Volunteer definition:**

| | |
| --- | --- |
| States | `pending`, `active` (qualifies) and `inactive` |
| Initial states | `pending` or `active` |
| Transitions | Every transition between two distinct states. A restricted graph would be approval in disguise (ADR 0009) |
| Features | `directory`, `people.lookup`, `people.create_person`, `people.read_basics`, `people.edit_basics` |
| Fields (N4) | `interests` and `availability`, optional and descriptive |
| Verification | For deletion only (A5) |
| Default role | None (AR2). A Volunteer relationship never results in a role |

V3. **What changed for Volunteers since the first version:**
- the relationship lives in `Relationships`;
- the routes are under `/admin/relationships/volunteers`;
- verified, audited deletion exists;
- there are two descriptive fields;
- `volunteers.manage` no longer requires `volunteers.view` in the role catalog (A4);
- the basic details of a Volunteer who is also a Guardian are editable within the Volunteer scope (N5).

Everything else approved in decisions 1–9 is unchanged.

### Part P — Relationship-scoped People access

P1. **CRM's delegated seam: narrow, relationship-agnostic, and with one caller.**
- **What it is.** CRM exposes `Crm\Application\Delegated`, a set of Application services authorized by their caller. This follows the precedent of `RenamePerson`, which "never learns which capability [its caller] checked".
- **Who may call it.** Its only permitted caller is `Relationships\Application`. An architecture test pins this (A10).
- **What it does not do.** It knows nothing of relationship types, names no capability, and no HTTP class reaches it.
- **Why it is not a backdoor.** Its operations are fixed to the five below. Each reads or writes only the three basic details or a minimal new Person. Every call is preceded by Relationships' authorization and scope check (P3). The scope can be established by the same manager through intake, which this design accepts openly (P8).

| Service | Does |
| --- | --- |
| `ReadPersonBasics(list<PersonId>)` | Batched: id, display name, primary email `value`, primary phone `value`, exactly as stored. Nothing else |
| `PeopleMatchingContact(string $text, list<PersonId> $within)` | Ids among `$within` whose CRM email contains the text, or whose phone digits contain its digits (3 or more) |
| `FindPersonCandidates(string $query)` | The bounded lookup of P4 |
| `RegisterMinimalPerson(name, ?email, ?phone, bool $confirmDistinct, Actor $by)` | A new Person with at most one email and one phone, each primary, attributed to `$by` (P9). It gives `RegisterContact`'s duplicate advice and shares that implementation |
| `UpdatePersonBasics(PersonId, BasicsChanges, Actor $by)` | Compare-and-set of exactly the three basic fields, attributed to `$by` (P5, P9) |

The rejected alternatives stand: CRM authorizing relationship capabilities itself (a cycle, and CRM would have to know every relationship), direct table access, and copying contact data.

P2. **What a relationship's capability may read about a Person.** Only for a Person who holds a relationship of that type, in any state, and only where the type has `people.read_basics`. A manage-only holder reads them through the management view (A4).
- **It reads:** the display name, the primary email value and the primary phone value.
- **It never reads:**
  - other contact methods or labels;
  - profile fields, tags, notes or interactions;
  - Membership, Account, role or security data;
  - the Person's relationships of other types, unless the Actor holds those types' capabilities.

P3. **The scope rule.** Every Relationships use case enforces it, before any delegated call, from Relationships' own table:
1. **Capability.** The Actor holds the type's capability that the operation needs (A1, A4).
2. **Feature.** The type's definition enables the feature.
3. **Relationship.** For a read or an edit of basic details, the Person holds a relationship of **that** type, in any state, and the request names that relationship's current id (F10).
   - Otherwise the answer is `404 relationship_not_found`, whether or not the Person exists, so the routes cannot be used to test whether a Person exists.
   - The scope is the relationship itself. It does not depend on, and is not narrowed by, the Person's other relationships.
4. **Intake.** Lookup and minimal creation exist only for intake, which creates the relationship in the same transaction.
   - **Attaching an existing Person is a deliberate act.** An intake that names `person_id` must also carry `confirm_existing_person: true`. Without it the answer is `422 confirmation_required`, and nothing is created. The Console asks for it in a confirmation step that shows the Person's name and states that recording them lets the manager maintain their basic details. The confirmation prevents accidental attachment. It is not a defence against a manager who intends to misuse intake (P8).
   - Selecting an existing Person who has no relationship of the type establishes the relationship and changes nothing else.
   - No basic detail of a Person can be edited before the relationship exists.
   - The creation history row names the operator who recorded the relationship (F6), so who brought a Person into scope is always answerable while the relationship exists. After a deletion, the `relationship.deleted` event names who deleted it and whom it concerned.

The scope does not lapse while the relationship exists: Inactive relationships are included (decision 9). It ends with permanent deletion.

P4. **Limited lookup**, under the type's manage capability and `people.lookup`.
- **The query:** 3 to 255 characters, matched in one of three ways:
  - an address (it contains `@`) matches CRM contact emails exactly, by CRM's `search_value`, never Account logins;
  - a phone number (at least 7 digits) matches exactly on its digits;
  - anything else is a case-insensitive "contains" match on the display name.
- **The result:** at most 10 candidates and a `truncated` flag. Each candidate carries:
  - the Person id and display name;
  - `matched_on` (`email`, `phone` or `display_name`);
  - the candidate's status **in this type only**.
- **Never returned:** contact values, other relationship types, profile, tags, roles, Account information.
- **Ambiguous names.** Candidates who share a display name are indistinguishable by design, because the lookup returns no contact value. When a name search returns two or more candidates with the same name, the Console does not let the operator pick one by name. It asks them to search again by exact email or phone, which matches at most the Persons who hold that value. If no exact contact is known, the operator adds a new Person, and CRM's duplicate advice and later merge (ADR 0034) handle the rest. The server does not enforce this UX rule, because the confirmation (P3.4) and the provenance (P8) already make every attachment deliberate and answerable.

P5. **Scoped editing of basic details** (N5), under the type's manage capability and `people.edit_basics`. In G10 that means Volunteers.

The authorization is specific to the operation, the field and the scope.

- **The operation.** One use case, `UpdateRelationshipPersonBasics`. Nothing else in Relationships writes to People data.
- **The fields.** Exactly three:
  - the display name, through Identity's `RenamePerson`;
  - the value of the primary CRM email;
  - the value of the primary CRM phone.

  Each is sent as `{from, to}`, and at least one is required. Any other key in the request is `422`. A field that is not sent is left alone (partial update).
- **The scope.** The Person holds a relationship of this type, in any state, and the request names its current `relationship_id` (P3). A Person who is also a Guardian, Member or anything else is in scope on the same terms. Affiliation neither blocks nor widens what may be done.
- **The expected-value contract.**
  - `from` is compared, as an exact string, with what `ReadPersonBasics` returns now: the display name, or the primary method's stored `value`. `null` means "there is no primary of this kind". The Console sends back exactly the value it was shown, so a CRM user's change of case or spacing since then is a real change, and is reported.
  - `to` is validated and normalised by CRM's own rules for its kind (the name by Identity's). A `to` that is equal to `from` changes nothing for that field.
  - Clearing a primary is not offered: `to` may not be null or blank (`422`).
- **What a change does to CRM's records.**
  - Replacing a primary updates that method's `value` in place, under CRM's normalisation and validation.
  - Setting a primary where none exists adds a method and makes it primary.
  - **Collision with a hidden method.** The manager cannot see the Person's non-primary methods (P2). CRM matches contact values by `search_value` (an email lower-cased, a phone number reduced to its digits and a leading plus) but stores and returns the display `value` as entered. So two values can collide while being written differently: `Ana@Example.org` and `ana@example.org`, or `+1 (555) 010-0100` and `+1 555 010 0100`. When `to`'s `search_value` equals that of an existing non-primary method of the same kind on that Person, the scoped edit, holding the profile lock (below):
    1. validates and normalises `to` by CRM's rules for its kind, exactly as a plain replacement does;
    2. **promotes that method to primary** instead of adding a duplicate or failing;
    3. **sets the promoted method's stored `value` to the validated display form of `to`**, the same value a plain replacement would store. Its `search_value` is unchanged by construction, so both of CRM's unique indexes still hold;
    4. leaves the previous primary on the Person as a non-primary method. Only its primary flag changes;
    5. stamps provenance on both rows: the promoted method and the demoted former primary (P9).

    Nothing is deleted.
  - **What the manager observes is identical with and without a hidden match.** The response, and every later read through the seam, carries the display form of `to`, exactly as a plain replacement would. Nothing reveals that a hidden method existed: not its previous value or formatting, its id, its label, or the fact of a match. The scoped path never answers `409 duplicate_contact_method`. CRM's own screens keep their existing behaviour, and a CRM user still sees both methods.
  - Provenance is recorded on every contact method the edit creates, changes, promotes or demotes (P9).
- **Concurrency and lock order.** One transaction, locks taken in this order, which extends CRM's own `UpdatePerson` order:
  1. the relationship row (`FOR UPDATE`), to hold the scope against a concurrent deletion;
  2. the `people` row, inside `RenamePerson`, when the name is sent or compared;
  3. the Person's `contact_profiles` row, CRM's per-Person write lock, when a contact is sent.

  Every `from` is compared after its lock is held. Any mismatch writes nothing, in any field, and answers `409 stale_person_basics` with the three current values. The manager may already read those values, so the answer discloses nothing new. A concurrent CRM change is never silently overwritten. `RenamePerson` gains an optional expected-name argument for this.
- **Audit.** A name change records `person.renamed`, exactly as it does from CRM. Contact changes carry provenance (P9) and, like CRM's own, are not security events.

P6. **What scoped editing can never reach** (N5). These limits are pinned by tests:
- **Login identity and credentials.**
  - The Account's login email, its `email_canonical`, its password, its second factor, its recovery codes, its sessions and its security generation are Identity's.
  - Relationships has no dependency on any of these, and Part P names none of them.
- **Contact email is not login email.**
  - Changing a CRM contact email changes no login, invitation or recovery address. That is the existing separation (*Repository facts*), pinned by tests that do the edit and show the Account unchanged.
  - An architecture test pins that Identity never depends on CRM.
  - The Console's invitation form is never pre-filled from CRM contact data without an explicit operator action. A future change that wants to pre-fill it must bring a design decision of its own.
- **Roles, capabilities and Console admission.** These are Access's. No Volunteer operation provisions or withdraws a role: the Volunteer type has no default role (V2), and the definition schema would refuse a default role without its verified operations (F5). Only the Guardian lifecycle can reach Access's sourced-grant services (Part K).
- **Guardian relationship state, metadata and provisioning.**
  - A Volunteer route can only act on the Volunteer relationship: the type is taken from the route's literal segment (Part H), and a field is always validated against the stored relationship's own type.
  - Nothing in a Volunteer operation can recognize, deactivate, reactivate, edit, provision for or delete a Guardian relationship.
- **Other CRM data.** Other contact methods' values and labels, profile fields, tags, notes and interactions are untouched. The seam has no operation that writes them. The only effects on a method other than the primary being replaced are those of the hidden-collision rule in P5:
  - the promoted method's primary flag, its display `value` (to the validated `to`, with the same `search_value`) and its provenance change, and its label does not;
  - the demoted former primary's primary flag and its provenance change, and nothing else.

P7. **Minimal creation and intake**, under `people.create_person`.
- A new Person is created with CRM's duplicate advice: `409 possible_duplicate`, the candidates (id, display name and `matched_on`, as `RegisterContact` returns them), and `confirm_distinct` to proceed anyway.
- Nothing is merged or adopted (ADR 0034, decision 10).
- The new Person, their primary methods (with provenance, P9), the relationship and its creation history row commit together. CRM's work nests as a savepoint (T2).
- **Two different concurrency questions, two different answers:**
  - **One relationship for one existing Person.** Two intakes that attach the same existing Person to the same type race on `unique(person_id, relationship_type)`. Exactly one commits. The other answers `409 relationship_exists` and changes nothing.
  - **Duplicate Persons from independent intakes.** Two intakes that each create a *new* Person can never collide on that index, because each creates its own Person. Whether they describe the same human is CRM's duplicate advice, which is checked before the transaction and is advisory, not race-proof, exactly as in `RegisterContact`. Duplicates that slip through are resolved later by CRM, not prevented by a lock.

P8. **Accepted consequence: a Volunteer manager can bring a Person into scope** (M1, AR3). This is approved, and it is stated here so that no other part of this ADR overstates the scope rule.
- **What it means.** A `volunteers.manage` holder may look up any Person (P4), record them as a Pending Volunteer without recent verification, and from then on edit their display name, primary CRM email and primary CRM phone. That includes a Person who is also a Guardian, and a Person who holds an Account, including an administrator. The scope rule (P3) does not prevent this, because the same capability that edits within scope establishes the scope.
- **Why it is acceptable.** The three fields carry no authority. Login identity, credentials, roles, capabilities, Console admission, Guardian state and every other CRM field stay out of reach (P6). In G10 every holder of `volunteers.manage` (the `guardian-full`, `guardian-senior` and `platform_administrator` roles) also holds `crm.people.manage`, so the path adds no power that anyone has today. It matters only for a future role with Volunteer management but no CRM access, and that role would be a deliberate catalog change.
- **The safeguards that actually exist:**
  - the deliberate confirmation for attaching an existing Person (P3.4), against accidents only;
  - creation provenance on the relationship and its first history row;
  - contact-edit provenance on every changed method (P9) and `person.renamed` for names;
  - the explicit three-field allowlist and CRM's canonical validation (P5);
  - compare-and-set on every field (P5);
  - instance-and-scope checks on every request (P3, F10);
  - privacy-limited lookup with no contact values (P4);
  - non-disclosing failures: `404 relationship_not_found` outside scope, and no duplicate oracle (P5).
- **What it deliberately does not add.** No recent verification on ordinary Volunteer intake. No blanket exclusion of Guardians or Account holders (N5). Relationship types with stricter initiation, such as Member or Partner later, define their own initiation policy (*Future architecture*). This consequence is not a universal rule for every type.

P9. **Contact-method provenance** (M2, AR4). CRM owns it, and WP3 builds it.
- **Schema.** `contact_methods.updated_by_account_id`, `char(26)`, nullable, with no foreign key. It is provenance (ADR 0021), as `contact_profiles.updated_by_account_id` is. Existing rows keep `NULL`, which means "before provenance was recorded". There is no backfill, because no truthful source for it exists.
- **Who writes it.** The rule is one sentence: **every row whose value, label or primary flag changes is stamped with the acting Account, in the same transaction and under the same profile lock as the change.** That includes rows changed as a side effect. Every path that does so today or in G10:

  | Path | Rows stamped |
  | --- | --- |
  | `RegisterContact` and `AddContactMethod`, through `ContactMethodWriter::add` | The new method. Where it becomes primary, also the former primary that `clearPrimary` demotes |
  | `UpdateContactMethod` | The changed method. On a primary change, also the method it demotes |
  | `RemoveContactMethod`, when it removes a primary | The earliest remaining method of that kind, which it promotes to primary |
  | Delegated `RegisterMinimalPerson` | Each new method |
  | Delegated `UpdatePersonBasics` (P5) | The replaced or added primary. On a hidden collision, the promoted method and the demoted former primary |

  `clearPrimary` and any similar bulk update take the acting Account, so a demotion can never be written without its provenance. A future path that changes a contact method joins this table.
- **What it never does.** It is never inferred from `contact_profiles.updated_by_account_id`, and an edit of profile fields never stamps a contact method. `contact_profiles.updated_by_account_id` keeps its meaning: the last editor of the profile fields. A row that does not change is never stamped.
- **Concurrency and ownership are unchanged.** CRM owns the column and every write to it. Every stamp happens under the Person's `contact_profiles` lock, which every one of these paths already takes.
- **Removal.** `RemoveContactMethod` deletes the row, as today. The row's provenance goes with it, and no new audit event is added: CRM data is not security data (ADR 0034). A method it promotes is stamped, as in the table. The scoped seam never removes a method.
- **Surfaces.** CRM's Person record may show "last changed by" for a method. That is a CRM UI decision and is not required by G10.
- **Tests (WP3).** Regression tests prove, for every row of the table, the right actor on every changed row and no stamp on unchanged rows. They also prove a `NULL` for legacy rows, and that a profile edit never stamps a method. A mutation check drops the stamp from the demotion in `clearPrimary`, and one drops it from `RemoveContactMethod`'s promotion. A test must catch each.

### Part A — Authorization

A1. **Four new capabilities, one pair per type, all independently assignable** (N1).

| Capability | Authorizes |
| --- | --- |
| `guardians.view` | The Guardian directory. Reading any Guardian relationship (status, dates, history, visible fields) and its Person's basic details (P2). Changes nothing |
| `guardians.manage` | Lookup and intake (recognition). Status changes (deactivation and reactivation). Field edits. Default-role decisions, where provisioning a grant also needs Access's own authority (K5). Permanent deletion. The supporting management reads in A4 |
| `volunteers.view` | The Volunteer directory, and reading any Volunteer relationship and its Person's basic details (decision 9). Changes nothing |
| `volunteers.manage` | Lookup and intake, status changes, field edits, scoped basic-detail edits (P5), permanent deletion, and the supporting management reads in A4 |

**Naming.** The names follow the two-part form of `discussions.view` and `resources.view`.

**Why per type and not shared.** Managing Guardians and managing Volunteers carry different weight and have different natural holders. A Volunteer Coordinator should not, through one shared grant, recognize Guardians.

**Where they come from.** Each pair is added to Access's `Capability` catalog by the package that first checks it (ADR 0017). Each type's definition names its pair.

A2. **Independence.**
- No capability implies another:
  - `guardians.view` and `guardians.manage` are independent;
  - `volunteers.view` and `volunteers.manage` are independent;
  - `resources.view` and `resources.manage` remain independent;
  - none of them implies, or is implied by, a CRM capability.
- **Each Relationships use case authorizes exactly one capability, taken from the validated definition.** The use cases are generic, so a use case names no capability case itself. It asks for the stored or routed type's `view` or `manage` capability, read from that type's `RelationshipDefinition`. Which of the two each operation needs is fixed in code, not in the definition. A literal scan, the method `CrmBoundariesTest` uses, therefore cannot prove this alone. The proof is in three parts:
  - **Runtime isolation (WP1).** For every type in the catalog, including the third test type, and every operation, a `Gate::before` test holding exactly the expected capability succeeds. The same test with any other single capability is refused `403`, and so is each other type's capabilities, `access.roles.assign` and every CRM capability. A mutation check that makes an operation use another type's definition, or the other half of the pair, must be caught.
  - **Static pins (WP1).** No `Relationships\Application` or `Relationships\Http` class names a `Capability` case, with exactly one exception (next bullet). Every capability used for authorization comes from a definition, and definitions name only their own pair (F5).
  - **The route table (WP1).** Each generated route carries exactly the type's capability in its `can:` middleware (A5, A10).
- **The one exception: the types use case** (`DescribeRelationshipTypes`, behind `GET /admin/relationship-types`, A6).
  - It authorizes nothing beyond Console admission at the route. It filters types by the Actor's view and manage capabilities, read from the definitions.
  - To compute `actions.provision_default_role` (F13), it asks Access whether the Actor holds `access.roles.assign`. That is the only place Relationships names `Capability::AssignRoles`.
  - The answer is a hint for the Console and is never used to authorize. Provisioning itself is still authorized by Access inside `GrantSourcedRole` (K5).
  - A static pin limits that name to this one class, and a test shows the hint has no effect on any mutation's outcome.
- **Provisioning authority is Access's own check, not a second Relationships capability.** When a Guardian operation asks for a grant, the Relationships use case still asks only for `guardians.manage`. Access's `GrantSourcedRole` then asks for `access.roles.assign` itself, against the same Actor, as `GrantRole` does (K5). Each module authorizes its own act.
- The Console infers no capability from another.

A3. **The role catalog and the initial capability mapping** (N1, AR1). These are defaults of the catalog, not rules. Roles are additive: an Account's capabilities are the union of every role it holds, independent or relationship-managed.

| Role key | Display name | Capabilities |
| --- | --- | --- |
| `platform_administrator` | Platform administrator | Every capability, present and future (derived, unchanged) |
| `guardian-full` | Guardian | Exactly today's `guardian` bundle: `console.access`, `crm.people.view`, `crm.people.manage`, `discussions.view`, `discussions.participate`, `resources.view`, `resources.manage`. Plus, from WP1: `guardians.view`, `volunteers.view`, `volunteers.manage` |
| `guardian-senior` | Senior Guardian | Initially identical to `guardian-full`. It has no approval, waiver, financial, subscription or administrative capability, and does not imply Platform Administrator. Later capabilities may tell it apart once their workflows are designed |
| `guardian-initiate` | Guardian Initiate | `console.access` only |
| `console-participant` | Console Participant | `console.access` only |

The new capabilities, by role:

| Role | `guardians.view` | `guardians.manage` | `volunteers.view` | `volunteers.manage` |
| --- | --- | --- | --- | --- |
| `platform_administrator` | ✓ (derived) | ✓ (derived) | ✓ (derived) | ✓ (derived) |
| `guardian-full`, `guardian-senior` | ✓ | — | ✓ | ✓ |
| `guardian-initiate`, `console-participant` | — | — | — | — |

- **Two restricted personas exist from G10** (M5). `guardian-initiate` and `console-participant` admit to the Console and grant nothing else: no `resources.view`, no `resources.manage`, no CRM, no Discussions. What such a user reads in the Resource Library comes only from their own relationships (policy C, R14). Holding `console.access` makes the second factor mandatory for them too ([ADR 0023](0023-multi-factor-authentication.md)).
- **No hierarchy.** No code compares roles, ranks them, or treats one as containing another. `guardian-senior` is not "more than" `guardian-full` in any code path; it is a separate bundle that happens to be equal today.
- A future role may hold any subset: a Volunteer Coordinator with `volunteers.manage` only, or a Guardian Steward with `guardians.manage` only. Either is a role-catalog change, and no such role is created here.
- `CatalogTest` pins the role list and this mapping, so changing either is visible and deliberate.

A4. **Supporting reads for manage-only holders** (N1, CR). Management grants the reads that managing needs, deliberately and narrowly, and never the view capability:
- **The candidate lookup (P4).** It is how a manage-only holder finds the Person they will act on, including someone who already holds a relationship of the type.
- **The management read** (`RelationshipManagementView`, F13) of one relationship. It is what an edit form, a status change or a compare-and-set needs: status, revision, allowed transitions, field values, and the three basic details where the type edits them.
- **Write responses.** These return the same management view.
- **The type's definition**, through `GET /admin/relationship-types`, which includes types the Actor may only manage.

A manage-only holder therefore gets no directory, no history, no `RelationshipView`, and no CRM read.

**The role-catalog pairing.** G10 adds no "manage implies view" pin for the new pairs. The existing pins for CRM and Resources (`CatalogTest.php:148,189`) hold for every role of the new catalog: `guardian-full` and `guardian-senior` grant both halves of each pair, and the restricted roles grant neither. They remain as G1 and G5 left them, with their positive controls updated to the new role list. A role that separates those pairs would revise its pin deliberately, and nothing in this design depends on the pins.

A5. **Recent verification: the exact contract** (N1, AR2).

| Operation | Guardian | Volunteer |
| --- | --- | --- |
| Intake: establishing a relationship, with or without creating a minimal Person | **Required.** Recognition confers Guardian eligibility and may provision a role | Not required |
| Status change (for Guardians this is always a deactivation or a reactivation) | **Required.** It withdraws or may provision a role | Not required |
| Default-role decision while the relationship exists (K8) | **Required.** It grants or withdraws a role | Not offered (no default role) |
| Field edit | Not required: descriptive data, no effect on authority or eligibility | Not required |
| Basic-detail edit (P5) | Not offered for Guardians (G5) | Not required, as for CRM maintenance (ADR 0034) |
| Permanent deletion | **Required** | **Required** |
| Lookup and every read | Not required | Not required |

How the routes enforce this:
- Both `can:guardians.manage` and `can:volunteers.manage` join the step-up exemption list, each pinned to `Relationships\Http`.
- The exemption means only that a route under the capability *may* skip verification. Which routes carry `security.verified` after the capability is fixed by this table.
- A Relationships route-table test pins this table, exactly as `ResourcesRoutesTest` pins Resources' two verified deletions.
- Each type's definition lists its verified operations (`verification`), and the routes are generated from it. The test compares the generated routes with this table.

A6. **Route exceptions to "one capability per admin route".** Exactly these GET routes carry `stateful`, `auth:web` and `can:console.access` and no other capability:
- `GET /admin/relationship-types` (F13). It describes several types to one Actor. Its use case returns only the types the Actor may view or manage, and no Person data.
- The three audience-library routes of R14, which serve audience-based reading in the Console. Their authority is the Actor's own relationships, which no capability expresses.

`AdministrationRoutesTest` gains these four as a pinned list naming this ADR. Every other admin route keeps the rule. Each exception is a read: there is no mutation without a capability.

A7. **Console admission is independent.**
- `console.access` comes only from Access grants: an independent role assignment, or a relationship-managed grant that an authorized operator chose to provision (Part K).
- A relationship never confers it by itself, and no route described here relaxes it.
- A Person with an Active Guardian relationship and no `console.access` cannot enter the Console. Their eligibility applies only on a future surface (R14).
- `console-participant` and `guardian-initiate` admit to the Console without any relationship. Admission is not eligibility: with no qualifying relationship, such a user reads no Resource at all (R12).

A8. **The one-way rule between relationships and roles** (D1, AR2; ADR 0036, decision 5).
- **A role never establishes a relationship.** No role, grant, capability or Console admission creates, implies, activates or keeps a relationship. Nothing reads a role as evidence of a relationship.
- **A relationship results in a role only through Part K.** Only the Guardian lifecycle, only for `ProvisionableRole` cases, only by an operator's explicit decision that Access authorizes, and only through Access's sourced-grant services. Every such grant is attributed to its relationship instance and withdrawn by it.
- **Relationships never touches Access's persistence.** It never reads or writes `role_assignments` or `sourced_role_grants`, never names `Access\Application\Role` or a role key, and never calls `GrantRole`, `RevokeRole` or their Account forms.
- **Access never depends on Relationships.** The module graph allows `Relationships → Access` and never `Access → Relationships`. Access neither reads relationship state nor asks Relationships anything when it authorizes. Consistency comes from making and withdrawing grants in the same transaction as the lifecycle change (K11).
- **`/me` changes only through Access grants.** A relationship change with no provisioning effect (a Volunteer change, a Guardian field edit, a Guardian intake whose operator declined the default role) leaves `/me` identical. A test pins both directions: unchanged without a grant, and changed exactly by the grant's capabilities with one.

A9. **Authorization never consults the Actor's affiliation** (G3).
- No use case's authorization depends on whether the Actor is a Guardian or a Volunteer.
- Relationship authorization never names a role: no Guardian-named role is hardcoded in any authorization decision.
- The general future direction for grants derived from relationships is described under *Future architecture*. Part K is the only form built in G10: an explicit, attributed, stored grant, not a rule the `Authorizer` evaluates.

A10. **Architecture-test changes, exactly** (M3, AR5). Each change keeps its protection, replaces it with an equal or stronger one, or narrows it to name the new legitimate use. The test that pins each one is built by the package named.

| Test | Today | After G10 | Package |
| --- | --- | --- | --- |
| `AccessBoundariesTest`, role-key literal guard (lines 143–178) | Role keys `platform_administrator` and `guardian` are forbidden as literals outside Access; one exemption, `'guardian'` in `Resources/Domain/Audience.php` | The guarded keys become the new catalog: `platform_administrator`, `guardian-initiate`, `guardian-full`, `guardian-senior`, `console-participant`. `guardian` is no longer a role key, so the relationship definition, the Resources eligibility mapping and the audience catalog may hold `'guardian'` as a type or audience key. The `Audience.php` exemption and its positive control are removed, because they are no longer needed. New positive controls assert that the scan sees each new key in `Role.php` | WP2A |
| *New:* retired role key | — | `Role::tryFrom('guardian')` is `null`. After the migration no `role_assignments` row holds `guardian` (a migration test). A stored `guardian` key grants nothing (the `Authorizer`'s existing fail-closed rule, re-proved) | WP2A |
| *New:* role identity stays inside Access | `Role` is "internal to Access" by comment and by the literal guard | An `arch()` test: nothing outside Access uses `Access\Application\Role`, `RoleAssignmentRepository`, `GrantRole`, `RevokeRole`, `GrantRoleToAccount`, `RevokeRoleFromAccount` or `ConsoleUserFixture`, except the existing development seeders. This is the guard against identity inference | WP2A |
| *New:* sourced-grant services have one caller | — | `Access\Application\GrantSourcedRole` and `WithdrawSourcedRoles` are used only by `Relationships\Application`. `ProvisionableRole` and `ListSourcedRoleGrants` are used only by Access and Relationships. This is the guard that keeps the provisioning integration narrow | WP2B |
| *New:* authorization is not affiliation | — | Each Relationships use case authorizes exactly one capability, from the validated definition, proved by runtime isolation for every type and operation (A2). Static pins: no `Relationships\Application` or `Relationships\Http` class names a `Capability` case except `DescribeRelationshipTypes`, which names `AssignRoles` for its hint only. No use case outside Relationships calls `QualifyingRelationships` except `Resources\Application\AudienceEligibility` (F14). Resources uses Access only for `AuthorizeAction`/`Capability` | WP1, WP2B (the hint), WP4 |
| `AccessBoundariesTest`, module graph (lines 238–260) | Frozen edges, no `Relationships` | Adds `Relationships → Access, Identity, Crm, Audit` and `Resources → Relationships, Membership`. No edge into `Access` from Relationships' side is reversed: `Access → Relationships` stays forbidden | WP1 (Relationships), WP3 (`Crm`), WP4 (Resources) |
| `CatalogTest` | Role list is exactly two roles; manage-implies-view pins for CRM, Discussions and Resources cover `platform_administrator` and `guardian`; no role named after a relationship | Role list is exactly the five of A3. The three pairing pins keep their rule; their positive controls name `platform_administrator`, `guardian-full` and `guardian-senior`. The A3 mapping is pinned. `ProvisionableRole` is exactly `guardian-initiate`, never `platform_administrator`, and no provisionable role grants `access.roles.assign` or any `identity.*` capability (standing invariants), or `resources.view` or `resources.manage` (G10 policy, R4; changing it is a deliberate, separately reviewed decision). "No role named `member` or `volunteer`" stands | WP2A (roles and `ProvisionableRole`, which WP2A builds), WP1 (new capabilities) |
| `RoleAssignment::KEY_SHAPE` and the revoke route's `{key}` pattern | `^[a-z][a-z0-9_]{0,63}$` | `^[a-z][a-z0-9_-]{0,63}$`, so the approved hyphenated keys are storable and revocable. A unit test keeps refusing upper case, spaces, leading digits and over-long keys | WP2A |
| `MfaBoundariesTest` (lines 157–164) | Pattern names `platform_administrator` and `'guardian'` | Pattern names every key of the new catalog, so Identity still names no role | WP2A |
| Console `src/guardrails.test.ts`, the "system role names" rule (line 120) | Forbids `platform_administrator`, a quoted `'guardian'`, `isGuardian`/`isAdmin`-style helpers and `.roles`/`roles:` in Console source; one exemption, `api/resources.ts`, for the `guardian` audience | The rule names each of the five role keys as a quoted literal: `platform_administrator`, `guardian-initiate`, `guardian-full`, `guardian-senior`, `console-participant`. Today's pattern matches only a quote directly after `guardian`, so on its own it would miss the hyphenated keys. The helper and `.roles` patterns stay. The quoted `'guardian'` stays forbidden outside `api/resources.ts`: it is no longer a role key, but it is the audience key and the relationship type key, and the Console needs neither in code (types come from served definitions, C2). The rule's message is reworded so that it no longer calls `guardian` a role. Positive controls cover each new key, the audience exemption, and a relationship-type literal | WP2A (WP5 adds its own no-type-name source test) |
| `CrmBoundariesTest` | Every use case with an `Actor $actor` parameter is in the capability table; nothing outside CRM uses CRM | The `Delegated` namespace is excluded from the capability table, and a new pin asserts that **no** `Delegated` class names a `Capability` or `AuthorizeAction`. "Nothing outside CRM uses CRM" allows exactly `Relationships\Application` → `Crm\Application\Delegated\*`, and nothing else of CRM | WP3 |
| `AdministrationRoutesTest` | Exactly one catalog capability per admin route; three step-up exemptions | Four pinned console-only GET routes (A6). The exemption list becomes five: `guardians.manage` and `volunteers.manage`, each pinned to `Relationships\Http`. Positive controls for both | WP1 (Relationships), WP4 (`my-resources`) |
| *New:* Relationships route table | — | Pins A5's verification table for the production catalog, as a hard-coded table (not derived from the definitions), so a definition change cannot silently remove verification | WP1 (WP2B adds the `default-role` route) |
| *New:* catalog validation | — | One refusal test per invariant of F5, and the third-type extensibility test (*Verification matrix*) | WP1 |
| `MembershipTrustBoundariesTest`, audit callers | Identity, Access, Audit, `DeletePack`, `DeleteCard` | Adds `Relationships\Application\DeleteRelationship` only. Grant events stay Access's | WP1 |
| `ResourcesBoundariesTest` | No Membership dependency; no Volunteer/Partner/Vendor/Artist words; audiences exactly `guardian`, `member` | Membership allowed through `GetCurrentMembership` only; `volunteer` and the eligibility seam allowed; Partner, Vendor and Artist still forbidden; audiences exactly `guardian`, `member`, `volunteer`; R15's separation pins | WP4 |

### Part K — Relationship-managed role provisioning (AR2)

This part is an approved requirement (AR2) with a proposed mechanism. It replaces the previous revision's absolute rule that a relationship never results in a role. It stays within ADR 0036, decision 5: the relationship stays the source of truth for affiliation, Access stays the source of truth for grants and capabilities, and a role is never read as proof that a relationship exists or is Active.

K1. **What it is, and what it is not.**
- **It is** an authorized operator's explicit, attributed decision to grant one specific role *because of* one specific relationship instance, kept consistent with that instance's lifecycle.
- **It is not** identity inference, role inheritance, a rule the `Authorizer` evaluates, or a generic callback. Nothing is granted because someone *is* a Guardian. Something is granted because an operator with role-assignment authority *chose* to grant it when recognizing or activating one.

K2. **The policy.** A definition's `default_role` is null or one case of Access's public `ProvisionableRole` enum.

| Relationship | Default role | When it may be provisioned |
| --- | --- | --- |
| Guardian | `guardian-initiate` | On Active recognition, on any transition into Active, and by a default-role decision while the relationship is Active (K8). Always with explicit confirmation |
| Volunteer | none | Never |
| Member | deferred | A future, verified activation policy (*Future architecture*) |
| Partner | deferred | As Member |

- **`ProvisionableRole` is Access's allowlist** of roles that a source may grant. In G10 it is exactly `guardian-initiate`, which carries `console.access` only. Access pins four rules on it:
  - it never contains `platform_administrator`;
  - no provisionable role grants `access.roles.assign` or any `identity.*` capability;
  - every case maps to a `Role`;
  - **in G10**, no provisionable role grants `resources.view` or `resources.manage` (R4).

  The first two are standing invariants: no relationship definition, today or under future configuration, can name a role that administers access or identity. The Resources pin is G10's policy. It is enforced exactly like the others, but it is not a permanent ban. A future default role carrying a Resources capability would need a deliberate role-catalog and `ProvisionableRole` change, with its own security review (R4). That is outside G10.
- Relationships never sees `Role` or a role key. It holds the `ProvisionableRole` case, and Access turns it into a role internally.

K3. **The decision record** (Relationships' `person_relationship_role_provisions`, F6). It holds the *current* decision for a relationship's default role:
- `granted`, by whom and when;
- `declined`, by whom and when;
- or no row: never decided, or deferred.

The history of what was actually granted and withdrawn is Access's audit trail (K10), not this row.

**The invariant.** For every relationship whose type has a default role, a grant sourced from it exists **if and only if** all three hold:
1. its status `qualifies` (for Guardian, `active`);
2. its decision is `granted`;
3. the decided role is the type's current default role.

Every use case that changes one of these makes or withdraws the grant in the same transaction (K11). `relationships:check` reports any departure (F15).

K4. **Access's persistence: grants with a source.** Access gains one table and four Application services. `role_assignments` is unchanged.

`sourced_role_grants` (Access)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `person_id` | `char(26)` | Foreign key to `people.id`, `RESTRICT` (ADR 0021), exactly as `role_assignments` |
| `role_key` | `string(64)` | A role key, held as a string and judged by the catalog exactly as in `role_assignments`. An unknown key grants nothing |
| `source_type` | `string(32)` | A closed Access vocabulary, `RoleGrantSourceType`. In G10: `relationship` |
| `source_id` | `char(26)` | The source instance: here the `person_relationships.id`. **No foreign key**: Access's schema must not depend on Relationships. Integrity is by transaction (K11) and by `relationships:check` |
| `granted_by_account_id`, `granted_at` | | The operator whose authorized act made this grant effective. Provenance, no foreign key |

`unique(source_type, source_id, role_key)`, index `(person_id)`, index `(role_key)`.

**Why a second table and not a column on `role_assignments`.** This is the smallest change that keeps revocation safe:
- `role_assignments` keeps `unique(person_id, role_key)` and its meaning: one *independent* grant per role.
- `RevokeRole` keeps deleting exactly that row, so revoking an independent grant can never remove a sourced one. Withdrawing a source can never remove an independent grant either.
- `AdministratorContinuity` and `BootstrapAdministrator` are untouched, because `platform_administrator` is never provisionable.
- A `source` column on `role_assignments` would have widened the unique key, changed `RevokeRole`'s delete, and made every existing reader source-aware. A reference-counted sources table would have changed what a `role_assignments` row means.

**The services** (all in `Access\Application`):

| Service | Authorizes | Does |
| --- | --- | --- |
| `GrantSourcedRole(Actor $by, PersonId, ProvisionableRole, RoleGrantSource)` | **`access.roles.assign`**, by the real `Authorizer`, before any write, exactly as `GrantRole` | Inserts the row if absent and records `role.granted`, in the caller's transaction. Idempotent: an existing row is `Unchanged` and records nothing. Ignores every other grant the Person holds |
| `WithdrawSourcedRoles(Actor $by, RoleGrantSource)` | Nothing. It only *removes* authority, and it is pinned to one caller (A10). Inactivation and deletion must never be blocked by a missing grant capability | Deletes every row of that source and records `role.revoked` for each. Idempotent: no rows is `Unchanged` |
| `ListSourcedRoleGrants(RoleGrantSourceType)` | Nothing; read-only, pinned caller | For `relationships:check`. Returns each grant's `person_id`, role, source, `granted_by_account_id` and `granted_at`, so the check can compare the grant's Person with its source's (F15) |
| `SourcedRoleGrantsOf(list<RoleGrantSource>)` | Nothing; read-only, pinned caller | For the default-role state in Relationships' views (F13) |

**What else in Access changes:**
- **`Authorizer::capabilitiesOf`** reads the Person's rows from both tables and unions their roles' capabilities. It is still live, uncached and fail-closed, and still re-resolves the Actor first. Capabilities stay a set, so two sources of one role are one capability set.
- **`AccountViews`** lists sourced grants separately from independent assignments, labelled by source type ("Granted through a relationship"), with when and by whom. They carry no revoke action.
- **`RevokeRoleFromAccount`** stays limited to the independent assignment. Withdrawing a relationship-managed grant is a decision on the relationship (K8), which carries its own verification.
- **`PeopleHoldingCapability`** (M4) unions both tables.

K5. **Authorization and confirmation: the non-escalating contract.**
- **Who may provision.** Provisioning needs two independent authorities, each checked by its own module: the type's manage capability (`guardians.manage`, checked by Relationships) and `access.roles.assign` (checked by Access in `GrantSourcedRole`). In G10 both are held by Platform Administrators.
- **The API never defaults to granting.** On every operation that can provision, the request must state `default_role`: `grant`, `decline` or `defer`. A missing value is `422 default_role_decision_required`. The server never infers a grant from a missing field, a stored decision or another role.
  - **`grant`** requires `access.roles.assign`, and is accepted only where the operation leaves the relationship in a qualifying state (Active). Without the authority the answer is `403 role_provisioning_not_permitted`, and **nothing** changes: not the relationship, its status or its decision. Against a relationship that is not Active, the answer is `409 relationship_not_active`, and nothing is written (K8). So a `granted` decision is only ever recorded together with the grant that Access authorized at that moment.
  - **`decline`** records the operator's refusal, replacing any stored decision, and withdraws any grant from this relationship. It needs only the manage capability, because it grants nothing.
  - **`defer`** makes no decision. It needs only the manage capability, and it can only reduce what is stored:
    - **It never creates, replaces or erases a `declined` decision.** A stored `declined` keeps its author and time. Only an authorized operator's explicit `grant`, or a deletion, ever replaces it.
    - **It removes a stored `granted` decision**, and withdraws any grant from this relationship. A `granted` decision may not stand without its grant on an Active relationship (K3). A `defer` is what an operator sends when they cannot or do not wish to confirm a grant. The removed decision's history stays in Access's audit trail: the original `role.granted` and the `role.revoked` that withdrew it, each with its actor and source (K10).
    - With nothing stored, it changes nothing.

    So an operator without role-assignment authority cannot erase a refusal, and cannot leave a decision behind that later becomes a grant.
- **The Console's confirmation.** The step-up flow and the confirmation show "Grant Guardian Initiate access". Its initial state depends on the operation, the stored decision and whether the operator may provision (`RelationshipTypeView.actions.provision_default_role`):

  | Situation | Operator may provision | Operator may not provision |
  | --- | --- | --- |
  | Active recognition (intake) | **Checked** | Unavailable; sends `defer` |
  | Reactivation, stored `granted` | **Checked** | Unavailable; sends `defer`, which removes the `granted` decision |
  | Reactivation, stored `declined` | **Unchecked** | Unavailable; sends `defer`, which keeps the `declined` decision |
  | Reactivation, no stored decision (never decided, or deferred) | **Checked**, as for recognition (AR2: offered preselected on activation) | Unavailable; sends `defer` |

  - Checked sends `grant`. Unchecked sends `decline`. The operator may change either before confirming.
  - "Unavailable" shows the explanation "Granting Guardian Initiate access needs permission to assign roles. Someone who has it can grant it from this record later."
  - A preselection is only the Console's starting point. **The server grants nothing that the request does not explicitly ask for:** an absent stored decision, a stored `granted` and a missing `default_role` never produce a grant on reactivation (`422 default_role_decision_required` for a missing value).
  - Guardian status is recorded the same way whatever is chosen.

K6. **Account absence and trusted linkage.**
- **Relationship creation never creates an Account.** Recognizing a Person who has no Account records the relationship, and, if the operator chose `grant`, the decision and a sourced grant on the **Person**.
- **Why this is the safe form of "deferred fulfillment".** Access's grants already belong to Persons, not Accounts (ADR 0015, ADR 0017). A grant on a Person with no Account that can authenticate confers nothing, because the `Authorizer` first re-resolves a live Actor. `InviteOperator` already relies on exactly this for invited operators. So:
  - the persisted, authorized intent is the decision row plus the inert sourced grant, with full attribution;
  - fulfilment happens at the moment a trusted linkage makes an Account for that Person able to authenticate (`AcceptInvitation` after an operator's `InviteExistingPerson`);
  - the recheck is continuous rather than a step at fulfilment. Every change to the relationship's status or decision has kept the grant consistent in its own transaction (K3, K11), so at the first sign-in the `Authorizer` reads a grant that already reflects the current relationship and decision. A policy change is handled at release time (K12).
  - Idempotency and attribution are the grant row's own (K4, K10).
- **The alternative that was rejected.** Writing no Access row until an Account exists would need Identity or Access to call back into Relationships when an invitation is accepted. That is a dependency cycle (Relationships → Identity, Access) or a domain-event outbox that does not exist yet. It adds machinery without making anything safer, because the inert grant already confers nothing.
- **Trusted linkage is pinned.** Today every way of giving an *existing* Person an Account is operator-authorized: `InviteExistingPerson` → `InviteAccountForPerson` (`identity.invitations.issue` and recent verification) is the only one. An architecture test pins `Identity\Application\InviteAccountForPerson` to that single caller. A mandatory invariant: **any future self-service or applicant flow creates a new Person, or links to an existing Person only through an operator-authorized step that has its own design gate.** So no applicant can attach themselves to a Person who carries a dormant grant. A future applicant can neither reach a Guardian intake (no `guardians.manage`) nor provision (no `access.roles.assign`).
- **The trust assumption, stated.** A dormant grant was authorized by an operator who holds `access.roles.assign`. But the operator who later invites the Person decides **which mailbox** receives the Account, and so who exercises the grant. That operator needs only `identity.invitations.issue`. The authority that makes a dormant grant effective is therefore the invitation authority, not the role-assignment authority.
  - **In G10 the two never separate.** `identity.invitations.issue`, `access.roles.assign` and `guardians.manage` are held only by the Platform Administrator role (A3). The same trusted administrators therefore decide both the grant and the linkage.
  - **A fresh security review is required** before any of these three capabilities is separated from the others, or delegated beyond the Platform Administrator role. That includes in particular a role holding `identity.invitations.issue` without `access.roles.assign`. That review must decide how an invitation of a Person who carries Console-admitting grants is authorized or disclosed. `CatalogTest`'s A3 mapping pin makes any such catalog change visible and deliberate.
  - **No new linkage system is built.** `InviteExistingPerson`'s doc comment ("an invited Member holds no capability by default") stops being true for a Person with a dormant grant. WP2B corrects it to describe this assumption.

K7. **Lifecycle effects.** Each happens in the transaction of the operation that causes it (K11).

| Operation | Effect on the decision | Effect on the grant |
| --- | --- | --- |
| Intake, initial state `active` | Recorded from `default_role` (`grant`, `decline`, or no row for `defer`) | Made if `grant` |
| Intake, initial state `inactive` (a former Guardian) | `default_role` must be absent (`422` if sent). No activation, no decision | None |
| Status → `inactive` | Kept as it is | **Withdrawn**, whatever the decision. No grant capability is needed |
| Status → `active` (reactivation) | From the required `default_role`, by K5's rules: `grant` records `granted`; `decline` records `declined`; `defer` keeps a `declined` decision and removes a `granted` one | Made if `grant` (K8) |
| A status request for the current status | Nothing changes; `default_role` is ignored. The default-role route changes a decision | Unchanged |
| Default-role decision (K8), relationship Active | `grant` and `decline` replace the stored value; `defer` as K5 | Made (`grant`) or withdrawn (`decline`, or `defer` over `granted`) |
| Default-role decision (K8), relationship Inactive | `grant` is refused, `409 relationship_not_active`, and nothing is written. `decline` replaces the stored value; `defer` as K5 | None exists to withdraw |
| Field edit | Unchanged | Unchanged |
| Permanent deletion (F12) | Deleted | **Withdrawn** |

K8. **Reactivation, restoration and changing a decision.**
- **Restoring a previous grant is a fresh, authorized act.** On reactivation the Console preselects "Grant Guardian Initiate access" when the stored decision is `granted` and the operator may provision. One confirmation restores it, attributed to that operator, with the original decision's author kept in the audit trail. In G10 every holder of `guardians.manage` may provision, so a previously authorized grant is always restorable in one step.
- **No silent restoration.**
  - If the stored decision is `declined`, the option starts unchecked, and only an authorized operator's explicit `grant` changes it.
  - If the operator lacks role-assignment authority, reactivation can only `decline` or `defer`. Neither erases a `declined` decision (K5). The relationship becomes Active, and no role is granted until someone with authority decides.
  - With no stored decision, the option is offered preselected to an operator who may provision, and the grant is made only by the explicit `grant` that operator confirms (K5).

  This keeps the rule that every *new* effective grant is authorized by Access at the moment it is made. Restricting operators without authority matters only for a future role that manages Guardians without assigning roles.
- **Changing a decision later.** `PUT /admin/relationships/guardians/{person}/default-role` with `{relationship_id, revision, default_role}` (Part H): `guardians.manage`, recent verification, and `access.roles.assign` for `grant`.
  - It is how an administrator grants Initiate access after a `defer`, or withdraws it (`decline`) while the Guardian stays Active.
  - **`grant` is accepted only while the relationship is Active.** Against an Inactive relationship it is `409 relationship_not_active`, decided from the locked row before Access is asked, and nothing is written. So no `granted` decision ever exists without the Access authorization of the grant it records. Initiate access for an Inactive Guardian is decided when they are reactivated.
  - `decline` and `defer` are accepted in either state, under K5's rules.
  - It increments the relationship's revision when it changes the stored decision, so it serializes with status changes and deletion.

K9. **Coexistence, no hierarchy and no suppression** (AR2, explicit ruling).
- Provisioning never looks at the Person's other roles or grants. A Person holding `guardian-full`, `guardian-senior` or `platform_administrator` receives `guardian-initiate` if an authorized operator chooses it, exactly as anyone else would.
- No role is replaced, downgraded, ranked, compared or suppressed. There is no special case for Senior Guardians.
- The same role from an independent assignment and from a relationship are two rows in two tables. Revoking the independent assignment leaves the sourced grant, and withdrawing the source leaves the independent assignment. Capabilities are their union.
- Every grant and withdrawal is idempotent: repeating it changes nothing and records nothing.

K10. **Audit.** Grants are security-relevant, so Access records them, in the transaction that changes them, with their source:
- `role.granted`, actor = the operator, subject = the Person, context `{role, source_type, source_id}`;
- `role.revoked`, the same shape, on withdrawal by inactivation, decision or deletion. The actor is the operator whose act withdrew it.

They hold no relationship content: no field value, no name and no contact detail. The decision row (K3) is attributed business state. A decline or a deferral is not a security event in itself, because the decision changes no authority. A withdrawal it causes is recorded by Access as `role.revoked`, like any other. `relationship.deleted` (F12) adds the count of grants withdrawn.

K11. **Consistency, failure and fail-closed behaviour.**
- **One transaction.** Every Relationships use case that can make or withdraw a grant runs as `$database->transaction(fn, 3)`. It locks the relationship row first, checks the instance and the revision, writes its own rows, and calls `GrantSourcedRole` or `WithdrawSourcedRoles`, which join the same transaction (nested savepoints, the `RegisterPersonWithMembershipAccess` precedent). Access's events commit with it. If anything fails, including Access's authorization or an event write, **everything rolls back**: no status change without its grant change, and no grant change without its status change.
- **The grant's Person is the relationship's subject.** The `PersonId` passed to `GrantSourcedRole` is always read from the locked relationship row. It is never taken from the route, the request body, the Actor or any other source. Access stores it as given, because it cannot check Relationships (A8). So the binding is Relationships' responsibility, and it is verified three ways:
  - a WP2B mutation check passes the acting operator's Person instead, and a test must catch it;
  - a WP2B test asserts the stored `person_id` after intake, reactivation and a default-role `grant`;
  - `relationships:check` reports any grant whose `person_id` differs from its relationship's (F15).

  Because the `Authorizer` applies a Person's grants only to the Account that belongs to that Person, a correctly bound grant can never affect any other Account.
- **Authorization before writes.** `GrantSourcedRole` authorizes before it writes. A refusal is thrown inside the transaction and rolls back whatever Relationships wrote first. The visible result is `403 role_provisioning_not_permitted` with nothing changed.
- **Withdrawal never depends on authority.** Inactivation and deletion withdraw grants without any grant capability. A Guardian can therefore always be inactivated, and the privilege goes with them.
- **Lock order.** The relationship row, then Access's `sourced_role_grants` rows for that source. Access's own `GrantRole`, `RevokeRole` and `AdministratorContinuity` never touch `sourced_role_grants`, so no cycle of locks arises.
- **No effective privilege outlives its cause.** Inactivation and deletion commit only together with the withdrawal. A sourced grant whose cause is missing can only arise outside the use cases: a manual database edit, a restore of mismatched backups, or a defect. `relationships:check` detects it (F15), and the adoption and release runbooks run it.
- **Residual, stated honestly.** The `Authorizer` does not consult Relationships, by design (A8), so it would honor such an orphan until it is removed. The alternative, an `Authorizer` that reads relationship state on every check, was rejected: it would make Access depend on relationship state, which this design forbids. Transactional consistency plus the check is the chosen safeguard.

K12. **Changing the policy, and future configuration.**
- **Changing a type's default role in code** is a Controlled, security-sensitive change (F4). Its release must include a migration or command that **withdraws** grants whose role is no longer the type's default role, with `role.revoked` events. It never adds grants: any new grant needs an operator's decision. `relationships:check` must be clean afterwards.
- **Future operator configuration** of default roles needs, as its own gate:
  - an explicit capability and recent verification;
  - validation against `ProvisionableRole`, which only a code change can widen, under the pins of K2;
  - versioning and a security event per saved version;
  - an explicit, reviewed plan for existing grants, withdrawing only and never adding silently;
  - escalation protection: a configurator can never make a role provisionable, nor provision a role whose capabilities they do not hold.

  G10 builds none of this.
- **Time-bounded relationships** (Member, Partner) must not rely on stored sourced grants alone. A grant must never outlive an expired subscription. Their software capabilities follow ADR 0028's query-time port, or a stored grant together with a mechanism that provably withdraws it at expiry, decided in their own gate.

### Part M — Guardian adoption, bootstrap and the role-key migration

Two operations happen in G10 and must not be confused. They have different semantics and different owners:
- **The role-key migration (M6)** renames a permission bundle. It is automatic, runs in one release, and changes no one's effective permissions. It creates no relationship.
- **Guardian adoption (M1–M5)** records organizational recognition. It is manual, done by an authorized operator, one Person at a time, and it creates no role unless that operator chooses the default role (Part K).

M1. **No automatic mapping** (N2). Nothing creates or removes a Guardian relationship because a Person holds any Guardian-named role (`guardian-full`, `guardian-senior`, `guardian-initiate`, or the retired `guardian`), holds `console.access` or Resource capabilities, or has an administrative Account. This rules out every migration, seeder, scheduled job and rule. The role-key migration (M6) in particular creates no relationship.
- **Why they differ.** A role is a permission bundle. The relationship is organizational recognition.
- **Who holds the role today.** Its holders may include people who are not Guardians.

M2. **Adoption is deliberate, authorized recording** (N2).
- After WP2B ships, a Platform Administrator records each Guardian through the ordinary Guardian intake. The administrator holds `guardians.manage` and `access.roles.assign` by derivation.
- For each Guardian: look the Person up, confirm that it is the right Person, establish the relationship as Active, set "Guardian since", decide on Guardian Initiate access (K5), and confirm with recent verification.
- **Existing role holders.** Many Guardians being adopted already hold `guardian-full` after M6. The Initiate option is still offered and preselected (K9). The operator decides: granting it is harmless overlap, and declining it is equally valid. Nothing compares the two roles.
- Each record goes through the same validation, verification, provenance and history as every later one.
- No automatic production migration of Guardian status is authorized.

M3. **The bootstrap path** (N2). Establishing the first Guardian relationships needs nothing that only Guardians have:
- `guardians.manage` and `access.roles.assign` come from the Platform Administrator role.
- That role exists from `BootstrapAdministrator` (ADR 0020), whose root of trust is server access. Neither the role-key migration nor provisioning touches `platform_administrator` or `AdministratorContinuity`, so no administrative access can be lost.
- No Guardian-management operation requires the Actor to be a Guardian, or requires any Guardian relationship to exist already.
- A Guardian may be recorded for a Person with no Account. If Initiate access was granted, it takes effect when an operator later invites that Person and they accept (K6).

So an installation with no Guardian relationships can always be brought up, and no second bypass is built.

M4. **The reconciliation report** (N2). `php artisan relationships:guardian-adoption-report` is read-only. It lists:
- Persons who hold `console.access` and have no Guardian relationship. They are listed for an administrator to review, with a heading saying that many Console participants are not Guardians. The report suggests nothing and converts nothing.
- Guardian relationships whose Person has no Account, or no `console.access`. This is informational; both are valid.
- The output of `relationships:check` (F15), so that an adoption pass ends with a consistent state.

How it works:
- It asks Access by **capability**, through a new `Access\Application\PeopleHoldingCapability(Capability)`, which reads both independent and sourced grants. So it never names a role (ADR 0017).
- It prints ids and names, and writes nothing.

M5. **Before, during and after adoption.**
- **Before adoption, no one is Guardian-eligible on audience delivery.**
  - Console users who hold `resources.view` (`guardian-full`, `guardian-senior`, administrators) read everything through policy A, which adoption does not change.
  - Console users without `resources.view` (`guardian-initiate`, `console-participant`) see only what their recorded relationships make them eligible for.
  - On the day G10 ships, every existing Console user holds `guardian-full` or `platform_administrator`, both of which include `resources.view`, so no one loses anything.
- **Unchanged by adoption:** capabilities and every existing screen. The only role change adoption can make is a Guardian Initiate grant an operator chose (Part K).
- **Accounts with capabilities and no Guardian relationship** keep every capability. These include administrators and trusted advisors. They are simply not Guardians.
- **Production data** is not touched by this gate. The role-key migration (M6) is the only automatic change to production data in G10, and it preserves permissions. Adoption is an operator task after release. The release notes record it, with the report as its checklist.

M6. **The role-key migration: `guardian` → `guardian-full`** (AR1). Built and tested in WP2A, run in that release's maintenance window.
- **Code, in one change:**
  - the `Role` enum loses `Guardian = 'guardian'` and gains `GuardianInitiate`, `GuardianFull`, `GuardianSenior` and `ConsoleParticipant`, with A3's bundles, display names and descriptions;
  - `RoleAssignment::KEY_SHAPE` and the revoke route's `{key}` pattern admit hyphens (A10);
  - `ConsoleUserFixture` grants `GuardianFull` where it granted `Guardian`, and gains fixtures for the restricted personas (`guardian-initiate`, `console-participant`) for tests and E2E;
  - every test that names `Role::Guardian` (about 30 files) moves to `Role::GuardianFull`;
  - the architecture tests change as A10 lists.
- **Data, in one migration.** `UPDATE role_assignments SET role_key = 'guardian-full' WHERE role_key = 'guardian'`, in a transaction.
  - It cannot collide with `unique(person_id, role_key)`, because no `guardian-full` row exists before it runs. The migration asserts that and refuses to run otherwise.
  - It creates no row, so no one gains a role, and it leaves every other key, including `platform_administrator`, untouched.
  - It writes no security event per row. The change is a rename with identical permissions, and the release notes record it. Historical `role.granted` events keep their original context (`guardian`), because the audit trail is append-only. Any screen that renders history shows a key that is no longer in the catalog as the raw key.
  - `down()` reverses the update, for a development rollback only.
- **Why it is safe on the real host.** Migrations run inside the maintenance window, against the new release, before the swap ([deployment runbook](../runbooks/deployment.md)). So no request is ever served by code that disagrees with the stored keys.
  - A code-only rollback would leave the old code reading `guardian-full` as an unknown key, which grants nothing. That fails closed, but it locks every Guardian-role user out. So the release that carries M6 is classified `restore-required` ([ADR 0027](0027-release-and-deployment-model.md)), with the pre-migration backup as the rollback.
- **What does not need changing.**
  - No cache: capabilities are read live, and sessions hold none.
  - No API shape: role keys are data in the role catalog API, and the Console shows whatever names Access serves.
  - No Console code: it names no role key.
  - Bootstrap: it names only `platform_administrator`.
  - OpenAPI: it lists no role keys.
- **What is proved (WP2A):**
  - before and after the migration, on both engines, every Person's effective capability set is identical;
  - no `guardian` row remains;
  - no Person gains or loses a role;
  - a stored `guardian` key grants nothing;
  - every Console user's `/me` is unchanged.

### Part R — Resources: audiences, three access policies, eligibility

R1. **The audience catalog is `guardian`, `member` and `volunteer`.**
- `volunteer` is added. There is no schema change, and existing rows stay valid.
- An unknown audience fails closed:
  - naming one in a write is `422`;
  - a stored one is dropped on read;
  - one used as a filter is `422`;
  - eligibility emits only catalog members.
- `ResourcesBoundariesTest`'s ban on invented relationship words is revised:
  - allowed: `volunteer` and the eligibility seam;
  - still forbidden: Partner, Vendor and Artist.

R2. **OR combination, narrowing and publication are unchanged** (ADR 0037, decisions 13–14 and 39–41). No audience implies another.

R3. **Preview** (`resources.manage`) accepts every catalog audience. Its file `download_path` points at the **management** file route, so that a manage-only previewer can open what preview shows, Draft Cards included.

R4. **Three access policies.** They are separate, non-interchangeable Application services. None takes its authority from a request parameter.

| Policy | Who | Reads | Publication | Audiences | Console endpoints |
| --- | --- | --- | --- | --- | --- |
| **A. Privileged viewing** | An Actor holding `resources.view` | Every Category, Pack and Card, including Drafts, at the latest saved content, with their files | Ignored for access; shown as state | Ignored for access; shown as data | `/admin/resource-library` |
| **B. Management** | An Actor holding `resources.manage` | Everything, plus every change and preview | As today | As today | `/admin/resources` |
| **C. Audience-based consumption** | Any authenticated Actor, by the Person's eligible relationships | Only what the Person is eligible for | Pack **and** Card Published | Effective audiences intersect the eligible set (OR) | `/admin/my-resources` (R14). Future surfaces reuse the same use cases |

- **Eligibility is not authority.** `resources.view` is a capability and is never derived from a relationship (D7). Eligibility to read Guardian-directed Resources is not viewing authority, and viewing authority is not eligibility.
- **The three policies are independent.**
  - `resources.manage` does not grant A.
  - Holding A does not change C.
  - **Eligibility never grants A or B.** Being eligible for an audience, through any relationship, never implies `resources.view` or `resources.manage`, and no eligibility rule derives either (D7).
  - **In G10, no relationship-managed grant carries A or B either.** The only provisionable role is `guardian-initiate`, which carries `console.access` only. Access pins that no `ProvisionableRole` carries `resources.view` or `resources.manage` (K2). That pin is enforced for all of G10, and the allowlist stays exactly `guardian-initiate`.
  - **This is G10's policy, not a permanent ban.**
    - `resources.view` stays exceptional: privileged reading of every audience and every publication state, Drafts included.
    - A future default role that carried `resources.view`, `resources.manage` or another sensitive Resources capability would need a deliberate role-catalog and `ProvisionableRole` change, authorized as such, with its own security review. That is outside G10.
    - Such a role would still be an operator-authorized grant under Part K, never an eligibility rule.

R5. **Policy A is its own read model.** It is `ViewerLibrary`, with the use cases `BrowseLibrary`, `ReadLibraryPack` and `DownloadLibraryFile`, each asking for `resources.view` alone.
- It never calls `ResourceProjection` and never receives an audience set.
- With policy C it shares only the pure pieces: ordering, outlines, content loading, the Card shape, search matching and the file opener.

R6. **What policy A reads:**
- every Pack and Card, at its latest saved content;
- Drafts, marked as such;
- everything in `(position, id)` order, with Cards numbered `1..n` across all of a Pack's Cards;
- uncategorized Draft Packs, in a final group;
- empty Packs.

Categories with no Pack are not listed.

R7. **What policy A carries,** beyond delivery:
- the Pack's `state` and `audiences`;
- each Card's `state`, `audience_mode` and effective audiences;
- `updated_at` and the last editor's name.

It never carries `revision`, stored positions, the creator, or anything that offers an action.

R8. **Policy A's filters**, applied by the server after authorization:
- `category`;
- `q`, over the titles and summaries of a Pack and **all** its Cards, Drafts included. This is privileged viewing's own search. ADR 0037, decision 48 (search over *visible* Cards only) still governs policy C unchanged, and the two searches share only the pure matching function;
- `publication`:
  - `published` returns Published Packs;
  - `draft` returns Draft Packs, and Published Packs that hold a Draft Card;
- `audience`.

Filters only narrow. An unknown value is `422`.

R9. **Policy A's errors and files.**
- A missing Pack, or a Card that is not in the Pack, is `404 resource_pack_not_found`.
- The file route serves any File Card of an existing Pack to `resources.view`. It streams with `private, no-store`, and never as a signed or long-lived URL.
- A missing file is `404 asset_unavailable`.

R10. **Audience eligibility.** `Resources\Application\AudienceEligibility` is the only producer of policy C's audience set.
- It knows nothing of the Console: its input is an `Actor`.
- **It re-resolves identity first** (AR9). It asks Identity's `ResolveActor` for the Actor's Account, exactly as the `Authorizer` does. If that yields no current Actor (a disabled Account, or one that can no longer authenticate), or one whose Person differs from the Actor's, the set is **empty**. Only then does it use the resolved `personId`. So a captured or stale `Actor`, for example in a future queued job or a future adapter, can never carry eligibility, and every surface gets this guarantee whether or not it also checks a capability.
- It takes no audience, Person or mode from its caller beyond the `Actor`.
- Its output is the union of each owner's current answer, through a mapping that Resources holds (Resources owns audiences; owners own who belongs to them):

| Audience | Owner's answer | Qualifying |
| --- | --- | --- |
| `guardian` | `Relationships\Application\QualifyingRelationships($person)` contains `guardian` | An `active` Guardian relationship (D7) |
| `volunteer` | Contains `volunteer` | An `active` Volunteer relationship (decision 3) |
| `member` | `Membership\Application\GetCurrentMembership($person)->active` | Membership's own rule, unchanged |

- **Never derived from capabilities, roles or admission.** `resources.view`, `resources.manage`, `console.access` and every role add nothing, including `guardian-initiate`, whether independent or relationship-managed (AR7).
- **Never derived from metadata.** Relationship fields add nothing either (N4).
- **Read on every call.** Nothing is cached.
- **Extensible.** A Partner audience is one catalog case and one mapping row. Member moving to another owner re-points one row (D8).

R11. **Policy C's use cases** are G5's delivery use cases, kept, re-pointed and renamed: `BrowseDeliveredResources`, `GetDeliveredPack` and `DownloadDeliveredFile`.
- `DeliverySurface::guardianConsole()` is removed.
- `GuardianDelivery` becomes `AudienceDelivery`, fed by `AudienceEligibility`.
- ADR 0037's projection applies exactly:
  - the Pack and the Card must be Published;
  - effective audiences must intersect;
  - everything hidden is one `404 resource_pack_not_found`;
  - visible Cards are numbered `1..n`;
  - search covers visible text only;
  - files resolve through the projection.
- No Draft, unpublished, management or provenance field reaches policy C.
- The use cases ask for no capability. They answer only about the Actor's own relationships, as `GetCurrentMembership` does.

R12. **The cases eligibility answers:**

| Situation | Policy C |
| --- | --- |
| Account with Person P (there is always exactly one) | P's set |
| Disabled Account, ended or superseded session | No Actor: `401` first (ADR 0025) |
| Active Guardian | `guardian` ∈ set |
| Inactive Guardian | `guardian` ∉ set |
| `guardian-full`, `guardian-senior` or `guardian-initiate` role, no Guardian relationship | `guardian` ∉ set (D2, AR7) |
| A relationship-managed `guardian-initiate` grant whose relationship was since inactivated | Impossible: inactivation withdraws the grant in the same transaction (K7). And `guardian` ∉ set regardless, because eligibility reads the relationship |
| Guardian relationship, no role | `guardian` ∈ set; no Console admission (A7) |
| `guardian-initiate` with an Active Guardian relationship | `{guardian}`: reads published Guardian Resources in the Console (R14) |
| `console-participant` with no relationship | `{}`: enters the Console, reads no Resource |
| `console-participant` who is an Active Volunteer (or Guardian, or Member) | `{volunteer}` (or the matching audience) |
| Stale or captured `Actor` whose Account can no longer authenticate | `{}` (R10) |
| Active Volunteer / Pending or Inactive Volunteer | `volunteer` ∈ / ∉ set |
| Active Member who is an Inactive Volunteer | `{member}` |
| Active Guardian, Active Volunteer and Member | `{guardian, member, volunteer}`, combined by OR |
| Holds `resources.view` or `resources.manage` | No effect on policy C |
| Status changes between requests | Seen on the next request |

R13. **Revocation between requests.** Policy C has no signed URL, cached grant or token.
- Every Pack, search and file request re-runs eligibility.
- After an inactivation or a deletion commits, the next request for content of that audience alone is `404`.
- A download that is already streaming finishes; that is accepted.
- Everything is `no-store`.

R14. **Audience-based reading in the Console** (N6).

**The routes.** Three GET routes in `Resources\Http`, behind `stateful`, `auth:web` and `can:console.access` only (A6):
- `GET /admin/my-resources?category=&q=` returns the library of what this Person may read;
- `GET /admin/my-resources/packs/{pack}` returns one Pack;
- `GET /admin/my-resources/packs/{pack}/cards/{card}/file` returns a file, as an attachment or, on request, inline. That is the same choice the library file route offers.

**The response.** G5's delivery shape (ADR 0037, decision 50): no state, audiences, revision or provenance. A file's `download_path` points at the `my-resources` file route.

**Errors.**
- Everything hidden, ineligible, Draft, unpublished, missing or empty is `404 resource_pack_not_found`, including on the file route. There is never a `403` for ineligibility.
- `403` means only a missing `console.access`.
- `401` means no session.

**The client cannot choose elevation.**
- These routes accept no audience, mode or person parameter, so no client value can widen them.
- Policy A's routes still demand `resources.view`.
- Calling the wrong family gains nothing:
  - an Actor without `resources.view` who calls policy A is refused `403`;
  - an Actor who calls policy C receives only what they are eligible for.

**Who uses which.**
- The Console calls policy C for an Actor who lacks `resources.view`, including a `resources.manage`-only holder, because management never grants viewing.
- An Actor who holds `resources.view` uses policy A, whose content contains everything policy C would give.

**Which audiences.** The Console serves every audience the Person is eligible for: Guardian, and also Member and Volunteer where the Person holds those relationships. This amends ADR 0037, decision 69, which kept the Console Guardian-only. The approved requirement is that a restricted Console user reads what their relationships make them eligible for, and nothing else.

**Not coupled to the Console.** The routes are a thin adapter. A future WordPress or `/my/` surface calls the same use cases and the same `AudienceEligibility` through its own authenticated adapter, without Console admission, after its own gate ([ADR 0033](0033-service-identity-and-delegated-human-authority-are-distinct.md)).

**Not built in G10:**
- the WordPress companion;
- a `/my/` Resources surface;
- service or delegated authentication;
- anonymous delivery.

R15. **The separation is pinned by architecture tests:**
- `ResourceProjection::visibleCards` is called only by `AudienceDelivery` and `PreviewPack`.
- `AudienceDelivery`'s set comes only from `AudienceEligibility`.
- `ViewerLibrary` uses neither.
- The policy C use cases are used only by the `my-resources` controllers. This pin describes G10. A future surface's gate adds its own adapter to it, and nothing else changes: the use cases and `AudienceEligibility` depend on no Console class, no `console.access` and no Guardian role, which a second pin asserts.
- Those controllers use nothing of policy A or B.
- The `my-resources` routes are GET only and carry no capability beyond `console.access`.
- The library routes are GET only and carry `resources.view`.
- No request class in the library or `my-resources` families accepts an audience set or a person id. The library's `audience` *filter* is validated and only narrows policy A.

R16. **What the `guardian` audience means.** It is real eligibility for Active Guardians:
- through policy C in the Console;
- on future surfaces.

To `resources.view` holders it is a targeting label: policy A shows every audience. The wording says "For Guardians", never "visible only to Guardians".

### Part C — Guardian Console

C1. **Navigation.**
- **Relationships.** A Relationships group in the People section, built from `GET /admin/relationship-types`:
  - one directory per type the Actor may **view**;
  - "Record a Guardian" or "Record a Volunteer" per type the Actor may **manage**.

  Nothing about a specific type is written into `navigation.ts`.
- **Resource Library.** It is shown to every Console user:
  - with `resources.view` it opens in viewer mode (policy A);
  - without it, in audience mode (policy C).

  The guard on `/resource-library` and `/resource-library/:packId` becomes "holds `console.access`". The mode is chosen from `/me`. The server enforces the policy whichever mode the client picks (R14).
- **When capabilities change during a session** (AR9). Capabilities are read live on the server, so a role change takes effect on the next request. The Console follows:
  - A `403` from a viewer-mode request (any `/admin/resource-library*` route) makes the library discard everything it holds from policy A: the loaded list, Pack and filters. It then refreshes `/me`. If `/me` still admits to the Console but lacks `resources.view`, the library re-opens in audience mode at the same route, and a deep link re-resolves through policy C, where a Draft or ineligible Pack reads "Resource not found". If `/me` no longer admits to the Console, the existing signed-out or `/my` handling applies.
  - Privileged data is never rendered in audience mode. The two modes use separate clients and separate state, and the audience-mode view renders only policy C responses (C7).
  - A gained `resources.view` is picked up at the next `/me` refresh (navigation or reload). Audience mode is never elevated in place.
  - Audience mode has no "forbidden" case to handle: ineligibility is a `404` (R14). A `403` there means Console admission was lost, which is handled as above.
- **The signed-in Person's own relationships** change no navigation.

C2. **One set of generic components, driven by definitions.**
- **The components:** `RelationshipDirectory`, `RelationshipRecord`, `RelationshipPanel`, `RelationshipIntake`, `StatusChange`, `FieldsForm`, `FieldValue` and `FieldInput` (one per value type), `BasicsForm` and `RelationshipHistory`.
- **Status badges** are a word and a shape from the state's label and tone, never colour alone.
- **Forms** come from the field definitions. The transitions offered come from the definition.
- **Validation in the client is advisory.** The server's `422` for a field is shown on that field.
- **Specialization.** The registry `relationshipExtensions[type]` lets a type add its own component. G10 registers none. The registry exists so that later features attach without editing the generic screens.

C3. **Directory** (`/relationships/:slug`, view capability). It follows `PeoplePage`'s conventions:
- server-side paging, 25 per page;
- a submitted search over names, and over primary contact values where the type reads basic details;
- a status filter built from the definition's states;
- columns: name, status, "Status since", and primary email and phone where the type reads basic details;
- loading, empty, no-match and error states;
- the stacked layout on narrow screens.

**On the server**, Identity's `SearchPeople` is restricted (`restrictToIds`) to the Person ids of the type's relationships in the filtered status. Contact matches are supplied as `includeIds`. Both sets are bounded by `PeopleQuery::MAX_ID_SET` (10,000), which is far above Flow Life's scale. A directory query whose id set would exceed it is refused as CRM refuses one (`422`, the `SearchTooBroad` precedent), never silently truncated. Paging relationships in Relationships' own table first is the planned change if a type ever approaches the bound. It is not built now.

C4. **Record** (`/relationships/:slug/:personId`). The page uses the view read, the management read, or both, according to the Actor's capabilities:
- **Basic details.** Read-only with the view capability. Editable as `{from, to}` with manage and `people.edit_basics`. A `stale_person_basics` refusal shows the current values beside the user's.
- **Every mutation sends the `relationship_id` and the `revision` it was shown** (F10). A basic-detail edit sends the `relationship_id` only (P5).
- **Status.** "Change status" sends both.
  - For a Guardian, the shared step-up flow runs first.
  - A transition into Active for a type with a default role includes the Initiate option, preselected or unavailable as K5 says.
  - On `stale_revision` the page re-reads and says who changed it and when. If the current `relationship_id` differs from the one shown, it says the relationship was deleted and recorded again. It never resends on the user's behalf.
- **Fields.** "Edit" sends both.
- **Default role** (types that have one; Guardian). A small panel: the current decision (granted, declined or not decided), who decided and when, and whether it is in effect, waiting for an Account, or withdrawn because the relationship is Inactive. "Grant" or "Withdraw" sends a default-role decision (K8) through step-up. "Grant" appears only when `actions.provision_default_role` is true and the relationship is Active (K8). "Withdraw" sends `decline`. The panel never shows the Person's other roles.
- **History.** Shown with the view capability only.
- **Danger zone.** Last on the page: "Delete permanently" (manage).
  - The step-up flow runs first.
  - The confirmation states that the relationship, its fields and its history are removed, that this cannot be undone, and that the Person and their other relationships remain. For a type with a default role, it also says that access granted through this relationship is withdrawn, and that roles granted directly are not affected.
- **"Open full record"** links to `/people/:personId`, shown only with `crm.people.view`.

A source test pins that the page's API client names only `/admin/relationships*` and `/admin/relationship-types`.

C5. **The Person record** (`/people/:personId`) gains a Relationships section.
- **Tabs.** One tab per type the Actor may view or manage, ordered by the definition's position.
  - Each tab shows the generic panel.
  - "Record as Guardian" or "Record as Volunteer" appears where the Person lacks the type and the Actor may manage it.
- **No leak through tabs.** A tab appears only for the Actor's own types. A Volunteer manager therefore never learns that a Person is a Guardian without a Guardian capability.
- **Still absent.** Membership, Account and security data do not appear. The Guardian tab's default-role panel (C4) shows only the decision about this relationship's own grant, never the Person's other roles.
- **Without CRM access.** Someone who has no `crm.people.view` works from the directories and records.

C6. **Intake** (`/relationships/:slug/new`, manage capability):
1. Search (P4). A candidate who is already related in this type links to their record. When two or more candidates share a name, the page asks for an exact email or phone instead of offering a choice by name (P4).
2. Select a candidate, or add someone new where `people.create_person` is enabled. Selecting an existing Person opens a confirmation that shows their name and says that recording them lets the manager maintain their basic details. It sends `confirm_existing_person: true` (P3.4).
3. Choose an initial state, optionally fill in the fields, and, for an Active Guardian, decide on Guardian Initiate access (K5). Then confirm. For Guardians, step-up runs first (A5).

`possible_duplicate` and `relationship_exists` are handled as CRM's registration handles them.

C7. **The Resource Library: one interface, two backend contracts.**
- **Viewer mode** (`resources.view`, policy A), as decided in the first version:
  - a publication filter: All, Published, Drafts, defaulting to All;
  - an audience filter;
  - Draft badges, and "*n* draft Cards";
  - audience labels;
  - the Uncategorized group;
  - Draft notes inside a Pack;
  - the effective audiences of narrowed Cards;
  - the last editor.
- **Audience mode** (policy C), which is G5's library as built:
  - the Category filter and search;
  - one Card, several Cards, or a Series;
  - files;
  - no state, audience or provenance markers, because none are delivered;
  - the empty state "There are no Resources for you yet.";
  - one "Resource not found" for any `404`.

  Deep links (`/resource-library/:packId?card=`) work in both modes. A Draft or ineligible Pack reads as "not found" in audience mode, saying nothing more.
- **One client per mode.** `api/resourceLibrary.ts` has one client for each mode, and each mode's file-path allowlist accepts only its own file route.
- **Still out of the library.** Approval wording and management actions. The G5 library boundary tests stand and are extended to the second contract.

C8. **Resources management** offers `volunteer` in audiences, filters and preview. Its delivery note says that Console users read Resources for their eligible audiences, and that nothing outside the Console delivers them yet.

C9. **Accessibility and layout.** Every new route and state joins:
- the axe pass, in both themes, with colour contrast;
- the sideways-scroll pass at 320, 375 and 1280 px;
- the route matrix.

Every flow is keyboard-complete: intake (including the existing-Person confirmation and the Initiate option), status change, field edit, basic-detail edit, default-role decisions, deletion with step-up, the record tabs, the library filters, both library modes and the transition between them.

### Part H — HTTP contract

All routes are under `/api/v1/admin`, behind `stateful`, `auth:web` and `can:console.access`, and described in `openapi/openapi.yaml`.

**Relationship routes.** They are generated by `Relationships\Http\routes.php` from the registry, with the type's slug as a literal path segment.
- Each route therefore carries one concrete capability, and verification exactly where A5 says.
- The type is always taken from the route, never from the body.

| Method and path | Capability | Verified | Notes |
| --- | --- | --- | --- |
| `GET /admin/relationship-types` | none beyond `console.access` (A6) | — | `RelationshipTypeView` for types the Actor may view or manage |
| `GET /admin/relationships/{slug}?status=&q=&page=&per_page=` | view | — | Directory; `per_page` ≤ 100. `422` if the id set would exceed its bound (C3) |
| `GET /admin/relationships/{slug}/{person}` | view | — | `RelationshipView`. `404 relationship_not_found` |
| `GET /admin/relationships/{slug}/{person}/management` | manage | — | `RelationshipManagementView` (A4). `404 relationship_not_found` |
| `GET /admin/relationships/{slug}/candidates?q=` | manage | — | P4. `422` under 3 characters. `candidates` never matches the ULID route pattern |
| `POST /admin/relationships/{slug}` | manage | Guardians | Intake: `person_id` with `confirm_existing_person: true`, or `new_person`; `status`; optional `fields`; `confirm_distinct`; `default_role` (`grant`/`decline`/`defer`), required for an Active initial state of a type with a default role and refused otherwise. `201` with the management view. Errors: `403 role_provisioning_not_permitted`, `409 relationship_exists`, `409 possible_duplicate`, `422 confirmation_required`, `422 default_role_decision_required`, `422 unknown_person`, `422 transition_not_allowed` (not an initial state), `422 unknown_relationship_field`, `422 invalid_relationship_field` |
| `PUT /admin/relationships/{slug}/{person}/status` | manage | Guardians | `{relationship_id, revision, status, default_role?}`. `default_role` is required when entering Active for a type with a default role (K7). `409 stale_revision` with `current`. `403 role_provisioning_not_permitted`. `422 transition_not_allowed`, `422 default_role_decision_required`. Same status: `200`, unchanged |
| `PUT /admin/relationships/{slug}/{person}/default-role` | manage, generated only for types with a default role (Guardians) | Yes | K8. `{relationship_id, revision, default_role}`. `grant` also needs `access.roles.assign` (`403 role_provisioning_not_permitted`) and an Active relationship (`409 relationship_not_active`, nothing written). `defer` never erases a `declined` decision (K5). Same decision, or a `defer` that changes nothing: `200`, unchanged |
| `PATCH /admin/relationships/{slug}/{person}/fields` | manage | — | `{relationship_id, revision, fields: {key: value or null}}`, partial |
| `PATCH /admin/relationships/{slug}/{person}/basics` | manage, for types with `people.edit_basics` (Volunteers) | — | P5. `{relationship_id, display_name?, primary_email?, primary_phone?}`, each `{from, to}`, at least one. Errors: `409 stale_person_basics` (with the three current values), `422` per field. Never `409 duplicate_contact_method` (P5) |
| `DELETE /admin/relationships/{slug}/{person}?relationship_id=&revision=` | manage | All types | F12. `204`. `409 stale_revision`, `409 relationship_in_use`. The instance and revision are query parameters because a `DELETE` body is not reliably carried |

**Refusal order:**
1. capability (`403`);
2. verification (the step-up refusal);
3. scope (`404`), then instance and revision (`409 stale_revision`);
4. request shape (`422`), including the default-role decision being present where it is required;
5. for a default-role `grant`, the relationship's state (`409 relationship_not_active`), decided from the locked row before Access is asked;
6. provisioning authority (`403 role_provisioning_not_permitted`), decided by Access inside the transaction, before Access writes, and rolling back anything already written;
7. the domain's other `409`s.

**Resources routes:**
- **Library.** The three `/admin/resource-library` routes keep their paths, methods and capability. Their responses become policy A's. Browsing gains the `publication` and `audience` filters.
- **New.** The three `/admin/my-resources` routes serve policy C (R14).
- **Preview** accepts `volunteer` and returns management file paths.

**Compatibility ([ADR 0007](0007-versioned-rest-api-openapi.md)).** G5's library contract has never been released, and its only consumer is the Console in the same release. The OpenAPI document changes in the same package, and the drift test enforces it. The new fields are additive, and the new routes are additions.

**Access.**
- `PeopleHoldingCapability(Capability)` is an Application read for the adoption report only. It has no route.
- The sourced-grant services (K4) have no route. They are reached only through the relationship routes above.
- The account views (`GET /admin/accounts`, `/admin/accounts/{account}`) gain a `sourced_grants` list beside `assignments`: role descriptor, source type, granted at and by whom. It is additive, and it carries no revoke action.
- The revoke route's `{key}` pattern admits hyphens (A10). Its behaviour is unchanged: it revokes the independent assignment only.
- Role keys are data in `GET /admin/roles`. The new keys are served there, and no schema lists role keys.

### Part T — Concurrency, transactions, portability

T1. **Races.** Each is proved by a two-process race test on both engines, and each is mutation-checked. The pause comes between the read and the write, as `tests/Support/ResourcesPauses.php` does.

| Race | Decided by |
| --- | --- |
| Two intakes attaching the **same existing Person** to the same type | The unique index. Exactly one commits; the loser gets `409 relationship_exists` and changes nothing (P7) |
| Two intakes each creating a **new Person** with the same name or email | No index decides it, by design: duplicate advice is advisory and checked before the transaction (P7). The test proves both Persons and both relationships commit, which documents the accepted behaviour, rather than claiming a guarantee that does not exist |
| Intakes of **different** types for one Person | Both succeed. This proves the index is per type |
| Two status changes from one revision | Row lock and revision |
| A field edit against a status change | One revision guards both |
| Deletion against any change | Deletion locks the row first. The loser gets `404` or `stale_revision`. No orphans remain, and no sourced grant outlives the relationship |
| A mutation naming a deleted instance against its re-creation | The instance id. The mutation gets `409 stale_revision` with the new instance and writes nothing, even though both revisions are 1 (AR8) |
| Inactivation against a default-role `grant` | Row lock and revision. Whichever commits second sees the other: after the inactivation, a grant is refused as stale; after the grant, the inactivation withdraws it. No interleaving leaves a grant on an Inactive relationship |
| Two default-role `grant`s for one relationship | Row lock, revision and `unique(source_type, source_id, role_key)`. One grant row and one `role.granted` event |
| A relationship-managed grant against an independent `GrantRole` of the same role | Different tables, no shared lock. Both rows commit. Revoking either leaves the other (K9) |
| A basic-detail edit (Volunteer scope) against a CRM edit of the same method | CRM's profile lock and compare-and-set. Neither overwrites the other silently |
| A rename from the Volunteer scope against a rename from CRM | `RenamePerson`'s row lock and the expected name |
| A basic-detail edit against a deletion of its relationship | The relationship row lock taken first (P5). The edit either completes in scope or answers `404` |

Reads after a lock are locking reads. This avoids the MariaDB REPEATABLE READ stale-snapshot trap that Resources WP1 measured.

T2. **Transactions.**
- Every transaction runs as `$database->transaction(fn, 3)` through an injected `ConnectionInterface`.
- Intake nests CRM's work as a savepoint. The precedent is `RegisterPersonWithMembershipAccess`.
- Access's sourced-grant services join the caller's transaction in the same way, so a lifecycle change and its grant change commit or roll back together (K11).
- Deletion writes its event, and Access's withdrawal events, inside its own transaction.
- Lock order, everywhere: the relationship row; then `people` (via `RenamePerson`); then `contact_profiles`; then Access's `sourced_role_grants` for the source. No path takes them in another order.

T3. **Portability ([ADR 0005](0005-mariadb-with-postgresql-portability.md)).** No exception is needed:
- ULIDs are generated in code.
- States, types, field keys, role keys, decisions and grant-source types are validated strings.
- The role-key migration (M6) is a plain `UPDATE` with a pre-check, identical on both engines.
- Times are UTC `dateTime`.
- Metadata is `text` in canonical forms.
- Indexes are plain primary, unique and composite indexes.
- There is no JSON, no partial index, no CHECK constraint and no generated column.
- The only lock is `SELECT ... FOR UPDATE`.
- Search uses Identity's portable search.
- Contact matching uses CRM's `search_value`. The accent-folding difference between engines is the one CRM already pins.

MariaDB is production. PostgreSQL is the guardrail.

## Configuration readiness

**G10 builds:**
- a closed, code-defined registry with the Guardian and Volunteer definitions;
- explicit lifecycle policies;
- typed, validated metadata;
- explicit audience eligibility providers;
- declarative presentation contracts;
- server-authoritative enforcement.

**G10 does not build an operator configuration interface.** What it does is keep a practical path to one, and the design choices that keep it practical are deliberate:
- types and fields are stored as validated strings, not database enums;
- definitions are data, validated by a schema, read only through the catalog, and they reach it through one source seam (F3). A persisted source is a later implementation of the same interface, not a new path;
- the relationship type is a value object the catalog issues, not an enum (F2);
- storage does not depend on the type (shared tables, key-value metadata);
- presentation is generic;
- routes are generated from the registry;
- eligibility goes through a mapping table, not a switch.

**What a later configuration package would add.** Each item would be its own gated work:

| Step | What it requires |
| --- | --- |
| Operator-edited presentation (labels, help, order, tone, loosened constraints) | A `relationship_definition_versions` table holding validated overrides merged over the code document by the catalog. Each save is a new version with its author and time, validated by `DefinitionSchema`. A capability (for example `relationships.configure`), recent verification, and a security event per saved version. The Console already renders from the served definition |
| Adding fields, retiring fields, tightening constraints | The same, plus F8's checks run before a version is accepted (the field part of `relationships:check` becomes a pre-save check). Changing a released field's key, type or visibility stays a data migration, never a runtime one |
| Lifecycle changes (states, transitions, initial states) | Versioned definitions, plus a check of stored statuses against the new graph, with migration of any orphaned status. A change to `qualifies` changes Resource eligibility, so it needs specially controlled approval and verification and is audited |
| Operator-added relationship types | The registry reading persisted types beside code types. Routes registered from it (already generated from the registry). Capabilities for a registry-defined type: Access's catalog is a code enum today, so this needs an Access design gate of its own (for example capabilities parameterized by type, still checked one per operation). An audience for the type: Resources' catalog becomes extensible by registered types, still failing closed. Each is a design gate; none is precluded |
| Feature configuration | Enabling an implemented feature for a type is a Controlled change. A feature that does not exist yet is new code: a domain handler and, where needed, a Console component |
| A new metadata value type | New code: a validator, a canonical form and a renderer |
| Default-role policy (Part K) | Security-sensitive configuration with its own gate: an explicit capability, recent verification, validation against Access's `ProvisionableRole` (only code widens it), versioning, a security event per version, a reviewed plan for existing grants that withdraws and never adds, and escalation protection (K12) |

**The mandatory invariants of F5 bind every step.** Configuration never bypasses:
- authentication and identity integrity;
- capability authorization;
- Person scope;
- audit integrity;
- Resource visibility and publication protections;
- safe migration;
- type validation;
- referential integrity;
- verification requirements;
- role provisioning only through Access, only of provisionable roles, and only by an authorized operator's decision;
- the catalog's security invariants (F5), including unique types and no capability serving two types.

**What configuration never becomes:** executable, a rules language, or a source of runtime-generated migrations.

## Future architecture (direction only; nothing built or committed)

**Grant sources.** In G10, capabilities come only from roles. A role reaches a Person independently (`role_assignments`) or through an operator's relationship-managed decision (`sourced_role_grants`, Part K). Later sources could include:
- explicit Account-level grants;
- named groups;
- capabilities derived from relationships at query time;
- participation in particular programs, teams or projects;
- object- or context-specific grants.

These would join as additional **positive** sources that the `Authorizer` unions. There would be no explicit-deny system unless a concrete need appears.

**Two forms of relationship-to-Access link, kept distinct:**
- **Part K's stored, attributed grant** suits a relationship that changes only by explicit transitions (Guardian). An authorized operator decides, the grant is stored with its source, and the lifecycle withdraws it.
- **A query-time derived capability** suits a relationship that can lapse by the passage of time (Member, Partner). It is an explicit rule in Access, fed through ADR 0028's port by the owner's current answer, so that it can never outlive the relationship. It would never be a stored role row on its own.

Neither ever makes a relationship and a role interchangeable.

**Global capabilities differ from scoped grants.** A global capability answers "may X anywhere". A scoped grant answers "may X on this object or in this context", which needs an object-aware check that ADR 0017 deferred.

**Mandatory conditions stay independent of every grant:**
- recent verification;
- publication rules for ordinary audiences;
- Person-scope rules;
- ownership;
- approval requirements.

**Member and Partner relationships** (AR10). The product owner intends to integrate them soon after G10. Their own gate designs them. This ADR records only the direction they must fit:
- **Initiation is not activation.** Designated actors (ordinary users, Guardians or others) may eventually *initiate* a Membership or Partnership. Activation may require any of these:
  - Account registration or activation;
  - a digitally signed agreement;
  - verified subscription payment;
  - an unexpired subscription period;
  - designated organizational approval;
  - other relationship-specific requirements.

  So these types will need an initiation policy stricter than Volunteer intake (P8). The foundation allows that: lifecycle, features and verification are per type, and a transition guard is a future, code-implemented feature.
- **Keep the facts apart.** Billing state, agreement state, approval state and relationship status are distinct facts, each with its own owner and history. A single status field is never overloaded with every prerequisite. Payment facts stay with the provider (ADR 0029).
- **Expiry and renewal.** Benefits may depend on a current subscription period. Renewal extends eligibility. Expired or invalid prerequisites may suspend it, by a future policy. No stale payment confirmation or client claim may establish continued access, so eligibility and any derived capability are computed from current, trusted facts (ADR 0028), never from a cached flag.
- **Authorized exceptions.** Senior Guardians or Council members may later be authorized to waive selected prerequisites, or to grant complimentary Memberships or Partnerships. Such an act would:
  - need its own capability, be independently authorized and audited;
  - record the requirement waived, the authorizing actor, the reason, the effective dates and an expiry where appropriate;
  - never be recorded as a payment or a signature that did not happen.

  No waiver capability exists in G10, and `guardian-senior` holds none.
- **Discounts and coupons.** Authorized recurring price adjustments, one-time and recurring discounts, limited- and unlimited-use coupon codes, and their controlled creation and administration are future billing concerns.
- **Billing provider.** Zeffy is the preferred first provider for Membership and Partnership billing. When that milestone arrives, its gate must verify, and record as blockers or limitations, Zeffy's actual support for:
  - recurring payments and subscription management;
  - renewal evidence, cancellation and expiry;
  - discount and coupon behaviour;
  - identity matching;
  - trusted payment notifications and reconciliation;
  - administrative access.

  Stripe, or a billing component owned by Bestside (the separate event-production platform formerly codenamed Quiverly), may be considered later if warranted. Nothing here assumes that Zeffy supports any of these.
- **Scope.** G10 builds none of this: no billing, agreement signing, approvals, renewals, discounts or coupons. Member stays in Membership (D8).

**Approval workflows** (deferred). The platform may later support:
- submissions;
- named approvers, or approvers chosen by role, relationship or group;
- approval, rejection and changes-requested outcomes;
- feedback and resubmission;
- context-specific approval policies;
- defaults and overrides, and media review.

They would attach here:
- **Transition guards.** A guard on a relationship transition would be a new, code-implemented feature in the closed set (for example, Pending to Active only after an approval outcome).
- **Approval stays separate from publication** ([ADR 0009](0009-authorization-separate-from-approval.md)). Resource publication is not approval, and G10's privileged draft viewing implies no approval process.

**Guided experiences** (deferred). Onboarding, assignments, presentations, quizzes, courses, mentorship, workshops and contextual Resource access. They would attach here:
- **Contextual audiences** would be new audience cases with their owner's eligibility answer.
- **Relationship-linked records** (assignments, enrollments) would reference `person_relationships.id` with `RESTRICT` and register in the deletion dependents registry (F12).
- **Specialized Console components** attach through `relationshipExtensions` (C2).

## Threat review

| # | Threat | Enforcement | Proof |
| --- | --- | --- | --- |
| 1 | A Guardian-named role is treated as Guardian status, or the reverse | No automatic mapping in either direction (M1, A8). Eligibility reads only the relationship (R10). Access never depends on Relationships. Role identity stays inside Access (A10) | Each Guardian-named role without a relationship: no `guardian` eligibility. Relationship without role: eligibility, no capability, no admission. `/me` unchanged by a relationship change with no grant. The role-key migration creates no relationship. Module-graph and role-identity architecture tests |
| 2 | Affiliation used as authority | Every use case authorizes by capability alone (A9) | Architecture test. An Active Guardian without `guardians.manage` gets `403` |
| 3 | A relationship manager reads unrelated CRM records | No CRM route is reachable. Delegated reads return three fields for in-scope Persons (P2–P3) | Capability isolation: every CRM route `403`. Exact response shapes |
| 4 | Scoped edits against an unrelated Person | Scope comes from the type's own table and the named instance; `404` outside it (P3) | An unrelated Person gets `404` and is unchanged |
| 4a | **Accepted:** a Volunteer manager brings a Person into scope by recording them (M1) | Not prevented, by product decision (P8). Bounded by the three-field allowlist, the protected operations (P6), and deliberate confirmation. Answerable through creation provenance, contact-method provenance and `person.renamed` | Attaching without `confirm_existing_person` is `422` and creates nothing. After attach and edit: the history names the operator, each changed method carries their Account, and nothing outside the three fields changed |
| 5 | Scoped edits reaching beyond basic details | One use case, three fields, `{from, to}` only (P5–P6) | Other keys `422`. Other contact methods' values and labels, profile, tags and notes unchanged after the edit |
| 5a | A scoped edit used as an oracle for hidden contact methods: by an error, or by the promoted method's previous display value | A collision with a hidden method promotes it **and rewrites its display value to the validated `to`**, keeping its `search_value`. The response and later reads match a plain replacement exactly. Never `409 duplicate_contact_method` on the scoped path. `409 stale_person_basics` returns only values the manager may read (P5) | For the same `to`, the response with a hidden colliding method is byte-identical to the response without one, including where the hidden value differs only in email capitalization or phone formatting. Mutation checks: returning CRM's duplicate error fails; keeping the hidden method's previous display value fails |
| 6 | Contact edits changing login identity | Login email and credentials are Identity's. No dependency from Identity to CRM. Invitations take an operator-typed address (P6) | Edit a Person's CRM email: `accounts.email`, invitations and reset addresses unchanged. Architecture test |
| 7 | Volunteer authority altering Guardian state | The type comes from the route literal. Fields are validated against the stored type (P6) | A Volunteer route cannot read or change the Guardian relationship of the same Person |
| 8 | A manage-only holder reading more than management needs | Management reads are a separate, narrow view (A4) | A manage-only holder: no directory, no history, no `RelationshipView`, no CRM; the management view is exact by shape |
| 9 | Lookup used for enumeration | Manage only; exact contact matching; 10 names; this type's status only (P4) | View-only `403`. A partial email matches nothing. No other type in results |
| 10 | Duplicate Persons or relationships | CRM's duplicate advice. Unique `(person_id, relationship_type)` | Advice tests. Race test; a mutation dropping the index is caught |
| 11 | Metadata integrity | Closed value types; validation on every write; no JSON column (F7) | Per-type unit tests; unknown key `422`; retired field `422` |
| 12 | Field visibility bypassed | `manage`-visibility fields are filtered from view responses and refused on view routes | Shape tests per capability |
| 13 | Unauthorized or unverified sensitive change | The route-table test pins A5's verification table | Guardian intake, status change and any deletion refused without verification. Nothing changes, and no event is recorded |
| 14 | Deletion leaves orphans or loses its audit | One transaction, the event inside it, `RESTRICT` keys, the dependents registry (F12) | Forced failure: nothing deleted, no event. Marker words never in the event. Person and other relationships intact |
| 15 | Definition tampering or drift | Definitions are code, validated at load, read only through the catalog. Future overrides are bound by F5 | Schema tests; every definition loads; source test |
| 16 | A Console user without `resources.view` reaching Drafts or ineligible content | Policy C only: publication, eligibility, one `404` (R11, R14) | The restricted-Guardian matrix rows, including the file route and deep links |
| 17 | The client selecting privileged mode or claiming audiences | Separate route families. No mode, audience or person parameter. Policy A demands `resources.view` (R14–R15) | Architecture tests. Without `resources.view`, every library route is `403` |
| 18 | Admission bypassed through a relationship | `console.access` is required on every route, including the `my-resources` routes. It comes only from Access grants (A7) | A Guardian relationship without `console.access` gets `403`. Recognition with `decline` or `defer` grants no admission |
| 19 | `resources.view` holder mutating; `resources.manage`-only holder viewing | The library is GET only. One capability per use case. Manage-only uses policy C | Route-table tests. "Manage is not view" kept |
| 20 | Unknown audiences or relationship types treated permissively | Fail closed on write, read, filter and eligibility | Tests with planted unknown keys |
| 21 | Privileged responses cached for ordinary users | Session-only routes, global `no-store`, separate route families | Header tests |
| 22 | Stale permissions or relationships | Read live. Disabled Accounts lose their sessions. No cached eligibility (R13) | Revoke a role: next request `403`. Inactivate: next policy C request `404` |
| 23 | Authorization drift (`guardians.manage` granted widely) | The role catalog is code, reviewed and pinned. Role grants are audited | `CatalogTest` pins the A3 mapping |
| 24 | A relationship manager provisions a role without role-assignment authority | Access's `GrantSourcedRole` checks `access.roles.assign` itself. The API never defaults to `grant` (K5) | `guardians.manage` alone with `grant`: `403 role_provisioning_not_permitted` and nothing changed, relationship included. With `defer`: relationship recorded, no grant |
| 25 | Provisioning an arbitrary or administrative role | Only `ProvisionableRole` cases, pinned to exclude `platform_administrator`, `access.roles.assign`, `identity.*` and Resources capabilities. Only the Guardian definition names one, and the Volunteer type has none (K2, P6) | Catalog pins. A definition naming a non-provisionable role is refused at load. No Volunteer operation reaches the sourced-grant services (architecture test) |
| 26 | A relationship-managed grant outlives its relationship | Withdrawal in the same transaction as inactivation and deletion, needing no grant authority. Every new grant is re-authorized (K7, K8, K11). `relationships:check` | Inactivate: the next request lacks the role's capabilities. Delete: grant gone. Forced failure of the withdrawal: the inactivation does not commit either. Planted orphan: reported by the check |
| 27 | Withdrawing a source removes an independent grant, or the reverse | Separate tables. `RevokeRole` touches only `role_assignments`; `WithdrawSourcedRoles` only its source's rows (K4, K9) | Both sources of one role: revoke either, the capability stays; revoke both, it goes |
| 28 | Silent restoration of a declined or withdrawn grant, or a decision recorded without authority | Reactivation requires an explicit `default_role`; a preselection is never a grant. A stored `declined` is never turned into a grant without an authorized `grant`, and `defer` never erases it. A `grant` is refused against an Inactive relationship, so no `granted` decision exists without Access's authorization of its grant (K5, K8) | Reactivate after `decline`: no grant unless `grant` is sent with authority. `defer` by an operator without authority leaves `declined` and its author unchanged. Reactivate with no stored decision and no `default_role`: `422`, nothing changed. Default-role `grant` on an Inactive relationship: `409 relationship_not_active`, nothing written |
| 29 | A dormant grant reached by an untrusted Account linkage | Grants on a Person are inert without a live Account. The only path from an existing Person to an Account is operator-authorized. Mandatory invariant for future applicant flows. The invitation authority decides who exercises a dormant grant: in G10 it is held only by administrators, with provisioning, and separating them needs a fresh security review (K6) | Architecture test: `InviteAccountForPerson` has one caller. A grant on an Account-less Person confers nothing until an invitation is accepted |
| 30 | A mutation lands on a re-created relationship | Instance id and revision on every mutation (F10, AR8) | Delete, re-create, replay the old request: `409 stale_revision`, nothing written |
| 31 | Stale or captured identity reaching audience delivery | `AudienceEligibility` re-resolves the Actor and yields `{}` otherwise (R10) | A disabled Account's stale `Actor` gets an empty set at the use-case level |
| 32 | The role-key migration changes anyone's authority | One `UPDATE`, pre-checked, in the maintenance window. The release is `restore-required` (M6) | Effective capability sets identical before and after on both engines; no `guardian` rows remain |
| 33 | A test-only or future type bypasses the catalog's security rules | One source seam for every definition, and the catalog invariants, including no capability serving two types (F3, F5). Generic use cases take their capability from the validated definition (A2) | The extensibility test passes through the same seam. Each invariant has a refusal test. Runtime isolation for every type and operation (A2) |
| 34 | A sourced grant lands on the wrong Person | `GrantSourcedRole`'s Person is read only from the locked relationship row (K11). The `Authorizer` applies a Person's grants only to that Person's own Account. `relationships:check` compares each grant's Person with its relationship's (F15) | Mutation check: passing the operator's Person is caught. The stored `person_id` is asserted after intake, reactivation and a default-role `grant`. A planted wrong-Person grant is reported by the check |

## Testing strategy

Every test named below is **to be written** by the package that builds what it proves (*Work packages*). None exists yet.

**Backend.** Pest on both engines:
- `Feature/Modules/Relationships`;
- `Unit/Modules/Relationships`: definitions, schema, catalog invariants, value types, lifecycle;
- `Feature/Modules/Access`: the role catalog, the role-key migration, sourced grants and the `Authorizer` union;
- `Architecture/RelationshipsBoundariesTest`;
- `Concurrency/RelationshipsRaceTest`;
- the revised Resources, CRM, Access, MFA and audit-seam boundary tests (A10).

How the tests are built:
- **Real personas, from real role assignments** (AR7). Each is made by Access's fixtures and Relationships' use cases, never by intercepting `/me`:
  - Guardian relationship, no role;
  - `guardian-full` role, no relationship;
  - `guardian-senior` role, no relationship;
  - `guardian-initiate` with an Active Guardian relationship (the restricted Guardian);
  - `guardian-initiate` with no relationship;
  - `console-participant` with no relationship, and with an Active Volunteer relationship;
  - an administrator with no relationship;
  - a Guardian who is also a Volunteer and a Member;
  - a Person with no Account, with and without a granted Initiate decision;
  - a Person holding `guardian-initiate` both independently and through their relationship.
- **`Gate::before` capability isolation** remains for the single-capability states that no real role has, such as `guardians.manage` without `access.roles.assign`, or `volunteers.manage` without CRM.
- **Exact response shapes** at every depth.
- **Mutation checks**, each one a deliberate break that a test must catch:
  - drop the unique index or the row lock;
  - skip the scope check, or the instance-id check;
  - widen a delegated read or the management view;
  - let a Volunteer route touch a Guardian relationship;
  - accept an unknown field;
  - let `pending` qualify;
  - let any role grant `guardian` eligibility;
  - let policy A call the projection;
  - let policy C read a Draft;
  - feed policy C a capability-derived audience;
  - skip `AudienceEligibility`'s re-resolution;
  - drop `console.access` from a `my-resources` route;
  - delete without writing the event;
  - skip `access.roles.assign` in `GrantSourcedRole`;
  - default a missing `default_role` to `grant`;
  - skip the withdrawal on inactivation or deletion;
  - let `RevokeRole` delete sourced rows, or `WithdrawSourcedRoles` delete independent rows;
  - suppress provisioning when another role is held;
  - return `409 duplicate_contact_method` from the scoped path;
  - keep a promoted hidden method's previous display value;
  - stamp a contact method from the profile's provenance;
  - drop the stamp from a demotion (`clearPrimary`) or from `RemoveContactMethod`'s promotion;
  - pass the acting operator's Person, not the relationship's, to `GrantSourcedRole`;
  - accept a default-role `grant` on an Inactive relationship;
  - let `defer` erase a `declined` decision;
  - authorize a Relationships operation with another type's capability;
  - let the migration touch a key other than `guardian`;
  - let the catalog accept a capability shared by two types.

**Frontend.** Vitest with `fakeApi`, definitions served by the fake:
- Two control types, one with a field of each value type, prove that rendering comes from definitions alone.
- Both library modes are tested against their own contracts, and so is the transition between them on a `403`. No policy A data is visible after the switch.
- The default-role option in each of its three presentations (preselected, unchecked, unavailable) and each value it sends.
- The existing-Person confirmation and the ambiguous-name flow.
- Source tests pin:
  - relationship pages name only relationship endpoints;
  - no component outside the registry names a type;
  - no component names a role key;
  - each library mode uses only its client;
  - the library imports no management or editor code.

**Browser.** Playwright against the real platform, `--workers=4`, with personas from real role assignments and relationships (`E2eAccountSeeder` gains them through `ConsoleUserFixture` and Relationships' use cases).
- **Relationship journeys:**
  - Guardian adoption by an administrator, with step-up, granting and declining Initiate access;
  - Volunteer intake, both for an existing Person (with confirmation) and a new one;
  - status changes, including a conflicting one, and an inactivation that removes Initiate access;
  - field edits;
  - a basic-detail edit of a Volunteer who is also a Guardian;
  - deletion with step-up;
  - the Person record's tabs;
  - the directories.
- **Library journeys:**
  - viewer mode with its filters and markers;
  - audience mode for a real `guardian-initiate` Account whose Person is an Active Guardian: published Guardian Resources only, Drafts and ineligible Packs as "not found", files;
  - a real `console-participant` with no relationship: the empty state; then, after an Active Volunteer relationship is recorded, Volunteer Resources appear;
  - the restricted Guardian sign-in, including the mandatory second factor.
- **Accessibility:** axe in both themes, the sideways-scroll pass, keyboard paths.

**Server-side proof.** Refusals are proved by the backend matrix. The browser proves that real personas work end to end, and that the Console never offers what the server would refuse.

## Verification matrix

Each row is a test to be written by the package named. "Both engines" means MariaDB and PostgreSQL (`./flow check all --pgsql`).

| Area | Scenario | Test | WP |
| --- | --- | --- | --- |
| **Role catalog and migration** | Existing `guardian` assignments migrate to `guardian-full` | Seed `guardian` rows, run the migration: every row is `guardian-full`, no `guardian` row remains, and the count of rows is unchanged. Both engines | 2A |
| | Existing permissions intact | Effective capability set of every seeded Person, and every `/me`, identical before and after. Administrators untouched | 2A |
| | The new roles exist with their bundles | `CatalogTest` pins exactly five roles and A3's mapping. `guardian-senior` equals `guardian-full`; `guardian-initiate` and `console-participant` are exactly `console.access` | 2A, 1 |
| | Hyphenated keys | Grant and revoke `guardian-initiate` through the administration routes. `KEY_SHAPE` still refuses malformed keys | 2A |
| | Retired key fails closed | A planted `guardian` row grants nothing | 2A |
| | No role creates a relationship | After the migration and after granting each role, no `person_relationships` row exists | 2A |
| | Migration refuses an unexpected state | A pre-existing `guardian-full` row for the same Person makes the migration stop, changing nothing | 2A |
| **Relationship-managed grants** | Authorized recognition offers the default role | `RelationshipTypeView.actions.provision_default_role` is true exactly for `guardians.manage` + `access.roles.assign` | 2B |
| | `grant` provisions `guardian-initiate` | One `sourced_role_grants` row with source and operator; one `role.granted` with source context; the Account's `/me` gains `console.access` | 2B |
| | `decline` | Relationship Active, decision `declined`, no grant, `/me` unchanged | 2B |
| | Missing decision | `422 default_role_decision_required`; nothing created | 2B |
| | Manager without role-assignment authority | `grant`: `403 role_provisioning_not_permitted`, nothing changed (relationship included). `defer`: relationship recorded, no decision, no grant | 2B |
| | No hierarchy or suppression | A Person holding `guardian-full`, one holding `guardian-senior`, and one holding `platform_administrator` each receive `guardian-initiate` on `grant`. No existing role is changed | 2B |
| | Two sources of one role | Independent `guardian-initiate` + sourced `guardian-initiate`: revoke the independent one, the capability stays; withdraw the source, the independent one stays; both gone, the capability goes | 2B |
| | Idempotency | Repeating `grant`, a withdrawal or a decision changes nothing and records no event | 2B |
| | Person without an Account | `grant` records the decision and an inert grant. No Account is created. The Person cannot authenticate. After an operator invites them and they accept, `/me` carries `console.access`, and the grant's attribution is the original operator's | 2B |
| | Intent rechecked by construction | Grant to an Account-less Person, inactivate, then invite and accept: no Initiate access | 2B |
| | Inactivation withdraws only the sourced grant | Initiate grant withdrawn, one `role.revoked`; independent roles untouched; next request lacks the capability | 2B |
| | Reactivation restores a previously authorized grant | Stored `granted`, reactivate with `grant` by an authorized operator: grant restored and attributed to them | 2B |
| | Declined is not silently restored | Stored `declined`, reactivate with `decline` or `defer`: no grant, and the decision is still `declined` with its original author and time. Only an authorized `grant` creates one | 2B |
| | `defer` semantics | `defer` over `declined` (at reactivation and on the default-role route): unchanged, `200`. `defer` over `granted` on an Active relationship: decision removed, grant withdrawn with `role.revoked`. `defer` with nothing stored: unchanged | 2B |
| | Reactivation with no stored decision | `RelationshipTypeView` lets the Console preselect the option. The server: missing `default_role` is `422` and nothing changes; `defer` grants nothing; only an authorized `grant` creates the grant | 2B |
| | Grant refused while Inactive | Default-role `grant` on an Inactive relationship, by an authorized operator and by one without authority: `409 relationship_not_active`, no decision row, no grant, no event, Access not called | 2B |
| | Grant bound to the relationship's Person | After intake (existing and new Person), reactivation and a default-role `grant`, the grant's `person_id` is the relationship's. Mutation check: the operator's Person is passed, and a test fails | 2B |
| | Deletion removes sourced grants and intent | Grant and decision gone; `relationship.deleted` counts the withdrawn grant; independent roles survive | 2B |
| | Self-service cannot provision | An Actor with `console.access` only (and a future applicant persona) cannot reach intake or the default-role route (`403`) | 2B |
| | Failure handling | Force Access's event write to fail during inactivation: the status, the grant and the history are all unchanged | 2B |
| | Policy drift detected | A planted orphan grant, a planted missing grant and a planted grant whose `person_id` differs from its relationship's are each reported by `relationships:check` | 2B |
| | Dormant-grant linkage documented | `InviteExistingPerson`'s doc comment states the K6 trust assumption; `CatalogTest`'s A3 pin shows `identity.invitations.issue`, `access.roles.assign` and `guardians.manage` held only by `platform_administrator` | 2B, 2A |
| | Trusted linkage | Architecture test: `InviteAccountForPerson` is called only by `InviteExistingPerson` | 2B |
| **Relationship identity** | Guardian relationship without a Guardian role | Eligible for `guardian`; no capability; no admission | 1, 4 |
| | Guardian role without a Guardian relationship | Each of `guardian-full`, `guardian-senior` and `guardian-initiate`: granted capabilities work; not `guardian`-eligible; not in the Guardian directory | 2A, 4 |
| | Trusted non-Guardian Console participant | `guardian-full` or `console-participant`, no relationship: their capabilities and nothing else; a Guardian nowhere | 2A, 4 |
| | Guardian and Volunteer on one Person | Two rows, two tabs, two directories, independent lifecycles | 1, 5 |
| | Person with no Account | Both types establishable and manageable; eligibility applies once an Account exists | 1, 4 |
| | One of each type per Person under concurrency | Unique index on both engines; race test; mutation drops the index | 1 |
| | Safe deletion and re-creation | Delete, re-create: new id, revision 1, new history; old history gone | 1 |
| | Stale instance or revision | A request naming the deleted instance, or a stale revision: `409 stale_revision`, nothing written. Both engines | 1 |
| **Capabilities** | Guardian view-only | Directory and records readable; every Guardian mutation and the management view `403` | 1 |
| | Guardian manage-only | Lookup, management view, intake, status, fields and deletion allowed (with verification as A5); directory, `RelationshipView` and history `403`; `grant` refused without `access.roles.assign` | 1, 2B |
| | Volunteer view-only / manage-only | As above, for Volunteers, plus basic-detail edits for manage-only | 1, 3 |
| | Resource view-only / manage-only | View-only: policy A, every management route `403`. Manage-only: management and preview; library routes `403`; reads through policy C | 4 |
| | Independently assignable | `CatalogTest` pins the A3 defaults; isolation tests prove every capability is checked alone; no pairing pin for the new capabilities | 1, 2A |
| | Definition-selected capability | For every catalog type (and the third test type) and every operation: the expected capability alone succeeds; any other single capability, including the other type's pair, is `403`. Static pins of A2, including `AssignRoles` only in `DescribeRelationshipTypes` | 1, 2B |
| | Provisioning hint never authorizes | `actions.provision_default_role` is true exactly for the type's manage capability plus `access.roles.assign`; a `grant` sent without the authority is still `403` whatever the hint said | 2B |
| **Lifecycle** | Create, deactivate, reactivate | Initial states only; fields and history kept; same row on reactivation | 1 |
| | History auditable | One row per change; a forced failure leaves neither row nor change | 1 |
| | Authorized permanent deletion | Verified; values, history and relationship gone; one `relationship.deleted` with counts and no values; Person and other relationships intact | 1 |
| | Unauthorized or unverified deletion | Refused; nothing deleted; no event | 1 |
| | Revision conflicts | `409 stale_revision`; the Console re-reads and never resends | 1, 5 |
| | Disallowed transition | `422 transition_not_allowed` | 1 |
| **Metadata** | Valid / invalid / unknown | Canonical round-trip per value type; `422 invalid_relationship_field`; `422 unknown_relationship_field` | 1 |
| | Date bound | `recognized_on` equal to today at UTC+14 accepted; the day after refused | 1 |
| | Unauthorized edit | View-only `403` | 1 |
| | No leakage across types | A Guardian field is not readable or writable on a Volunteer relationship, and the reverse | 1 |
| | Fields grant nothing | Setting every field changes no capability, role or eligibility | 1 |
| **Volunteer management and scoped People edits** | Deliberate attachment | `person_id` without `confirm_existing_person`: `422 confirmation_required`, nothing created. With it: relationship created, creation history names the operator | 3 (1 for the flag) |
| | Edit within scope, Guardians included | A Volunteer manager edits the three fields of an Active Guardian who is also a Volunteer; CRM's validation applies; `person.renamed` for a name | 3 |
| | Contact provenance | For every path in P9's table, each created or changed method carries the acting Account, unchanged rows are not stamped, legacy rows stay `NULL`, and a profile edit stamps no method. Includes `AddContactMethod` demoting a primary through `clearPrimary`, `UpdateContactMethod`'s primary change, and `RemoveContactMethod` promoting the earliest remaining method | 3 |
| | Hidden collision: email capitalization | Hidden non-primary `Ana@Example.org`; scoped `to` = `ana@example.org`: the hidden method becomes primary with `value` `ana@example.org` and the same `search_value`; the former primary stays, non-primary; nothing deleted; both unique indexes hold | 3 |
| | Hidden collision: phone formatting | Hidden non-primary `+1 (555) 010-0100`; scoped `to` = `+1 555 010 0100`: promoted with `value` `+1 555 010 0100`, same `search_value` | 3 |
| | Hidden collision: identical value | Hidden non-primary value written exactly as `to`: promoted; observable result identical to the two cases above | 3 |
| | Hidden collision is not disclosed | For each case above, the response and the next `ReadPersonBasics` are byte-identical to the same edit on a Person with no hidden method. No id, label, previous value or match indicator appears; never `409 duplicate_contact_method` | 3 |
| | Hidden collision provenance | The promoted method and the demoted former primary both carry the acting Account; no other method is stamped | 3 |
| | Expected values | `from` compared exactly with `ReadPersonBasics`' values; a case-only CRM change is reported as stale | 3 |
| | Unrelated Person | `404`; unchanged | 3 |
| | Account security protected | Account email, `email_canonical`, credentials, sessions, roles, grants and capabilities unchanged after any scoped edit; extra keys `422` | 3 |
| | Concurrent CRM and delegated edits | Compare-and-set `409 stale_person_basics`; race tests (method and rename) on both engines show neither overwrites the other | 3 |
| | Shared contact data stays canonical | After a scoped edit, CRM's record shows the change in the same contact method; no copy exists in `Relationships` | 3 |
| | Guardian state untouched through Volunteer routes | Every Volunteer route leaves the Guardian relationship's status, fields, decision, grants and history unchanged | 3 |
| **Resources** | Privileged viewer reads every audience and publication state | Drafts, every audience, uncategorized and empty Packs, Draft files; policy A search over every Card | 4 |
| | Resource manager independently authorized | `resources.manage` alone: management and preview; library `403`; policy C for reading | 4 |
| | Restricted Guardian Initiate reads published Guardian Resources | Real `guardian-initiate` + Active Guardian: `my-resources` returns them, with search and files | 4 |
| | Restricted Guardian Initiate cannot read Drafts | Draft Pack and Draft Card absent; deep link `404`; file `404` | 4 |
| | Role without relationship does not qualify | `guardian-initiate` (independent or sourced-but-the-relationship-inactive) with no Active Guardian relationship: `{}` | 4, 2B |
| | Console Participant | No relationship: the empty library. With an Active Volunteer relationship: Volunteer-only content appears | 4 |
| | Multi-relationship union | A `{guardian, volunteer}` Pack is seen by each alone; a Guardian-and-Volunteer reads both audiences | 4 |
| | Inactivation revokes eligibility | A file is served, the relationship is inactivated, the same request is `404` | 4 |
| | File authorization matches Resource authorization | Draft, narrowed, ineligible and non-existent Cards all answer the same `404` on the `my-resources` file route | 4 |
| | Unknown audiences fail closed | On write, filter and stored key | 4 |
| | Current trusted identity | A stale `Actor` for a disabled Account yields `{}` from `AudienceEligibility` | 4 |
| | Console admission separate | Guardian relationship without `console.access`: every `my-resources` route `403` | 4 |
| | No client elevation | No parameter exists; policy A routes `403` without `resources.view` | 4 |
| | Mode transition | Vitest: a `403` in viewer mode clears policy A state, refreshes `/me`, re-opens in audience mode | 6 |
| **Extensibility** | Test-only type through the real seam | A test application binds sources = the production Volunteer document + a test `apprentice` document using the `guardians.*` pair (Guardian not loaded, so the pair is unused; capabilities are a closed Code-path enum and are borrowed, not invented). It proves registration, schema validation, persistence, lifecycle, metadata validation, the types endpoint, route generation (with verification from its definition) and API shapes, with no change to Person identity or the projection. `apprentice` has no audience row, so `QualifyingRelationships` may list it while `AudienceEligibility` adds nothing (fail closed) | 1 |
| | Console presentation of a third type | Vitest renders an unknown control type from a served definition | 5 |
| | Invalid definitions fail deterministically | One refusal test per F5 invariant, each naming the document and the rule | 1 |
| | Capability collisions rejected | Two types naming one capability, or one type naming one capability twice: refused | 1 |
| | Metadata validation centralized | Source test: no field rule outside `DefinitionSchema` and the value types; Console validation generated from the served definition | 1, 5 |
| | Consistent definitions | The types endpoint mirrors the catalog; generic components render a control type | 1, 5 |
| | Member/Partner later | Architecture review at closeout: adding a type needs no change to `people`, `person_relationships`' shape or the projection | 7 |
| **Engines** | Lifecycle, grant sources, transactions, concurrency | Every backend row above on both engines; the races of T1 on both | each |
| **Integration and browser** | Real personas, real assignments | `./flow test e2e --workers=4` with the personas of the testing strategy; no `/me` interception is needed to prove a restricted persona | 7 |
| | Accessible workflows and responsive library modes | axe in both themes; 320, 375 and 1280 px; keyboard paths | 5, 6, 7 |
| | No external delivery | No route reaches policy C except the three Console routes | 4, 7 |

## Work packages

Every package is gated on `./flow check all --pgsql`. Browser packages are also gated on `./flow test e2e --workers=4`. Status lives in the [roadmap](../roadmap.md). The domain and the security boundaries come before the surfaces that depend on them.

**WP2 is split into WP2A and WP2B** (AR1, AR2). The role catalog and its migration change every Console user's authority, and the provisioning lifecycle crosses two modules. Each deserves its own deep audit, and neither should wait for the other's UI. The milestone scope is unchanged.

| WP | Scope | Depends on | Acceptance and required tests | Security focus | Build / audit |
| --- | --- | --- | --- | --- | --- |
| **WP1** | **Relationship foundation backend.** The `Relationships` module: `RelationshipType` (value object), `DefinitionSource` (seam) with the directory source, `DefinitionSchema` and the F5 invariants, `RelationshipCatalog`, and the Guardian and Volunteer definitions with their N4 fields (`default_role` null for both until WP2B). The four tables, with `person_relationship_role_provisions` created but unused until WP2B. Lifecycle, revision, **instance id**, locks and history. Metadata validation, with the UTC+14 date bound. Deletion with verification, `relationship.deleted` and the dependents registry. The four capabilities with the A3 defaults on today's roles. The exemption entries and the A5 verification table. The generated per-type routes for directory (names only), record, management view, candidates (names only), intake of an existing Person by id with `confirm_existing_person`, status, fields and deletion. `GET /admin/relationship-types` and its pinned exception. `QualifyingRelationships`. `relationships:check` (types, states, fields). OpenAPI | WP0 accepted | The relationship identity, capability, lifecycle, metadata and extensibility rows of the matrix, including the third-type test through the real seam and one refusal test per catalog invariant. Races on both engines, mutation-checked. Boundary tests: module graph, no `Access → Relationships`, one definition-selected capability per use case proved by runtime isolation for every type and operation plus the A2 static pins, definitions only in the catalog, the A5 route table, audit callers | Uniqueness; instance identity; verification contract; deletion and audit; type taken from the route; catalog invariants; affiliation never authority; manage-only reads narrow | Opus build, **Opus deep audit** |
| **WP2A** | **Access: role catalog, role-key migration and sourced grants.** The five roles of A3 with their descriptors. `KEY_SHAPE` and the revoke-route pattern. The `guardian` → `guardian-full` migration (M6), `restore-required`. `ConsoleUserFixture` (now granting `guardian-full`) and the restricted-persona fixtures. `sourced_role_grants`, `RoleGrantSource`, `ProvisionableRole` (exactly `guardian-initiate`, with K2's pins), `GrantSourcedRole`, `WithdrawSourcedRoles`, `ListSourcedRoleGrants` (returning each grant's `person_id`, F15), `SourcedRoleGrantsOf`. The `Authorizer` union. `AccountViews` with sourced grants. `PeopleHoldingCapability`. Every test that names `Role::Guardian` moved. The A10 changes to the role-key guard, `CatalogTest`, `MfaBoundariesTest` and the Console's `guardrails.test.ts` role-name rule, plus the new role-identity and retired-key tests | WP1 | The role catalog and migration rows of the matrix. The sourced-grant service contracts tested in Access alone: authorization, idempotency, both-sources coexistence, events with source context, `RevokeRole` untouched, administrator continuity untouched. The migration on both engines, capability sets identical before and after | Every Console user's authority preserved exactly; no role made provisionable that administers access or Resources; no path from a sourced grant into `role_assignments` | Opus build, **Opus deep audit** |
| **WP2B** | **Guardian default-role provisioning and adoption.** Guardian's `default_role` and verified operations. `default_role` (`grant`/`decline`/`defer`) on intake and status, and the `default-role` route. The decision record and the K3 invariant in every lifecycle use case, including deletion's withdrawal. K5's `defer` semantics (never erasing `declined`) and the Console-preselection contract. `409 relationship_not_active` for a default-role `grant` on an Inactive relationship. The grant's Person read only from the locked row (K11). `relationships:check` for grants, including the wrong-Person rule (F15). The `provision_default_role` hint in `DescribeRelationshipTypes` and its static pin (A2). `relationships:guardian-adoption-report`. The adoption runbook entry (adoption ≠ migration). The `InviteAccountForPerson` single-caller pin, and `InviteExistingPerson`'s doc comment corrected to state K6's trust assumption. OpenAPI | WP1, WP2A | Every relationship-managed-grants row of the matrix, including the Account-less Person, the forced-failure rollback, no suppression, no silent restoration, `defer` over `declined`, reactivation with no stored decision, the Inactive `grant` refusal, and the wrong-Person mutation check. Provisioning races on both engines (T1). Independence tests with real personas. The bootstrap path with no Guardian relationships | No provisioning without Access's authority; withdrawal never blocked; no grant outlives its cause; no applicant path to a dormant grant | Opus build, **Opus deep audit** |
| **WP3** | **Relationship-scoped People access and contact provenance.** `contact_methods.updated_by_account_id`, stamped on every changed row by every path in P9's table: CRM's own use cases (including `clearPrimary` demotions and `RemoveContactMethod`'s promotion) and the seam (P9). `Crm\Application\Delegated` and its pinned caller. Basic details in the directory, record and management view. Contact search. Lookup with contact matching. Intake of a new Person with duplicate advice. Scoped basic-detail edits with the expected-value contract, the lock order and hidden-collision promotion, which rewrites the promoted method's display value to the validated `to` (P5). `RenamePerson`'s expected name. The `CrmBoundariesTest` changes of A10 | WP1 | The Volunteer-management and scoped-People rows, including the Volunteer-who-is-a-Guardian edit, the hidden-collision cases (email capitalization, phone formatting, identical value, non-disclosure, promotion and demotion provenance), provenance on every P9 path and the Account-unchanged proofs. Basic-detail and rename races on both engines. CRM's suite unchanged apart from the new provenance assertions. Mutation checks for scope, fields, type isolation, the duplicate oracle and provenance | Writes to another module's data; login identity untouched; no backdoor beyond the accepted P8 scope; no oracle | Opus build, **Opus deep audit** |
| **WP4** | **Resources authorization transition.** The `volunteer` audience. Policy A (`ViewerLibrary`) behind the library routes, with its filters, search and fields. Policy C (`AudienceEligibility` with Actor re-resolution, over Relationships and Membership; `AudienceDelivery`; three use cases). The three `/admin/my-resources` routes and their pinned exception. Preview's management file paths. Revised boundary tests (A10, R15). OpenAPI | WP1, WP2A (for the real restricted personas) | Every Resources row of the matrix, with real `guardian-initiate` and `console-participant` personas. G5's non-disclosure suite passes on policy C through the `my-resources` routes. R15's architecture tests, each mutation-checked | Highest risk: who reads what, and admission | Opus build, **Opus deep audit** |
| **WP5** | **Relationships Console.** Navigation from definitions. The generic directory, record, panel, intake (existing-Person confirmation, ambiguous names), status, fields, basic-details and history components. Instance id and revision on every mutation. The Initiate option (K5) and the default-role panel (C4). The management-view path for manage-only holders. Deletion and Guardian changes through the shared step-up flow. The Person record's Relationships tabs. The extension registry. The Access account page's sourced-grants list | WP1, WP2B, WP3 | Vitest per component and state: both conflict flows, the re-created-instance conflict, step-up, the default-role option's three presentations, manage-only and view-only states. Two control types. Source-boundary tests (no role key, no type name outside the registry). axe and layout | No client-side authority; the Console never defaults to granting a role; no type names outside the registry | Sonnet build, Opus audit (focused) |
| **WP6** | **Resources Console.** `volunteer` in authoring, filters and preview. The library's two modes: viewer mode's filters, markers, Uncategorized group and provenance; audience mode on the `my-resources` contract. The `/resource-library` guard becomes `console.access` with the mode taken from `/me`. One client and one file allowlist per mode. The `403` mode transition (C1) | WP4 | Vitest for both modes and the transition, proving no policy A data survives it. Extended library boundary tests (each mode uses only its client; no management or editor code). axe and layout | The client cannot elevate; the library stays read-only; nothing privileged is shown after a downgrade | Sonnet build, Opus audit (focused) |
| **WP7** | **End-to-end proof, demo and closeout.** `RelationshipsDemoSeeder` (opt-in, `local`/`testing` only, through the use cases): Guardians (one with no role, one former, one with relationship-managed Initiate access, one with `guardian-full` and Initiate), Volunteers in each state, a Guardian who is also a Volunteer and a Member, a Person with no Account and a granted decision. Guardian- and Volunteer-targeted Packs added to the Resources demo. E2E personas from real assignments: a `guardian-full` holder without a relationship, a restricted `guardian-initiate` Guardian, and a `console-participant` with and without a Volunteer relationship. Browser journeys for the whole of G10. `relationships:check` clean on the demo data. Closeout notes and checklist | WP2B, WP5, WP6 | `./flow test e2e --workers=4` green; the integration and browser rows; `./flow check all --pgsql` green; the closeout checklist | Demo data never outside `local`/`testing`; real personas prove restriction end to end | Sonnet build, **Opus closeout review** with the full acceptance gates |

**Why this shape:**
- **WP1 is the foundation alone.** It covers schema, domain, the catalog seam, authorization and the routes over relationship state, with no CRM writes, no role changes, no Resources change and no UI. It fixes the contracts WP2A–WP4 build on: the catalog and its seam, the instance-and-revision mutation contract, the lifecycle transaction shape that WP2B extends, the decision table, and `QualifyingRelationships`.
- **WP2A is Access-only and runs before anything depends on the new roles.** It changes no Relationships behaviour, so its audit can concentrate on one question: is every Console user's authority exactly preserved, and is the sourced-grant mechanism unable to escalate?
- **WP2B is where the two modules meet.** Provisioning's lifecycle, transaction and failure rules get their own deep audit, separate from the role migration.
- **WP3 and WP4 are separate security surfaces, each with a deep audit.** WP3 covers CRM writes, provenance and the boundary with login identity. WP4 covers the Resources transition and its Console audience routes. WP3 needs only WP1. WP4 needs WP1, and WP2A for real restricted personas. WP3, WP2B and WP4 may proceed in parallel once their dependencies are met.
- **Model use.** Opus builds and deeply audits the five architecture-sensitive backend packages (WP1, WP2A, WP2B, WP3, WP4). Sonnet builds the bounded UI packages (WP5, WP6) under a focused, independent Opus review. WP7 runs the full acceptance gates under an Opus closeout review.

## Deferred

Not designed here, and nothing is built for any of it:

- **Relationship types and data:**
  - Member integration into `Relationships`, and Partner (both near-term future work);
  - Vendor and Artist;
  - a preferred name;
  - a reason on status changes;
  - field value history;
  - Guardian seniority levels or trainee states *as relationship states* (seniority is expressed by roles, AR1);
  - capabilities that tell `guardian-senior` apart from `guardian-full`, until their workflows are designed;
  - Volunteer Coordinator or Guardian Steward roles (role-catalog changes when wanted);
  - Member and Partner initiation, activation prerequisites, agreements, subscriptions, renewals, waivers, discounts, coupons and billing (*Future architecture*);
  - basic-detail editing under `guardians.manage` (a Controlled change if ever wanted).
- **Configuration and authorization:**
  - an operator configuration interface;
  - persisted definition versions;
  - operator-added types;
  - registry-defined capabilities;
  - operator configuration of default-role policies, and any provisionable role beyond `guardian-initiate`;
  - groups, Account-level grants, query-time capabilities derived from relationships, and context grants;
  - scoped grants;
  - a domain-event outbox (Part K does not need one, K6).
- **Workflows and guided experiences:** approvals, nominations, applications, onboarding, training, assignments, shifts, hours, quizzes, courses and programs.
- **Resources:**
  - approval workflows, reviewer assignment, review comments, revision history and comparison (decision 14);
  - Boolean and contextual audiences;
  - the WordPress companion;
  - a `/my/` Resources surface;
  - service and delegated authentication.
- **Elsewhere:**
  - notifications;
  - any coupling between Resources and Discussions;
  - erasure and anonymisation policy, still open in [data ownership](../architecture/data-ownership.md).

## Implementation-time observations (not G10 scope)

Recorded for the G6 audit:
- Resources parses a stored `state` and `audience_mode` with `::from` (`DatabaseCardRepository.php:392,422,432`, `DatabasePackRepository.php:252`), so a corrupt value is a `500` rather than a fail-closed omission.
- The library's delivered file has no `available` field (ADR 0037 follow-ups).

## Consequences

**For the organization:**
- Flow Life can record who its Guardians and Volunteers are as facts about people, independent of who may use the software.
- A trusted advisor can hold Console permissions without being a Guardian (`console-participant`, or any other role).
- A junior Guardian can hold few permissions and still read the published Guardian Resources: an Active Guardian with `guardian-initiate`. This is a real persona from G10, proved end to end.
- Recognizing a Guardian can, by an authorized operator's choice, also give them Guardian Initiate access. Stepping down removes it, and the record shows who granted what and why.

**For the platform:**
- **The Guardian-named roles are permission bundles only.** `guardian` is renamed `guardian-full` with identical permissions, and three roles join it. Until Guardians are recorded, no one is Guardian-eligible through audience delivery. Every current Console user holds `resources.view`, so no one loses anything.
- **Access gains a second assignment table**, `sourced_role_grants`, and the `Authorizer` reads both. Independent assignments, their administration and the last-administrator invariant are unchanged. The release with the role-key migration is `restore-required`.
- **The Console has two reading contracts**, privileged and audience-based, behind one library interface. Console admission stays a capability. Relationship eligibility is reusable by a future external surface without the Console.
- **One module and one set of contracts serve both relationships.** The design leaves an honest, bounded path to operator configuration. Partner is a definition, a capability pair and a mapping row. Member can join the contracts without moving.
- **Volunteer managers maintain the basic details of the Volunteers they manage, Guardians included**, under precise field, scope and identity boundaries. A Guardian's login is never reachable through contact data.
- **Everything is visible to `resources.view` holders.** Every holder (from G10, every `guardian-full`, `guardian-senior` and `platform_administrator` holder, and never `guardian-initiate` or `console-participant`) reads Member-only, Volunteer-only, Guardian-only and Draft Resources in the Console, as decisions 11–13 require.
- **New seams and dependencies.** CRM gains one seam, authorized by its caller and pinned to one caller, and gains contact-method provenance. Access gains sourced-grant services pinned to Relationships. Resources depends on Relationships and Membership earlier than ADR 0037 planned. `AdministrationRoutesTest` gains four pinned console-only read routes and two step-up exemptions.
- **Accepted consequence.** A Volunteer manager can bring any Person into their basic-detail scope by recording them (P8). It is bounded, attributed and stated, not prevented.
- **Deletion and data.** Relationship deletion becomes the platform's third audited deletion behind verification. More personal data, and its history, joins the open erasure question.

## Alternatives considered

- **Guardian stays a role.** Rejected by D2.
- **Role grants creating relationships.** Rejected by D1 and ADR 0036, decision 5.
- **Relationships granting roles automatically, by rule or by inheritance.** Rejected by AR2. Part K grants only by an authorized operator's explicit decision.
- **Suppressing the default role when a "higher" role is held, or replacing roles.** Rejected by AR2 (K9). Roles are additive and unranked.
- **A `source` column on `role_assignments`, or reference-counted grant sources.** Rejected for K4's reasons: both would change what existing rows and `RevokeRole` mean.
- **An `Authorizer` that reads relationship state live to validate sourced grants.** Rejected by A8: Access must not depend on relationship state. Transactional consistency plus `relationships:check` instead (K11).
- **Writing no grant until an Account exists, fulfilled by a callback on invitation acceptance.** Rejected (K6): a dependency cycle or an outbox, for no gain over an inert Person-level grant.
- **Restoring a previous grant on reactivation without fresh authority.** Rejected (K8): every new effective grant is authorized by Access when it is made.
- **Recent verification on ordinary Volunteer intake, to address self-scoping.** Rejected by AR3. Deliberate confirmation and provenance instead (P8).
- **A PHP enum of relationship types.** Rejected by AR6 (F2): it would make a test type impossible without a production change, and a persisted type impossible later.
- **Keeping the hyphen out of role keys.** The approved keys are hyphenated (AR1), so the key shape is widened (A10). `platform_administrator` keeps its form.
- **Separate modules and tables per relationship.** Rejected by F1 and F6.
- **Metadata as JSON, or as typed columns per field.** Rejected by F6.
- **Shared `relationships.view` and `relationships.manage` capabilities.** Rejected by A1.
- **Moving Membership into `Relationships` now.** Rejected by D8.
- **A dynamic type engine, a schema designer or a rules DSL in G10.** Rejected by D4. A bounded future path is kept instead (*Configuration readiness*).
- **Declaring code-defined properties permanently immutable.** Rejected by CR. The classification describes the future path rather than forbidding it.
- **A role-catalog rule that manage implies view for the new pairs.** Rejected by N1 and A4. Management gets narrow, deliberate reads instead.
- **Blocking scoped basic-detail edits for anyone who is a Guardian.** Proposed in the previous revision, rejected by N5. Authorization is by operation, field and scope, with login identity separated (P5–P6).
- **One library endpoint that chooses the privileged or the audience policy from the Actor's capabilities.** Rejected. Two separately authorized route families keep each policy's code and tests separate, and the client still chooses neither: each endpoint enforces its own policy.
- **A capability such as `resources.read` for audience reading in the Console.** Rejected. Audience reading is decided by relationships, not capabilities (D1, D7).
- **Recording every role holder as a Guardian at migration.** Rejected by M1 and N2.
- **The earlier rejections still stand** (commit `9eeb4f9` and the first revision):
  - a Volunteer flag, tag or role;
  - one row per volunteering period;
  - status history in `security_events`;
  - copying contact data;
  - CRM authorizing relationship capabilities;
  - direct `contact_*` access;
  - scoped role assignments;
  - a revision column on CRM;
  - privileged viewing implemented as the audience projection with flags;
  - review endpoints beside an unchanged library;
  - inferring `resources.view` from `resources.manage`;
  - signed file URLs.

## Revision history

**First version (`9eeb4f9`, 2026-10-08).** Volunteer relationships only, in a `Volunteering` module, with:
- scoped Volunteer management through a CRM seam pinned to that module;
- no relationship deletion;
- three Resource access paths, with audience eligibility for `member` and `volunteer` only, and none in the Console;
- six work packages.

**First revision (2026-10-08, uncommitted, never accepted):**
- the organizational relationship foundation in one `Relationships` module;
- Guardian as a relationship, with manual adoption;
- `guardians.*` capabilities;
- definitions split into locked and descriptive properties;
- verified, audited deletion;
- a "protected basics" rule for Guardians;
- audience delivery with no route;
- seven work packages;
- six open decisions, N1–N6.

**Second revision (`30a5443`, 2026-10-08): the product owner's rulings.**

*Resolved:* N1–N6 (Approved requirements).

*Changed:*
- **Capabilities.** Each pair is independent, with narrow management reads (A4) and no pairing pin.
- **Verification.** The exact contract is A5: Guardian recognition, status changes and every deletion are verified.
- **Scoped basic-detail edits.** Allowed for Guardians in Volunteer scope, with the field, scope and login-identity boundaries of P5–P6. The "protected basics" rule is removed.
- **Audience-based reading in the Console.** Added (R14, C7), with three console-only routes and the library's two modes.
- **Bootstrap.** The path is explicit (M3).
- **Fields.** Defined in full (F7).
- **Configuration readiness.** Rewritten as code-defined today with an honest future path, bounded by mandatory invariants (F4–F5).
- **Work packages.** WP4 and WP6 cover the Console audience contract.

*Unchanged:* every approved decision 1–14 and D1–D8, the shared persistence, the delegated seam, policy A, non-disclosure, and the earlier threat mitigations.

**Third revision (`96a01ea`, 2026-10-08): remediation of the independent architecture audit.** The audit found no blocker, no high finding, five medium findings and ten advisories. The product owner's rulings are AR1–AR10.

*Medium findings:*

| Finding | Disposition | Where |
| --- | --- | --- |
| M1: a Volunteer manager can self-establish scope | Accepted by product decision (AR3); stated with its real safeguards; deliberate attachment confirmation added | P3.4, P4, P8, threats 4a and 5a |
| M2: contact provenance claim was false | Corrected (AR4): `contact_methods.updated_by_account_id` on both edit paths; expected values, lock order and hidden collisions specified | P5, P9, T2 |
| M3: `guardian` literal collides with the role-key guard | Resolved (AR5): the role key becomes `guardian-full`; the guard and its replacements specified | A10, M6 |
| M4: test-only type under-specified; no capability-uniqueness invariant | Resolved (AR6): value-object type, one source seam, catalog invariants, and an honest third-type test | F2, F3, F5, *Verification matrix* |
| M5: no real user lacks `resources.view` | Resolved (AR1, AR7): `guardian-initiate` and `console-participant`; real-persona browser tests | A3, R12, *Testing strategy* |

*Advisories:* the ADR 0037 amendment inventory (header); stale architecture documents ([member access](../architecture/member-access.md), [data ownership](../architecture/data-ownership.md), [charter](../architecture/charter.md)); the intake race wording (P7, T1); the instance id on mutations (F10); Actor re-resolution (R10); the contact expected-value contract and hidden collisions (P5); the integrity check, date zone and lock order (F15, F7, T2); the CRM seam's test pins (A10); the directory's id-set bound and the future reuse of policy C (C3, R15); the client's response to capability changes (C1). All are incorporated. None is deferred.

*Added by product decision:* the five-role catalog and the role-key migration (AR1, M6); relationship-managed default-role provisioning (AR2, Part K); the Member and Partner direction (AR10).

*Work packages.* WP2 is split into WP2A (Access) and WP2B (provisioning and adoption), each with a deep audit.

*Unchanged:* every approved decision 1–14, D1–D8 (D1 qualified only as AR2 states) and N1–N6; the shared persistence; the delegated seam; the three Resource policies; non-disclosure.

**This version (2026-10-08): remediation of the focused verification.** An independent verification of `96a01ea` confirmed that M1–M5 and the ten advisories were resolved. It found one medium issue and eight low ones. All are corrected. No approved decision, product ruling or work-package boundary changes.

| Finding | Correction | Where |
| --- | --- | --- |
| MEDIUM-1: promoting a hidden contact method kept its previous display value. CRM matches by `search_value` but returns the stored `value`, so the response could reveal that a hidden method existed and how it was written | The promoted method's display value is rewritten to the validated `to`, keeping its `search_value`. Responses and later reads are identical to a plain replacement. Regression cases cover email capitalization, phone formatting, identical values, non-disclosure and provenance | P5, P6, P9, threat 5a, *Verification matrix*, WP3 |
| LOW-1: `relationships:check` did not bind a grant to its relationship's Person | The check reports a grant whose `person_id` differs from its relationship's. `GrantSourcedRole`'s Person is read only from the locked row. A WP2B mutation check passes the operator's Person | F15, K4, K11, threat 34, WP2B |
| LOW-2: decision edge cases | A default-role `grant` on an Inactive relationship is `409 relationship_not_active`, and nothing is written. `defer` never erases a `declined` decision and removes only a `granted` one. Reactivation with no stored decision is preselected in the Console but grants only on an explicit, authorized `grant` | K2, K5, K7, K8, C4, Part H, threat 28, WP2B |
| LOW-3: who makes a dormant grant effective | The trust assumption is stated. The invitation authority decides which mailbox exercises a dormant grant; in G10 it is held only by administrators, together with provisioning; any separation or delegation needs a fresh security review. WP2B corrects `InviteExistingPerson`'s doc comment | K6, threat 29, WP2B |
| LOW-4: provenance paths incomplete | P9 stamps every changed row on every path, including `clearPrimary` demotions and `RemoveContactMethod`'s promotion | P9, WP3 |
| LOW-5: the Resources pin read as a permanent ban | Restated as G10 policy, enforced exactly as before. Eligibility never implies `resources.view` (D7). A future default role with a Resources capability would be a deliberate, separately reviewed catalog decision | K2, R4, A10 |
| LOW-6: the Console's role-name guardrail was missing from A10 | Added, owned by WP2A, re-keyed to the five roles | A10, WP2A |
| LOW-7: one capability per use case could not be proved by a literal scan | Capabilities come from the validated definition, proved by runtime isolation per type and operation. `DescribeRelationshipTypes` is the one exception: it names `AssignRoles`, for a hint only | A2, A10, threat 33, WP1, WP2B |
| LOW-8: terminology | "Quiverly" is now Bestside, noting its former codename, here and in the roadmap and module map. Accepted ADR 0037 keeps its original wording as a historical record | *Future architecture* |

*Also corrected:* `CatalogTest`'s `ProvisionableRole` pin is assigned to WP2A, which builds the enum.
