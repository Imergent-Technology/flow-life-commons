# ADR 0034: CRM enriches Identity's Person

- **Status:** Accepted (implemented: Identity's People ports, the `Crm` backend (WP2) notes and interactions (WP3), the Console's People, notes and tag screens (WP4, WP5) and the end-to-end and demo closeout (WP6))
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
- **One primary per kind, in the database.** `contact_methods.primary_kind` holds the kind when the row is the primary and NULL otherwise, with `unique(person_id, primary_kind)`. Both engines allow many NULLs, so no partial index or generated column is needed. The database guarantees at most one; the use cases, under the Person's write lock, guarantee exactly one: a Person with any method of a kind has one primary of it. The first is the primary, promoting another demotes the old one atomically, removing the primary promotes the earliest remaining one, and the current primary cannot simply be un-set (`422 invalid_contact_input` on `is_primary`).
- **Normalisation (decision 12, as built).** Email: trimmed for display, lower-cased for matching; no alias, plus-address or provider rules. Phone: trimmed and whitespace-collapsed for display; for matching, its digits with a leading plus kept. It infers no country code and claims no E.164 identity, so `+1 555 010 0100` and `555 010 0100` are different, and an extension's digits simply join the number. A phone is searched by digits only from three digits up. Accent sensitivity and non-ASCII order follow each engine's collation. The normalisation CRM applies is portable for ASCII and case only: `search_value` and `name_canonical` are plain `utf8mb4_unicode_ci` columns on MariaDB, so non-ASCII values that the collation folds (`café`/`cafe`, `josé@x.org`/`jose@x.org`) are one value there (a duplicate method or tag is refused) and two on PostgreSQL. This is the same behaviour Account email canonicalisation already has; CRM promises no accent folding, and a test pins the difference so it is known rather than accidental.
- **Account login email is deliberately not checked for duplicates** when registering a Person (the option this ADR's disclosure rule left open). Saying "that address belongs to an Account" would let `crm.people.manage` probe data guarded by `identity.accounts.view`, and CRM may not read Account storage. Revisit only through an Identity seam that answers without disclosing.
- **Duplicate advice** uses CRM emails and the exact display name (ignoring case), the latter through Identity's `FindPeopleNamed`, an exact lookup with no result bound that a name's sort position could defeat. Candidates are returned as id, display name and what matched: directory information. Policy today: every role that grants `crm.people.manage` also grants `crm.people.view`, pinned by a role-catalog test. Capabilities are still checked independently, and there is no manage-only role; a future one would have to gate this candidate detail first.
- **Routine maintenance asks for no recent verification.** [ADR 0024](0024-privileged-operator-administration.md)'s rule that every administration mutation needs recent verification governs operations that change authority. The People mutations change none, so they are exempt, by capability: `AdministrationRoutesTest` names `crm.people.manage` as the one exemption and pins that it covers exactly the Crm routes.
- **Writes return what they wrote**, not a re-read of everything CRM holds. That is not a disclosure boundary: a write can still reveal CRM data (a profile update returns both profile fields, a duplicate candidate list names People, a duplicate method or a tag in use says it exists). The protection is the role invariant above (manage implies view, in every role), not the response shape.
- **Search bound.** The directory composes CRM's matches with Identity's search by passing id sets, bounded at 10,000 (`SearchTooBroad`, a 422, beyond it). A dedicated search read model is a later decision with its own trigger.
- **Starter tags are not seeded.** The vocabulary is data a Guardian creates; WP6 added an opt-in demo seeder for development (see the WP6 notes), and nothing production-side creates a tag.

## Implementation notes (WP3, 2026-10-02)

Where building notes and interactions settled what decision 17 left open. None changes a decision above.

- **Shape.** One concrete table, `contact_interactions` (no generic activity framework): `kind` (`note`, `call`, `email`, `meeting`), `body`, `occurred_at`, the author, the last editor, and created/updated times. Routes: `GET`/`POST /admin/people/{person}/interactions`, `PATCH`/`DELETE /admin/people/{person}/interactions/{interaction}`. Reads need `crm.people.view`, every change `crm.people.manage`, none asks for recent verification.
- **Author attribution.** The author and the last editor are held as Person ids (`author_person_id`, `updated_by_person_id`), provenance with no foreign key (ADR 0021). They are Persons, not Accounts: the Person is the stable anchor, a reader needs only a name, and CRM responses never carry an Account id. Names are resolved live through Identity's `FindPeople` (one batched query per page), so a rename shows on notes already written.
- **Chronology.** `occurred_at` is when the contact happened, defaults to now, and may not be in the future (five minutes of clock difference allowed). The list is `occurred_at`, then `created_at`, then id, all descending: a total order, so a page boundary is never ambiguous. Offset paging like the directory (`page`, `per_page` at most 100, with `total`).
- **Edit.** In place, by anyone holding `crm.people.manage` (there is no per-author ownership in Phase 1). The row records who last changed it and when; there is no edit history. The author and the Person never change.
- **Removal.** Hard delete, the convention contact methods already follow. No soft delete or versioning: nothing in Phase 1 needs one, and erasure policy remains an open question (see Consequences). A note removed is gone.
- **No audit entry.** Recording, editing and removing write nothing to `security_events`: it is not the CRM activity log (decision 17), and ordinary business content is not over-audited into the security subsystem.
- **No lock.** A note has no uniqueness or "exactly one" rule, so it does not take the Person's profile-row lock and does not create the profile row. Edit re-reads after writing (an unchanged row reports zero affected rows on MariaDB), so a note removed in between is reported gone rather than returned as if it still existed.

## Implementation notes (WP4, 2026-10-02)

Where building the Guardian People screens settled what this ADR left to the UI. None changes a decision above.

- **Surface.** A People section in the Console's rail (`/people`, `/people/new`, `/people/:personId`), shown by `crm.people.view` and `crm.people.manage` independently: view reaches the directory and the record, manage alone reaches "Add a person". The Console never treats one capability as the other, and the server decides every request.
- **What a record shows.** The Person's name, CRM's profile (how we know them, affiliation) and contact methods. Nothing from an Account, Membership or security appears, and the record's `tags` are returned by the API but not read: tags and notes are WP5.
- **Search** is submitted rather than run on every keystroke, and a new search returns to page 1. The server owns order, search and paging.
- **Edits send only what changed.** The API is a partial update, so a field the form did not touch is never sent and cannot overwrite what someone else changed since the page loaded.
- **Duplicate advice is advice.** A `409 possible_duplicate` is shown with the candidates and what matched, and nothing is created. The Guardian either opens a candidate or states that this is a different person, which resends the same request with `confirm_distinct`. Nothing is merged or adopted.
- **The server decides which contact method is primary.** After every contact-method change the Console re-reads the record rather than keeping its own idea of the primary; "make primary" is its own action, so the Console never asks to un-set a primary.

## Implementation notes (WP5, 2026-10-02)

Where building the notes and tag screens settled what this ADR left to the UI. None changes a decision above.

- **Surface.** A Person's record gains a Tags panel and a Notes and interactions panel. The list of tags (the vocabulary) is its own small page, `/people/tags`, in the People section: a Person's tags are chosen from it, and renaming or deleting a tag affects everyone who holds it, so it is not a per-Person control. Seeing any of this needs `crm.people.view`; recording, correcting and removing notes, assigning tags and managing the list need `crm.people.manage`, and the Console never treats one as the other.
- **Notes are for everyone who can view people.** The panel says so once, in the words decision 17 uses ("write them as though the person could one day ask to read them"), and offers no private or confidential option. It does not claim the Person can read them today.
- **The ordinary note is one box.** The kind defaults to a note and the time to now; the author is never asked for or sent (the server takes it from the session). A correction sends only the fields that changed and nothing if none did. Authors appear as the API's minimal Person projection, and the page says who last edited a note without implying any earlier version exists.
- **Removal is permanent and says so.** A confirmation names the note, states that it cannot be restored, and has Cancel first. Nothing is retained, and nothing in the Console speaks of undo or history.
- **A tag is only a label.** It is shown as words, with no colour, status or grouping, and no part of the Console reads a tag's name to decide anything. A Person's tags are saved as the whole chosen set, which is what the API takes. A tag still held by anyone cannot be deleted: the Console shows the server's refusal and keeps the tag, and never changes anyone's tags to make a deletion succeed.
- **No tag is built in.** The Console holds no list of tag names; the starter vocabulary is data a Guardian creates (WP6 decides whether a demo seeder does).

## Implementation notes (WP6, 2026-10-02)

Where closing the milestone settled what this ADR left to demonstration and proof. None changes a decision above.

- **A demo dataset, opt-in and development-only.** `CrmDemoSeeder` (`./flow artisan db:seed --class=CrmDemoSeeder`) makes eight People, the seven example tags and twenty-three notes, so a Guardian can review the CRM without typing it in. It is not part of `DatabaseSeeder`, refuses to run outside `local` and `testing`, and nothing in the application refers to it. It writes through the CRM's own use cases as real operator Accounts, so it obeys the rules a Guardian's own entries do; it creates no Account, role or Membership, and records no Account's login as a contact method. The tag names are demo labels: nothing reads them, and a Guardian can rename or delete any of them like any other tag.
- **Repeatable.** A Person is recognised by their exact display name and a tag by its name; one that exists is left as it is (with whatever a Guardian has done to it), and a Person's notes are written only when the run creates the Person. A note's author and last editor are provenance with no foreign key (ADR 0021), so when the operators they were written as are replaced (the browser suite recreates its fixture Accounts, and their Persons, on every run) the next run points such notes at the current operators and touches nothing else.
- **What the dataset exercises.** A Person with no CRM data at all, one with an email only, a phone only, both, and two emails; People with several tags and with none; every kind of note; a Person with twelve notes (more than a page) with two authors and a corrected note; a Person with one long note; and a Person whose CRM email is the deterministic target of the duplicate-advice case.
- **The duplicate-advice case is advice, demonstrated end to end.** Registering anyone with the demo Person's email, or the same name in any case, meets her as a candidate; nothing is created until the Guardian says the person is distinct, and nothing is merged or adopted. The browser journey puts the shared email back afterwards, so the case is the same on every run.
- **What the browser suite now proves.** The working journeys (`people.spec.ts`); the demo data read as a Guardian reads it, a real history's paging by keyboard, the duplicate case against the demo Person, what each capability sees, what the wire carries, the keyboard and focus behaviour of confirmations and refusals, and a 320px screen (`people-closeout.spec.ts`); and the People routes in the real-browser axe pass in both themes and the sideways-scroll pass at every width (`accessibility.spec.ts`, `layout.spec.ts`).
- **What a browser can and cannot show about capabilities.** The Console shows what `/me` reports and the server decides every request. No role holds one CRM capability without the other, so the browser journeys for view without manage and manage without view are TOLD that by `/me` (the Account's real capabilities untouched); the server's own refusals, one capability at a time, remain proved by `PeopleAccessControlTest`. A real Account with no CRM access is proved in the browser against the real server.
- **Disclosure is checked by shape, not by search.** The record, the history, the directory and the tag list are asserted to carry exactly their documented keys at every depth, and none of any real Account's login or id appears in them. No endpoint offers the security audit trail as CRM history.

## Consequences

- The `Crm` module can be built without an Identity schema change. What Identity supplies is three Application ports, built and tested on both engines: `SearchPeople`, a paged, id-composable search returning a Person's id and display name, `FindPeopleNamed` (added with the WP2 audit remediation: an exact, case-insensitive name lookup, so duplicate advice never depends on where a name sorts), and `RenamePerson`.
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
