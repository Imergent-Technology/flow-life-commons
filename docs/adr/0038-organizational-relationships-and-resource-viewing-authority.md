# ADR 0038: Organizational Relationships and Resource Viewing Authority

- **Status:** Proposed. This is the G10 design gate (WP0) in its final design, ready for independent audit. Decisions N1–N6 are resolved by the product owner. Nothing is implemented.
- **Date:** 2026-10-08. Revised twice before acceptance on the same day, recorded under *Revision history*. The first version is commit `9eeb4f9`.
- **Supersedes:** none
- **Superseded by:** none
- **Amends:**
  - [ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md):
    - decision 3: Volunteer is owned by the `Relationships` module, not by a separate Volunteering module;
    - decision 7: Guardian becomes a business relationship and is no longer represented only in Access;
    - the rejected alternative "a generic relationships table now": its trigger, a second real consumer, has arrived.
  - [ADR 0037](0037-resources-are-audience-targeted-packs-of-cards.md), decisions 42–46, 49–51, 66 and 69. They change:
    - who reads what in the Console;
    - the `volunteer` audience is added;
    - Guardian eligibility comes from the Guardian relationship;
    - Console users without `resources.view` can read what they are eligible for;
    - Membership becomes a dependency of Resources earlier than planned.
  - Every other decision of both ADRs is unchanged.
- **Related:** [ADR 0005](0005-mariadb-with-postgresql-portability.md), [ADR 0007](0007-versioned-rest-api-openapi.md), [ADR 0009](0009-authorization-separate-from-approval.md), [ADR 0015](0015-identity-owns-person.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0019](0019-security-event-auditing-seam.md), [ADR 0020](0020-administrator-bootstrap-and-last-administrator-invariant.md), [ADR 0021](0021-cross-module-referential-integrity.md), [ADR 0023](0023-multi-factor-authentication.md), [ADR 0024](0024-privileged-operator-administration.md), [ADR 0025](0025-account-security-generation.md), [ADR 0028](0028-membership-grants-derived-at-query-time.md), [ADR 0033](0033-service-identity-and-delegated-human-authority-are-distinct.md), [ADR 0034](0034-crm-enriches-identity-person.md)

## How to read this ADR

Every statement below is one of four kinds:

- **Approved requirement.** A product decision already made, and not reopened here. These are:
  - the fourteen G10 planning decisions (1–14);
  - the organizational direction (D1–D8);
  - the six rulings on the questions the previous revision raised (N1–N6);
  - the correction to configuration readiness (CR).
- **Proposed mechanism.** How this ADR meets those requirements. It becomes binding when the ADR is accepted.
- **Deferred.** Recorded so that the design leaves room for it. It is not designed here and nothing is built for it.
- **Future path.** What a later change would require, stated honestly. It is not a commitment.

## Context

G5 (Resources) is complete and merged. G10 is next on the [roadmap](../roadmap.md).

This ADR has gone through three versions:
1. **First version** (commit `9eeb4f9`). It designed G10 around the Volunteer relationship only.
2. **First revision.** It made Guardian an organizational relationship, independent of roles. It put both relationships on one shared foundation whose definitions are centralized and declarative. It raised six questions.
3. **This version.** It records the product owner's rulings on those six questions, three of which refine or reverse the revision's recommendation. It also corrects how configuration readiness was described.

The rulings that changed the design:
- **Basic details of Guardians (N5).** A Volunteer manager may edit the approved basic details of a Person in their Volunteer scope even when that Person is a Guardian. The safeguard is a precise boundary on which operation, which fields and which scope is involved, together with a verified separation between contact data and login identity. Guardian affiliation is no longer an automatic block.
- **Console reading for people without `resources.view` (N6).** Console users who lack `resources.view` read the published Resources their relationships make them eligible for, in G10.
- **Independent capabilities (N1, CR).** Every capability pair is independently assignable. A manage-only holder gets only the narrow reads that managing needs.
- **Configuration readiness (CR).** The split between what is code-defined today and what may become configurable is not a permanent ban. It is a statement of what G10 builds and what a later, controlled change would need.

What carries over unchanged: everything else from the earlier versions. That includes the Volunteer decisions, the shared persistence, the scoped People seam, the separation of privileged viewing from audience delivery, and the threat mitigations.

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
  - lines 148 and 189 pin that today's roles grant CRM manage only with CRM view, and Resources manage only with Resources view. These describe how the current roles are composed. The capabilities themselves are still checked independently.
  - lines 205–212 pin that no role is named `member` or `volunteer`.

**People / CRM**

- **People are thin.** `people` holds an id, `display_name` and timestamps. There is no preferred name.
- **Renaming belongs to Identity.** `RenamePerson` authorizes nothing ("Identity never learns which capability CRM checked"), locks the row and records `person.renamed`.
- **Contact data belongs to CRM.**
  - `contact_profiles`, keyed by `person_id`, is also the per-Person write lock.
  - `contact_methods` keeps one primary per kind (`unique(person_id, primary_kind)`).
  - CRM has no optimistic concurrency; its screens send only changed fields.
  - `RegisterContact` gives duplicate advice (`409 possible_duplicate`; `confirm_distinct` overrides it).
- **CRM's boundaries are pinned.** Each CRM use case asks for exactly one capability (`CrmBoundariesTest.php:254-299`), and nothing outside CRM uses CRM (`CrmBoundariesTest.php:82`).

**Membership**

- `GetCurrentMembership(PersonId)` answers current membership, derived at query time. It authorizes nothing.
- Membership's history is its non-deleting grant rows ([ADR 0028](0028-membership-grants-derived-at-query-time.md)).

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
| D1 | Relationships, roles and audiences are independent concepts. A relationship never establishes a role, and a role never establishes a relationship | F1, A8–A9, R10 |
| D2 | Guardian is a first-class relationship attached to a Person. It coexists with Volunteer, and later Member and Partner. It is never inferred from a role name or from Console capabilities | G1–G6, M1–M5 |
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
| N1 | `guardians.view` and `guardians.manage` are introduced. Initially, `guardians.manage` goes to Platform Administrators and `guardians.view` to the `guardian` role. The capabilities do not depend on each other. Initial grants are not permanent rules, and future roles may receive either one independently. Guardian status never grants either. Sensitive Guardian changes (recognition, deactivation, reactivation, deletion) require recent verification. Manage-only holders get only the narrow reads management needs | A1–A6, G6 |
| N2 | The Guardian lifecycle is Active and Inactive. Junior or trainee Guardians are represented by restricted roles, not by a state. Adoption is deliberate and manual, supported by a read-only reconciliation report. There is no automatic creation from roles, Console access, Resource capabilities or administrative Accounts, and no automatic production migration. There must be a safe bootstrap path that works with no Guardian relationships | G2, M1–M5 |
| N3 | Permanent deletion removes the relationship, its metadata and its status history. A minimal security audit record remains. It is atomic and verified, and it never touches the Person or other relationships. Future relationship-linked records can constrain deletion, but never by cascading silently. Deactivation and reactivation stay non-destructive | F12 |
| N4 | Initial optional fields: Guardian `recognized_on` and `stewardship`; Volunteer `interests` and `availability`. They are descriptive and never assign roles, permissions or eligibility | F7, G4, V2 |
| N5 | **Revised.** No blanket prohibition for Guardians. A Volunteer manager may maintain the approved basic fields of a Person in their Volunteer scope even when that Person is a Guardian. Authorization is specific to the operation, the field and the scope. Login identity, credentials, roles, capabilities and Guardian relationship state remain out of reach | P5–P7 |
| N6 | **Revised.** G10 delivers audience-based reading of published Resources in the Console to Console users without `resources.view`, through a separate backend contract and the existing library interface. Console admission is still required independently. Future external delivery uses the same eligibility, and the eligibility is not coupled to the Console | R4, R10–R15, C7 |
| CR | Code-defined today is not immutable forever. The design keeps a practical, honest path to operator configuration, bounded by mandatory invariants | F4–F5, *Configuration readiness* |

## Decision

### Part F — The relationship foundation

**Ownership and shape**

F1. **A new `Relationships` module owns organizational relationships.**

It owns:
- the registry of relationship types and their definitions;
- relationship instances, their lifecycle, status history and metadata values;
- the eligibility read that other modules ask;
- the presentation contract the Console renders;
- the orchestration of relationship-scoped People operations (Part P).

It does not own:
- Persons (Identity);
- contact data (CRM);
- Accounts, roles or capabilities (Identity, Access);
- Membership, which keeps its own module, model and history (D8).

The product word is *relationship*. The Person record has a "Relationships" section, and the types appear as "Guardians" and "Volunteers".

**Why one module and not one per relationship.** Guardian and Volunteer share everything structural: one ongoing instance per Person, a small status lifecycle, history, descriptive metadata and deletion.
- Separate modules would need the same lifecycle engine, history, validation and concurrency code, either duplicated or placed in `Shared`. The brief forbids duplication. The charter keeps `Shared` a tiny kernel.
- One module still means one authoritative owner per relationship, as ADR 0036, decision 3, requires.

**Why ADR 0036's objection no longer applies.** ADR 0036 rejected "a generic relationships table now" because only Membership existed. Two similar consumers now exist. Membership stays where it is: its time-bounded grants are a different model (ADR 0028).

F2. **Relationship types form a registry, which is closed and code-defined in G10.**
- **The registry.** `Relationships\Application\RelationshipType` lists the types the platform knows: `guardian` and `volunteer`. Every consumer asks the registry. No consumer assumes the set.
- **Storage.** A type is stored as a validated `string(32)`. A stored type the registry does not know is ignored by every read and by every eligibility answer (fail closed), and an integrity check reports it.
- **Adding a type in G10** is a code change: a registry entry, a definition, its capabilities, and its routes, which are generated from the registry.
- **Adding types through operator configuration later** is a deliberate future path. It is described under *Configuration readiness*, together with what it would require.

**Definitions: centralized and declarative**

F3. **Each type has one declarative definition, kept in one place.**
- Each definition is a data document: a PHP file returning a plain array, one file per type, in `app/Modules/Relationships/Definitions/`.
- `Relationships\Application\RelationshipCatalog` loads each document through `Relationships\Domain\DefinitionSchema`, which validates it, and turns it into an immutable `RelationshipDefinition` value.
- A definition that fails validation fails closed: the catalog refuses it and a test fails.

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
- recent-verification requirements on security-sensitive operations.

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
    'capabilities' => ['view' => 'volunteers.view', 'manage' => 'volunteers.manage'],
    'features' => ['directory', 'people.lookup', 'people.create_person', 'people.read_basics', 'people.edit_basics'],
    'verification' => ['delete'],
    'deletion' => true,
    'presentation' => ['position' => 20],
];
```

**Persistence**

F6. **Three tables, all owned by `Relationships`:** one instance table, one history table and one metadata table.

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
- **`date`:** stored as `YYYY-MM-DD`. Optional `not_after: today`.
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
- **Tightening a constraint:** first run `relationships:fields:check`, which lists the stored values that would now fail. Those values stay readable, and any edit must satisfy the new rule.

Values carry no definition version. A field's key and value type change only through a migration, so a stored value's meaning cannot drift under it.

**Lifecycle and concurrency**

F9. **Each type defines its lifecycle; the rules around it are shared.**
- States, initial states and transitions come from the definition.
- Creation must use an initial state, and a change must be an allowed transition.
- Asking for the current state succeeds and changes nothing.
- **Deactivation** keeps the relationship's id, metadata, history and provenance (D5).
- **Reactivation** moves the same row back. A duplicate is impossible because of the unique index.

F10. **Optimistic concurrency and row locks.** This is the Resources pattern (ADR 0037, decisions 56–57).
- Every status change and field edit states the `revision` it was based on.
- The use case then, in one transaction:
  - locks the row (`SELECT ... FOR UPDATE`);
  - compares the revisions;
  - writes the change, its provenance and `revision + 1`;
  - for a status change, writes exactly one history row with the true `from_status`.
- A mismatch is `409 stale_revision`, carrying the current state.

**What history records.** The history is complete by construction: one row for creation and one for each status change, committed with the change itself.
- Field edits record provenance only, with no history of values. This follows CRM's precedent for descriptive data.
- There is no reason field. Its likeliest content is sensitive, and everyone with view access reads the history. A narrative belongs in CRM notes.

**History and audit**

F11. **Status history is business history, not a security event.**
- This follows Membership (ADR 0028) and CRM (ADR 0034, decision 17).
- Creating a relationship, changing its status and editing its fields call no audit seam.
- The one exception is permanent deletion (F12).

**Permanent deletion (N3)**

F12. **Permanent deletion is deliberate, verified, atomic and audited.**

*Authorization.* The type's manage capability, then recent verification (`security.verified`). The definition must allow deletion; both G10 types do.

*Refusals.* A refusal deletes nothing and records nothing.
- **Dependents:** `409 relationship_in_use`.
  - The use case consults the module's dependents registry. Each future module whose records reference a relationship registers a check there, with its own retention rule, and holds a `RESTRICT` foreign key to `person_relationships.id` as the backstop.
  - Deletion never cascades into another module's data.
  - G10 registers no dependents.
- **Missing relationship:** `404`.
- **Missing capability or verification:** the ordinary refusal.

*The transaction.* All of this happens in one transaction:
1. Lock the relationship row.
2. Delete its field values, then its status history, then the relationship itself. Children go first, because of the `RESTRICT` keys.
3. Record one security event, `relationship.deleted`, through `Audit\Application\RecordSecurityEvent`, with outcome `success`. It holds:
   - `actor_account_id`;
   - `subject_person_id`;
   - `occurred_at`, which is the seam's own time;
   - a context of `relationship_id`, `relationship_type`, the status at deletion, and the counts of history rows and field values removed.

   It never holds a field value, a label, a name or a contact detail. The ADR 0037, decision 55, precedent applies: the event records the act and copies none of the content.
4. If the event cannot be written, nothing is deleted.

*What deletion never touches:*
- the `people` row;
- CRM data;
- the Person's other relationships;
- roles and Accounts.

*Afterwards:*
- The Person may later receive a new relationship of the same type. That is a new instance and not a reactivation.
- Any scope that came from the deleted relationship (Part P) ends with it.

*Deletion is not deactivation.* Deactivation and reactivation stay non-destructive (F9).

*Audit wiring.* `MembershipTrustBoundariesTest`'s list of callers gains `Relationships\Application\DeleteRelationship` by name. A Relationships boundary test pins that this is its only use of `Audit`.

**Presentation and eligibility contracts**

F13. **The presentation contract.** The server describes relationships in three shapes. All are built from definitions, filtered by the Actor's capabilities, and versioned.

- **`RelationshipTypeView`**, from `GET /admin/relationship-types`. It covers each type for which the Actor holds the view **or** the manage capability, and contains:
  - `type`, `slug`, labels and `definition_version`;
  - the states, initial states and transitions;
  - the fields the Actor may see;
  - `features` and `presentation`;
  - `actions`: which of `view`, `manage` and `delete` this Actor may attempt. This is a hint for the Console; the server still decides every request.
- **`RelationshipView`**, the record's read for the view capability. It contains:
  - the type and `definition_version`;
  - the Person's id and display name, and their basic details where the type reads them (P2);
  - the status (key, since, by), the `revision` and when and by whom it was created;
  - the visible field values;
  - the history.
- **`RelationshipManagementView`**, the management read for the manage capability (A4). It contains:
  - the Person's id and display name;
  - the basic details, only where the type edits them (P5);
  - the status, the `revision` and the allowed transitions;
  - every field value.

  It contains no history and no provenance beyond what management needs.

Provenance names are resolved by `FindPeople`.

**Extending to Member or Partner later (D8).**
- **Partner** is a new type: a registry entry, a definition, capabilities and routes.
- **Member** keeps Membership's model. A later gate may add a read-only adapter that produces `RelationshipView` from `GetCurrentMembership`, so that the Person record can show it through the same contract.

F14. **The eligibility read.** `Relationships\Application\QualifyingRelationships(PersonId): list<RelationshipType>` returns the types whose current status `qualifies`.
- It authorizes nothing. Its caller passes a Person resolved from the session.
- It reads the current rows on every call. Nothing is cached.
- Its only consumer is Resources' `AudienceEligibility` (R10), and an architecture test pins that.

### Part G — The Guardian relationship

G1. **Guardian is a relationship type** (D2). This amends ADR 0036, decision 7.
- A Guardian is a Person with a `guardian` relationship. Nothing else in the platform says who is a Guardian.
- The relationship coexists with a Volunteer relationship and, later, Membership or Partner.
- The `guardian` **role** is a bundle of capabilities and nothing more. After G10:
  - holding the role is no evidence of being a Guardian;
  - lacking the role is no evidence of not being one (Part M).

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
- **No trainee, junior or seniority state.** A junior or trainee Guardian is an Active Guardian whose Account holds whatever restricted roles the operators assign. Any authorized combination of roles and capabilities is valid for an Active Guardian.

G3. **Affiliation is not authority** (D3).
- A Guardian relationship grants nothing:
  - no capability;
  - no `console.access`;
  - no Resource viewing;
  - no Guardian management.
- Authorization never consults the Actor's own relationships (A9).
- A Guardian changes another Guardian's relationship only by holding `guardians.manage`, exactly as a non-Guardian operator would.

G4. **Guardian fields** (N4): `recognized_on` and `stewardship`, both optional and descriptive (F7). Guardian eligibility derives from the lifecycle state alone (R10).

G5. **Guardian features:**
- Enabled: `directory`, `people.lookup`, `people.create_person`, `people.read_basics`.
- Not enabled: `people.edit_basics`.
  - **What this means.** `guardians.manage` alone does not edit a Person's name or contact details. Those are maintained through CRM (`crm.people.manage`), or through a Volunteer scope where the Person is also a Volunteer (P5).
  - **Why.** Guardian management is about recognition, and no product decision asks Guardian managers to maintain contact data.
  - **How to change it later.** Enabling the feature for Guardians is a "Controlled" change (F4).

G6. **Guardian capabilities** (N1): `guardians.view` and `guardians.manage`, independently assignable (A1–A6). Recognition, deactivation, reactivation and deletion require recent verification. Field edits do not (A5).

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
- **Who may call it.** Its only permitted caller is `Relationships\Application`. An architecture test pins this.
- **What it does not do.** It knows nothing of relationship types, names no capability, and no HTTP class reaches it.
- **Why it is not a backdoor.** Its operations are fixed to the five below. Every call is preceded by Relationships' authorization and scope check (P3).

| Service | Does |
| --- | --- |
| `ReadPersonBasics(list<PersonId>)` | Batched: id, display name, primary email value, primary phone value. Nothing else |
| `PeopleMatchingContact(string $text, list<PersonId> $within)` | Ids among `$within` whose CRM email contains the text, or whose phone digits contain its digits (3 or more) |
| `FindPersonCandidates(string $query)` | The bounded lookup of P4 |
| `RegisterMinimalPerson(name, ?email, ?phone, bool $confirmDistinct, Actor $by)` | A new Person with at most one email and one phone, each primary. It gives `RegisterContact`'s duplicate advice and shares that implementation |
| `UpdatePersonBasics(PersonId, BasicsChanges, Actor $by)` | Compare-and-set of exactly the three basic fields (P5) |

The rejected alternatives stand: CRM authorizing relationship capabilities itself (a cycle, and CRM would have to know every relationship), direct table access, and copying contact data.

P2. **What a relationship's capability may read about a Person.** Only for a Person who holds a relationship of that type, in any state, and only where the type has `people.read_basics`. A manage-only holder reads them through the management view (A4).
- **It reads:** the display name, the primary email value and the primary phone value.
- **It never reads:**
  - other contact methods or labels;
  - profile fields, tags, notes or interactions;
  - Membership, Account or security data;
  - the Person's relationships of other types, unless the Actor holds those types' capabilities.

P3. **The scope rule.** Every Relationships use case enforces it, before any delegated call, from Relationships' own table:
1. **Capability.** The Actor holds the type's capability that the operation needs (A1, A4).
2. **Feature.** The type's definition enables the feature.
3. **Relationship.** For a read or an edit of basic details, the Person holds a relationship of **that** type, in any state.
   - Otherwise the answer is `404 relationship_not_found`, whether or not the Person exists, so the routes cannot be used to test whether a Person exists.
   - The scope is the Volunteer relationship itself. It does not depend on, and is not narrowed by, the Person's other relationships.
4. **Intake.** Lookup and minimal creation exist only for intake, which creates the relationship in the same transaction.
   - Selecting an existing Person who has no relationship of the type establishes the relationship and changes nothing else.
   - No basic detail of a Person can be edited before the relationship exists.

The scope does not lapse while the relationship exists: Inactive relationships are included (decision 9). It ends with permanent deletion.

P4. **Limited lookup**, under the type's manage capability and `people.lookup`.
- **The query:** 3 to 255 characters, matched in one of three ways:
  - an address (it contains `@`) matches CRM contact emails exactly, never Account logins;
  - a phone number (at least 7 digits) matches exactly on its digits;
  - anything else is a case-insensitive "contains" match on the display name.
- **The result:** at most 10 candidates and a `truncated` flag. Each candidate carries:
  - the Person id and display name;
  - `matched_on`;
  - the candidate's status **in this type only**.
- **Never returned:** contact values, other relationship types, profile, tags, Account information.

P5. **Scoped editing of basic details** (N5), under the type's manage capability and `people.edit_basics`. In G10 that means Volunteers.

The authorization is specific to the operation, the field and the scope.

- **The operation.** One use case, `UpdateRelationshipPersonBasics`. Nothing else in Relationships writes to People data.
- **The fields.** Exactly three:
  - the display name, through Identity's `RenamePerson`;
  - the value of the primary CRM email;
  - the value of the primary CRM phone.

  Each is sent as `{from, to}`. Any other key in the request is `422`.
- **The scope.** The Person holds a relationship of this type, in any state (P3). A Person who is also a Guardian, Member or anything else is in scope on the same terms. Affiliation neither blocks nor widens what may be done.
- **CRM's rules apply unchanged:**
  - replacing a primary updates that method under CRM's normalisation, validation and duplicate rule (`409 duplicate_contact_method`);
  - setting a primary where none exists adds one;
  - clearing a primary is not offered;
  - CRM's provenance is recorded (`contact_profiles.updated_by_account_id`).
- **Concurrency.**
  - The contact fields are compared under CRM's profile lock.
  - The name is compared under `RenamePerson`'s row lock, through a new optional expected-name argument.
  - Any mismatch writes nothing and answers `409 stale_person_basics` with the current values, so a concurrent CRM change is never silently overwritten.
- **Audit.** A name change records `person.renamed`, exactly as it does from CRM.

P6. **What scoped editing can never reach** (N5). These limits are pinned by tests:
- **Login identity and credentials.**
  - The Account's login email, its `email_canonical`, its password, its second factor, its recovery codes, its sessions and its security generation are Identity's.
  - Relationships has no dependency on any of these, and Part P names none of them.
- **Contact email is not login email.**
  - Changing a CRM contact email changes no login, invitation or recovery address. That is the existing separation (*Repository facts*), pinned by tests that do the edit and show the Account unchanged.
  - An architecture test pins that Identity never depends on CRM.
  - The Console's invitation form is never pre-filled from CRM contact data without an explicit operator action. A future change that wants to pre-fill it must bring a design decision of its own.
- **Roles, capabilities and Console admission.** These are Access's. Relationships never depends on role assignment.
- **Guardian relationship state and metadata.**
  - A Volunteer route can only act on the Volunteer relationship: the type is taken from the route's literal segment (Part H), and a field is always validated against the stored relationship's own type.
  - Nothing in a Volunteer operation can recognize, deactivate, reactivate, edit or delete a Guardian relationship.
- **Other CRM data.** Other contact methods, labels, profile fields, tags, notes and interactions are untouched. The seam has no operation that writes them.

P7. **Minimal creation and intake**, under `people.create_person`.
- A new Person is created with CRM's duplicate advice: `409 possible_duplicate`, the candidates, and `confirm_distinct` to proceed anyway.
- Nothing is merged or adopted (ADR 0034, decision 10).
- The new Person, the relationship and its creation history row commit together.
- If two intakes race, the one that loses on the unique index answers `409 relationship_exists` and rolls back any Person it created.

### Part A — Authorization

A1. **Four new capabilities, one pair per type, all independently assignable** (N1).

| Capability | Authorizes |
| --- | --- |
| `guardians.view` | The Guardian directory. Reading any Guardian relationship (status, dates, history, visible fields) and its Person's basic details (P2). Changes nothing |
| `guardians.manage` | Lookup and intake (recognition). Status changes (deactivation and reactivation). Field edits. Permanent deletion. The supporting management reads in A4 |
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
- Each Relationships use case asks for exactly one capability. A `RelationshipsBoundariesTest` pins this, as `CrmBoundariesTest` does for CRM.
- The Console infers no capability from another.

A3. **The initial role mapping** (N1). These are defaults of today's two roles, not rules.

| Role | `guardians.view` | `guardians.manage` | `volunteers.view` | `volunteers.manage` |
| --- | --- | --- | --- | --- |
| `platform_administrator` | ✓ (derived) | ✓ (derived) | ✓ (derived) | ✓ (derived) |
| `guardian` (the role) | ✓ | — | ✓ | ✓ |

- A future role may hold any subset: a Volunteer Coordinator with `volunteers.manage` only, or a Guardian Steward with `guardians.manage` only. Either is a role-catalog change, and no such role is created here.
- `CatalogTest` pins this mapping, so changing it is visible and deliberate.

A4. **Supporting reads for manage-only holders** (N1, CR). Management grants the reads that managing needs, deliberately and narrowly, and never the view capability:
- **The candidate lookup (P4).** It is how a manage-only holder finds the Person they will act on, including someone who already holds a relationship of the type.
- **The management read** (`RelationshipManagementView`, F13) of one relationship. It is what an edit form, a status change or a compare-and-set needs: status, revision, allowed transitions, field values, and the three basic details where the type edits them.
- **Write responses.** These return the same management view.
- **The type's definition**, through `GET /admin/relationship-types`, which includes types the Actor may only manage.

A manage-only holder therefore gets no directory, no history, no `RelationshipView`, and no CRM read.

**The role-catalog pairing.** G10 adds no "manage implies view" pin for the new pairs. The existing pins for CRM and Resources (`CatalogTest.php:148,189`) describe today's two roles. They remain as G1 and G5 left them. A role that separates those pairs would revise its pin deliberately, and nothing in this design depends on the pins.

A5. **Recent verification: the exact contract** (N1).

| Operation | Guardian | Volunteer |
| --- | --- | --- |
| Intake: establishing a relationship, with or without creating a minimal Person | **Required.** Recognition confers Guardian eligibility | Not required |
| Status change (for Guardians this is always a deactivation or a reactivation) | **Required** | Not required |
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
- `console.access` comes only from roles.
- A relationship never confers it, and no route described here relaxes it.
- A Person with an Active Guardian relationship and no `console.access` cannot enter the Console. Their eligibility applies only on a future surface (R14).

A8. **Relationships never become roles, and roles never become relationships** (D1; ADR 0036, decision 5).
- Relationships never grants or revokes a role or capability, and never reads `role_assignments`.
- Access never depends on Relationships. The module graph allows `Relationships → Access` and never `Access → Relationships`.
- `/me` is identical before and after any relationship change. A test pins this.

A9. **Authorization never consults the Actor's affiliation** (G3).
- No use case's authorization depends on whether the Actor is a Guardian or a Volunteer.
- Relationship authorization never names a role: no Guardian-named role is hardcoded.
- The future direction for grants derived from relationships is described under *Future architecture*. It is not built.

### Part M — Guardian adoption and the transition from the role

M1. **No automatic mapping** (N2). Nothing creates or removes a Guardian relationship because a Person holds the `guardian` role, holds `console.access` or Resource capabilities, or has an administrative Account. This rules out every migration, seeder, scheduled job and rule.
- **Why they differ.** The role is a permission bundle. The relationship is organizational recognition.
- **Who holds the role today.** Its holders may include people who are not Guardians.

M2. **Adoption is deliberate, authorized recording** (N2).
- After the backend package ships, a Platform Administrator records each Guardian through the ordinary Guardian intake. The administrator holds `guardians.manage` by derivation.
- For each Guardian: look the Person up, establish the relationship as Active, set "Guardian since", and confirm with recent verification.
- Each record goes through the same validation, verification, provenance and history as every later one.
- No automatic production migration of Guardian status is authorized.

M3. **The bootstrap path** (N2). Establishing the first Guardian relationships needs nothing that only Guardians have:
- `guardians.manage` comes from the Platform Administrator role.
- That role exists from `BootstrapAdministrator` (ADR 0020), whose root of trust is server access.
- No Guardian-management operation requires the Actor to be a Guardian, or requires any Guardian relationship to exist already.

So an installation with no Guardian relationships can always be brought up, and no second bypass is built.

M4. **The reconciliation report** (N2). `php artisan relationships:guardian-adoption-report` is read-only. It lists:
- Persons who hold `console.access` and have no Guardian relationship. They are listed for an administrator to review, with a heading saying that many Console participants are not Guardians. The report suggests nothing and converts nothing.
- Guardian relationships whose Person has no Account, or no `console.access`. This is informational; both are valid.

How it works:
- It asks Access by **capability**, through a new `Access\Application\PeopleHoldingCapability(Capability)`, so it never names a role (ADR 0017).
- It prints ids and names, and writes nothing.

M5. **Before, during and after adoption.**
- **Before adoption, no one is Guardian-eligible on audience delivery.**
  - Console users who hold `resources.view` read everything through path A, which is unaffected.
  - Console users without `resources.view` see only what other relationships make them eligible for, until they are recorded.
  - Today every Console user holds `resources.view`, so no one loses anything.
- **Unchanged by adoption:** Console admission, capabilities and every existing screen.
- **Accounts with capabilities and no Guardian relationship** keep every capability. These include administrators and trusted advisors. They are simply not Guardians.
- **The role's display name.** WP2 relabels the `guardian` role's display name and description, for example to "Standard Console access", unless the product owner prefers to keep it. Its stored key, `guardian`, stays. This is a naming choice. It does not affect any decision.
- **Production data** is not touched by this gate or by any migration. Adoption is an operator task after release. The release notes record it, with the report as its checklist.

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
  - A relationship never grants A or B.

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
- `q`, over the titles and summaries of a Pack and **all** its Cards;
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
- It knows nothing of the Console: its input is an `Actor`, and it reads only `Actor::$personId`.
- Its output is the union of each owner's current answer, through a mapping that Resources holds (Resources owns audiences; owners own who belongs to them):

| Audience | Owner's answer | Qualifying |
| --- | --- | --- |
| `guardian` | `Relationships\Application\QualifyingRelationships($person)` contains `guardian` | An `active` Guardian relationship (D7) |
| `volunteer` | Contains `volunteer` | An `active` Volunteer relationship (decision 3) |
| `member` | `Membership\Application\GetCurrentMembership($person)->active` | Membership's own rule, unchanged |

- **Never derived from capabilities, roles or admission.** `resources.view`, `resources.manage`, `console.access` and the `guardian` role add nothing.
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
| `guardian` role, no Guardian relationship | `guardian` ∉ set (D2) |
| Guardian relationship, no role | `guardian` ∈ set; no Console admission (A7) |
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
- The policy C use cases are used only by the `my-resources` controllers.
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

**On the server**, Identity's `SearchPeople` is restricted to the type's Person ids. Contact matches are supplied as `includeIds`, within CRM's 10,000-id bound.

C4. **Record** (`/relationships/:slug/:personId`). The page uses the view read, the management read, or both, according to the Actor's capabilities:
- **Basic details.** Read-only with the view capability. Editable as `{from, to}` with manage and `people.edit_basics`. A `stale_person_basics` refusal shows the current values beside the user's.
- **Status.** "Change status" sends the `revision`.
  - For a Guardian, the shared step-up flow runs first.
  - On `stale_revision` the page re-reads and says who changed it and when. It never resends on the user's behalf.
- **Fields.** "Edit" sends the `revision`.
- **History.** Shown with the view capability only.
- **Danger zone.** Last on the page: "Delete permanently" (manage).
  - The step-up flow runs first.
  - The confirmation states that the relationship, its fields and its history are removed, that this cannot be undone, and that the Person and their other relationships remain.
- **"Open full record"** links to `/people/:personId`, shown only with `crm.people.view`.

A source test pins that the page's API client names only `/admin/relationships*` and `/admin/relationship-types`.

C5. **The Person record** (`/people/:personId`) gains a Relationships section.
- **Tabs.** One tab per type the Actor may view or manage, ordered by the definition's position.
  - Each tab shows the generic panel.
  - "Record as Guardian" or "Record as Volunteer" appears where the Person lacks the type and the Actor may manage it.
- **No leak through tabs.** A tab appears only for the Actor's own types. A Volunteer manager therefore never learns that a Person is a Guardian without a Guardian capability.
- **Still absent.** Membership, Account and security data do not appear.
- **Without CRM access.** Someone who has no `crm.people.view` works from the directories and records.

C6. **Intake** (`/relationships/:slug/new`, manage capability):
1. Search (P4). A candidate who is already related in this type links to their record.
2. Select a candidate, or add someone new where `people.create_person` is enabled.
3. Choose an initial state, optionally fill in the fields, and confirm. For Guardians, step-up runs first (A5).

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

Every flow is keyboard-complete: intake, status change, field edit, basic-detail edit, deletion with step-up, the record tabs, the library filters, and both library modes.

### Part H — HTTP contract

All routes are under `/api/v1/admin`, behind `stateful`, `auth:web` and `can:console.access`, and described in `openapi/openapi.yaml`.

**Relationship routes.** They are generated by `Relationships\Http\routes.php` from the registry, with the type's slug as a literal path segment.
- Each route therefore carries one concrete capability, and verification exactly where A5 says.
- The type is always taken from the route, never from the body.

| Method and path | Capability | Verified | Notes |
| --- | --- | --- | --- |
| `GET /admin/relationship-types` | none beyond `console.access` (A6) | — | `RelationshipTypeView` for types the Actor may view or manage |
| `GET /admin/relationships/{slug}?status=&q=&page=&per_page=` | view | — | Directory; `per_page` ≤ 100 |
| `GET /admin/relationships/{slug}/{person}` | view | — | `RelationshipView`. `404 relationship_not_found` |
| `GET /admin/relationships/{slug}/{person}/management` | manage | — | `RelationshipManagementView` (A4). `404 relationship_not_found` |
| `GET /admin/relationships/{slug}/candidates?q=` | manage | — | P4. `422` under 3 characters. `candidates` never matches the ULID route pattern |
| `POST /admin/relationships/{slug}` | manage | Guardians | Intake: `person_id` or `new_person`, `status`, optional `fields`, `confirm_distinct`. `201` with the management view. Errors: `409 relationship_exists`, `409 possible_duplicate`, `422 unknown_person`, `422 transition_not_allowed` (not an initial state), `422 unknown_relationship_field`, `422 invalid_relationship_field` |
| `PUT /admin/relationships/{slug}/{person}/status` | manage | Guardians | `{status, revision}`. `409 stale_revision` with `current`. `422 transition_not_allowed`. Same status: `200`, unchanged |
| `PATCH /admin/relationships/{slug}/{person}/fields` | manage | — | `{revision, fields: {key: value or null}}`, partial |
| `PATCH /admin/relationships/{slug}/{person}/basics` | manage, for types with `people.edit_basics` (Volunteers) | — | P5. `{display_name?, primary_email?, primary_phone?}`, each `{from, to}`. Errors: `409 stale_person_basics`, `409 duplicate_contact_method`, `422` per field |
| `DELETE /admin/relationships/{slug}/{person}` | manage | All types | F12. `204`. `409 relationship_in_use` |

**Refusal order:**
1. capability (`403`);
2. verification (the step-up refusal);
3. scope (`404`);
4. request shape (`422`);
5. the domain's `409`s.

**Resources routes:**
- **Library.** The three `/admin/resource-library` routes keep their paths, methods and capability. Their responses become policy A's. Browsing gains the `publication` and `audience` filters.
- **New.** The three `/admin/my-resources` routes serve policy C (R14).
- **Preview** accepts `volunteer` and returns management file paths.

**Compatibility ([ADR 0007](0007-versioned-rest-api-openapi.md)).** G5's library contract has never been released, and its only consumer is the Console in the same release. The OpenAPI document changes in the same package, and the drift test enforces it. The new fields are additive, and the new routes are additions.

**Access.** `PeopleHoldingCapability(Capability)` is an Application read for the adoption report only. It has no route.

### Part T — Concurrency, transactions, portability

T1. **Races.** Each is proved by a two-process race test on both engines, and each is mutation-checked. The pause comes between the read and the write, as `tests/Support/ResourcesPauses.php` does.

| Race | Decided by |
| --- | --- |
| Two intakes of the same type for one Person | The unique index. The loser gets `409 relationship_exists` and rolls back its new Person |
| Intakes of **different** types for one Person | Both succeed. This proves the index is per type |
| Two status changes from one revision | Row lock and revision |
| A field edit against a status change | One revision guards both |
| Deletion against any change | Deletion locks the row first. The loser gets `404` or `stale_revision`. No orphans remain |
| A basic-detail edit (Volunteer scope) against a CRM edit of the same method | CRM's profile lock and compare-and-set. Neither overwrites the other silently |
| A rename from the Volunteer scope against a rename from CRM | `RenamePerson`'s row lock and the expected name |

Reads after a lock are locking reads. This avoids the MariaDB REPEATABLE READ stale-snapshot trap that Resources WP1 measured.

T2. **Transactions.**
- Every transaction runs as `$database->transaction(fn, 3)` through an injected `ConnectionInterface`.
- Intake nests CRM's work as a savepoint. The precedent is `RegisterPersonWithMembershipAccess`.
- Deletion writes its event inside its own transaction.

T3. **Portability ([ADR 0005](0005-mariadb-with-postgresql-portability.md)).** No exception is needed:
- ULIDs are generated in code.
- States, types and field keys are validated strings.
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
- definitions are data, validated by a schema, read only through the catalog;
- storage does not depend on the type (shared tables, key-value metadata);
- presentation is generic;
- routes are generated from the registry;
- eligibility goes through a mapping table, not a switch.

**What a later configuration package would add.** Each item would be its own gated work:

| Step | What it requires |
| --- | --- |
| Operator-edited presentation (labels, help, order, tone, loosened constraints) | A `relationship_definition_versions` table holding validated overrides merged over the code document by the catalog. Each save is a new version with its author and time, validated by `DefinitionSchema`. A capability (for example `relationships.configure`), recent verification, and a security event per saved version. The Console already renders from the served definition |
| Adding fields, retiring fields, tightening constraints | The same, plus F8's checks run before a version is accepted (`relationships:fields:check` becomes a pre-save check). Changing a released field's key, type or visibility stays a data migration, never a runtime one |
| Lifecycle changes (states, transitions, initial states) | Versioned definitions, plus a check of stored statuses against the new graph, with migration of any orphaned status. A change to `qualifies` changes Resource eligibility, so it needs specially controlled approval and verification and is audited |
| Operator-added relationship types | The registry reading persisted types beside code types. Routes registered from it (already generated from the registry). Capabilities for a registry-defined type: Access's catalog is a code enum today, so this needs an Access design gate of its own (for example capabilities parameterized by type, still checked one per operation). An audience for the type: Resources' catalog becomes extensible by registered types, still failing closed. Each is a design gate; none is precluded |
| Feature configuration | Enabling an implemented feature for a type is a Controlled change. A feature that does not exist yet is new code: a domain handler and, where needed, a Console component |
| A new metadata value type | New code: a validator, a canonical form and a renderer |

**The mandatory invariants of F5 bind every step.** Configuration never bypasses:
- authentication and identity integrity;
- capability authorization;
- Person scope;
- audit integrity;
- Resource visibility and publication protections;
- safe migration;
- type validation;
- referential integrity;
- verification requirements.

**What configuration never becomes:** executable, a rules language, or a source of runtime-generated migrations.

## Future architecture (direction only; nothing built or committed)

**Grant sources.** Today capabilities come only from roles. Later sources could include:
- explicit Account grants;
- named groups;
- grants derived from relationships;
- contextual participation, such as enrollment in a workshop or assignment to a project.

These would join as additional **positive** sources that the `Authorizer` unions. There would be no explicit-deny system unless a real need appears.

A capability derived from a relationship would be an explicit rule in Access, fed by `Relationships\Application`'s current answer. It would never be a stored role row (ADR 0028's rule for lapsing eligibility). It would never make a relationship and a role interchangeable.

**Global capabilities differ from scoped grants.** A global capability answers "may X anywhere". A scoped grant answers "may X on this object or in this context", which needs an object-aware check that ADR 0017 deferred.

**Mandatory conditions stay independent of every grant:**
- recent verification;
- publication rules for ordinary audiences;
- Person-scope rules;
- ownership;
- approval requirements.

**Approval and guided experiences.** These include submission and approval by a Person, role, relationship or group; changes requested; resubmission; defaults and overrides; and media review. Guided experiences include onboarding, steps, quizzes, training, workshops and courses. They would attach at these points:
- **Transition guards.** A guard on a relationship transition would be a new, code-implemented feature in the closed set (for example, Pending to Active only after an approval outcome).
- **Approval stays separate from publication** ([ADR 0009](0009-authorization-separate-from-approval.md)). G10's privileged draft viewing implies no approval process.
- **Contextual audiences** would be new audience cases with their owner's eligibility answer.
- **Relationship-linked records** (assignments, enrollments) would reference `person_relationships.id` with `RESTRICT` and register in the deletion dependents registry (F12).

## Threat review

| # | Threat | Enforcement | Proof |
| --- | --- | --- | --- |
| 1 | The `guardian` role is treated as Guardian status, or the reverse | No mapping in either direction (M1, A8). Eligibility reads only the relationship (R10). Access never depends on Relationships | Role without relationship: no `guardian` eligibility. Relationship without role: eligibility, no capability, no admission. `/me` unchanged by relationships. Module-graph test |
| 2 | Affiliation used as authority | Every use case authorizes by capability alone (A9) | Architecture test. An Active Guardian without `guardians.manage` gets `403` |
| 3 | A relationship manager reads unrelated CRM records | No CRM route is reachable. Delegated reads return three fields for in-scope Persons (P2–P3) | Capability isolation: every CRM route `403`. Exact response shapes |
| 4 | Scoped edits against an unrelated Person | Scope comes from the type's own table; `404` outside it (P3) | An unrelated Person gets `404` and is unchanged |
| 5 | Scoped edits reaching beyond basic details | One use case, three fields, `{from, to}` only (P5–P6) | Other keys `422`. Other contact methods, profile, tags and notes unchanged after the edit |
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
| 18 | Admission bypassed through a relationship | `console.access` is required on every route, including the `my-resources` routes (A7) | A Guardian relationship without `console.access` gets `403` |
| 19 | `resources.view` holder mutating; `resources.manage`-only holder viewing | The library is GET only. One capability per use case. Manage-only uses policy C | Route-table tests. "Manage is not view" kept |
| 20 | Unknown audiences or relationship types treated permissively | Fail closed on write, read, filter and eligibility | Tests with planted unknown keys |
| 21 | Privileged responses cached for ordinary users | Session-only routes, global `no-store`, separate route families | Header tests |
| 22 | Stale permissions or relationships | Read live. Disabled Accounts lose their sessions. No cached eligibility (R13) | Revoke a role: next request `403`. Inactivate: next policy C request `404` |
| 23 | Authorization drift (`guardians.manage` granted widely) | The role catalog is code, reviewed and pinned. Role grants are audited | `CatalogTest` pins the A3 mapping |

## Testing strategy

**Backend.** Pest on both engines:
- `Feature/Modules/Relationships`;
- `Unit/Modules/Relationships`: definitions, schema, value types, lifecycle;
- `Architecture/RelationshipsBoundariesTest`;
- `Concurrency/RelationshipsRaceTest`;
- the revised Resources, CRM, Access and audit-seam boundary tests.

How the tests are built:
- **Real personas, from the existing roles:**
  - Guardian relationship, no role;
  - `guardian` role, no relationship;
  - an administrator with no relationship;
  - a Guardian with no `resources.view`, given `console.access` alone through capability isolation;
  - a Guardian who is also a Volunteer and a Member;
  - a Person with no Account.
- **`Gate::before` capability isolation** for every single-capability state. No real role holds one capability of a pair without the other.
- **Exact response shapes** at every depth.
- **Mutation checks:**
  - drop the unique index or the row lock;
  - skip the scope check;
  - widen a delegated read or the management view;
  - let a Volunteer route touch a Guardian relationship;
  - accept an unknown field;
  - let `pending` qualify;
  - let the role grant `guardian` eligibility;
  - let policy A call the projection;
  - let policy C read a Draft;
  - feed policy C a capability-derived audience;
  - drop `console.access` from a `my-resources` route;
  - delete without writing the event.

**Frontend.** Vitest with `fakeApi`, definitions served by the fake:
- Two control types, one with a field of each value type, prove that rendering comes from definitions alone.
- Both library modes are tested against their own contracts.
- Source tests pin:
  - relationship pages name only relationship endpoints;
  - no component outside the registry names a type;
  - each library mode uses only its client;
  - the library imports no management or editor code.

**Browser.** Playwright against the real platform, `--workers=4`.
- **Relationship journeys:**
  - Guardian adoption by an administrator, with step-up;
  - Volunteer intake, both for an existing Person and a new one;
  - status changes, including a conflicting one;
  - field edits;
  - a basic-detail edit of a Volunteer who is also a Guardian;
  - deletion with step-up;
  - the Person record's tabs;
  - the directories.
- **Library journeys:**
  - viewer mode with its filters and markers;
  - audience mode for a restricted Guardian persona, which `/me` interception produces from a real Guardian-relationship Account: published Guardian Resources only, Drafts and ineligible Packs as "not found", files.
- **Accessibility:** axe in both themes, the sideways-scroll pass, keyboard paths.

**Server-side proof.** Refusals are proved by the backend matrix.

## Verification matrix

| Area | Scenario | Test |
| --- | --- | --- |
| **Relationships and roles** | Guardian with limited roles | Relationship with no role, or with capabilities isolated: eligible for `guardian`; refused everything not granted |
| | `guardian` role, no Guardian relationship | Every granted capability works; not eligible for `guardian`; not in the Guardian directory |
| | Trusted non-Guardian Console participant | Role, no relationship: full Console function; a Guardian nowhere |
| | Guardian and Volunteer on one Person | Two rows, two tabs, two directories, independent lifecycles |
| | Active and Inactive Guardian eligibility | Active: `guardian` ∈ set. Inactive: ∉ set. Changes apply on the next request |
| | Person with no Account | Both types establishable and manageable; eligibility applies once an Account exists |
| | One of each type per Person; concurrent creation | Unique index on both engines; race test; mutation drops the index |
| **Capabilities** | Guardian view-only | Directory and records readable; every Guardian mutation and the management view `403` |
| | Guardian manage-only | Lookup, management view, intake, status, fields and deletion allowed (with verification as A5); directory, `RelationshipView` and history `403` |
| | Volunteer view-only / manage-only | As above, for Volunteers, plus basic-detail edits for manage-only |
| | Resource view-only / manage-only | View-only: policy A, every management route `403`. Manage-only: management and preview; library routes `403`; reads through policy C |
| | Independently assignable | `CatalogTest` pins the A3 defaults; isolation tests prove every capability is checked alone; no pairing pin for the new capabilities |
| **Lifecycle** | Create, deactivate, reactivate | Initial states only; fields and history kept; same row on reactivation |
| | History auditable | One row per change; a forced failure leaves neither row nor change |
| | Authorized permanent deletion | Verified; values, history and relationship gone; one `relationship.deleted` with counts and no values; Person and other relationships intact |
| | Unauthorized or unverified deletion | Refused; nothing deleted; no event |
| | Revision conflicts | `409 stale_revision`; the Console re-reads and never resends |
| | Disallowed transition | `422 transition_not_allowed` |
| **Metadata** | Valid / invalid / unknown | Canonical round-trip per value type; `422 invalid_relationship_field`; `422 unknown_relationship_field` |
| | Unauthorized edit | View-only `403` |
| | No leakage across types | A Guardian field is not readable or writable on a Volunteer relationship, and the reverse |
| | Fields grant nothing | Setting every field changes no capability, role or eligibility |
| **Scoped People edits** | Volunteer manager edits the basic details of an Active Guardian who is also a Volunteer | Allowed for the three fields; CRM's validation applies; `person.renamed` recorded for a name |
| | Unauthorized edit of an unrelated Person | `404`; unchanged |
| | Security-sensitive Account fields protected | Account email, `email_canonical`, credentials, sessions, roles and capabilities unchanged after any scoped edit; extra keys `422` |
| | Concurrent CRM and Volunteer updates | Compare-and-set `409 stale_person_basics`; the race test shows neither overwrites the other |
| | Shared contact data stays canonical | After a scoped edit, CRM's record shows the change in the same contact method; no copy exists in `Relationships` |
| | Guardian state untouched through Volunteer routes | Every Volunteer route leaves the Guardian relationship's status, fields and history unchanged |
| **Resource access** | Privileged viewer reads Drafts and all audiences | Drafts, every audience, uncategorized and empty Packs, Draft files |
| | Restricted Console Guardian reads published Guardian Resources | `my-resources` returns them, with search and files |
| | Restricted Console Guardian cannot read Drafts | Draft Pack and Draft Card absent; deep link `404`; file `404` |
| | Restricted Console Guardian cannot read Member- or Volunteer-only Resources without the relationship | Absent; `404`. With an Active Volunteer relationship added, Volunteer-only content appears |
| | Console admission still required | Guardian relationship without `console.access`: every `my-resources` route `403` |
| | Ordinary delivery cannot invoke privileged mode | No parameter exists; policy A routes `403` without `resources.view` |
| | Hidden File Card URLs do not disclose | Draft, narrowed, ineligible and non-existent Cards all answer the same `404` on the `my-resources` file route |
| | Inactivation revokes eligibility | A file is served, the relationship is inactivated, the same request is `404` |
| | Multiple eligible audiences combine by OR | A `{guardian, volunteer}` Pack is seen by each alone |
| | Unknown audiences fail closed | On write, filter and stored key |
| **Configuration readiness** | Definitions consumed consistently | The types endpoint mirrors the catalog; generic components render a control type; a source test shows no layer outside the catalog holds definitions |
| | Unknown metadata and relationship types fail closed | Unknown field `422`; a planted unknown type row is ignored by reads and eligibility and reported by the integrity check |
| | No duplicated validation | Console validation is generated from the served definition; a source test shows no hardcoded field rules |
| | A new type reuses the infrastructure | A third test-only definition and registry entry, in tests only, works through storage, lifecycle, the API and the Console with no change to Person identity or the Resource projection |
| **Engines** | Uniqueness, transitions, metadata, transactional history, scoped Person updates, deletion with audit | Every row above on MariaDB and PostgreSQL (`./flow check all --pgsql`) |
| **Integration** | Real server; no external delivery | `./flow test e2e --workers=4`. No route reaches policy C except the three Console routes |

## Work packages

Every package is gated on `./flow check all --pgsql`. Browser packages are also gated on `./flow test e2e --workers=4`. Status lives in the [roadmap](../roadmap.md). The domain and the security boundaries come before the surfaces that depend on them.

| WP | Scope | Depends on | Acceptance and required tests | Security focus | Build / audit |
| --- | --- | --- | --- | --- | --- |
| **WP1** | **Relationship foundation backend.** The `Relationships` module: the registry, `DefinitionSchema`, `RelationshipCatalog`, and the Guardian and Volunteer definitions with their N4 fields. The three tables. Lifecycle, revision, locks and history. Metadata validation. Deletion with verification, `relationship.deleted` and the dependents registry. The four capabilities with the A3 defaults. The exemption entries and the A5 verification table. The generated per-type routes for directory (names only), record, management view, candidates (names only), intake of an existing Person by id, status, fields and deletion. `GET /admin/relationship-types` and its pinned exception. `QualifyingRelationships`. OpenAPI | WP0 accepted | The relationship, capability, lifecycle, metadata and configuration rows of the matrix. Races on both engines, mutation-checked. Boundary tests: module graph, no `Access → Relationships`, one capability per use case, the seam caller list, definitions only in the catalog, the A5 route table, the test-only third type | Uniqueness; verification contract; deletion and audit; type taken from the route; affiliation never authority; manage-only reads narrow | Opus build, **Opus deep audit** |
| **WP2** | **Guardian adoption and role transition.** `Access\Application\PeopleHoldingCapability`, `relationships:guardian-adoption-report`, the role display relabel (M5) and the adoption runbook entry | WP1 | The report lists exactly the documented sets and writes nothing. Independence tests with real personas. The bootstrap path is shown with an empty relationship table | No automatic mapping; the report names no role | Sonnet build, Opus audit (focused) |
| **WP3** | **Relationship-scoped People access.** `Crm\Application\Delegated` and its pinned caller. Basic details in the directory, record and management view. Contact search. Lookup. Intake of a new Person with duplicate advice. Scoped basic-detail edits with compare-and-set (N5). `RenamePerson`'s expected name | WP1 | The scoped-People rows, including the Volunteer-who-is-a-Guardian edit and the Account-unchanged proofs. Basic-detail and rename races on both engines. CRM's suite unchanged. Mutation checks for scope, fields and type isolation | Writes to another module's data; login identity untouched; no backdoor | Opus build, **Opus deep audit** |
| **WP4** | **Resources authorization transition.** The `volunteer` audience. Policy A (`ViewerLibrary`) behind the library routes, with its filters and fields. Policy C (`AudienceEligibility` over Relationships and Membership, `AudienceDelivery`, three use cases). The three `/admin/my-resources` routes and their pinned exception. Preview's management file paths. Revised boundary tests. OpenAPI | WP1 (independent of WP2 and WP3) | Every Resource-access row of the matrix. G5's non-disclosure suite passes on policy C through the `my-resources` routes. R15's architecture tests, each mutation-checked | Highest risk: who reads what, and admission | Opus build, **Opus deep audit** |
| **WP5** | **Relationships Console.** Navigation from definitions. The generic directory, record, panel, intake, status, fields, basic-details and history components. The management-view path for manage-only holders. Deletion and Guardian changes through the shared step-up flow. The Person record's Relationships tabs. The extension registry | WP1, WP3 | Vitest per component and state: both conflict flows, step-up, manage-only and view-only states. Two control types. Source-boundary tests. axe and layout | No client-side authority; no type names outside the registry | Sonnet build, Opus audit (focused) |
| **WP6** | **Resources Console.** `volunteer` in authoring, filters and preview. The library's two modes: viewer mode's filters, markers, Uncategorized group and provenance; audience mode on the `my-resources` contract. The `/resource-library` guard becomes `console.access` with the mode taken from `/me`. One client and one file allowlist per mode | WP4 | Vitest for both modes. Extended library boundary tests (each mode uses only its client; no management or editor code). axe and layout | The client cannot elevate; the library stays read-only | Sonnet build, Opus audit (focused) |
| **WP7** | **End-to-end proof, demo and closeout.** `RelationshipsDemoSeeder` (opt-in, `local`/`testing` only, through the use cases): Guardians (one with no role, one former), Volunteers in each state, a Guardian who is also a Volunteer and a Member, a Person with no Account. Guardian- and Volunteer-targeted Packs added to the Resources demo. E2E personas: a role holder without a relationship, a Guardian, and a Guardian with `console.access` only (`/me`-isolated). Browser journeys for the whole of G10. Closeout notes and checklist | WP2, WP5, WP6 | `./flow test e2e --workers=4` green; the frontend and integration rows; the closeout checklist | Demo data never outside `local`/`testing` | Sonnet build, Opus review of the closeout |

**Why this shape:**
- **WP1 is the foundation alone.** It covers schema, domain, authorization and the routes over relationship state, with no CRM writes, no Resources change and no UI. Its audit can concentrate on uniqueness, verification, deletion and capability boundaries.
- **WP3 and WP4 are separate security surfaces, each with a deep audit.** WP3 covers CRM writes and the boundary with login identity. WP4 covers the Resources transition and its new Console audience routes. Both depend only on WP1, so they may run in either order.
- **WP2 is small and stays separate.** It is where the role stops meaning "Guardian", so the change gets its own review.

## Deferred

Not designed here, and nothing is built for any of it:

- **Relationship types and data:**
  - Member integration into `Relationships`, and Partner (both near-term future work);
  - Vendor and Artist;
  - a preferred name;
  - a reason on status changes;
  - field value history;
  - Guardian seniority levels or trainee states;
  - Volunteer Coordinator or Guardian Steward roles (role-catalog changes when wanted);
  - basic-detail editing under `guardians.manage` (a Controlled change if ever wanted).
- **Configuration and authorization:**
  - an operator configuration interface;
  - persisted definition versions;
  - operator-added types;
  - registry-defined capabilities;
  - groups, Account-level grants, and grants derived from relationships or context;
  - scoped grants.
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
- A trusted advisor can hold Console permissions without being a Guardian.
- A junior Guardian can hold few permissions and still read the published Guardian Resources.

**For the platform:**
- **The `guardian` role becomes a permission bundle only**, once Guardians are recorded. Until then, no one is Guardian-eligible through audience delivery. Every current Console user holds `resources.view`, so no one loses anything.
- **The Console has two reading contracts**, privileged and audience-based, behind one library interface. Console admission stays a capability. Relationship eligibility is reusable by a future external surface without the Console.
- **One module and one set of contracts serve both relationships.** The design leaves an honest, bounded path to operator configuration. Partner is a definition, a capability pair and a mapping row. Member can join the contracts without moving.
- **Volunteer managers maintain the basic details of the Volunteers they manage, Guardians included**, under precise field, scope and identity boundaries. A Guardian's login is never reachable through contact data.
- **Everything is visible to `resources.view` holders.** Every holder (today, every Guardian-role holder) reads Member-only, Volunteer-only, Guardian-only and Draft Resources in the Console, as decisions 11–13 require.
- **New seams and dependencies.** CRM gains one seam, authorized by its caller and pinned to one caller. Resources depends on Relationships and Membership earlier than ADR 0037 planned. `AdministrationRoutesTest` gains four pinned console-only read routes.
- **Deletion and data.** Relationship deletion becomes the platform's third audited deletion behind verification. More personal data, and its history, joins the open erasure question.

## Alternatives considered

- **Guardian stays a role.** Rejected by D2.
- **Role grants creating relationships, or the reverse.** Rejected by D1 and ADR 0036, decision 5.
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

**This version (2026-10-08): the product owner's rulings.**

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
