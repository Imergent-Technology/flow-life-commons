# ADR 0035: Guardian Discussions are durable asynchronous threads

- **Status:** Accepted (implemented: the `Discussions` backend (WP1) and the Guardian Console screens (WP2); the demo data and the closeout proof are not)
- **Date:** 2026-10-02
- **Supersedes:** none
- **Superseded by:** none
- **Related:** [ADR 0015](0015-identity-owns-person.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0019](0019-security-event-auditing-seam.md), [ADR 0021](0021-cross-module-referential-integrity.md), [ADR 0024](0024-privileged-operator-administration.md), [ADR 0034](0034-crm-enriches-identity-person.md)

## Context

G1 (CRM / People) is complete. G2 is **Guardian Discussions**: the first place inside Commons where Guardians keep questions, decisions, context and follow-up that would otherwise vanish into transient messaging. This ADR is the G2 design gate. It is written before a `Discussions` module exists so that the module is built inside it.

The product owner settled the shape before this gate: a standalone Guardian workspace, asynchronous rather than chat, a topic with an opening message and flat chronological replies, a two-state Open/Resolved lifecycle, author-only editing with visible edit marking, removal that leaves a tombstone rather than a hole, no per-thread privacy, and no notifications. This ADR records those decisions, makes the remaining ones, and states the Phase 1 contract precisely enough that implementation needs no further product decision.

Repository facts that shaped it, checked on `main` at `8ee96a5`:

- **The capability catalog** (`Access\Application\Capability`) gives each domain a read capability and a write capability (`membership.records.view`/`manage`, `crm.people.view`/`manage`), checked independently, with a role-catalog test pinning that every role granting the write also grants the read.
- **The administration route table test** (`AdministrationRoutesTest`) requires recent verification (`security.verified`) on every `/admin` mutation, with exactly one exemption, by capability: `crm.people.manage`, pinned to `Crm` routes. Any Discussions mutation under `/admin` meets that rule.
- **CRM notes** (`contact_interactions`, ADR 0034 WP3) already hold authored business content: author and last editor as Person ids with no foreign key, names resolved live through Identity's `FindPeople`, no `security_events` entry, offset paging (`page`, `per_page` at most 100, `total`).
- **Timestamps are second-precision.** Measured on the development databases: `contact_interactions.created_at` is `datetime` with precision 0 on MariaDB and `timestamp(0) without time zone` on PostgreSQL. Two replies in the same second tie on time, and the ULID that would break the tie is minted in application code before the transaction commits, so it does not record commit order either.
- **Identity has no path that deletes a Person.** Merge and anonymisation are open questions ([data ownership](../architecture/data-ownership.md)).

## Decision

### Ownership

1. **A new `Discussions` module owns discussion business state.** Not CRM (which is about People), not Identity (which owns Person, not what Persons write), not Access (which owns authorization machinery, not the state it guards), not Membership. It depends only on `Access\Application` (authorization), `Identity\Application` (`FindPeople`, for author names) and `Shared`. It has no `Audit`, `Crm` or `Membership` dependency, and it queries no table it does not own.
2. **It owns two tables, `discussions` and `discussion_messages`**, and nothing else. There is no generic comment, activity or discussion-target framework (charter rule 17): Discussions owns discussion behaviour, concretely.
3. **Authorship is anchored on Identity's Person.** There is no second Guardian identity: an author is the `PersonId` of the signed-in `Actor`, always taken from the session and never from the request.

### The thread model

4. **A discussion is a title, an opening message, and flat chronological replies.** Every discussion has exactly one opening message, created atomically with it.
5. **The opening message lives in the same table as replies** (Q9). It is the message with `sequence = 1`. One authorship, editing and removal model covers every message, so the opening message cannot drift from replies; a future rule that applies to messages (official responses, nesting) applies to it without a second code path. The title belongs to the discussion, not to a message.
6. **Messages are ordered by a per-discussion `sequence`**: 1 for the opening message, then 2, 3, … in the order replies commit. It is allocated under the discussion's row lock (decision 22), backed by `unique(discussion_id, sequence)`, and never reused or renumbered; a removed message keeps its number. Time is not the order, because time ties (Context).
7. **The discussion's creator is the opening message's author.** It is not stored a second time on the discussion row: a duplicated value would be an invariant to keep and nothing reads it differently.

### Lifecycle

8. **A discussion is `open` or `resolved`.** No other state, and no priority, assignment, vote or moderation queue.
9. **Anyone who may participate may resolve or reopen any discussion** (Q7). Guardians are peers, the person who opened a thread may be away when it is settled, and resolving destroys nothing and is undone by reopening. The discussion records who resolved it and when; reopening clears both. Resolution history (who resolved, reopened and resolved again) is not kept in Phase 1.
10. **A resolved discussion remains readable by everyone who may view discussions, and receives no new replies** (Q8). A reply to a resolved discussion is refused; reopening it is how a conversation continues.
11. **Resolution does not freeze authored content.** An author may still correct or remove their own message in a resolved discussion, and the creator may still correct its title. Freezing would add a second lifecycle rule and a lock to every edit for no Phase 1 need; removal in particular must never be blocked by lifecycle, because it is the author's way to withdraw something they should not have written. Edits after resolution are marked like any other.
12. **Resolve and reopen are idempotent.** Resolving a resolved discussion, or reopening an open one, succeeds and changes nothing (the original resolver is kept), so two Guardians acting at once both see the state they asked for.
13. **There is no discussion deletion in Phase 1.** A discussion, once started, is part of the record. Its words can be withdrawn message by message (decision 17).

### Authorship and editing

14. **Every message has one original author, which never changes** (`author_person_id`). It is provenance and the basis of the ownership rule.
15. **Only a message's author may edit it** (Q4). A Guardian may edit their own message and no other: there is no capability, role or administrator power in Phase 1 that rewrites another Person's words. Editing is checked as **ownership plus participation**: the caller must hold `discussions.participate` *and* be the author. Only the discussion's creator may correct its title, under the same rule.
16. **An edit is recorded explicitly and shown.** A message holds `edited_at` and `edited_by_person_id`, set by every edit that changes the text; `edited_at` being set is what "edited" means and is what the Console shows. `updated_at` alone is not enough: removal also changes the row, and "edited" must not be inferred from a timestamp with two meanings. `edited_by_person_id` always equals the author in Phase 1, and is stored anyway because it is the seam that lets a future rule (decision 31) let someone else edit without rewriting what authorship means. An edit that leaves the normalised text unchanged writes nothing and does not mark the message edited. **No previous version is retained**: Phase 1 has no edit history, and the Console never implies one.

### Removal

17. **Only a message's author may remove it** (Q5). Removal is not deletion: the row stays as a **tombstone** in its place in the sequence, and the Console shows "This message was removed" there, so the replies around it still read coherently.
18. **What a tombstone keeps** (Q6): the message's id, its discussion, its sequence, its author, and when it was written and removed. **What it loses:** the text. Removal sets `body` to `NULL` in the same statement that sets `removed_at`; the words are gone from the row, not hidden in another column. Nothing in Phase 1 restores them, and there is no trash, restore, version history or retained copy. This is also the only erasure Phase 1 offers, and it is a real one in the live database. Backups taken before the removal still hold the text, and the platform has no backup retention policy yet ([backup and restore](../runbooks/backup-and-restore.md)), so removal is not a guarantee about backups.
19. **A removed message cannot be edited, and is never given text again.** The edit statement is conditional on `removed_at IS NULL`, so an edit that loses a race with removal changes nothing and is reported as removed. Removing an already-removed message succeeds and changes nothing.
20. **The opening message may be removed like any other.** The discussion keeps its title (which the creator may correct) and its replies.
21. **There is no moderator removal in Phase 1** (decision 27). If it is added later it must remove, never rewrite, and will record who removed the message (a `removed_by_person_id` column, added then).

### Concurrency

22. **One real invariant needs a lock: no message is added to a resolved discussion, and sequences are gap-free in commit order.** Posting a reply, resolving and reopening each take the discussion row with `SELECT ... FOR UPDATE` (the pattern `Crm`'s profile row and every existing race test use on both engines), so a reply and a resolution are serialised: whichever commits first wins, and a reply that queues behind a resolution is refused with the discussion's resolved state. The same lock allocates the next sequence and updates `message_count` and `last_activity_at`. `unique(discussion_id, sequence)` is the backstop.
23. **Nothing else takes a lock.** Edits and removals change one message row and do not touch the discussion, so they need none. Two edits of the same message by its author (two tabs) are last-write-wins with no version token: Phase 1 edits are self-concurrent, the edited marker makes the change visible, and optimistic concurrency is something a future multi-editor rule would bring with it (decision 31). Edit versus removal is settled by the conditional update in decision 19.

### Visibility

24. **Discussions are not private per thread.** Everyone who may view discussions may read every discussion, open or resolved. There are no private, invite-only or restricted threads, per-thread audiences or secret replies.
25. **An author is shown as their Person's id and display name and nothing else**, resolved live through Identity's batched `FindPeople`, so a rename shows on messages already written. A response never carries an Account id, login email, Account status, role, MFA or other security state. A Person `FindPeople` cannot resolve is shown as an unknown person (`display_name: null`), never as an error. The Console does not link an author to their People record in Phase 1.

### Access

26. **Phase 1 introduces two capabilities** (Q1), by the package that first checks them (ADR 0017):
    - **`discussions.view`** — may list and read discussions and their messages. Changes nothing.
    - **`discussions.participate`** — may start a discussion, reply, resolve and reopen, and edit or remove *their own* messages and correct *their own* discussions' titles.

    Both are granted to the **Guardian** role; the Platform Administrator receives them automatically. A role-catalog test pins that **every role granting `discussions.participate` also grants `discussions.view`**, because writes return what they wrote (the CRM precedent). The capabilities are still checked independently, and the Console never treats one as the other.
27. **There is no `discussions.manage` and no moderation capability in Phase 1.** Its only plausible meanings are "edit anyone's words", which this ADR forbids, and "remove anyone's message", which has no consumer yet. It is added, with its own decision, when moderation is needed.
28. **Routine discussion is exempt from recent verification.** Writing in a discussion grants and removes no authority, and asking for a fresh password and second factor to post a reply would make the feature unusable, exactly ADR 0034's reasoning for CRM maintenance. The administration route-table test's single exemption (`crm.people.manage`, pinned to `Crm` routes) becomes an explicit list of exactly two, **`crm.people.manage` pinned to `Crm` routes and `discussions.participate` pinned to `Discussions` routes**, each still checked to cover its own module's routes and nothing else. A materially sensitive discussion operation (bulk export, moderator removal of another's words) would get its own capability and would not inherit the exemption.
29. **No security event accompanies any discussion mutation** (Q14). Discussion history is business data owned by Discussions, and `security_events` is scoped to identity and access by ADR 0019; nothing in Phase 1 changes anyone's authority. This is the same call CRM notes made.

### Not in Phase 1

30. **Notifications are entirely deferred** (Q17): no email, push, mention or digest, and no coupling to production mail activation. The feature works with none. Nor is there realtime delivery, presence, typing indicators, private messaging, chat rooms, reactions, read receipts, mentions or collaborative editing.
31. **Official and collaborative posts are reserved, not built** (Q16). A future message may be editable by more than its author (all Guardians, Guardian leadership, an explicit editor set). Phase 1 keeps that possible by separating original author from last editor (decision 16) and by putting the opening message in the same model as replies (decision 5), and builds nothing for it: no `official` flag, editor table, leadership rule, shared authorship or collaborative-edit API. When it comes, it is a new, named kind of message with its own edit rule and its own concurrency control, not a boolean on today's message.
32. **Nested replies are reserved, not built** (Q15). The likely future shape is a nullable `parent_message_id` within the same discussion, added by migration; `sequence` remains the commit order, the opening message the root, and today's flat list the depth-one case. Phase 1 adds no parent column, because nothing reads it and a column that is always NULL proves nothing about the eventual tree rules.
33. **Text search is title-only in Phase 1** (Q18): a case-insensitive "contains" match on the title, the same portable `lower(column) like ? escape` strategy CRM uses, combined with the state filter. Message bodies are not searched; body search and any search index are deferred until browsing proves insufficient.

## Phase 1 contract

Recorded so implementation follows it. Names may be refined by the implementing package where nothing above depends on them; the decisions may not.

### Schema

`discussions`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `title` | `string(200)` | Required, single line, trimmed, no control characters |
| `state` | `string(16)` | `open` or `resolved`; validated in the domain (no database enum, charter rule 13) |
| `message_count` | integer | The last allocated `sequence`; tombstones count, since each holds its place |
| `last_activity_at` | `dateTime` | When the most recent message was posted (decision 34) |
| `resolved_at` | `dateTime`, nullable | Set when resolved, cleared when reopened |
| `resolved_by_person_id` | `char(26)`, nullable | Provenance, no foreign key |
| `created_at`, `updated_at` | `dateTime` | UTC |

Indexes: `(last_activity_at, id)` for the list; `(state, last_activity_at, id)` for the filtered list.

`discussion_messages`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `discussion_id` | `char(26)` | Foreign key to `discussions.id`, `RESTRICT` (within the module) |
| `sequence` | integer | `unique(discussion_id, sequence)`; also the paging index |
| `author_person_id` | `char(26)` | Provenance, **no foreign key**; never changes |
| `body` | `text`, nullable | Required while the message exists; `NULL` exactly when removed (enforced in the domain and tested, not by a CHECK constraint) |
| `created_at` | `dateTime` | |
| `edited_at` | `dateTime`, nullable | Set by an edit that changes the text |
| `edited_by_person_id` | `char(26)`, nullable | Provenance, no foreign key |
| `removed_at` | `dateTime`, nullable | Set by removal |

There is no `updated_at` on a message: its three meaningful changes each have their own column. Bodies are limited to 10,000 characters after normalisation (CRLF and lone CR to LF, trimmed, no control characters other than LF and TAB, formatting characters allowed: the rules CRM notes apply, implemented in Discussions' own domain rather than shared).

**Foreign keys** (Q12, Q13, ADR 0021): `discussion_messages.discussion_id` is an invariant within the module and gets `RESTRICT` (no discussion is deleted in Phase 1). Every `*_person_id` is provenance and gets **no foreign key**, as CRM notes and audit references do: a message must neither block a future Person merge or anonymisation nor be broken by one. There is therefore no cross-module foreign key and no migration-order dependency beyond running after Identity by convention.

**When a Person disappears** (Q13): no Identity path deletes a Person today. If a future merge or anonymisation operation removes or rewrites one, the messages remain, with their `author_person_id` unchanged; their author shows as unknown (decision 25), and because no Actor can then be that Person, no one can edit or remove those messages until a moderation rule exists. Re-pointing authorship on merge is that future operation's decision, not this one's.

### Portability (Q19)

Nothing here needs an exception to [ADR 0005](0005-mariadb-with-postgresql-portability.md) or the portability scan: ULID keys generated in code, UTC `dateTime` columns written from the domain, `state` as a validated string rather than a database enum, the body/removal invariant enforced in the domain rather than by a CHECK constraint, plain composite and unique indexes (no partial or functional index, no generated column), `SELECT ... FOR UPDATE` as the only lock, and title search by `lower(title) like ? escape`. Case folding of non-ASCII titles follows each engine's collation, as CRM search already does, and a test should pin the difference rather than leave it accidental. Every behaviour above is tested on both engines, including the race.

### Ordering and paging

34. **Discussions list most recently active first** (Q10): `last_activity_at` descending, then `id` descending, a total order. **Activity is posting a message**: starting the discussion, or a reply. Editing, removing, correcting the title, resolving and reopening are not activity and do not move a discussion. Stated because an edit resurfacing a thread would draw attention to a silent change, and a removal resurfacing one would draw attention to exactly what its author withdrew.
35. **Messages list in `sequence` order, oldest first** (Q11). Tombstones are listed in their place.
36. **Paging is the existing offset convention**: `page` and `per_page` (default 25, at most 100), with `meta.page`, `per_page`, `total` and `last_page`. A list can shift while it is being paged when new activity arrives; that is accepted, as it is for the People directory.

### Application use cases

All take the `Actor`. Reads ask for `discussions.view`, writes for `discussions.participate`, through `Access\Application\AuthorizeAction`; ownership and lifecycle are checked by Discussions after the capability.

| Use case | Does |
| --- | --- |
| `PageDiscussions` | Lists discussions, filtered by state (`open`, `resolved`, or both) and optional title text, with each discussion's title, state, creator, message count, last activity and resolution |
| `GetDiscussion` | One discussion's header: the same fields |
| `PageDiscussionMessages` | A discussion's messages in sequence order |
| `StartDiscussion` | Creates the discussion and its opening message in one transaction |
| `ReplyToDiscussion` | Under the discussion's lock: refuses if resolved, allocates the next sequence, writes the message, updates `message_count` and `last_activity_at` |
| `EditOwnMessage` | Author only, not removed; sets the text, `edited_at`, `edited_by_person_id` |
| `RemoveOwnMessage` | Author only; sets `removed_at` and nulls `body`; idempotent |
| `RetitleOwnDiscussion` | Creator only |
| `ResolveDiscussion`, `ReopenDiscussion` | Under the discussion's lock; idempotent |

Author names are composed for a whole page with one `FindPeople` call, as CRM does.

### HTTP surface

Under `/api/v1/admin`, behind `stateful`, `auth:web` and `can:console.access`, with one capability per route and no `security.verified` (decision 28). Every route is described in `openapi/openapi.yaml`.

| Method and path | Capability | Use case |
| --- | --- | --- |
| `GET /admin/discussions?state=&q=&page=&per_page=` | `discussions.view` | `PageDiscussions` |
| `POST /admin/discussions` (`title`, `body`) | `discussions.participate` | `StartDiscussion` |
| `GET /admin/discussions/{discussion}` | `discussions.view` | `GetDiscussion` |
| `PATCH /admin/discussions/{discussion}` (`title`) | `discussions.participate` | `RetitleOwnDiscussion` |
| `POST /admin/discussions/{discussion}/resolve` | `discussions.participate` | `ResolveDiscussion` |
| `POST /admin/discussions/{discussion}/reopen` | `discussions.participate` | `ReopenDiscussion` |
| `GET /admin/discussions/{discussion}/messages?page=&per_page=` | `discussions.view` | `PageDiscussionMessages` |
| `POST /admin/discussions/{discussion}/messages` (`body`) | `discussions.participate` | `ReplyToDiscussion` |
| `PATCH /admin/discussions/{discussion}/messages/{message}` (`body`) | `discussions.participate` | `EditOwnMessage` |
| `DELETE /admin/discussions/{discussion}/messages/{message}` | `discussions.participate` | `RemoveOwnMessage` (returns the tombstone) |

Refusals, in the order they are checked: no capability, `403`; unknown discussion, or a message not in that discussion, `404`; not the author or creator, `403 not_author` (existence is not secret, since every viewer can read every message); replying to a resolved discussion, `409 discussion_resolved`; editing a removed message, `409 message_removed`; invalid input, `422`.

A message is presented as its id, sequence, author (`id`, `display_name`), `created_at`, and then either `body`, `edited_at` and the editor, or, for a tombstone, `removed: true` and `removed_at` with no `body`. The `/me` capability list is how the Console decides what to show; the server decides every request.

## Architecture and security review

What this gate checked the contract against, and what answers each.

| Risk | Answer |
| --- | --- |
| Ownership drift into CRM, Identity or Access | Decision 1; module-boundary tests already forbid any other module's `Domain`, `Infrastructure` or `Http` |
| A capability read as a broader power | Decisions 15, 26, 27: `participate` never means editing others' words, and there is no `manage` to misread |
| Forged authorship | Decision 3: the author is the session's Person; no request field names an author, editor or resolver |
| Editing someone else's content | Decision 15, checked in the use case after the capability; a test per refusal |
| Disclosure through author identity | Decision 25: Person id and display name only, asserted by response shape as CRM's are |
| Removed text leaking | Decisions 18 and 19: the text is NULL in the row, the edit is conditional, the presenter emits no `body` for a tombstone |
| Reply after resolution, duplicate or reordered sequence | Decision 22: one row lock, a unique index, and a two-process race test on both engines |
| Cross-engine divergence | Portability above |
| Realtime or chat scope creep | Decision 30; no websocket, polling contract, presence or unread state exists to grow from |
| Generic comment framework scope creep | Decision 2; the tables name discussions and nothing else, and no other module may write to them |

## Package sequence (Q20)

WP1 backend, WP2 Guardian UI, WP3 end-to-end proof and demo closeout. Scope and validation boundaries are in the [roadmap](../roadmap.md); the sequence is recorded there because it is status, not a decision.

## Deferred, recorded so Phase 1 does not block them

None of these is designed here, and nothing is built for any of them.

| Possibility | What keeps it open |
| --- | --- |
| Nested replies | Decision 32: a later nullable parent within the discussion; `sequence` stays commit order |
| Cross-domain links (a discussion about a Person, an event, a resource) | Discussions is standalone; a link from a concrete consumer is a later, explicit reference, not a polymorphic target table |
| Official or team-owned responses, collaborative editing, leadership-only editing, explicit editor lists | Decisions 16 and 31: author and last editor are already distinct |
| Private or restricted discussions | Designed from a concrete requirement; today every viewer sees every thread |
| Moderation (removing another's message, locking a thread) | Decisions 21 and 27: a new capability and a `removed_by_person_id`, never rewriting |
| Notifications, mentions, digests | Decision 30; a later package once the core exists, after production mail is active |
| Reactions, attachments, read receipts | No model; attachments wait for the platform's file and media decision |
| Realtime updates, presence, chat | Horizon ([roadmap](../roadmap.md)); Phase 1 is read-on-request |
| Member or Volunteer participation | A projection of Commons-owned discussions onto `/my/` later; the Guardian Console surface is not reused for it |
| Body search, a search index | Decision 33 |
| Resolution history, edit history, discussion deletion | Decisions 9, 16 and 13 |

## Implementation notes (WP1, 2026-10-02)

Where building the backend made a call this ADR left open, or measured something it asserted. None changes a decision above.

- **Shape.** The `Discussions` module is `Domain` (`Discussion`, `DiscussionMessage`, `DiscussionState`, `DiscussionTitle`, `MessageBody`, the two ports), `Application`, `Infrastructure` (query builder, no Eloquent) and `Http`. Migrations `2026_10_02_000002_create_discussions_table` and `2026_10_02_000003_create_discussion_messages_table` create the two tables exactly as the contract above states, with the indexes it names and no others. The use cases are `PageDiscussions`, `GetDiscussion`, `PageDiscussionMessages`, `StartDiscussion`, `ReplyToDiscussion`, `EditOwnMessage`, `RemoveOwnMessage`, `RetitleOwnDiscussion`, `ResolveDiscussion` and `ReopenDiscussion`, and the ten routes are as tabled, named `api.v1.admin.discussions.*`.
- **The repository writes only the columns a change owns.** A reply writes the count and activity time, a retitle the title, a resolution the state columns, an edit the text and edit provenance, a removal the tombstone columns. That is what lets retitle, edit and removal take no lock without overwriting a counter a concurrent reply moved, and it is a second reason a title correction can never count as activity: the activity column is not in its write.
- **Edit and removal are conditional writes, re-read afterwards.** `UPDATE ... WHERE removed_at IS NULL` (and, for an edit, `AND author_person_id = ?`), then the use case re-reads, because MariaDB reports an unchanged row as zero affected rows. An edit that lost a race with a removal is reported `message_removed` and has changed nothing. Removing a removed message succeeds, keeps the first removal time, and returns the tombstone.
- **Where the locking is the point, measured.** Reply, resolve and reopen take `SELECT ... FOR UPDATE` on the discussion row. With the lock removed, the reply-behind-resolution, reply-behind-reopening and simultaneous-replies race tests fail on both MariaDB and PostgreSQL. The resolve-behind-reply test still passes without it, correctly: the reply's own row update already blocks the resolver, so only the read-then-decide cases need the explicit lock. With the edit's `removed_at IS NULL` condition removed, the edit-versus-removal test fails on both engines.
- **Messages are presented in one of two shapes**, keyed by `removed`. A live message has `body`, `edited_at` and `edited_by`. A tombstone has `removed: true` and `removed_at` and **no `body` key at all**, and does not say whether it was ever edited. Both carry the author.
- **An author Identity cannot resolve** is `{ "id": ..., "display_name": null }`, as decision 25 says: the id stays, so a client can still tell whose it was, and nobody can ever be that Person.
- **What each write returns.** Starting a discussion returns the header (201); a reply returns the message (201); an edit and a removal return the message (200, the tombstone for a removal); retitling, resolving and reopening return the header (200). The header carries `title`, `state`, `creator`, `message_count`, `last_activity_at`, `created_at`, `resolved_at` and `resolved_by`, and not `updated_at`.
- **Which refusal comes first.** The route's capability check answers first (a plain 403 with no code). Request *shape* (a missing or non-string field, a bad `state`, an out-of-range page) is validated by the request class before the controller runs, so it answers 422 before a 404 or `not_author`, as every other administration surface does. The *domain* rules (a blank or over-long title, control characters) are judged inside the use case after existence, authorship and state, so an author editing a removed message with a bad body hears `message_removed`, and a reply to a resolved discussion hears `discussion_resolved`, not `invalid_discussion_input`.
- **Search** trims `q`, treats a blank one as none, takes `%` and `_` literally, and folds case as CRM's does. Listing and paging take the existing `page`/`per_page` convention, with `per_page` at most 100 and a `COUNT` for `total`.
- **The step-up exemption is a list of exactly two**, `crm.people.manage` pinned to `Crm\Http` and `discussions.participate` pinned to `Discussions\Http`, in `AdministrationRoutesTest`. A route-table test also pins every Discussions route to the Console boundary and exactly one capability (view for reads, participate for changes).
- **`FindPeople` has a third consumer.** The architecture test that names who may use Identity's batched read port now lists `Discussions\Application`, beside Membership, Access and CRM. Discussions reads no table of Identity's.
- **The Guardian role's `/me` list** is now `console.access`, `crm.people.manage`, `crm.people.view`, `discussions.participate`, `discussions.view`. The Console reads none of the new capabilities until WP2.

## Implementation notes (WP2, 2026-10-02)

Where building the Guardian Console screens settled what this ADR left to the UI. None changes a decision above, and the backend is untouched.

- **Surface.** A Discussions section in the Console's rail (`/discussions`, `/discussions/new`, `/discussions/:discussionId`). The list and a thread need `discussions.view`; starting one needs `discussions.participate`. The Console never treats one capability as the other, so a Guardian who could participate but not view would reach the start form and, once it succeeded, be refused the thread it opened. The server decides every request.
- **The list** shows each discussion's title, its state in words, who started it, its message count and when it was last active, in the server's order and never re-sorted. Search is submitted rather than run on every keystroke, matches titles only and says so in its label; the Open/Resolved filter applies at once; either returns to page 1. It offers no preview of replies and nothing that implies message text is searched.
- **A thread is a flat record, not a feed.** The header (title, state, who started it and, when resolved, by whom and when), then the messages in `sequence` order, then, only for someone who may take part and only while it is open, a reply form. Each message is a bordered record with its author, its place (`Opening message`, `Message N`), its time and, if edited, `Edited` and when. There is no avatar, no nesting and nothing that answers one message to another.
- **Ownership is the signed-in Person's id.** `GET /me` already carries `person.id`; a message is "mine" when its author's id equals it, and a discussion is "mine" when its creator's id does. A name is never compared, an author Identity no longer holds (`display_name: null`) is shown as "Unknown person" and is nobody's, and a tombstone has no controls. Edit and Remove are offered only on one's own live messages, and "Edit title" only to the creator, and only to someone who may take part.
- **A tombstone** is a dashed placeholder that says "This message was removed.", names its place and author, and says when it was written and removed. It shows nothing of what it said (the server sends no text) and offers no restore.
- **Reply.** One box and one button. A Resolved discussion replaces the form with a panel that says replies are closed and why, rather than letting it vanish. An unsent reply is held by the page, not the form, so it survives the discussion being resolved under the writer and comes back when it is reopened. A `discussion_resolved` refusal adds nothing, says so, and re-reads the discussion so the page shows what is now true.
- **Edit, remove and retitle.** An edit sends only the body and a retitle only the title, and neither sends anything when the text is as it was. Removal asks first, in a dialog that says the text goes for everyone, a placeholder stays and it cannot be undone; Cancel is the next stop after the dialog opens and Escape does the same. A message that was removed or has gone while it was being edited closes the form, says so, and re-reads the thread and the header. A resolved discussion still allows all three.
- **`not_author` is now distinguishable.** The shared HTTP layer used to reduce every 403 to "not permitted"; a 403 now carries the server's `code` when it has one (additive: a 403 without one is unchanged), so the Console can say "Only the person who wrote that can change it." where it would otherwise blame the account.
- **A long unbroken title overflowed the page** at every width until `PageHeader`'s heading was allowed to break a long word. The browser layout suite found it (it failed at all nine widths before the one-line fix and passes after); jsdom has no layout, so it is the only test that can.
- **What the tests prove where.** Component tests drive the real client, router and pages against a fake server (the list, starting, reading, replying, editing, removing, retitling, resolving, every capability combination, the absence of nesting, official, moderation, notification, mention, reaction, attachment, privacy and chat controls, and that no Account or security data reaches the page). Mutation checks broke seven properties (edit offered to everyone, ownership by name, reply while resolved, participate as read authority, dropping tombstones, ordering by time, retitle offered to every participant) and each went red. A dialog's Escape and the return of focus are the browser's own `<dialog>` and are proved only in real Chromium (`e2e/discussions.spec.ts`), together with a message someone else wrote (no controls, and the server refuses a direct attempt), what each capability is shown, and the whole life of a discussion. The thread and list routes are in the browser's axe pass (both themes) and sideways-scroll pass (every width).

## Consequences

- Guardians get one durable, attributable place for asynchronous coordination, with no new identity, no new authorization machinery and no infrastructure (no worker, mail, websocket or index), so it runs on the shared host as the platform does today.
- The step-up exemption stops being a single capability. It stays a short, explicit, test-pinned list, and each entry still has to name the module it covers.
- `discussions.view` reveals the display names of everyone who has written in a discussion. They are Guardians writing for Guardians, and a name is what a reader needs; nothing about their Accounts is reachable through it.
- Withdrawing a message really removes its words, but every other change is lossy in the other direction too: an edit overwrites, and no one, including an administrator, can see what a message used to say. That is the intended trade for Phase 1; a feature that needs history must add it deliberately.
- A message whose author can no longer act (their Person merged away, or simply no longer a Guardian) cannot be corrected or withdrawn by anyone until moderation exists. Losing `discussions.participate` does not remove a person's words, and does not let anyone else remove them.
- Erasure of a Person's discussion content as a whole (rather than message by message) belongs to the still-open anonymisation policy, which this ADR adds more personal data to.

## Alternatives considered

- **The opening message as columns on `discussions`.** Rejected: two copies of the authorship, edit and removal rules, and a future official or nested rule would have to be written twice. The title stays on the discussion because it is a property of the thread, not of a message.
- **Order messages by `created_at`, then `id`.** Rejected: both engines store second precision (measured), and the ULID is minted before commit, so ties are ordered arbitrarily rather than by when the reply actually landed. The sequence costs one counter and uses a lock the resolve rule needs anyway.
- **Only the opener may resolve.** Rejected: threads would stay open whenever the opener is absent, and resolving is reversible and harmless.
- **Freeze edits and removals on resolution.** Rejected (decision 11): a second rule, a lock on every edit, and it would block an author withdrawing something they should not have posted.
- **Keep removed text in a hidden column.** Rejected: it would be a store of words their author withdrew, with no reader and no policy, and removal would not be erasure.
- **Hard delete a removed message.** Rejected by the product decision: chronology and the replies that answered it would lose their context.
- **One capability for reading and writing.** Rejected: reading is disclosure and writing is speaking in one's own name; every other domain separates them, and a read-only role (an observer, a future projection) becomes a role edit rather than a capability change.
- **A `discussions.manage` capability now.** Rejected (decision 27): nothing would check it that this ADR allows.
- **Author foreign keys to `people` with `RESTRICT`.** Rejected: authorship is provenance (ADR 0021), and a key would let a future merge or anonymisation be blocked by, or forced to rewrite, what people wrote.
- **Record discussion mutations in `security_events`.** Rejected (decision 29).
- **Require recent verification for posting.** Rejected (decision 28).
- **Snapshot the author's display name on each message.** Rejected: CRM resolves names live, a rename should read consistently everywhere, and a snapshot is personal data copied into a second place.
