# ADR 0034: CRM enriches Identity's Person

- **Status:** Accepted (implemented: Identity's People ports and the `Crm` backend, WP2; notes and the Console UI are not built)
- **Date:** 2026-09-29
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0015](0015-identity-owns-person.md) (the decision that CRM will own rich contact data keyed by `person_id`, stated there as a consequence, is made concrete here; nothing in ADR 0015 changes)
- **Related:** [ADR 0021](0021-cross-module-referential-integrity.md), [ADR 0024](0024-privileged-operator-administration.md), [ADR 0028](0028-membership-grants-derived-at-query-time.md), [ADR 0029](0029-commerce-providers-own-payment-facts.md)

## Context

The foundation phase (Identity, Access, MFA, Membership, the Member surface) is complete and product development returns to Guardian-facing value. A Guardian product capability design gate (2026-09-29) ordered the next domains: **G1 CRM / People, G2 Discussions, G3 Events, G4 Publishing / Announcements, G5 Knowledge / Resources**. CRM comes first because everything after it points at people: event participants, discussion authors, announcement audiences.

Two earlier decisions already shape it. ADR 0015 made Person a thin anchor (an id and a display name) and said CRM would own contact data keyed by `person_id`. ADR 0029 recorded that an account-less Person has no email, so payment-provider matching cannot work, and deferred that to "a future CRM / contact-data decision". This ADR is that decision's boundary. It is written before the `Crm` module exists so that the module is built inside it.

The risk to avoid is the one ADR 0015 named: Person becoming a god table, or CRM quietly growing a second concept of "a human" beside it.

## Decision

**Ownership**

1. **Identity owns Person** as the human anchor. Nothing here moves it or widens it.
2. **CRM owns business and contact information *about* a Person**, keyed by `person_id`.
3. **CRM never creates a second human identity concept.** There is no separate Contact, Lead or Party entity. A lead is a Person with CRM data.
4. **A Person the platform knows of may have no Account, no Membership and no Guardian access, and still have CRM data.**
5. **The People directory lists every Person Identity holds.** There is no `is_in_crm` flag. A Person with no CRM data is in the directory.
6. **CRM data is sparse.** No CRM row is required merely because a Person exists; rows appear when there is something to record.
7. **CRM records reference `person_id`.** Whether a given reference carries a foreign key follows ADR 0021 (an invariant gets `RESTRICT`; provenance such as "who edited this" gets none).
8. **Identity remains the only creator of Persons.** CRM creates a Person by calling Identity's `RegisterPerson`, as Membership does, so ADR 0015's extraction trigger is not tripped.

**Contact data**

9. **Contact methods are not globally unique across Persons.** A shared household or organisational address is legitimate; uniqueness, if any, is per Person.
10. **Duplicate detection may *suggest* possible matches. It never merges Persons and never silently adopts an existing one.** Merging remains a separate, later, deliberate administrative operation (ADR 0015).
11. **Matching by email fails closed when ambiguous.** Contact emails make candidate matching *possible* for the first time (ADR 0029's account-less Member), but because they are not unique, more than one candidate, or none, means no match and no automatic action.
12. **A phone number is stored as the human entered it.** A separate, conservatively normalised value may exist for searching and candidate matching. CRM Phase 1 does not claim to establish a global phone identity: country codes, local forms, extensions and formatting are ambiguous, and this ADR assumes no phone-number library. The exact contact-method shape is decided by the package that builds it.

**Labels and status**

13. **Tags are labels only.** A tag never grants a capability, establishes Membership, establishes Volunteer status, or establishes Guardian access. The starter vocabulary is Lead, Partner, Facilitator, Performer, Vendor, Donor, Volunteer Interest and Artist; these are editable labels, not a fixed taxonomy.

**Neighbouring ownership stays where it is**

14. **Membership remains owned by Membership**, and Account and security state by Identity and Access. Guardian screens *compose* these next to CRM data through each owner's own authorized seam, as the Member detail page already composes Membership and Commons access. CRM does not copy them.
15. **Volunteer business state belongs to a future Volunteering domain** (see the note below).
16. **Communication consent and preferences are outside CRM Phase 1.** They belong with whatever sends communications (Publishing).

**History and audit**

17. **CRM owns its business notes and interactions. `security_events` is not the CRM activity log** (ADR 0019 built it for identity and access occurrences). Private notes are deferred: Phase 1 notes are visible to everyone who may view CRM data, and Guardians are told to write them as though the person could one day ask to read them. How a note is removed or corrected is decided when notes are built, after inspecting repository conventions; this ADR fixes no deletion policy.

**Rename**

18. **Renaming a Person is an Identity mutation.** CRM does not update `people`. A higher-level, authorized use case checks the caller's CRM capability and then calls Identity's `RenamePerson`; Identity depends on neither CRM nor Access for this.
19. **Correcting a display name does not inherently require step-up.** ADR 0024's rule (every mutation needs the capability and recent verification) governs operator administration of *authority*: access, accounts, roles, invitations. A display-name correction grants and removes nothing, so requiring a password and second factor for it would only make ordinary maintenance unusable. Rename is still validated, authorized by the calling use case, and recorded as a security event (`person.renamed`).

**The disclosure boundary for People search**

20. **Identity's People search returns a Person's id and display name and nothing else.** It does not search or return the Account's login email, Account status, roles, invitation state or any security data. The Account directory is guarded by `identity.accounts.view`; if a broader CRM capability could match or reveal Account fields through a People search, it would act as an oracle around that boundary ("does this address have an Account?"). Account and Commons-access information reaches a Guardian screen only through the seams that authorize it. Searching People by an address a Guardian recorded is done over CRM-owned contact methods, which are the CRM view's own data.

**Introduction of capabilities**

21. Consistent with ADR 0017, capabilities are added by the package that first checks them. The `Crm` module's first package introduces **`crm.people.view` and `crm.people.manage`** and grants both to the Guardian role; the Platform Administrator receives them automatically. `manage` covers renaming.

## Implementation notes (WP2, 2026-10-01)

Recorded where the build made a call this ADR left open. None changes a decision above.

- **Shape.** The `Crm` module is `Domain`, `Application`, `Infrastructure` (query builder, no Eloquent) and `Http`. Tables: `contact_profiles` (keyed by `person_id`), `contact_methods`, `contact_tags`, `contact_tag_assignments`. Routes are under `/api/v1/admin`: `people`, `people/{person}`, `people/{person}/contact-methods`, `people/{person}/tags`, `contact-tags`. The product word is People; the module is Crm because that is what it is.
- **The profile row is also the lock.** A Person has a `contact_profiles` row once there is CRM data to hold, created empty by the first CRM write. Every CRM mutation for a Person takes it with `SELECT ... FOR UPDATE`, so "no duplicate method" and "one primary" are serialised identically on MariaDB and PostgreSQL; the unique indexes are the backstop. Reading never creates rows.
- **One primary per kind, in the database.** `contact_methods.primary_kind` holds the kind when the row is the primary and NULL otherwise, with `unique(person_id, primary_kind)`. Both engines allow many NULLs, so no partial index or generated column is needed. The first method of a kind is that kind's primary; removing a primary promotes the earliest remaining one.
- **Normalisation (decision 12, as built).** Email: trimmed for display, lower-cased for matching; no alias, plus-address or provider rules. Phone: trimmed and whitespace-collapsed for display; for matching, its digits with a leading plus kept. It infers no country code and claims no E.164 identity, so `+1 555 010 0100` and `555 010 0100` are different, and an extension's digits simply join the number. A phone is searched by digits only from three digits up. Accent sensitivity and non-ASCII order follow each engine's collation.
- **Account login email is deliberately not checked for duplicates** when registering a Person (the option this ADR's disclosure rule left open). Saying "that address belongs to an Account" would let `crm.people.manage` probe data guarded by `identity.accounts.view`, and CRM may not read Account storage. Revisit only through an Identity seam that answers without disclosing.
- **Duplicate advice** uses CRM emails and the exact display name (ignoring case), the latter by walking Identity's `SearchPeople` up to ten pages. Candidates are returned as id, display name and what matched: directory information. Today every role that may manage People may also view them; a future role that manages without viewing would have to gate the candidate detail.
- **Routine maintenance asks for no recent verification.** [ADR 0024](0024-privileged-operator-administration.md)'s rule that every administration mutation needs recent verification governs operations that change authority. The People mutations change none, so they are exempt, by capability: `AdministrationRoutesTest` names `crm.people.manage` as the one exemption and pins that it covers exactly the Crm routes.
- **Writes return what they wrote.** A mutation's response is the resource it changed, never a re-read of everything CRM holds, so a caller who may manage but not view learns nothing more from writing.
- **Search bound.** The directory composes CRM's matches with Identity's search by passing id sets, bounded at 10,000 (`SearchTooBroad`, a 422, beyond it). A dedicated search read model is a later decision with its own trigger.
- **Starter tags are not seeded.** The vocabulary is data a Guardian creates; the demo/e2e closeout (WP6) decides whether a demo seeder creates it. Notes and interactions (WP3) are not built, and their deletion mechanics are still open.

## Consequences

- The `Crm` module can be built without an Identity schema change. What Identity supplies is two Application ports, built and tested on both engines: `SearchPeople`, a paged, id-composable search returning a Person's id and display name, and `RenamePerson`.
- The directory needs no backfill and no membership in anything to appear.
- Because search over CRM data and over Persons live in two modules, the People list composes them by passing sets of ids. That is fine at Flow Life's scale (thousands of People). A dedicated search read model is a later decision with its own trigger, not a Phase 1 need.
- Someone reading only Identity's search will not find a Person by login email. That is deliberate (decision 20).
- ADR 0015's "Main risk: Person becoming a god table" stands, and now has a concrete counterpart: CRM is where the temptation goes.
- Erasure and anonymisation remain open ([data ownership](../architecture/data-ownership.md)); this ADR does not solve them and adds more personal data to the platform, which makes that question more pressing rather than less.

## Note on Volunteers

Volunteers are Members with elevated duties and privileges. They are not a second identity type, and they are not merely an Access role. That relationship is settled. What is **not** decided is the Volunteering domain model: its lifecycle, assignments, duties, history, schedules and business data, and whether some Access role or capabilities mirror part of the relationship. Nothing in this ADR designs it. A "Volunteer Interest" tag is a label a Guardian can apply and carries no meaning to the platform.

## Roadmap direction

Recorded for sequencing and domain ownership only; each later domain has its own design gate and, where warranted, its own ADR.

| Milestone | Domain |
| --- | --- |
| G1 | CRM / People (this ADR) |
| G2 | Discussions |
| G3 | Events |
| G4 | Publishing / Announcements |
| G5 | Knowledge / Resources |

Commons domains own durable business state and rules; the Guardian Console is the primary rich authoring surface; `/my/`, WordPress and any future client are presentation surfaces over Commons capabilities. Member-facing expansion is parked.

## Alternatives considered

- **Add contact fields to Person.** Rejected by ADR 0015 and reaffirmed: the first "just one column" is exactly the failure that ADR describes.
- **A separate Contact or Lead entity linked to Person.** Rejected: it creates a second notion of a human and a reconciliation problem (is this Contact that Person?) the platform does not need. CRM data hangs directly off the Person.
- **Create CRM rows for every Person (or an `is_in_crm` flag).** Rejected: it needs a backfill, invents a status that means nothing, and a Person with no CRM data is already a valid directory entry.
- **Unique contact emails per Person across the platform.** Rejected: real households and organisations share addresses, and the constraint would force Guardians to record false data.
- **Search Account login email in the People search "for convenience".** Rejected (decision 20): it widens what `crm.people.view` can learn beyond `identity.accounts.view`.
- **Require step-up for rename.** Rejected (decision 19): step-up is for authority, and this changes none.
- **Put CRM activity in `security_events`.** Rejected: that table is append-only, has no read path, and is scoped to identity and access by ADR 0019.
