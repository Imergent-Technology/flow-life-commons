# Flow Life Commons Product Roadmap

This document tracks product sequencing and implementation status. ADRs and architecture documents remain authoritative for technical and product decisions; where this page and one of them disagree about a decision, they win and this page is wrong.

**Status words:** *Complete* · *Active* (being built now) · *Next* · *Planned* · *Deferred* (planned, but its sequencing or ownership is under review) · *Parked* (built or started, deliberately not being extended) · *Operational* (code exists, an operator task remains) · *Horizon* (an idea, not roadmap work). No dates, no percentages.

## Where things are

**Production runs release v0.3.0.** `main` is ahead of it: not every commit is released or deployed ([release vs deployment](runbooks/deployment.md#release-is-not-deployment)).

| Area | Status | In production (v0.3.0)? |
| --- | --- | --- |
| Identity and Account lifecycle (invitations, password reset and change, sessions) | Complete | Yes |
| Access: code-owned capabilities and roles, operator administration | Complete | Yes |
| Multi-factor authentication: enrollment, challenge, recovery, enforced for the Console ([ADR 0023](adr/0023-multi-factor-authentication.md)) | Complete | Yes |
| Membership: time-bounded grants, operator API and Console administration ([ADR 0028](adr/0028-membership-grants-derived-at-query-time.md)) | Complete | Yes |
| Guardian Console shell and visual foundation ([ADR 0030](adr/0030-guardian-console-visual-system.md)) | Complete | Yes |
| Browser security policy; release and deployment tooling ([ADR 0026](adr/0026-production-browser-security-policy.md), [ADR 0027](adr/0027-release-and-deployment-model.md)) | Complete | Yes |
| Member foundation, work packages 1–5: existing-Person invitation, `/my/` self-service shell, neutral credential presentation, end-to-end proof ([member access](architecture/member-access.md)) | Complete; further expansion Parked | No, on `main` only |
| Production transactional mail: authenticated-SMTP code foundation ([ADR 0031](adr/0031-production-transactional-mail-uses-authenticated-smtp.md)) | Complete (code) | No, on `main` only |
| CRM / People architecture, WP0 ([ADR 0034](adr/0034-crm-enriches-identity-person.md)) | Complete | Docs only |
| Identity People search and rename, WP1 | Complete | No, on `main` only. An Application seam with no route: nothing user-visible yet |
| CRM backend, WP2: the People directory API, contact methods, tags, and the Guardian's `crm.people.view`/`manage` access | Complete | No, on `main` only. Its Console screens are WP4 (People, contact methods) and WP5 (tags) |
| CRM notes and interactions backend, WP3: record, list, correct and remove a Person's notes | Complete | No, on `main` only. Its Console screen is WP5 |
| Guardian People UI, WP4: the People directory, a Person's record, adding a person, editing their profile and managing their contact methods | Complete | No, on `main` only |
| Guardian notes and tag UI, WP5: a Person's notes and interactions (record, correct, remove), their tags, and the Tags page for the list of tags | Complete | No, on `main` only |
| CRM end-to-end proof and demo closeout, WP6: a repeatable demo dataset and the browser coverage of the whole Phase 1 workflow | Complete | No, on `main` only. Closes G1 |
| Guardian Discussions architecture, G2 WP0 ([ADR 0035](adr/0035-guardian-discussions-are-durable-asynchronous-threads.md)) | Complete | Docs only |
| Guardian Discussions backend, G2 WP1: the `Discussions` module, its tables, the `discussions.view`/`participate` capabilities and the `/admin/discussions` API | Complete | No, on `main` only |
| Guardian Discussions UI, G2 WP2: the discussion list, starting a discussion, and a thread with replies, edit and remove of one's own messages, retitle, resolve and reopen | Complete | No, on `main` only |
| Guardian Discussions end-to-end proof and demo closeout, G2 WP3: a repeatable demo dataset and the browser coverage of the whole Phase 1 workflow | Complete | No, on `main` only. Closes G2 |
| Resources architecture, G5 WP0 ([ADR 0037](adr/0037-resources-are-audience-targeted-packs-of-cards.md)), and the business-relationship principle it rests on ([ADR 0036](adr/0036-business-relationships-are-independent-and-not-access-roles.md)) | Complete | Docs only |
| Resources backend, G5 WP1: the `Resources` module, its five tables, the `resources.view`/`manage` capabilities and the `/admin/resources` and `/admin/resource-library` API | Complete | No, on `main` only |

## Earlier milestone: G1 — CRM / People (Complete)

Make Commons genuinely useful to Guardians as a people and contact system. The boundary and owner decisions are in [ADR 0034](adr/0034-crm-enriches-identity-person.md); the module's design is not repeated here.

| Package | Scope | Status |
| --- | --- | --- |
| WP0 | CRM / Person architecture | Complete |
| WP1 | Identity People search and rename | Complete |
| WP2 | CRM profiles, contact methods and tags (introduces the CRM capabilities) | Complete |
| WP3 | Notes and interactions backend | Complete |
| WP4 | Guardian People UI | Complete |
| WP5 | Notes timeline and tag-management UI | Complete |
| WP6 | End-to-end proof and demo closeout | Complete |

## Most recent milestone: G2 — Guardian Discussions (Complete)

A durable place for Guardians' asynchronous coordination: a topic, an opening message and flat chronological replies, Open or Resolved, with authorship that never changes, author-only editing that is visibly marked, and removal that leaves a tombstone. Not chat, and not a generic comment framework. The decisions and the Phase 1 contract (schema, capabilities, routes, ordering) are [ADR 0035](adr/0035-guardian-discussions-are-durable-asynchronous-threads.md) and are not repeated here.

| Package | Scope | Validation boundary | Status |
| --- | --- | --- | --- |
| WP0 | Product and architecture design gate | ADR 0035 accepted | Complete |
| WP1 | Discussions backend: the `Discussions` module, `discussions` and `discussion_messages`, the `discussions.view`/`participate` capabilities granted to the Guardian, every use case and route in the ADR, OpenAPI, and the step-up exemption extended to exactly two pinned capabilities | `./flow check --pgsql` green; module-boundary, route-table and role-catalog tests; ownership, tombstone and disclosure-by-shape tests; a two-process race test (reply against resolve) on both engines, mutation-checked by dropping the lock | Complete |
| WP2 | Guardian Discussions UI: the list (state filter, title search, paging), a discussion (messages, tombstones, edited marks, reply), start, edit and remove own messages, retitle, resolve and reopen, each shown by capability | Vitest, typecheck, lint; the axe and sideways-scroll passes cover the new routes | Complete |
| WP3 | End-to-end proof and demo closeout: an opt-in development demo dataset written through the use cases, and browser journeys for the whole Phase 1 workflow, two Guardians, and each capability | `./flow test e2e` green with `--workers=4`; closes G2 | Complete |

The backend is one package rather than two (CRM split its backend because notes arrived after the directory): two tables and nine use cases share one lock and one ownership rule, and splitting lifecycle from messages would test the reply-against-resolve invariant only after both halves exist.

## Current milestone: G5 — Resources / Knowledge (Active)

The shared layer through which Commons delivers organizational knowledge and resources: Categories of Resource Packs, each Pack holding Cards that are complete resources in themselves (Basic rich content, External Links, Files), targeted at audiences, published reversibly, and never disclosing what a viewer may not see. Phase 1 is Guardian-only: management and a Guardian library in the Console. The decisions and the Phase 1 contract (schema, rich-content profile, audiences, projection, capabilities, routes, concurrency, files) are [ADR 0037](adr/0037-resources-are-audience-targeted-packs-of-cards.md), resting on [ADR 0036](adr/0036-business-relationships-are-independent-and-not-access-roles.md), and are not repeated here.

| Package | Scope | Validation boundary | Status |
| --- | --- | --- | --- |
| WP0 | Product and architecture design gate | ADR 0036 and ADR 0037 accepted | Complete |
| WP1 | Resources backend foundation: the `Resources` module; Categories, Packs and Cards of Type `basic` and `external_link`; the document profile validator and summary derivation; audiences, inheritance and narrowing; publication and its invariants; ordering; revisions; the one projection behind library, Pack, search and preview; deletion, with its two security events (`resource.pack_deleted`, `resource.card_deleted`: action and actor, never content); the `resources.view`/`manage` capabilities granted to the Guardian; the step-up exemption extended to a third capability with the two deletions still verified; OpenAPI | `./flow check --pgsql` green; module-boundary, route-table and role-catalog tests; non-disclosure by response shape and by search; the deletion-audit proofs listed in ADR 0037 (an event exactly when a deletion commits, none on refusal or failure, no content in it, none for any other mutation) and the revised audit-seam boundary tests; two-process race tests (publish against delete, last-Card unpublish against publish, narrowing against audience reduction, reorder against create, and the read-to-write windows) on both engines, mutation-checked by dropping each lock; the shared example documents, backend side. Notes: [ADR 0037](adr/0037-resources-are-audience-targeted-packs-of-cards.md#implementation-notes-wp1-2026-10-04) | Complete |
| WP2 | Rich-content foundation in the Console: Tiptap configured to the profile (including the exact editor-default attributes ADR 0037's implementation notes list), the read-only renderer, the shared fixture corpus both stacks check (WP1 wrote its backend side and left the sharing mechanism to WP2), and real-editor fixtures run through the server; no Resources screens yet | Every fixture validates on the server and renders in the Console; the editor works under the production CSP in real Chromium (no CSP violations); no `innerHTML`; Vitest, typecheck, lint | Next |
| WP3 | Managed files: `resource_assets`, the private disk, File Cards, upload and replacement, management and delivery downloads, the type allowlist measured on the production host, the production-check upload-limit item, orphan pruning, and the backup runbook | `./flow check --pgsql`; type-spoofing, oversize, disallowed-type, invisible-Card and missing-file tests; inline PDF behaviour under the CSP proved in Chromium or PDF made download-only; host-sensitive, so a real-host check before release | Planned |
| WP4 | Guardian management UI: Categories, Packs and Cards, the editor, audiences and narrowing, publication with its requirements, ordering, preview as an audience, files, revision conflicts, and confirmed, verified deletion | Vitest, typecheck, lint; the axe and sideways-scroll passes cover the new routes | Planned |
| WP5 | Guardian library UI: the Category-organized library, search and Category filter, one-Card and multi-Card presentation, label navigation, Series previous/next and *n of m*, generic Link and File handling | As WP4 | Planned |
| WP6 | End-to-end proof and demo closeout: an opt-in demo dataset written through the use cases, and browser journeys for the whole Phase 1 workflow, including non-disclosure and preview | `./flow test e2e` green with `--workers=4`; closes G5 | Planned |

Why this shape: the rich-content contract between the two stacks is the riskiest new idea, so it is proved on its own (WP2) while WP1's validator is fresh, before any screen depends on it. Files are separated from the backend foundation because uploads and file serving are their own security surface and depend on host limits, and are additive to the Card model. Volunteer delivery is not in G5; see G10.

## Near-term usability and operations

These run alongside the milestones and are not milestones themselves.

- **MFA enrollment usability — Complete.** Who must use MFA is unchanged. First-time enrollment offers a scannable QR code and a manual setup key, restarting a setup is clear about what changed, and real-device enrollment with an authenticator app, including a later TOTP sign-in, has been verified by the product owner. Two possible refinements are deferred polish, not active work: keeping a pending setup across a page refresh, and explaining more specifically why promotion to Console access ends an open session.
- **Administrative credential recovery — Phase 1 complete (send password reset email); the rest deliberately separate.** Lets an authorized administrator help a Guardian who is locked out, without ever handling their credentials. The actions are deliberately separate, and each is authorized, protected by the existing recent-security-verification (step-up) mechanism, audited, and explicit about its effect:
  - **Send a password-reset email — Complete.** From an Account's detail page an administrator (`identity.accounts.manage`, with recent verification) starts the existing password-recovery flow: the same token, message, link and reset page as "I forgot my password". They do not choose or see the password and never receive the reset token or link; the person receives the message and sets their own password. Only an active Account (an invited one needs a new invitation; a disabled one is not recovered), at most one a minute, audited as `password.reset_requested_by_operator`. Administrators do not set or learn another person's password, and "set a custom password" is not planned; administrator-assigned temporary credentials would need their own security decision.
  - **Reset the authenticator — Complete (existing, separate).** The existing MFA reset stays its own action; sending a password reset does not touch it.
  - **End active sessions — Planned / deferred.** A separate security operation, if and when the Console exposes it.
  - **Reset sign-in access (future, undefined).** A combined, higher-impact action is possible later, but its semantics (which of session termination, password recovery and MFA recovery it coordinates) need a dedicated security-design decision first. It does not exist and is not scheduled.

  **Delivery in production still depends on production transactional mail activation (below), which has not happened:** until it does, the feature works end to end in development (Mailpit) and, in production, the reset message goes to the log, not to the person.
- **Production transactional mail activation — Operational.** The code exists; choosing a provider, configuring DNS and credentials, and verifying a real send do not. **Production mail is not active** and still runs `MAIL_MAILER=log` ([production readiness](runbooks/production-readiness.md)).

## Product direction (realigned 2026-10-03)

Commons is increasingly the **organizational platform**: identity, access, Membership, Guardian and (later) Volunteer coordination, People / CRM, durable discussions, shared organizational resources and knowledge, selected community and Member experiences, and the authorization and audience decisions around Commons-owned content. It does not automatically become the implementation home for every specialized Flow Life workflow.

- **Quiverly.** A separate event-production platform (working codename Quiverly) is being developed and may own substantial specialized event-production functionality, some of it general beyond Flow Life. **The division between Commons and Quiverly is not settled and this roadmap does not set it.** The strategic intent is only: avoid implementing specialized event-production capability twice; establish a clean seam later; do not share database ownership casually; let Commons consume authoritative projections from Quiverly without absorbing Quiverly's internal domain model. No protocol, API, schema or synchronization design exists or is implied.
- **Preferred development pattern.** For any expansion of an existing domain or any new one: an interactive product-planning session first, which settles scope and semantics; the implementation package is written only after it.
- **Audiences are open-ended.** A Resource may eventually be visible to Guardians, Volunteers, Members, Partners, Artists, specific roles or groups, or a contextual audience such as people enrolled in a future class. Visibility is not modeled as a Guardian / Volunteer / Member hierarchy. **Decided by the Resources WP0 gate** ([ADR 0037](adr/0037-resources-are-audience-targeted-packs-of-cards.md)): Resources owns which audiences its content targets (a code-owned set, combined by OR, narrowable per Card), and each relationship's own domain answers who belongs to it. It is not a generic policy or entitlement engine.
- **Relationships are independent** ([ADR 0036](adr/0036-business-relationships-are-independent-and-not-access-roles.md)). Membership and Volunteering are separate business relationships: a Person may hold either, both or neither, and the same holds for Partner, Vendor and Artist. This **supersedes** the earlier statement that Volunteers are Members with elevated duties and privileges. A Volunteer is still a Person, not a second identity, and Volunteering is still not merely an Access role. Roles and capabilities decide what an Account may do in the software; a relationship may result in Access grants, never the reverse. Volunteer resource access and eventual Volunteer participation in Discussions are future Commons direction (G10, G9). **Discussion moderation remains Guardian-only** for the foreseeable expansion; Volunteer Moderators are not designed.

## Milestones and sequence

Domain ownership rule: Commons domains own durable business state and rules; the Guardian Console is the primary rich authoring surface; `/my/`, WordPress and any future client present Commons capabilities and own none ([charter](architecture/charter.md)). Each milestone gets its own design gate, and an ADR where a decision is durable.

**Identifiers are stable names, not an ordering.** G3 and G4 keep the names every ADR and architecture page already uses; the table below is in sequence order, and this page is where that order lives. Identifiers G6–G10 are new.

| Milestone | Goal | Status |
| --- | --- | --- |
| **G1 — CRM / People** | A Guardian people and contact system that enriches Identity's Person | Complete |
| **G2 — Guardian Discussions** | Durable asynchronous Guardian threads owned by Commons ([ADR 0035](adr/0035-guardian-discussions-are-durable-asynchronous-threads.md)); later projection to Members or Volunteers stays possible | Complete |
| **G5 — Resources / Knowledge** | The Resources foundation and useful internal Guardian resource workflows: SOPs, policies, training material, reference resources, curated links and files. Designed by [ADR 0037](adr/0037-resources-are-audience-targeted-packs-of-cards.md); packages above | **Active** (WP0 and WP1 complete; WP2 next) |
| **G10 — Volunteer relationship and Resources audience** | The first audience added after the Resources foundation. First the authoritative Volunteer relationship: a minimal Volunteering domain that can say who is a Volunteer now, scoped in its own interactive planning session ([ADR 0036](adr/0036-business-relationships-are-independent-and-not-access-roles.md)); then the `volunteer` audience in Resources (ADR 0037, decision 44), targetable and previewable. Delivery to Volunteers arrives with the community surface (G7, G9). Resources does not fake the relationship in the meantime | Planned, after G5 |
| **G6 — Core audit / stabilization checkpoint** | A review gate, not a feature, before the externally exposed surface grows. See below | Planned |
| **G7 — WordPress companion foundation** | A secure, narrow Commons ↔ WordPress foundation, beginning with low-risk read-oriented surfaces such as announcements and resources ([WordPress integration](integrations/wordpress.md)) | Planned |
| **G8 — Selected Member-facing projections** | Chosen Member-facing functionality projected outward, then Member discussion-board participation | Planned |
| **G9 — Volunteer resource and Member expansion** | Volunteer resource delivery through the community surface (some resources within shared sets remaining Guardian-only, which ADR 0037's per-Card narrowing already provides), then Volunteer-oriented projections and functionality, then eventual Volunteer participation in Discussions | Planned |
| **G3 — Event Planning** | Practical operational planning for Flow Life events. **Ownership under review**: Commons may own some event-management functions, consume event information from Quiverly, or provide event-facing projections; the system-of-record boundary is undecided. Not cancelled | Deferred |
| **G4 — Announcements / Publishing** | Announcements and publication. **Ownership under review.** Conceptually, Commons may naturally own organizational announcements, Guardian/member/volunteer communications, Commons-native notices and access-controlled community information, while Quiverly may naturally own event campaigns, promotional production workflows, event marketing assets and event-specific publishing; this distinction is not a decision. Timing may also depend on production mail | Deferred |
| **CRM expansion** | Further CRM capability beyond G1, scoped in an interactive planning session | Planned, no package yet. After G5 and the WordPress foundation (G7) |
| **Discussions expansion** | Further Discussions capability beyond G2, scoped in an interactive planning session | Planned, no package yet. After G5 and the WordPress foundation (G7) |

G1 and G2 are complete foundations and do not receive opportunistic feature expansion in the meantime.

### G6 — Core audit / stabilization checkpoint

Placed after the Resources core is established and before substantial external WordPress exposure (G7 onward); it is a planned gate, not work to start now. It reviews: Identity and Accounts; Access and capabilities; Membership; MFA, security and session behavior; CRM / People; Discussions; Resources; architecture and module boundaries; accessibility; browser and end-to-end coverage; MariaDB / PostgreSQL compatibility; deployment and release assumptions; and roadmap-to-architecture alignment. Its scope and exit criteria are written when it is reached.

## Deliberately deferred foundations

Not scheduled. Each is built when a milestone actually needs it, not before ([charter rule 17](architecture/charter.md)).

- **Generated API client** — the original trigger has fired ([api-client README](../packages/api-client/README.md)); not yet picked up as its own piece of work.
- **Domain events and transactional outbox** — built with the first real consumer ([integration model](architecture/integration-model.md)).
- **Person merge and anonymisation / retention policy** — open ([data ownership](architecture/data-ownership.md)).
- **Volunteering domain model** — Volunteering is a business relationship independent of Membership ([ADR 0036](adr/0036-business-relationships-are-independent-and-not-access-roles.md)); its lifecycle, assignments, duties and history are undecided. G10 needs only "who is a Volunteer now", and builds no more than that.
- **Deeper WordPress projection** — G7 and G8; see below.
- **Unified search, generic entity references, a shared media library** — no design exists; each is decided by the first milestone that needs it. Two neighbouring questions are now answered for their first consumer only: Resources owns its own audience targeting ([ADR 0037](adr/0037-resources-are-audience-targeted-packs-of-cards.md); not a platform-wide policy engine), and Resources owns the files its File Cards hold, behind a port, until a second module needs managed files.

## Members and WordPress

- The Member foundation exists and is **Parked**. `/my/` is the Commons-hosted self-service foundation ([ADR 0032](adr/0032-members-use-a-commons-hosted-surface.md)). Substantive Member expansion waits for the WordPress foundation and selected projections (G7, G8) and is not the next priority.
- **WordPress companion sequence.** After the Resources foundation has several useful internal use cases (and the G6 checkpoint): (1) a secure, narrow Commons ↔ WordPress foundation (G7); (2) selected Member-facing information, beginning with low-risk read-oriented surfaces such as announcements and resources; (3) later, Member discussion-board participation (G8); (4) later, Volunteer-oriented projections and functionality (G9). The companion is today a skeleton ([WordPress integration](integrations/wordpress.md)).
- Commons-hosted `/my/` and WordPress projections may coexist; this roadmap does not decide that every Member feature lives in WordPress.
- Person-specific, delegated WordPress authentication remains governed by [ADR 0018](adr/0018-client-and-delegated-authentication.md) and [ADR 0033](adr/0033-service-identity-and-delegated-human-authority-are-distinct.md): it is built when a real product need meets the trigger stated there, not before. Public or non-person read surfaces need at most service authentication.
- Guardian-domain capabilities may later be offered to Members or Volunteers, on `/my/` or through WordPress.

## Horizon

Ideas only; nothing here is planned, designed or scheduled, and none of it should shape near-term work.

- Realtime chat, private messaging and presence
- Richer realtime collaboration and collaborative document editing
- Campaign automation and multi-channel publishing
- Classes, course enrollment, homework and context-driven resource access (may belong partly or substantially to Quiverly; not near-term scope)
- Advanced social and community experiences

Where an existing external tool already does a job well, integrating with it may be better than rebuilding it.
