# ADR 0037: Resources are audience-targeted Packs of Cards

- **Status:** Accepted (implemented: the `Resources` backend foundation, WP1, and the Console's rich-content foundation, WP2; managed files and both Console surfaces are later packages)
- **Date:** 2026-10-04
- **Amended:** 2026-10-04, before implementation: permanent deletion of a Pack or Card now records a security event (decision 55). Nothing else changed
- **Supersedes:** none
- **Superseded by:** none
- **Related:** [ADR 0005](0005-mariadb-with-postgresql-portability.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0019](0019-security-event-auditing-seam.md), [ADR 0021](0021-cross-module-referential-integrity.md), [ADR 0023](0023-multi-factor-authentication.md), [ADR 0026](0026-production-browser-security-policy.md), [ADR 0027](0027-release-and-deployment-model.md), [ADR 0028](0028-membership-grants-derived-at-query-time.md), [ADR 0035](0035-guardian-discussions-are-durable-asynchronous-threads.md), [ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md)

## Context

G1 (CRM / People) and G2 (Guardian Discussions) are complete. G5, **Resources / Knowledge**, is next ([roadmap](../roadmap.md)): the shared layer through which Flow Life delivers knowledge and resources (SOPs, recipes, instructions, policies, curated links, reference material, files) to Guardians first and, later, to Members, Volunteers and other relationships. This ADR is the G5 design gate. It is written before a `Resources` module exists so the module is built inside it.

The product owner settled the shape in an interactive planning session before this gate: a hierarchy of Category, Resource Pack and Card; a Card that is a complete resource in itself; three initial Card Types; reversible publication of Packs and Cards; audiences that are positive, Pack-level and narrowable per Card; complete non-disclosure of what a viewer may not see; Tiptap as the editor; server-authoritative sanitization; File Cards that own one managed file; permanent deletion behind recent verification; and a Guardian Console–only first surface. This ADR records those decisions, makes the remaining ones, and states the Phase 1 contract precisely enough that implementation needs no further product decision.

The principle that shapes the generality of everything below: **build for Flow Life first; keep a boundary clean where that is cheap; build no speculative multi-tenant, SaaS or CMS machinery.** The possibility that Commons is one day extracted into a general platform is a pressure against gratuitous Flow-Life-specific coupling in low-level primitives, and nothing more.

Repository facts that shaped it, checked on `main` at `957fd05` (measurements were made against the development stack and a scratch copy of the lockfile; the working tree was not changed by them):

- **Capabilities and roles.** Each domain has a read and a write capability, checked independently (`Access\Application\Capability`). `Role` is internal to Access, and an architecture test forbids any other module from naming it, so no module outside Access can ask "is this a Guardian?" except as a capability.
- **The step-up exemption** in `AdministrationRoutesTest` is a list of exactly two capabilities, each pinned to its own module's `Http` namespace (`crm.people.manage`, `discussions.participate`). A route holding an exempt capability may still carry `security.verified`; the test only requires that every *unverified* mutation hold an exempt capability, and that the proof comes after the capability. Recent verification is a password and second factor within 15 minutes ([ADR 0023](0023-multi-factor-authentication.md)).
- **The Console forbids HTML injection.** `guardrails.test.ts` fails on `dangerouslySetInnerHTML` or an `.innerHTML =` assignment anywhere in the Console source.
- **The production CSP** ([ADR 0026](0026-production-browser-security-policy.md), `config/security.php`) is `default-src 'none'` with `script-src`, `style-src`, `img-src`, `font-src` and `connect-src` all `'self'`, no `'unsafe-inline'`, and `frame-ancestors 'none'`. An editor that injects a `<style>` element, content with an external image, or an embedded `<iframe>` would each be blocked.
- **Nothing in the platform handles HTML or files today.** `composer.json` has no HTML sanitizer; the Console has no editor dependency; no application code uses `Storage` or a disk.
- **Storage across releases.** On the production host the only mutable state shared between immutable releases is `shared/.env` and `shared/storage` ([ADR 0027](0027-release-and-deployment-model.md)), and `storage/` is not reachable from the web (production readiness R8, verified).
- **PHP on both sides.** `fileinfo` and `dom` are loaded in development; `fileinfo` is among the extensions verified on the production host and checked by `security:production-check`. Development PHP has the stock upload limits: **measured `upload_max_filesize = 2M`, `post_max_size = 8M`**. The production host's limits have not been measured.
- **Column types, measured on both engines** with Laravel's schema builder: `json()` becomes `longtext` on MariaDB but `json` on PostgreSQL (divergent semantics); `mediumText()` becomes `mediumtext` and `text`, and both held a 70,000-character value (MariaDB `text` stops at 65,535 bytes). A unique index over a nullable column accepted two rows with `NULL` and the same position on **both** engines.
- **Dependencies, resolved against a scratch copy of the real lockfile.** `symfony/html-sanitizer`'s newest line requires PHP ≥ 8.4.1; under the repository's PHP 8.3 platform pin Composer resolves **v7.4.20**, the same 7.4 LTS line as the installed Symfony components, adding one transitive package (`masterminds/html5`); `league/uri`, which it also needs, is already locked. `ueberdosis/tiptap-php` hard-requires `spatie/shiki-php`, a Node-backed highlighter, and production has no Node. On npm, Tiptap is at 3.31.4 (MIT), including `@tiptap/static-renderer`.
- **Timestamps are second-precision** and ULIDs are minted before commit ([ADR 0035](0035-guardian-discussions-are-durable-asynchronous-threads.md)), so neither can be an ordering.
- **The audit seam** is `Audit\Application\RecordSecurityEvent`, called synchronously inside the transaction of the change it records, so an event exists if and only if the change committed ([ADR 0019](0019-security-event-auditing-seam.md)). Event types are module-owned dotted names (`role.granted`, `password.reset_requested_by_operator`, `mfa.recovery_code_used`); operator events carry the acting Account and a small flat context. Precedent for what context holds: `person.renamed` records `{"changed": "display_name"}`, never the old or new name. Today only Identity and Access call the seam: an architecture test (`MembershipTrustBoundariesTest`) pins `RecordSecurityEvent` to Identity, Access and Audit, and CRM, Membership and Discussions each pin that they do not use Audit at all.
- **No member-facing authorization consumer exists.** ADR 0028's Access-defined, Membership-implemented capability port is still unbuilt, by design, until a consumer needs it.

## Decision

### Vocabulary and ownership

1. **Terms** (Q1). *Resources* is the user-facing feature and the module. A *Category* is global organizational metadata. A *Resource Pack* ("Pack" in code and the Console) is the shareable unit. A *Card* is one complete resource inside a Pack. A *Card Type* selects how a Card presents and integrates. Code names: `ResourceCategory`, `ResourcePack`, `ResourceCard`, `CardType`, `Audience`. The working title "Knowledge" is retired. Not "deck", "collection", "course" or "lesson".
2. **A new `Resources` module owns all of it** (Q35): Categories, Packs, Cards, their rich content, audience targeting, publication, ordering, and the managed files that File Cards own. It depends only on `Access\Application` (authorization), `Identity\Application` (`FindPeople`, for provenance names in management responses), `Audit\Application` (`RecordSecurityEvent`, for the two permanent-deletion events of decision 55 and nothing else) and `Shared`. It has no `Crm`, `Discussions` or `Membership` dependency in Phase 1 (decision 44 says when `Membership` arrives), it queries no table it does not own, and nothing depends on it.
3. **Resources owns which audiences content targets, never the facts that put a Person in an audience** ([ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md)). It stores no membership, volunteer, partner, vendor, artist, enrollment or role state, and no copy of any.
4. **Managed files live inside Resources in Phase 1** (Q14, Q35): a `resource_assets` table and a `ResourceFileStore` port, implemented in `Resources\Infrastructure` over a dedicated private Laravel disk. Laravel's filesystem is the storage abstraction; nothing is added to `Shared`, which stays the tiny kernel the charter describes, and no `Files` or `Media` module is created for one consumer. The trigger to extract one is a second module needing managed files (Discussion attachments, say); that is its own design gate, and the port makes it a move rather than a rewrite.
5. **No generic CMS.** No page builder, content-type registry, polymorphic content item, workflow engine or plugin mechanism. Card Types are a closed enum in code.

### Hierarchy and ordering

6. **Category → Pack → Card.** A Pack has at most one Category, and exactly one while Published; a Draft Pack may have none. There is no multi-Category membership and no tagging in Phase 1; cross-cutting discovery gets tags or filters later, if a real need appears.
7. **A Category is a name**, at most 80 characters, unique case-insensitively through a lower-cased, whitespace-collapsed `name_canonical` column (the CRM tag precedent, and the same documented accent-folding difference between engines). Categories are not audiences and not authorization. How a consumer presents them (list, grid, masonry, sections) is the consumer's business, not the Category's.
8. **Order is explicit** (Q7–Q9). Categories, the Packs within a Category, and the Cards within a Pack each have an integer `position`; order is `(position, id)`, a total order. Creation time never orders anything.
9. **Reordering states the whole sibling list.** A reorder request carries the complete ordered list of ids for one sibling set (all Categories; the Packs of one Category; the Cards of one Pack). Under the parent's lock, the server checks that the list is exactly the current set (otherwise `409 order_mismatch`, because someone else added, moved or deleted a sibling) and rewrites positions `1..n`. A new item is appended at `max(position) + 1`; a Pack moved to another Category is appended there. **There is no unique index on position**: a unique index makes every reorder a two-phase update, and (measured) it would not constrain the uncategorised Draft Packs anyway, because both engines treat `NULL`s as distinct. A transient duplicate position is still totally ordered by id and is normalised by the next reorder.
10. **A Category can be deleted only while no Pack references it**, in any state (`409 category_not_empty`; the foreign key is the backstop). Nothing is lost but a name, so this needs no recent verification.

### Packs

11. **A Pack has** a title (required, at most 200 characters), an optional summary (plain text, at most 300), a Series flag, at most one Category, an audience set, a publication state, a revision and provenance. It holds zero or more Cards while Draft, at most 100.
12. **Pack lifecycle** (Q2): `draft` and `published`, nothing else. A Pack is created as a Draft. Publishing and unpublishing are reversible and idempotent (publishing a Published Pack succeeds and changes nothing). There is no Archive, Trash, scheduled publication or expiry. The Console calls the unpublished state *Draft*, for Packs and Cards alike, and *Unpublish* is the action that returns something to it.
13. **Publish validation for a Pack** (Q4): a title, a Category, at least one audience, and at least one Published Card. A Pack that fails is refused with `409 pack_not_publishable` and the list of unmet requirements.
14. **A Published Pack always satisfies its publish validation.** While a Pack is Published, a change that would break a requirement is refused with `409 published_pack_requirement`: clearing its Category, removing its last audience, or unpublishing or deleting its last Published Card. The editor unpublishes the Pack first, or publishes another Card. "Published" therefore always means "publishable", and nothing has to re-check it later.
15. **A Pack summary is explicit text only.** It has no derived mode and never follows a Card. The Console may offer a Card's summary as a starting value for a one-Card Pack; once saved it is the Pack's own text.
16. **Series is presentation only** (Q11). A Series Pack emphasises Previous and Next and the position *n of m* over the Cards the viewer can see. It implies no completion tracking, prerequisites, locking or progress, nothing is stored per viewer, and a viewer may always go directly to any Card they can see.

### Cards

17. **A Card is a complete resource**: a title (required, at most 200), a summary, rich content, a Card Type, a position, a publication state, an optional external URI, and, for a File Card, one owned asset. It needs no URI or file to have meaning.
18. **Initial Card Types** (Q12), stored explicitly as a string:
    - **`basic`**: the rich content is the resource. A URI is optional and is shown as a related link.
    - **`external_link`**: a URI is required, at creation and always. The Card's title, summary and content are ordinary Card fields, and presentation is generic.
    - **`file`**: owns exactly one managed asset, required at creation and always. Its title, summary and content are ordinary Card fields (the content describes the file); the Console shows the file's name, type and size, with View (where safe, decision 63) and Download.
19. **A Card's Type is fixed at creation** in Phase 1. Changing it means creating a new Card. This removes every type-transition question (what happens to a file when a File Card becomes Basic) for no Phase 1 need.
20. **Every Card keeps the generic fallback.** Delivery always carries the title, summary, content and the Card's safe action (its link or its file), whatever the Type, so a consumer that does not recognise a Type renders the Card generically. A future provider-specific Type (YouTube, Google Docs, Canva, a WordPress page) adds validation and a richer presentation and degrades to that fallback when its enhancement is unavailable. There is no WordPress Card Type in Resources v1; an External Link Card is sufficient until the WordPress companion milestone.
21. **URI detection is advisory.** When the editor later recognises a pasted URI, it *suggests* a Type; the chosen Type is what is stored. Detection is never re-run on stored Cards, so improving its rules never silently changes an existing Card.
22. **Card lifecycle** (Q3): `draft` and `published`, independent of the Pack, created as Draft, reversible and idempotent. A Draft Card stays visible and ordered for editors and is completely absent from delivery: from navigation, counts and search. Unpublishing is the reversible "archive for later".
23. **Publish validation for a Card** (Q5): a title; for `basic`, content with non-empty text; for `external_link`, a valid URI; for `file`, its asset. Publishing a Card never needs the Pack to be Published.
24. **External URIs are validated by Resources' Domain** (Q13), in an `ExternalUri` value object, not a shared validator, because Resources is its only consumer. An external URI is trimmed, at most 2,048 characters, absolute, with scheme `https` or `http` (stored lower-cased), a host, and no user-information part, whitespace or control characters. Links *inside* rich content follow the same rule and may also be `mailto:` with an address. `javascript:`, `data:`, `vbscript:`, `file:` and relative references are refused. **The platform never fetches an external URI in Phase 1**: no unfurling, title import, preview or reachability check, so there is no server-side request forgery surface to defend.

### Rich content

25. **The canonical form is a versioned structured document** (Q16). A Card stores `content_format`, `content_version` and `content_document`. Phase 1 has one format, `prosemirror` (the document shape Tiptap edits), at `content_version = 1`, which names the **Resources document profile v1** below. `content_document` is that document's canonical JSON text. HTML is never canonical and is never stored.
26. **Resources document profile v1** — an allowlist, and nothing outside it:
    - **Blocks:** `paragraph`; `heading` with `level` 2, 3 or 4 (the Card title is the page's heading); `bulletList`, `orderedList` (`start`, a positive integer), `listItem` (a paragraph, then blocks); `blockquote`; `horizontalRule`; `codeBlock` (plain text, no language or highlighting); `table`, `tableRow`, `tableHeader`, `tableCell` (`colspan` and `rowspan` 1–20; no nested tables).
    - **Inline:** `text` and `hardBreak`.
    - **Marks:** `bold`, `italic`, `underline`, `code`, and `link` with an `href` that passes decision 24.
    - **Not in v1:** images, embeds, iframes, raw HTML, styles, classes, colours, fonts, alignment, column widths, task lists, mentions, strike-through, headings at level 1, 5 or 6.
    - **Limits:** at most 256 KiB of canonical JSON and nesting depth 24. Text contains no control characters other than tab, and a line feed only inside a code block.
    - Attributes the configured editor always emits at a fixed default (a link's `target`, `rel` and `class`; a cell's `colwidth`; similar) are accepted *only* at that default and are not stored, because render decides them (decision 29). Any other attribute, node or mark is refused.
27. **Server-side validation is the authoritative sanitization** (Q18). The server parses every submitted document into a Domain value against the profile, and refuses anything outside it with `422 invalid_content`, naming the offending path. It does not silently strip: the Console's editor is configured to the same profile, so an out-of-profile document is either a defect or an attack, and quietly discarding part of what a Guardian wrote would hide both. What is stored is the server's re-serialisation of the validated value, never the request's bytes. Client-side behaviour is never the boundary.
28. **Tiptap is a Console dependency and nothing more** (Q17). The server never depends on Tiptap, ProseMirror or a JavaScript runtime; it knows the profile. The Console's editor is Tiptap 3, configured to the profile: the starter kit without strike-through, headings 2–4 only, links restricted to the profile's schemes, tables without column resizing (resizing writes inline styles the CSP refuses) and with cell content that excludes tables, and **`injectCSS: false`** with the editor's styles shipped in the Console's own stylesheet (an injected `<style>` element is refused by `style-src 'self'`). A **fixture corpus** of documents produced by the real editor is checked by both test suites: every fixture validates on the server and renders in the Console. A document the editor can produce that the server refuses is a defect the tests find, not a production surprise.
29. **The Console renders the document, never HTML** (Q18). Content is rendered from the validated document to React elements by `@tiptap/static-renderer`'s React renderer with the same extension set. No HTML string is produced or interpreted in the browser, so the existing `dangerouslySetInnerHTML` guardrail stays absolute and no client sanitizer (DOMPurify) is needed. Rendering re-checks every link's scheme as defence in depth; `http` and `https` links open in a new browsing context with `rel="noopener noreferrer"`.
30. **HTML sanitization has a named tool and no Phase 1 caller.** Phase 1 has no HTML ingress (the browser parses pasted HTML into the profile before anything is sent) and no HTML egress (the Console renders the document). The platform's server-side HTML sanitizer is **`symfony/html-sanitizer` on the 7.4 LTS line** (measured above), configured as an allowlist mirroring the profile. It is added by the first package that either accepts HTML on the server or emits HTML for another client; the WordPress companion (G7) is the expected first, and there the server will generate HTML from the validated document and pass it through the sanitizer as the output boundary. It is not added before then (charter rule 17).
31. **Source and HTML editing are deferred** (Q19). When they come, HTML source is an *input format*: it is parsed against the profile into a document (whatever falls outside is refused or dropped visibly, never kept), and the document is validated by the server as in decision 27. Raw HTML never becomes canonical. Richer layouts, including AI-designed Cards, arrive as new profile node types in a new profile version, never as permitted HTML; scripts, event attributes, `style`, dangerous URL schemes and unrestricted frames are never allowed. The basic WYSIWYG editor does not wait for any of this.
32. **Profile versions.** The validator accepts every known version. An additive change (a new node) bumps the version and leaves existing documents valid as they are. A non-additive change requires an explicit migration that rewrites stored documents. The Console renders every known version.
33. **Discussions are not changed.** Adopting the editor for Discussion messages later uses a separate, narrower profile under its own format and version, decided by its own gate.

### Summaries

34. **A Card has `summary_mode` (`derived` or `custom`, default `derived`) and a stored `summary_text`** (Q20).
35. **Derivation**, whenever a `derived` Card's content changes: take the text of the validated document in order, separating blocks, list items, table cells and hard breaks by a space; collapse all whitespace to single spaces and trim; if the result exceeds the configured length (`resources.summary.derived_length`, default 200 characters), cut it there, back off to the last space if one lies in the second half, and append an ellipsis. An empty document derives an empty summary. Markup never reaches the summary, because it is built from text nodes, not from rendered output.
36. **A custom summary** is plain text, single-line, at most 300 characters, with no control characters. Writing one sets `summary_mode = custom`, and later content changes never overwrite it. Returning to automatic sets `derived` and recomputes at once. A change to the configured length takes effect for each Card the next time its derived summary is recomputed; nothing rewrites summaries in bulk.
37. **Summaries are what navigation, the library and search show**: a Card label's help text, a Pack's description in the library, search matching (decision 48), and later projections.

### Audiences

38. **The audience catalog is code-owned**, a `Resources\Domain\Audience` enum with two members in Phase 1, **`guardian`** and **`member`** (Q21), stored as those strings. A write naming an unknown audience is refused; a stored key the catalog no longer knows matches no viewer (fail closed).
39. **A Pack targets a set of audiences, combined by OR** (Q22). A Person who satisfies any of them qualifies. There are no negative ACLs, deny rules, exception lists, precedence or per-Person sharing. A Published Pack has at least one audience (decision 14).
40. **A Card inherits or narrows** (Q23). `audience_mode = inherit` means the Pack's set, read at projection time, so it follows later changes to the Pack. `audience_mode = narrowed` means an explicit, non-empty set that must be a subset of the Pack's; it is fixed, and does not grow when the Pack's set grows. A Card can never be broader than its Pack. At projection the effective set of a narrowed Card is its set intersected with the Pack's, so even a broken invariant could never widen it.
41. **The subset rule is held on every write**, under the Pack's lock. Narrowing a Card to anything that is not a non-empty subset of the Pack's set is refused (`422 card_audience_not_subset`). Changing the Pack's set so that some narrowed Card is no longer a subset is refused with `409 card_audience_conflict`, naming those Cards; the editor changes them first. Nothing is adjusted automatically, because an automatic intersection could leave a Card visible to no one without anyone having decided that.
42. **A surface decides which audiences it serves, and an audience's owner decides who is in it** (Q25, Q27). The projection (decision 45) takes a set of audiences. Each delivery surface computes that set for its viewer:
    - **The Guardian Console** serves the `guardian` audience, to an Actor holding `resources.view`, and only that audience. There is no Guardian business relationship, so Guardian eligibility is, for now, Access's answer, asked as a capability ([ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md), decision 7). If a Guardian relationship is designed later, this surface asks it instead; stored audience keys do not change.
    - **`member`** eligibility belongs to Membership: an active grant now, derived at query time ([ADR 0028](0028-membership-grants-derived-at-query-time.md)), asked through `Membership\Application` (not through Access: it is a relationship question, not a permission). No Phase 1 surface delivers Member content, so nothing asks it in Phase 1, and no Membership port is built speculatively (the rule [member access](../architecture/member-access.md) already states for Member capabilities). In Phase 1 `member` is targetable, validated, filterable and previewable.
    - **A community surface** (the WordPress companion, G7) will compute a Person's set as the union of each owner's answer, restricted to the audiences that surface serves.
43. **Platform capabilities are not the community audience system.** `resources.view` and `resources.manage` are software permissions. No role or capability ever satisfies `member`, and nothing a community surface delivers is decided by holding `resources.view`.
44. **Volunteer is the first audience to add after the foundation** (Q26), and the seam is small: one `volunteer` enum member and one eligibility source, asked through the Volunteering domain's `Application` layer. No schema, projection or API shape changes. It is not added until that domain exists and can say who is a Volunteer now: Resources never stores a Volunteer fact or a stand-in for one, and a Volunteer is never treated as a Member ([ADR 0036](0036-business-relationships-are-independent-and-not-access-roles.md)). `Membership\Application` becomes a dependency of Resources at the same time as the first surface that delivers to Members.

### Projection and non-disclosure

45. **One projection rule**, in `Resources\Application`, is used by the library, a Pack, search, file download and preview alike. For a set of audiences *A*: a **Card is visible** when its Pack is Published, the Card is Published, and its effective audience set (decision 40) intersects *A*. A **Pack is visible** when it has at least one visible Card. A **Category is listed** when it has at least one visible Pack.
46. **Non-disclosure is complete** (Q24). An invisible Card is absent: not its id, title, summary, Type, position, navigation slot, or a count that includes it. Visible Cards are numbered `1..n` among themselves; stored positions never leave management. If five Cards exist and a viewer may see four, that viewer receives an ordinary four-Card Pack. A Pack with zero visible Cards for the viewer is omitted. A Pack that does not exist, is a Draft, or is not visible answers the same `404 resource_pack_not_found` on every delivery route.
47. **One Card or several is decided by the projection, not stored** (Q10). One visible Card is presented simply, with no browsing chrome; more than one is a navigable Pack whose navigation is the Cards' titles as labels, with their summaries as help text. The same Pack can be one-Card for one viewer and multi-Card for another.
48. **Consumer search and filtering** (Q29). Search is a case-insensitive "contains" match on a Pack's title and summary and on the titles and summaries of its *visible* Cards. It is computed over the projection in PHP with Unicode lower-casing, so it behaves identically on both engines and can never match an invisible Card. It returns Packs, in library order, never loose Cards, and never searches Card content. Category is the first-class consumer filter; audience is not a consumer filter, since a consumer simply receives their own projection. Management may filter by Category, audience, publication state and Card Type.
49. **Preview** (Q30) is a `resources.manage` operation on one Pack and one audience. It returns the delivery shape that audience would receive **if the Pack were Published as it stands**: the Pack's own publication is set aside, and everything else (Card publication, audiences, narrowing, non-disclosure) is the same projection as delivery. The response also states whether the Pack is currently Published and whether the audience is on it; a Pack not visible to that audience is reported as such rather than as a 404, because the caller may see it. Rendering a single Draft Card is the editor's own preview and needs no projection. Preview is an editor capability, not a lifecycle state and not an audience.
50. **Delivery carries no management or provenance data**: no state, revision, audience mode or audience list, stored position, or Person id or name.

### Capabilities and recent verification

51. **Two capabilities** (Q31), added by the package that first checks them (ADR 0017):
    - **`resources.view`**: may read the Guardian Console resource library (the `guardian` projection) and download the files of Cards visible in it. Changes nothing.
    - **`resources.manage`**: may read and change everything in management: Categories, Packs, Cards, content, audiences, publication, ordering, files, management downloads and preview, including Drafts.

    Both are granted to the **Guardian** role, as CRM's and Discussions' capabilities are: Guardians are peers, and organizational content is not owned by its author (decision 54). The Platform Administrator holds them by derivation. A role-catalog test pins that every role granting `resources.manage` also grants `resources.view`; the two are still checked independently. A narrower editorial role, if one is wanted later, is a role-catalog change that moves `resources.manage` out of Guardian.
52. **Routine management needs no recent verification.** The step-up exemption list becomes exactly three: `resources.manage` pinned to `Resources\Http`, beside CRM's and Discussions'. Creating, editing, ordering, targeting, publishing, previewing, deleting an empty Category and replacing a file are routine.
53. **Permanent deletion of a Card or a Pack needs `resources.manage` and recent verification** (Q6): the two `DELETE` routes carry `security.verified` after the capability, which the route-table test already accepts on an exempt capability. A Resources route-table test pins that exactly those two routes carry it. The Console asks for explicit confirmation first, and for a Pack it states that every Card in it and every file they own will be permanently deleted. There is no Trash, Restore or Archive. A successful permanent deletion is recorded as a security event (decision 55).
54. **Organizational content has no owner** (provenance). Anyone holding `resources.manage` may edit any Category, Pack or Card; there is no author-only rule, deliberately unlike Discussions. Provenance is recorded and never confers authority: `created_by_person_id`, `created_at`, `updated_by_person_id` and `updated_at` on Categories, Packs and Cards, and `uploaded_by_person_id` on assets, all Person ids with no foreign key (provenance, ADR 0021), resolved to display names by `FindPeople` in management responses only.
55. **Permanent deletion, and only permanent deletion, is audited** (Q6). Deleting a Pack or a Card is a privileged destructive action behind recent verification, and afterwards the thing deleted no longer exists to say anything about itself; so the action leaves a durable record of what happened and who did it. **Resources owns Resource business state; the audit seam records the privileged destructive action.** The record outlives its subject exactly as a role grant's does after the Account is gone, and it is not Resources' history.
    - **Events.** `resource.pack_deleted` and `resource.card_deleted` (the `area.thing_verb` pattern of `mfa.recovery_code_used`), defined in a Resources-owned event enum and recorded through `Audit\Application\RecordSecurityEvent` with outcome `success`, **inside the deletion's own transaction** (ADR 0019). An event therefore exists if and only if the deletion committed; if the transaction fails, neither the deletion nor the event happens, and if the event cannot be written, the deletion fails.
    - **What an event holds.** The acting Account (the session's Actor, as every operator event records it); no subject Person or Account, since the subject is not a person; no IP address or user agent, as role grants record none; and a flat context. For `resource.pack_deleted`: `pack_id`, `cards_deleted` and `files_deleted` (counts). For `resource.card_deleted`: `card_id`, `pack_id` and `card_type`. The occurrence time is the seam's own. A Pack deletion records one event; the Cards it removes are counted in it, not recorded one by one.
    - **What an event never holds**: a title or other snapshot (the `person.renamed` precedent: the record says what kind of thing changed, never the text); summaries; the rich-content document or any part of it; a URI; a filename, file contents, digest or any copy of an asset; audience or category data; or anything that could reconstruct or restore the deleted Resource. Ids are enough to correlate with backups and logs when an investigation needs to.
    - **Refusals record nothing.** A deletion refused for want of the capability, for want of recent verification, because the Pack or Card does not exist, or by a domain rule (`409 published_pack_requirement`) emits no event of either kind. Deleting something already gone is a `404`, not a second event.
    - **Nothing else in Resources is audited.** Creating, editing, ordering, targeting, publishing, unpublishing, previewing, replacing a file and deleting an empty Category record no security event, as CRM's and Discussions' routine work records none.
    - **The boundary change this needs.** Resources becomes the first module outside Identity and Access to call the seam. The WP1 package that implements it revises `MembershipTrustBoundariesTest`'s list of `RecordSecurityEvent` users deliberately, naming this ADR, and gives Resources its own boundary test pinning that it uses `Audit\Application` only (never `Audit\Domain` or `Audit\Infrastructure`) and only from the two deletion use cases.

### Concurrency

56. **Concurrent editors are expected, so authored fields use optimistic concurrency** (added question). Packs and Cards carry a `revision`. An edit of authored fields (title, summary, Series, Category, content, URI) must state the revision it was based on; the update is conditional on it and increments it, and a mismatch is `409 stale_revision` with the current state. This is the version token ADR 0035 (decision 23) said a multi-editor rule would bring. Publication, audiences, ordering and file replacement are separate operations with their own invariants under locks; they are idempotent or replace a whole set, and do not use the revision.
57. **Row locks guard the structural invariants**, with `SELECT ... FOR UPDATE` on both engines and one lock order: Categories (by id), then the Pack. The set of Categories is locked to create or reorder Categories; a Category's row to create, move or reorder Packs in it and to delete it; a Pack's row to publish or unpublish it or its Cards, change its or a Card's audiences, create, reorder or delete Cards, and replace a Card's file. Moving a Pack (a Category change in `UpdatePack`, including clearing it) locks both Categories in id order, then the Pack. Edits of authored text (titles, summaries, Series, content, URI) take no lock; the revision guards them. Decided by these locks: publish against delete, unpublishing the last Card against publishing the Pack, narrowing a Card against reducing the Pack's audiences, and reordering against creation (`409 order_mismatch`). Each is proved by a two-process race test on both engines, mutation-checked by removing the lock.

### Deletion

58. **Deleting a Card** (Q6, Q15), under its Pack's lock: refused if it would leave a Published Pack with no Published Card (decision 14); otherwise its audience rows, the Card and its asset row are deleted and `resource.card_deleted` is recorded, in one transaction, and its file is removed after commit.
59. **Deleting a Pack**, under its Category's lock (if any) and its own: its Cards' audience rows, its Cards, their asset rows, its audience rows and the Pack are deleted and `resource.pack_deleted` is recorded, in one transaction, then the files are removed after commit. A Published Pack may be deleted directly; confirmation and recent verification are the safeguard.
60. **Foreign keys within the module are `RESTRICT`, and deletion is an explicit use case** that removes children first, as everywhere else in the platform. There is no cascading delete: the use case must enumerate the assets anyway to remove their files.
61. **Files are removed after the database commits, never before.** Deleting a file first would leave a row pointing at nothing if the transaction then rolled back. A file whose removal fails afterwards (or one written for an upload whose transaction failed) is an orphan: referenced by no row, so never servable, because every download is resolved through a row. Failures are logged, and `resources:assets:prune` removes files that no row references and that are older than a grace period.

### Managed files

62. **One File Card owns exactly one asset** (Q14). No sharing or reuse across Cards, folders, reference counting, version history or media library. Replacing the file replaces the Card's asset; deleting the Card or its Pack deletes it.
63. **An asset** records its id, a storage key, the original filename (sanitised for display, at most 255 characters), the detected media type, the size in bytes, a SHA-256 digest, who uploaded it and when. The **storage key is derived from the asset's ULID alone**, with no user-supplied path component or extension. The disk is `resources`, private, rooted under `storage/app/private/resources`, which on the production host is inside `shared/storage` (ADR 0027) and outside the web root. The business model refers to the asset, never to a path.
64. **Uploads.** The maximum size is configured (`resources.assets.max_bytes`, default 20 MiB), and `security:production-check` fails when PHP's `upload_max_filesize` or `post_max_size` is below it (the development defaults, measured at 2M and 8M, must be raised for the development stack too). The media type is **detected from the content** (`fileinfo`), must be on the allowlist, and must agree with the filename's extension; the client's `Content-Type` is ignored. Phase 1 allowlist: PDF, PNG, JPEG, WebP, GIF, plain text, CSV, DOCX, XLSX and PPTX. Never: SVG, HTML, XML, JavaScript, executables or archives. The package that builds this measures what `fileinfo` reports for each allowed type, in development and on the production host, and pins it.
65. **Replacement**: the new file is written first; then, in one transaction under the Pack's lock, the new asset row is inserted, the Card points at it and the old asset row is deleted; the old file is removed after commit.
66. **Serving** (Q33). Files are served only by authorized Laravel routes, streamed: a management route (`resources.manage`, any Card, Draft included) and a delivery route (`resources.view`, and only for a Card visible in the `guardian` projection; otherwise the same `404` as decision 46). Responses carry the stored, allowlisted `Content-Type`, the platform's `nosniff` and CSP headers, `Cache-Control: private, no-store`, and `Content-Disposition: attachment` with an RFC 6266 encoded filename. `inline` is offered only for PDF and the raster image types, on request; the asset package proves inline PDF viewing works under the production CSP in real Chromium, and if it does not, PDF is download-only. A row whose file is missing (after a partial restore, say) answers `404 asset_unavailable`, and management shows it; it is never a 500.
67. **Malicious uploads** (Phase 1 posture). There is no scanning infrastructure on the host, and none is built. Uploads come only from authenticated holders of `resources.manage`; types are allowlisted by content; nothing scriptable is accepted; nothing is executed, transformed or placed in the web root; downloads default to `attachment` with `nosniff`. The upload use case has one inspection step before the asset row is written, where a scanner can be added when the hosting supports one.
68. **Asset files are data of record.** They live in `shared/storage` and must be in backups; the asset package updates the [backup and restore runbook](../runbooks/backup-and-restore.md) in the same change.

### Surfaces

69. **Phase 1 has one surface: the Guardian Console** (Q28), for management (`resources.manage`) and for a library of Guardian-directed Resources (`resources.view`). A Guardian who is also a Member, or later a Volunteer, does not receive community Resources there: the Console stays focused on Guardian work. Editors see what any audience would receive through preview.
70. **The community surface is later and singular.** The WordPress companion (G7) will offer one personalised Resources experience projecting the union of everything a Person is eligible for (Member, Volunteer, and later others), not separate "Member Resources" and "Volunteer Resources" products unless a later UX decision asks for them. `/my/` is not a Resources surface in Phase 1. WordPress integration is not part of Resources v1.

## Phase 1 contract

Recorded so implementation follows it. Names may be refined by the implementing package where nothing above depends on them; the decisions may not.

### Schema

`resource_categories`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `name` | `string(80)` | Trimmed, single line, no control characters |
| `name_canonical` | `string(80)` | Lower-cased, whitespace collapsed; `unique` |
| `position` | integer | Decision 9; index `(position, id)` |
| `created_at`, `updated_at` | `dateTime` | UTC |
| `created_by_person_id`, `updated_by_person_id` | `char(26)` | Provenance, no foreign key |

`resource_packs`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `category_id` | `char(26)`, nullable | Foreign key to `resource_categories.id`, `RESTRICT`; required while Published (domain) |
| `position` | integer | Within the Category; index `(category_id, position, id)` |
| `title` | `string(200)` | |
| `summary` | `string(300)`, nullable | Explicit text only (decision 15) |
| `is_series` | boolean | Default false |
| `state` | `string(16)` | `draft` or `published`; validated in the domain, no database enum |
| `revision` | integer | Decision 56 |
| provenance and timestamps | as for Categories | |

`resource_pack_audiences`: `pack_id` (`char(26)`, foreign key, `RESTRICT`) and `audience` (`string(32)`), composite primary key `(pack_id, audience)`, plus an index on `audience` for the management filter.

`resource_cards`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `pack_id` | `char(26)` | Foreign key to `resource_packs.id`, `RESTRICT`; index `(pack_id, position, id)` |
| `position` | integer | Within the Pack |
| `type` | `string(32)` | `basic`, `external_link`, `file`; fixed at creation |
| `title` | `string(200)` | |
| `summary_mode` | `string(16)` | `derived` or `custom` |
| `summary_text` | `string(300)` | Empty string when a derived summary is empty |
| `content_format` | `string(32)` | `prosemirror` |
| `content_version` | integer | `1` |
| `content_document` | `mediumText` | Canonical JSON text, at most 256 KiB (domain). Not `json()`, which diverges between engines (measured) |
| `external_uri` | `string(2048)`, nullable | Required for `external_link`, optional for `basic`, absent for `file` |
| `asset_id` | `char(26)`, nullable, `unique` | Foreign key to `resource_assets.id`, `RESTRICT`; required for `file` (added by the asset package's migration) |
| `audience_mode` | `string(16)` | `inherit` or `narrowed` |
| `state` | `string(16)` | `draft` or `published` |
| `revision` | integer | |
| provenance and timestamps | as for Categories | |

`resource_card_audiences`: `card_id` (`char(26)`, foreign key, `RESTRICT`) and `audience` (`string(32)`), composite primary key; rows exist only for a `narrowed` Card, and at least one must (domain).

`resource_assets` (the asset package)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID, primary | |
| `storage_key` | `string(64)`, `unique` | Derived from the id (decision 63) |
| `original_filename` | `string(255)` | Sanitised display name |
| `media_type` | `string(127)` | Detected and allowlisted |
| `byte_size` | `bigInteger` | |
| `sha256` | `char(64)` | |
| `uploaded_by_person_id` | `char(26)` | Provenance, no foreign key |
| `created_at` | `dateTime` | |

Every foreign key is within the module. There is no cross-module foreign key, so the migrations depend on no other module's.

### Portability (Q34)

No exception to [ADR 0005](0005-mariadb-with-postgresql-portability.md) or the portability scan is needed: ULID keys generated in code; UTC `dateTime` columns written from the domain; states, modes, Types and audiences as validated strings rather than database enums; the document as `mediumText` holding JSON text, never queried inside and never a database `json` type (measured to diverge); plain composite, unique and primary-key indexes (no partial or functional index, no generated column, no CHECK constraint); case-insensitive uniqueness through a canonical column; `SELECT ... FOR UPDATE` as the only lock; and search computed in PHP over the projection rather than by collation-dependent SQL. Every behaviour, including each race in decision 57, is tested on both engines.

### Application use cases

All take the `Actor`. Management use cases ask for `resources.manage`, delivery for `resources.view`, through `Access\Application\AuthorizeAction`. Names are illustrative.

| Area | Use cases |
| --- | --- |
| Categories | `ListCategories`, `CreateCategory`, `RenameCategory`, `DeleteCategory` (empty only), `ReorderCategories` |
| Packs | `PageManagedPacks` (filters: Category, audience, state, Card Type, title text), `GetManagedPack`, `CreatePack`, `UpdatePack` (authored fields and Category, with revision), `SetPackAudiences`, `PublishPack`, `UnpublishPack`, `ReorderPacks`, `DeletePack` (records `resource.pack_deleted`), `PreviewPack` |
| Cards | `GetManagedCard`, `CreateCard`, `UpdateCard` (with revision), `SetCardAudiences`, `PublishCard`, `UnpublishCard`, `ReorderCards`, `DeleteCard` (records `resource.card_deleted`) |
| Files (asset package) | `ReplaceCardFile`, `DownloadManagedFile`, `DownloadResourceFile` |
| Delivery | `BrowseResourceLibrary` (optional Category and search text; Categories in order, each with its visible Packs' titles, summaries, Series flag and visible Card counts), `GetResourcePack` (the Pack and its visible Cards with content) |

### HTTP surface

Under `/api/v1/admin`, behind `stateful`, `auth:web` and `can:console.access`, with one capability per route, described in `openapi/openapi.yaml`. Management lives under `/admin/resources`, delivery under `/admin/resource-library`, so a route-table test can pin each prefix to its capability.

| Method and path | Capability | Notes |
| --- | --- | --- |
| `GET /admin/resources/categories` | manage | |
| `POST /admin/resources/categories` | manage | |
| `PATCH /admin/resources/categories/{category}` | manage | |
| `DELETE /admin/resources/categories/{category}` | manage | Empty only; no recent verification |
| `PUT /admin/resources/categories/order` | manage | Full ordered id list |
| `PUT /admin/resources/categories/{category}/pack-order` | manage | Full ordered id list |
| `GET /admin/resources/packs?category=&audience=&state=&card_type=&q=&page=&per_page=` | manage | Existing paging convention |
| `POST /admin/resources/packs` | manage | |
| `GET /admin/resources/packs/{pack}` | manage | All Cards, Drafts included, with state, audiences and provenance |
| `PATCH /admin/resources/packs/{pack}` | manage | `revision` required |
| `PUT /admin/resources/packs/{pack}/audiences` | manage | |
| `POST /admin/resources/packs/{pack}/publish`, `/unpublish` | manage | Idempotent |
| `DELETE /admin/resources/packs/{pack}` | manage + **`security.verified`** | |
| `GET /admin/resources/packs/{pack}/preview?audience=` | manage | |
| `PUT /admin/resources/packs/{pack}/card-order` | manage | Full ordered id list |
| `POST /admin/resources/packs/{pack}/cards` | manage | JSON for `basic` and `external_link`; multipart for `file` (asset package) |
| `GET /admin/resources/packs/{pack}/cards/{card}` | manage | With content |
| `PATCH /admin/resources/packs/{pack}/cards/{card}` | manage | `revision` required |
| `PUT /admin/resources/packs/{pack}/cards/{card}/audiences` | manage | `mode` and, when narrowed, the set |
| `POST /admin/resources/packs/{pack}/cards/{card}/publish`, `/unpublish` | manage | Idempotent |
| `DELETE /admin/resources/packs/{pack}/cards/{card}` | manage + **`security.verified`** | |
| `POST`, `GET /admin/resources/packs/{pack}/cards/{card}/file` | manage | Replace; management download (asset package) |
| `GET /admin/resource-library?category=&q=` | view | |
| `GET /admin/resource-library/packs/{pack}` | view | |
| `GET /admin/resource-library/packs/{pack}/cards/{card}/file` | view | Asset package |

Refusals, in the order checked: no capability, `403`; for the two deletions, no recent verification, the existing step-up refusal; an unknown Category, Pack or Card, or a Card not in that Pack, `404` (on delivery, also every invisible one, decision 46); request shape, `422`; then the domain: `409 stale_revision`, `409 order_mismatch`, `409 pack_not_publishable`, `409 published_pack_requirement`, `409 card_audience_conflict`, `409 category_not_empty`, `409 card_limit_reached` (adding a 101st Card to a Pack), `422 card_audience_not_subset`, `422 invalid_content`, `422 invalid_uri`, and, for files, `422 file_type_not_allowed`, `413 file_too_large`, `404 asset_unavailable`.

### Deletion audit: what WP1 must prove

WP1 implements decision 55 and proves it, on both engines, with tests that fail when the property is broken:

- a successful permanent Pack deletion records exactly one `resource.pack_deleted`, with the acting Account and the context of decision 55, and no `resource.card_deleted` for the Cards it removed;
- a successful permanent Card deletion records exactly one `resource.card_deleted`;
- a deletion refused for want of `resources.manage`, for want of recent verification, for a missing Pack or Card, or by `409 published_pack_requirement` records no deletion event;
- a deletion whose transaction fails (a forced failure after the rows are deleted) leaves the rows in place and records no event, and a failure to write the event leaves the Pack or Card undeleted;
- no deleted content reaches the event: the context's keys are exactly those listed, and a Pack and Card seeded with unique marker words in their titles, summaries, content, URI and filename produce events in which none of those words appears;
- every other Resources mutation (create, edit, reorder, audiences, publish, unpublish, file replacement, Category deletion) records no security event;
- the boundary tests of decision 55: Resources uses `Audit\Application` only, and only from the two deletion use cases.

A delivered Pack is its id, title, summary, Series flag, Category (id and name), the visible Card count and its visible Cards in order, each with its id, visible index, title, summary, Type, content (`format`, `version`, `document`), and its link (`uri`) or file (name, media type, size, download path). Nothing else.

## Architecture and security review

| Risk | Answer |
| --- | --- |
| Ownership drift into Membership, Access or a future Volunteering domain | Decisions 2, 3, 42–44 and ADR 0036; module-boundary tests forbid other modules' `Domain`, `Infrastructure` and `Http` |
| An invisible Card leaking through a title, count, position, search hit or file URL | Decisions 45–48 and 66: one projection for every delivery path, response-shape tests, a search test that seeds a unique word in an invisible Card, and the file route resolved through the projection |
| A Card broader than its Pack | Decisions 40–41: subset held on every write under the Pack's lock, and intersected again at projection |
| Draft content disclosed | Decisions 22, 46, 49 and 50: delivery never reads Drafts, the same 404 for Draft and non-existent, preview and Draft files only under `resources.manage` |
| Stored XSS through rich content | Decisions 26–29: allowlisted document validated on the server, no HTML stored, no HTML interpreted in the browser, the existing guardrail, link schemes checked twice |
| Dangerous links | Decision 24: one URI rule, no executable schemes, no server-side fetching |
| Malicious or mis-typed uploads | Decisions 63–67: content-detected allowlisted types, nothing scriptable, private storage keyed by id, `nosniff`, `attachment` by default |
| Path traversal or guessable file URLs | Decision 63: keys derived from ULIDs only; every download resolved through an authorized row |
| Lost updates between editors | Decision 56 |
| Publish/delete and audience races | Decision 57, with two-process race tests on both engines |
| Destructive deletion without proof | Decision 53: capability, then recent verification, then confirmation; pinned by a route-table test |
| Untraceable destructive deletion, or the audit trail becoming a copy of deleted content | Decision 55: one event per successful deletion, in the same transaction, holding ids and counts only; tested by marker words |
| A role or capability standing in for a relationship | Decisions 42–43 and ADR 0036 |
| Editor broken by the production CSP | Decision 28: `injectCSS: false`, no resizable columns, proved in real Chromium under the production policy |
| Lost files on restore | Decisions 66 and 68 |
| CMS or LMS scope creep | Decisions 5, 16 and the deferred list |

## Rulings index (Q1–Q36 and the questions this gate added)

| Q | Ruling | Decisions |
| --- | --- | --- |
| Q1 Terminology | Resources, Category, Resource Pack ("Pack"), Card, Card Type; "Knowledge" retired | 1 |
| Q2 Pack lifecycle | `draft` ↔ `published`, reversible, idempotent; nothing else | 12 |
| Q3 Card lifecycle | `draft` ↔ `published`, independent of the Pack; unpublish is the archive | 22 |
| Q4 Pack publish validation | Title, Category, at least one audience, at least one Published Card; held while Published | 13, 14 |
| Q5 Card publish validation | Title, plus per Type: text content, valid URI, or asset | 23 |
| Q6 Permanent deletion | Card and Pack only; `resources.manage` + recent verification + confirmation; transactional rows, files after commit; a `resource.pack_deleted` or `resource.card_deleted` security event recorded in the same transaction, holding ids and counts, never content | 53, 55, 58–61 |
| Q7–Q9 Ordering | Explicit positions per sibling set; reorder by full list under the parent's lock; no unique index | 8–10 |
| Q10 One vs multi-Card | From the viewer's visible Card count, not stored | 47 |
| Q11 Series | A presentation flag only | 16 |
| Q12 Card Types | `basic`, `external_link`, `file`; fixed at creation; generic fallback always | 18–21 |
| Q13 URI validation | Resources' Domain `ExternalUri`; https/http (and mailto inside content); no fetching | 24 |
| Q14 Asset ownership | `resource_assets` inside Resources, one per File Card, behind a port over a private disk | 4, 62, 63 |
| Q15 Replacement and deletion | Write new, swap in a transaction, remove old after commit; prune orphans | 61, 65 |
| Q16 Canonical content | `prosemirror` document, profile v1, stored as canonical JSON text | 25, 26, 32 |
| Q17 Tiptap boundary | Console only, configured to the profile, contract-tested by a shared fixture corpus | 28 |
| Q18 Sanitization | Server-side profile validation is authoritative; render from the document; `symfony/html-sanitizer` 7.4 for the first HTML boundary | 27, 29, 30 |
| Q19 Source/HTML editing | Deferred; HTML is only ever an input format | 31 |
| Q20 Summaries | Card: derived/custom with stored text; Pack: explicit only | 15, 34–37 |
| Q21 Initial audiences | `guardian`, `member` | 38 |
| Q22 Pack audiences | Positive OR; no deny or per-Person | 39 |
| Q23 Card audiences | Inherit, or narrow to a fixed non-empty subset; never broader | 40, 41 |
| Q24 Non-disclosure | Invisible Cards absent entirely; Packs with none omitted; one 404 | 46 |
| Q25 Eligibility | Guardian: the Console's `resources.view` until a Guardian relationship exists; Member: Membership, asked by the first surface that delivers to Members | 42 |
| Q26 Volunteer seam | One enum member and one eligibility source, after the Volunteering domain exists | 44 |
| Q27 Relationship vs role | ADR 0036; capabilities never satisfy a community audience | 43 |
| Q28 Console scope | Guardian audience only, plus management and preview | 69 |
| Q29 Search and filter | Pack and visible-Card titles and summaries, over the projection; Category filter; returns Packs | 48 |
| Q30 Preview | The audience's projection as if the Pack were Published; manage only | 49 |
| Q31 Capabilities | `resources.view`, `resources.manage`, both to Guardian; manage implies view by role catalog | 51 |
| Q32 Draft disclosure | Never in delivery; same 404; Drafts and their files only under manage | 22, 46, 50, 66 |
| Q33 File download | Authorized, streamed, resolved through the projection or manage; `attachment` by default | 66 |
| Q34 Portability | No exceptions; `mediumText` not `json`; PHP-side search | Portability |
| Q35 Module boundaries | `Resources` owns all of it, assets included; depends on Access and Identity Application layers | 2–5 |
| Q36 Package sequence | WP1 backend foundation; WP2 rich-content editor foundation; WP3 managed files; WP4 management UI; WP5 library UI; WP6 end-to-end proof and demo closeout ([roadmap](../roadmap.md)) | — |
| Added: concurrent editors | `revision` and `409 stale_revision` on authored fields | 56 |
| Added: Card Type changes | Not in Phase 1; Type is fixed at creation | 19 |
| Added: who manages | All Guardians (`resources.manage` to the Guardian role) | 51 |
| Added: deletion record | A security event for each successful permanent deletion (action and actor only); nothing else in Resources is audited, and no deleted content is retained anywhere | 55 |
| Added: server fetching | None in Phase 1 | 24 |

## Package sequence (Q36)

Six packages after this gate. Scope and validation boundaries are in the [roadmap](../roadmap.md); the sequence is recorded there because it is status, not a decision. The shape, briefly: the backend foundation first, without files (WP1); then the Console's rich-content editor and renderer against WP1's validator, so the profile contract between the two stacks is proved early and on its own (WP2); then managed files, a distinct and host-sensitive security surface (WP3); then the management UI (WP4) and the library UI (WP5); then the end-to-end proof and demo closeout (WP6). Volunteer delivery is not in G5 (decision 44).

## Deferred, recorded so Phase 1 does not block them

None of these is designed here, and nothing is built for any of them.

| Possibility | What keeps it open |
| --- | --- |
| Volunteer audience and delivery | Decision 44: one enum member and one eligibility source once the Volunteering relationship exists; delivery with the community surface |
| Partner, Vendor and Artist audiences; group, class-enrollment, event-assignment and other contextual audiences | The audience set and the surface/owner split (decision 42): each is an enum member plus an owner's eligibility answer. Resources never owns classes, enrollment or events (which may partly live in Quiverly) |
| Per-Person sharing, deny rules, exceptions | Out by decision 39; would need its own gate |
| WordPress Card Type, metadata import, provider-specific Types and embeds (YouTube, Spotify, SoundCloud, Google Docs, Canva) | Decisions 20 and 21: Types extend the generic Card; embeds also need a deliberate CSP change (frames are refused today) |
| Thumbnail, icon, icon-and-label or overlay navigation; masonry and other presentation modes | Consumer presentation over the same projection; nothing persisted in Phase 1 |
| Courseware: classes, homework, projects, quizzes, forms, completion or progress | Decision 16 forbids reading Series as any of it; a later delivery-context audience is the seam |
| Advanced source/HTML editing and arbitrary layouts, including AI-designed Cards | Decision 31: new profile nodes, never raw HTML |
| Shared asset or media library, cross-Card reuse, asset versions, image handling in content | Decision 4: extraction gate when a second module needs files |
| Full-text search of Card content, a search index | Decision 48 |
| Changing a Card's Type | Decision 19 |
| Trash, Restore, soft deletion, or any retained copy or history of deleted or edited content | Deliberately absent: unpublishing is the reversible path, and decision 55's event records the deletion, not the content |
| Multi-Category membership, tags | Decision 6 |
| Discussions using the editor | Decision 33 |
| Generic SaaS or multi-tenant extraction | Not designed; boundaries above are kept clean only where cheap |

## Implementation notes (WP1, 2026-10-04)

Where building the backend made a call this ADR left open, or measured something it asserted. None changes a decision above; where one extends it, it says so.

**Shape.** The `Resources` module is `Domain` (`Category`, `Pack`, `Card`, `CardOutline`, the value objects `CardSummary`, `CardAudience`, `AudienceSet`, `Provenance`, `ExternalUri`, `ResourceText`, the closed enums, the one `ResourceProjection` rule, the three repository ports, and `Domain\Content`: `DocumentProfile`, `ContentDocument`, `SummaryDeriver`), `Application` (the 25 use cases, their DTO views, `ResourceViews`, the refusal exceptions, `ResourceEvent`), `Infrastructure` (query-builder repositories, no Eloquent) and `Http` (25 single-action controllers, `routes.php`, the presenter, `ResourcesProblems`). Migrations `2026_10_04_000001`-`000003` create the five tables WP1 owns exactly as the contract states, with the indexes it names, plus an index on `resource_packs.state`. `resource_assets` and `resource_cards.asset_id` wait for WP3; `CardType` has two cases and `file` adds a third without a schema change.

**The Member and Membership.** The work package text asked Resources to use Membership's seam for Member eligibility. ADR 0037 (decisions 2, 42 and 44) says the opposite for Phase 1, and it is the authority: no Phase 1 surface delivers to Members, so nothing asks Membership, and a preview takes an audience (`member`), not a Person. `Resources` therefore has **no `Membership` dependency**, pinned by an architecture test, and `Membership\Application` arrives with the first surface that delivers to Members. Being a Member was proved to grant nothing: an Account with an active Membership grant and no role holds no capability and is refused the library.

**Errors added to the contract.** The ADR names the refusals that matter; building it needed the rest. `category_not_found`, `pack_not_found`, `card_not_found` (404); `resource_pack_not_found` (the single delivery 404, decision 46); `unknown_category` (422, a body reference to a Category that does not exist); `duplicate_category` (409); `card_not_publishable` (409, `unmet` of `content` or `uri`; also what an edit that would leave a **Published** Card unpublishable answers, because a Published Card keeps meeting its Type's requirements for the same reason a Published Pack does); `invalid_resource_input` (422, the general domain refusal for titles, names and summaries, beside `invalid_content`, `invalid_uri` and `card_audience_not_subset`). `published_pack_requirement` carries `requirement` (`category`, `audience`, `published_card`), `pack_not_publishable` and `card_not_publishable` carry `unmet`, `card_audience_conflict` carries `cards`, and `stale_revision` carries `current`.

**Request and response conventions.** A reorder sends `{"ids": [...]}`. An authored edit sends `revision` (required, so there is no last-write-wins). Preview is `?audience=`, which must be a catalog audience. A Card's audiences are `{"mode": "inherit"|"narrowed", "audiences": [...]}`. A Pack is shown to management with its Cards' outlines (no content); a list carries counts only. The library is not paged. Every id parameter is constrained to the lowercase ULID form, so a malformed id is a plain 404.

**Concurrency, as built and as measured.**
- *Pack and Category row locks and lock order* are as decision 57. `Categories` take `lockAll()` (id order) to create or reorder, and `DeleteCategory`, `CreatePack` and `ReorderPacks` take the one Category's row. A Pack-structural operation takes the Pack's row. A Category change on a Pack takes both Categories in id order and then the Pack, and **re-reads the Pack under the lock** before deciding.
- *`lockAll()` locks and reads in two statements, deliberately.* Measured: on PostgreSQL a statement's snapshot is taken before it waits for a lock, so one `SELECT ... FOR UPDATE` missed a Category committed by the transaction it waited for, and a reorder of the old list passed where MariaDB's locking read refused it. A second, fresh read sees what committed while waiting, on both engines. The race test that exposed it passes on both.
- *An authored edit of a Card is conditional on the revision **and the state** it was validated in.* Measured: with the state left out, an edit checked against a Draft (so allowed to empty the content) could land after the Card was Published. `PublishCard` also takes the Card's own row after the Pack's, so an edit arriving in that window waits and is then judged as a Published Card's. A Pack's own authored edit needs only the revision condition, except a Category change, which is structural and locked.
- *The harness gap, and how it was closed.* `Race::against` pauses a use case after its write, and a write takes its own row lock, so a use case that forgot its explicit lock still passed. `Tests\Support\ResourcesPauses` pauses **between a read and the write made from it**, by decorating a repository, and six scenarios use it. Mutation-checked: removing the lock from `PublishPack`, `ReorderCards`, `CreateCard`, `CreateCategory`, `SetPackAudiences`, `SetCardAudiences`, `UnpublishCard` and the Card row lock in `PublishCard` each turns a scenario red. Removing `DeletePack`'s Pack lock turns its scenario red **on PostgreSQL only**: on MariaDB, InnoDB's foreign-key locking makes the lock redundant there. That is exactly why the suite runs on both. `DeletePack` also reads its Cards' ids with a locking, current read (`deleteAllOf`): its first read (finding the Pack, to know its Category) fixes a MariaDB REPEATABLE READ snapshot before it waits for the lock, so a plain read afterwards would miss a Card committed during the wait while the `DELETE` removed it, under-counting `cards_deleted` and leaving a narrowed Card's audience rows to trip the foreign key. Two race tests (a plain and a narrowed late Card) fail on MariaDB without it and pass on both engines with it; on PostgreSQL they also pass without it, because each statement takes a fresh snapshot.
- *The two-statement `CreateCategory` pre-check* for a duplicate name is a fast path: the unique index plus the caught `UniqueConstraintViolationException` give the same answer, which a race test pins.

**The request pipeline would have corrupted content.** Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull` rewrite every string in a request, so the space in `"Hello "` before a bold `"world"` is trimmed. They are switched off for `/api/v1/admin/resources/*` only, in `bootstrap/app.php`, with a test that sends significant whitespace through the real pipeline and a test that no other route inherits the exemption. The domain trims and judges titles, names and summaries itself. The price is that a blank string now reaches the request instead of becoming `null`, and Laravel skips the non-implicit rules (`ulid`, `array`, `Rule::enum`) for a blank, so the requests decide it themselves (`Http\BlankInput`, with the same `trim` test Laravel uses): a blank optional id or filter (`category_id`, `category`, `audience`, `state`, `card_type`, `q`) is absent, so `category_id: ""` clears a Category exactly as `null` does; a blank list (`ids`, `audiences`) becomes `null` and is refused `422` by its `array` rule, and a blank required value is refused `422` as before. None of it reaches a value-object constructor. PHP's default `trim` also strips NUL, which would quietly clean up a control character; `ResourceText` and `ExternalUri` trim ordinary whitespace only, and refuse the rest.

**The document profile, concretely.** Decision 26's "fixed default" attributes are, exactly: a link's `target` is `null` or `_blank`, its `rel` is `null`, `noopener noreferrer nofollow` or `noopener noreferrer`, its `class` is `null`; a `codeBlock`'s `language` is `null`; an `orderedList`'s `type` is `null` and its `start` is stored only when not 1; a cell's `colwidth` is `null` and `colspan`/`rowspan` are stored only when not 1. **WP2 must configure the editor to emit exactly these.** Marks are stored in the fixed order bold, italic, underline, code, link. A paragraph, list, blockquote, table or row refuses any attribute at all (the first implementation accepted and silently dropped them, contrary to decision 27; it was found by checking the shared fixtures against the validator, and is now a fixture). Text is never HTML-escaped or converted. The shared example documents are `apps/platform/tests/Fixtures/resource-content/` (14 valid, 42 invalid, with a README); the backend suite checks every file, and **sharing them with the Console's suite is WP2's decision**, because the Console's container mounts only `apps/guardian-console`.

**Summaries.** A derived summary is the plain text of the document (a space after each block, list item, table cell and hard break, whitespace collapsed), cut at `resources.summary.derived_length` (default 200, clamped to 20-299; `config/resources.php`, no environment variable). If the cut lands inside a word it backs off to the last space only when that space is in the second half of the allowance, so a long unbroken word is cut hard. A cut that lands exactly at a word's end keeps the word. The ellipsis is the single character `…`. `summary_mode: custom` with no text keeps the stored text as the custom one.

**Search** is Unicode-folded in PHP (`mb_stripos`) over the projection: Pack title and summary, and the title and summary of each **visible** Card. `%` and `_` are ordinary characters. Management filtering by title uses `lower(...) like ? escape '!'`, as CRM and Discussions do.

**Deletion and its audit, as built.** `DeletePack` and `DeleteCard` call `RecordSecurityEvent` inside their transaction and are the only Resources classes that do (decision 55; the test pins the two classes, not the module). `files_deleted` is `0` until WP3. The audit-seam allowance is narrow and in three places: the module graph (`Resources -> Access, Identity, Audit`), the list of classes allowed to use `RecordSecurityEvent`, and the architecture rule that Resources reaches Audit only through its Application layer. One more deliberate exemption was needed: the architecture scan that keeps role keys out of code outside Access names `app/Modules/Resources/Domain/Audience.php` and the one literal `guardian`, because an audience is not a role (ADR 0036) and the stored audience key is that word (decision 38).

**What the tests prove.** Mutation-checked, each turning a test red: the Card audience subset rule on write and again at projection; a hidden, unpublished, other-audience or Draft Card reaching delivery, counts, numbering or search; search reaching rich content or hidden Cards; a delivery refusal that distinguishes missing from Draft; the revision condition on both write paths; the whole-set reorder check (including a full set plus a repeat); `security.verified` on either permanent deletion, asked before the capability, or added to a routine mutation; the audit write moved outside the deletion's transaction; a title or summary added to an audit context; an ordinary mutation recording an event; and the whitespace exemption. Two mutations survive and are expected: removing `CreateCategory`'s duplicate pre-check (the unique index still refuses it) and, on MariaDB only, removing `DeletePack`'s Pack lock (see above).

**One change outside the module, to the test run.** The full backend suite exceeded the dev image's 512M CLI memory limit once Resources' tests joined it (measured peak 577 MB over 2,572 tests; the architecture tests died with "Allowed memory size exhausted" in a full run while passing in any smaller one), so `phpunit.xml` now sets `memory_limit` to 1G for the run ([testing](../development/testing.md)). The Guardian's capability list is pinned by several existing suites (role catalog, `/me`, sign-in and enrolment responses, People access control); each now lists `resources.manage` and `resources.view`.

**Not in WP1, so the next packages know:** the Tiptap editor, renderer and CSP proof (WP2, since built; see its notes below); `file` Cards, `resource_assets`, uploads, downloads, the production upload-limit check and the backup runbook change (WP3); every Console screen (WP4, WP5); a demo dataset and browser journeys (WP6); a Member or Volunteer delivery surface; `symfony/html-sanitizer`, which stays uninstalled until something accepts or emits HTML on the server.

## Implementation notes (WP2, 2026-10-04)

Where building the Console's rich-content foundation made a call this ADR left open, or measured something it asserted. None changes the document profile or any server behaviour; WP2 changed no backend code, only the shared fixture corpus (three files added) and its README.

**Shape.** Everything is in `apps/guardian-console/src/richtext/` and is imported by no screen yet, so the production bundle is unchanged until WP4 imports it (and WP4 should load the editor lazily: Tiptap and ProseMirror are about 225 kB gzipped, with React, in a measured test build). `contract.ts` is the profile in TypeScript; `profile.ts` is Tiptap configured to it; `editorDocument.ts` converts between the canonical document and Tiptap's; `RichTextEditor.tsx` and `EditorToolbar.tsx` are the editing surface; `RichContentRenderer.tsx` draws a document; `richtext.css` is their stylesheet. A source rule (`guardrails.test.ts`) keeps `@tiptap` and `prosemirror` inside this folder, so no screen can call an editor command or accept content the profile has not checked.

**Packages (Tiptap 3.31.4, all MIT, all local).** `@tiptap/core`, `@tiptap/pm`, `@tiptap/react`, `@tiptap/starter-kit` and `@tiptap/extension-table`, plus `@tiptap/extension-link`, `-code-block` and `-ordered-list` as direct dependencies because the profile extends them (they were already in the lockfile through the starter kit). The lockfile gains 52 entries, all Tiptap, ProseMirror, `linkifyjs`, `@floating-ui/*` (pulled in by `@tiptap/react`'s menus, which are not used), `fast-equals`, `orderedmap`, `rope-sequence`, `use-sync-external-store`, `w3c-keyname`. No collaboration, comments, AI, cloud, realtime or Yjs package, no analytics, no network call.

**The renderer does not use `@tiptap/static-renderer` (a refinement of decision 29's mechanism, not of its property).** Decision 29's property is that no HTML string is produced or interpreted in the browser, and that holds. Its named mechanism was the static renderer's React output with the editor's extension set. WP2 renders instead with a closed `switch` over the validated document, straight to React elements. Reasons: it needs no further package; it is exactly the allowlist the contract tests exercise, where the static renderer draws whatever its extensions' specs say (and the extension spec for a table is what wrote `style` attributes, below); and an unknown node or mark cannot reach it because the document is parsed against the profile first and withheld whole if it does not parse. A link's address is re-checked as decision 29 requires. A document that is out of the profile is not drawn: the reader sees one line ("This content can't be displayed."), never the document, and the rest of the page is untouched. The editor refuses to open such a document in the same way.

**The contract layer.** `canonicalDocument` is the server's `DocumentProfile` and `ExternalUri` in TypeScript, with the same refusal paths and the same canonical form (defaults dropped, marks in the fixed order). It is not an authority; it is how the editor's output is held to what the server stores, and how the renderer checks what it is given. The server remains the only validator that matters.

**Sharing the corpus.** One home, the platform's `tests/Fixtures/resource-content/`. The Console's suite reads it by the relative path `../platform/tests/Fixtures/resource-content` (`src/test/resourceFixtures.ts`), and the Console's container gets the directory as a read-only compose mount at the same relative path (`compose.yaml`, `guardian` service), so there is no second copy and nothing to keep in step. A missing directory fails the suite. Nothing in a production build reads the fixtures. Against the corpus (17 valid, 42 invalid) the Console proves: the TypeScript profile gives the stored form for every valid document and refuses every invalid one at the path the server names; the real editor, configured to the profile, round-trips every valid document; the renderer draws every valid one with all its text and withholds every invalid one; the component opens every valid one without a change event. The three `editor-*` fixtures are **produced by the real editor** (`editorFixtures.test.ts` holds their recipes, regenerates them on request, and fails if the editor stops producing what is committed), so the server validates exactly what the editor emits. Each of the 17 valid and 42 invalid files was also run through the real PHP `DocumentProfile` and `ContentDocument` (accepted, stored form and derived text equal to the file, stable on a second pass; refused at the named path).

**Where Tiptap and the contract did not agree, and what the frontend did about it.** Each was found by a test and proved with a minimal case; none needed a backend change.
- *Tiptap 3's link carries a `title` attribute* (emitted as `null`), which the profile does not have and the server refuses. The profile's link does not define it.
- *A table writes inline styles even when it is not resizable.* Tiptap's table renders `style="min-width: ..."` on the table and on each `<col>` through `setAttribute('style', ...)`, from its `renderHTML` and again from its NodeView (`View`), which the production CSP blocks. Decision 28 named resizing; the table itself is the larger source. The profile's table has `resizable: false`, `View: null` and a `renderHTML` of a plain `<table><tbody>`. A real-browser run with the production header recorded no violation; the same page with `injectCSS: true` recorded `style-src-elem blocked inline`.
- *`insertTable` inside a table cell never returns* (it tries to fit a table where the schema allows none). The profile's command refuses when the selection is already in a table, and the toolbar shows the button as unavailable.
- *Pasted markup can set attributes the server refuses:* a link's `target`, `rel` and `class`; an ordered list's `type` and a `start` of 0 or beyond the bound; a code block's language class; a cell's `colspan` beyond 20, `rowspan` and `data-colwidth`. The profile pins each to its fixed default or clamps it to the server's bounds, whatever was pasted. A link whose address the server's rule refuses is not made a link, whether typed, pasted or parsed from pasted HTML.
- *Characters:* the server refuses control characters, line and paragraph separators and lone surrogates, and a line feed outside code. Pasted content is cleaned of them (`cleanPastedSlice`) rather than left to fail on save.
- *The empty document.* An editor always holds a block, so `{"type":"doc","content":[]}` is shown as one empty paragraph, and an editor holding only one empty paragraph is emitted as the empty document. This is the one deliberate normalisation of the round trip; both stored forms (`empty-document` and `empty-paragraph`) come back as the empty document.
- *Tiptap's own extras are left out:* `trailingNode` (appends an empty paragraph nobody wrote) and `dropcursor` (positions its indicator with inline styles). Strike-through, `UndoRedo` aside, is not in the profile.

**The editor emits canonical documents and stays quiet until something changes.** `onChange` receives `canonicalDocument(editor JSON)`, which is what the server will store, after an edit and only then: opening a document, replacing `value`, or a parent handing back what it was just sent calls nothing. Content the profile refuses (more than 256 KiB, nested deeper than 24) is reported through `onRefusal` and not passed to `onChange`; the parent must not save it.

**The CSP choices.** `injectCSS: false` on the editor, with the rules Tiptap would have injected in `richtext.css` (white-space handling, the gap cursor, selected nodes and cells), in theme roles only; no table resizing; no table NodeView; no drop cursor; a source rule against `injectCSS` other than `false`, `resizable` other than `false`, and `injectNonce`. The policy was not changed. The component test fails on any `<style>` element or `style` attribute written while the editor is used (it also fails with `injectCSS: true`, as the guardrail does independently). A scratch run in the pre-installed Chromium (not committed, so not part of the suite) served a build of the editor and renderer with the production header from the Caddyfile, typed, formatted, linked, listed and edited a table, and recorded no `securitypolicyviolation`; the committed browser journey is WP6's.

**Accessibility.** The editing surface is a named multi-line text box (`label`, or `labelledBy`), with `describedBy` and `aria-invalid` for the parent's validation state, `aria-readonly` and a tab stop when read-only (no toolbar), `aria-disabled` and no tab stop when disabled. The toolbar is an ARIA toolbar named "Formatting": one tab stop, Left, Right, Home and End move within it, every button has a name, toggles expose `aria-pressed`, a command that cannot run is `aria-disabled` and stays in the order (so focus is never lost to a button that just disabled itself), and a command returns focus to the editor. The link panel ("Edit link") takes focus, applies on Enter without submitting a form the editor sits in, cancels on Escape without closing a dialog around it, and says in words what is wrong with an address. Table commands appear only inside a table. axe passes on the editable, in-table, link-panel-with-error, invalid, read-only and disabled states and on the rendered content (colour contrast cannot be judged in jsdom; it is for the browser journey in WP6). A few buttons show short text ("H2", "B") over a fuller accessible name ("Heading 2", "Bold").

**Paste.** Tiptap's schema turns pasted HTML into the configured nodes and marks and keeps the text of what it drops (a struck-through word stays, without the strike; an image, frame or script goes); the profile pins the attributes (above). Pasted plain text becomes paragraphs. No importer was built.

**The Discussions seam.** `createEditorProfile(name, features)` builds the extension set from a list of features, so a narrower editor is a shorter list and has a smaller schema (tested). `RichTextEditor` takes the profile as a prop. Nothing else is generalised, and Discussions is unchanged.

**Mutation-checked** (each turned a test red, and was reverted): an unsupported attribute accepted by the contract layer; `injectCSS: true`; table resizing enabled; the table's NodeView restored; a wrong fixed default (a code block's `language`); the link `title` attribute restored; the renderer writing HTML; the link `rel` removed; the link address check removed from the editor; a table insertable inside a table.

**Not in WP2:** HTML or source editing, Markdown, images, embeds, text colour or size, autolinking while typing, a placeholder, autosave, every Resources screen, and any Playwright journey (WP4 and WP6). Test counts are in the roadmap.

## Consequences

- Guardians get one place to author and find organizational knowledge, with no new identity, authorization machinery or infrastructure beyond a private disk inside storage the host already shares across releases.
- Member-targeted content can be authored, targeted and previewed in Phase 1 but is delivered nowhere until the community surface exists; a Pack targeted only at Members appears in no Guardian's library. That is the intended consequence of keeping the Console Guardian-focused.
- The step-up exemption list grows to three, still each pinned to its own module, and Resources is the first exempt module with routes that still require the proof.
- Rich content costs the Console a sizeable dependency (Tiptap and ProseMirror); the editor should be loaded only where it is used, and its output stays the platform's own documented format rather than HTML.
- Phase 1 records who permanently deleted which Pack or Card, and when, in `security_events`, and never what it contained: once deleted, a Resource's text and files are gone from Commons except in backups. The audit seam gains its first caller outside Identity and Access, for exactly two event types, and the architecture tests that pin who may call it are revised deliberately to say so.
- Files become data of record: backups, restores and host disk quota now cover them, and the host's PHP upload limits become a production-check item.
- Because summaries are stored, a change to the derived length applies gradually, Card by Card, as content changes.
- Organizational content has no owner: any Guardian can change or unpublish another's work. That is the product decision; revision tokens and provenance make it visible and safe, not restricted.

## Alternatives considered

- **HTML as the canonical content, sanitized on save.** Rejected by the product owner and here: a sanitizer's output is still HTML whose meaning depends on every future renderer, and the Console would have to inject it, against an existing guardrail. A validated document is smaller, versionable and renders without HTML.
- **Silently strip what is outside the profile.** Rejected (decision 27): it would hide editor defects and attacks alike and quietly discard what a Guardian wrote.
- **Store a generated HTML copy beside the document now.** Rejected: nothing in Phase 1 reads HTML, and a stored copy is a second representation to keep in step. It is generated when a consumer needs it (decision 30).
- **`ezyang/htmlpurifier` as the sanitizer.** Rejected for the future HTML boundary: LGPL, and an HTML 4-era model; Symfony's sanitizer is MIT, maintained on the same LTS line as the framework's Symfony components, and resolves cleanly (measured).
- **`ueberdosis/tiptap-php` to validate or render on the server.** Rejected: it pulls a Node-backed highlighter the production host cannot run, and it converts rather than validates against an allowlist.
- **A database `json` column.** Rejected: measured to be `longtext` on MariaDB and `json` on PostgreSQL, with different semantics, for a value never queried inside.
- **Assets in a `Files` module or in `Shared` now.** Rejected (decision 4): one consumer, and `Shared` must not become a media library.
- **Cascading foreign keys.** Rejected (decision 60): the use case must enumerate assets anyway, and the platform deletes explicitly.
- **A unique index on positions.** Rejected (decision 9).
- **Implicit inheritance (no rows means inherit).** Rejected: deleting the last narrowing row would silently broaden a Card to its whole Pack. The mode is explicit.
- **Automatically intersect narrowed Cards when the Pack's audiences shrink.** Rejected (decision 41).
- **Locked placeholder slots for invisible Cards.** Rejected by the product owner (non-disclosure).
- **Search in SQL with `lower(...) like`.** Rejected for delivery: it would have to re-express the projection in SQL to avoid matching invisible Cards, and collation would make the engines differ; Phase 1 volumes are small enough to search the projection in PHP. Management filtering may use SQL, since it discloses nothing.
- **Asking Access whether someone is a Member** (through ADR 0028's reserved port). Rejected: membership of an audience is a relationship question for Membership, not a permission (ADR 0036). ADR 0028's port remains the route for a *capability* derived from membership.
- **Treating `console.access` as the Guardian audience.** Rejected: it means "may use the Console", which an operator-only role could hold without being a Guardian; `resources.view` is the capability that defines this surface.
- **Last-write-wins for Card edits**, as Discussions does. Rejected (decision 56): Discussions edits are self-concurrent; Resources edits are deliberately multi-editor.
- **Owner-only editing, as Discussions does.** Rejected by the product owner: Resources are organizational content.
- **No record of permanent deletion.** The original ruling of this gate; reversed before implementation. The action is privileged and irreversible, and without a record nobody could later say who removed a Pack or when.
- **A Resources-owned deletion log.** Rejected: it would be a second audit mechanism beside ADR 0019's, and a business table of deleted things invites keeping more of them. The seam already records privileged actions durably and outlives its subjects.
- **A title snapshot in the event.** Rejected: the platform's precedent (`person.renamed`) records that something changed, not the text; a title is authored content, and ids correlate with backups when needed.
- **Soft deletion so the audit can point at a row.** Rejected: deletion is permanent by product decision, and the audit records the action, so it needs no surviving row.
- **Audit every Resources mutation.** Rejected: routine content work grants and removes no authority, and CRM and Discussions record none of theirs.
- **One backend package including files.** Rejected for the sequence: uploads and file serving are a separate security surface with host-sensitive limits, and are additive to the Card model.
