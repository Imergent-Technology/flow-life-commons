# G7 entry conditions

- **Package:** G6 WP5, the output named in the [G6 contract](README.md#wp5--g7-entry-conditions-documentation-only-nothing-built). Documentation only. The acceptance record is [wp5-record.md](wp5-record.md).
- **Written against:** `main` at `8efce28` (G6 WP4 integrated, post-merge CI green). WP6 has not run, so G6-D01 and G6-D02 are still open and the roadmap's G6 statuses are not yet updated.
- **What this is:** the list of decisions G7's design gate must address, explicitly, before any G7 implementation begins. **It makes none of them.** No authentication, authorization or publication policy is approved here, and nothing in it changes an ADR, a debt disposition or any code.
- **G7** is the WordPress companion foundation: "a secure, narrow Commons ↔ WordPress foundation, beginning with low-risk read-oriented surfaces" ([roadmap](../../roadmap.md), [WordPress integration](../../integrations/wordpress.md)). It is planned and not started. Its design gate "begins from G6's G7 entry conditions and debt register".

**How to read each condition.** *Current fact* is verified against the repository on the base above, with the source named. *Risk* says why G7 cannot ignore it. *G7 design-gate decision* is phrased as questions to resolve, accept or defer; where it names options, they are the ones the existing record already contains, not recommendations. Anything inferred rather than read from a source is marked **(inference)**.

## Overview

| # | Condition | Core question for the design gate | Unconditional? |
| --- | --- | --- | --- |
| 1 | [Person-scoped Resource delivery outside Commons](#1-person-scoped-resource-delivery-outside-commons) | Which of two different things is G7 delivering: a signed-in Person's Resources through a trusted client, or content for a public audience? | Yes: G7 must say which, or neither |
| 2 | [The first service identity](#2-the-first-service-identity) | What is a non-human caller, and how is a client-asserted `person_id` structurally excluded? | If G7 has any non-Console caller |
| 3 | [Authentication strength for non-Console callers](#3-authentication-strength-for-non-console-callers) | Where is authentication strength carried and enforced when the caller has no `console.access`? | If G7 has any such caller (G6-D11) |
| 4 | [ADR 0038 P8: identity matching](#4-adr-0038-p8-identity-matching) | May a WordPress identity ever be linked to a Person by CRM email? | If G7 links any WordPress identity |
| 5 | [Trusted proxies and request attribution](#5-trusted-proxies-and-request-attribution) | What address and which caller does a throttle key and a `security_events` row name? | If G7 adds an external caller or a proxy/CDN |
| 6 | [Step-up for externally visible publication](#6-step-up-for-externally-visible-publication) | Does content becoming visible outside Commons keep today's routine-management policy? | If G7 exposes content outside the Console |
| 7 | [Rendering Resources on another origin](#7-rendering-resources-on-another-origin) | Who renders the document, and who owns the XSS defence? | If G7 renders Resource content off-origin |
| 8 | [A minimal `/v1` compatibility policy](#8-a-minimal-v1-compatibility-policy) | What may an external client rely on about errors and change? | If any external client depends on `/v1` |
| 9 | [Browser-workflow verification](#9-browser-workflow-verification) | Is there a scoped or on-demand GitHub browser workflow before or during G7? | Yes (owner ruling D7) |
| 10 | [G7-triggered debt-register items](#10-g7-triggered-debt-register-items) | Eleven rows name G7; each needs a disposition at the gate | Mixed: per row |

Conditions 1 to 3 and 6 to 7 are linked: delivering content to a client is not one decision but a chain (who is the caller, whose authority, what may it see, in what form). The sections say where they depend on each other, and do not resolve the order.

---

## 1. Person-scoped Resource delivery outside Commons

**Current fact (verified).**
- Resources are delivered only to an authenticated human through the Commons Console. The audience catalog is exactly `guardian`, `member` and `volunteer` (`Resources\Domain\Audience`; [ADR 0038, R1](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md)). **No public audience exists.** No Resources route is unauthenticated: the platform has exactly ten routes without `auth:web`, none of them in Resources ([trust boundaries](../../architecture/trust-boundaries.md#public-unauthenticated-surface-today), enforced by `RouteAuthenticationSurfaceTest`).
- Audience eligibility is decided per Person. `AudienceEligibility` re-resolves the Actor from the session, then asks Relationships (Guardian, Volunteer) and Membership (Member) ([ADR 0038, Part R](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md)).
- [ADR 0032](../../adr/0032-members-use-a-commons-hosted-surface.md) puts the Member surface on Commons itself. Today WordPress is, at most, a link to Commons.
- [ADR 0033](../../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md) states the trigger for delegated authentication precisely: person-specific Commons data that must **render inside a WordPress-served page**. It also says a public, non-person dataset needs at most service authentication, and is "its own, cheaper, separately-triggered piece of work". Neither mechanism is built: no client table, no client credential, no delegated token store.

**Risk.** "Resources outside Commons" hides two different designs with different trust models.
- *Person-scoped delivery:* eligibility is a property of a Person, so the caller must act for a specific, authenticated Person. That is delegated human authority (conditions 2 and 3), and it carries the confused-deputy risk.
- *Public delivery:* there is no Person and so no eligibility to evaluate. It needs a new notion of audience, and everything that follows from content becoming world-readable (conditions 6 and 7).
- A service identity reading "as itself" is **neither**: it has no Person, so it cannot be an eligible reader of a Guardian, Volunteer or Member audience **(inference from the per-Person eligibility above)**. Treating the two designs as interchangeable would let a public surface be built on top of person-scoped machinery, or the reverse.

**G7 design-gate decision.**
1. Which is G7's first delivery: person-scoped, public, or neither (link out to Commons per ADR 0032)? State it, and defer the others explicitly.
2. If person-scoped: it is ADR 0033's delegated authentication. The gate must take conditions 2, 3, 5, 6 and 7 as one decision set, since each applies to it.
3. If public: is it a new audience in the catalog, a separate publication channel, or something else? Who may mark content public, and what does the current ability to publish immediately (condition 6) mean for it? This is not an extension of delegated authentication.
4. If the first delivery is "announcements": the roadmap's G4 (*Announcements / Publishing*) has "ownership under review" and no implementation exists, so G7's scope depends on that unresolved question.

**Supporting references.** [ADR 0033](../../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md); [ADR 0018](../../adr/0018-client-and-delegated-authentication.md); [ADR 0032](../../adr/0032-members-use-a-commons-hosted-surface.md); [ADR 0037](../../adr/0037-resources-are-audience-targeted-packs-of-cards.md); [ADR 0038, A6 and Part R](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md); [WordPress integration](../../integrations/wordpress.md); [roadmap, G4 and G7](../../roadmap.md).

**Trigger or dependency.** Before any WordPress-facing Resources endpoint, any client table, or any public-audience change is designed. Also governs G6-D13 and G6-D17 ([condition 10](#10-g7-triggered-debt-register-items)).

---

## 2. The first service identity

**Current fact (verified).**
- The platform has one guard, `web` (a session over the `accounts` provider), and no way to authenticate a client or a token. `MembershipRouteSurfaceTest` pins this: it fails on any `/api/v1` route that is member-, WordPress- or `wp`-facing (the one self-service route excepted), and on any route middleware other than `auth:web` that looks like `auth:*`, bearer, token, client, signature, hmac or jwt. It also pins `config('auth.guards')` to exactly `['web']`.
- `MembershipTrustBoundariesTest` pins the Actor model: an `Actor` is minted only in Identity's Application layer; it has one shape (a private constructor, one factory `user()`, three properties `accountId`, `personId`, `authenticatedVia`); `AuthenticationMethod` has exactly two cases; and a Membership request's caller comes from `$request->user()` and nothing else in the request, resolved in one place, `ResolveActor`. Both tests say in their own headers that these are **phase boundaries** that "are EXPECTED to fail" when ADR 0018 is implemented, and must be "revised deliberately — with the new Actor shape and its minting site named here — not deleted".
- `ResolveActor` turns an `AccountId` into an `Actor` for an active Account. `Authorizer` re-resolves the Actor through it and then reads role assignments and sourced grants **by Person** (`Authorizer::capabilitiesOf`, which `allows` is defined in terms of).
- `security_events.actor_client_id` exists in the frozen schema ("for API clients, which do not exist"), and nothing writes it. CORS allows no credentials (`supports_credentials` false, [ADR 0016](../../adr/0016-guardian-console-same-origin-session-authentication.md)).
- The invariants are already decided ([ADR 0018](../../adr/0018-client-and-delegated-authentication.md), [ADR 0033](../../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md)): a service client is its own record with its own credential, holds no person-scoped capability and cannot hold a role; a delegated request needs two independent proofs; **a client-asserted `person_id`, `user_id` or signed claim is never accepted as identity.**

**Risk.** The confused deputy. Authority in this platform attaches to a Person (through an Account). An Actor that names a Person is authorized as that Person. If a trusted external client could cause the platform to build such an Actor from something the client says (a `person_id` in a body, header or token claim, a WordPress user mapped to a Person), the client would hold that Person's full authority regardless of who is actually on the other end. The current Actor also has no honest form for a non-human caller: its Account and Person are mandatory, so the shortest path to a service caller is to fake one, which is the failure this design exists to prevent. The three boundary tests are what would catch it, so revising them is where the decision is made visible.

**G7 design-gate decision.**
1. What is a non-human caller in the Actor model: a distinct form with its own minting site, or something else? Name the minting site (the revised rule for `MembershipTrustBoundariesTest`).
2. How is a service caller authorized: with its own capability list that excludes person-scoped capabilities (ADR 0018), evaluated by a path that cannot read a Person's roles?
3. For delegated human authority: what are the two proofs, where is the subject credential issued, and how is the client-asserted identifier structurally excluded (not only by convention)? ADR 0033 fixes the shape (authorization-code semantics, PKCE, short-lived, narrow, revocable) and **deliberately leaves open** the token lifetime, storage, endpoint paths and refresh behaviour.
4. How `MembershipRouteSurfaceTest` (guards, route and middleware naming, the closed route surface) and `ResolveActor` change, and what each test still asserts afterward. The durable part, that a client-asserted identity is never proof, must remain asserted.
5. Whether service authentication is needed at all in the first G7 slice (ADR 0033: a public, non-person dataset needs "at most" service authentication).

**Supporting references.** [ADR 0018](../../adr/0018-client-and-delegated-authentication.md), [ADR 0033](../../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md); [trust boundaries](../../architecture/trust-boundaries.md); `tests/Architecture/MembershipTrustBoundariesTest.php`, `tests/Feature/Modules/Membership/MembershipRouteSurfaceTest.php`; `Identity\Application\ResolveActor`, `Access\Application\Authorizer`, `Shared\Domain\Actor`.

**Trigger or dependency.** The first G7 design that has a caller other than a Console session. Conditions 3 and 5 and G6-D10, G6-D11 depend on it.

---

## 3. Authentication strength for non-Console callers

**Current fact (verified).**
- `Actor::$authenticatedVia` records `session` or `session_second_factor`, set by `ResolveActor` from a flag the transport supplies. **No authorization decision reads it** (the only use in `app/` is its declaration). The audit classed this INFORMATIONAL, not a defect, and the owner ruled it "accept as a G7 entry condition" ([WP1-05](wp1-audit.md#wp1-05--informational--actorauthenticatedvia-is-read-by-nothing-g7-entry-condition), [triage M2](wp1-triage.md), G6-D11).
- It is safe today because the enforcement is elsewhere. Every role that grants any capability grants `console.access` (`CatalogTest`, "grants console.access in every human role that grants any capability", a WP2 deliverable whose own comment says a service identity is a separate model that it neither covers nor constrains); the second-factor policy is tied to `console.access` and fails closed; `EnforceSecondFactorWhereDue` ends a password-only session that gains Console access; and at the audit all 96 `/admin` routes carried `can:console.access`.
- Step-up (`security.verified`, recent verification) is a **separate** mechanism: a proof held in the session for a sensitive mutation. It is not the same thing as `authenticatedVia`, which records how the session began.

**Risk.** The second-factor requirement is attached to `console.access`, not to the Actor's recorded strength. A caller without `console.access` (a service identity, a delegated WordPress caller, a future role that omits it) is outside that policy, and nothing else consults `authenticatedVia`, so the strength it records would be **unenforced**. The two concepts, what the system records and what decisions use, are currently coupled only by an invariant on the role catalog.

**G7 design-gate decision.**
1. What does "authentication strength" mean for a service client (a credential, not a session or a second factor), and for a delegated human action (the human's strength at Commons, carried how and bound to what)?
2. Where is it enforced: which decisions must read it, or is it replaced by an enforcement point that does not depend on `console.access`?
3. For sensitive operations: can a service or delegated caller ever satisfy `security.verified`, or are those operations simply not reachable by it?
4. Does `AuthenticationMethod` grow, or does non-session strength live somewhere else? Does the "every role grants `console.access`" invariant stay as a standing guard?

**Supporting references.** [WP1 audit, WP1-05](wp1-audit.md#wp1-05--informational--actorauthenticatedvia-is-read-by-nothing-g7-entry-condition); [WP1 triage, M2](wp1-triage.md); G6-D11 in the [register](debt-register.md); [ADR 0023](../../adr/0023-multi-factor-authentication.md), [ADR 0024](../../adr/0024-privileged-operator-administration.md), [ADR 0025](../../adr/0025-account-security-generation.md).

**Trigger or dependency.** Any caller without `console.access`. Decided together with condition 2.

---

## 4. ADR 0038 P8: identity matching

**Current fact (verified).**
- P8 is an **accepted consequence**: a `volunteers.manage` holder can look up any Person, record them as a Pending Volunteer without recent verification, and from then on edit their display name, **primary CRM email** and primary CRM phone, including for a Guardian or an administrator. It is acceptable because "the three fields carry no authority": login identity, credentials, roles and Console admission stay out of reach (P6, P8). The safeguards are confirmation against accidents, provenance (`contact_methods.updated_by_account_id`, P9) and `person.renamed`; **there is no security event for a contact change** (CRM data is not security data, ADR 0034).
- A CRM contact email is **not** a login email. Changing one changes no login, invitation or recovery address; an architecture test pins that Identity never depends on CRM; the invitation form is never pre-filled from CRM without an explicit operator action (P6).
- `contact_methods` has no verification state, and a value is deliberately **not unique across Persons** (a shared household or organisational address is legitimate); uniqueness is per Person only (migration `2026_10_01_000002`). `crm.people.manage` can already rename any Person (G6-D15).
- [WordPress integration](../../integrations/wordpress.md), rule 7: an external identity "is a link, not a merge", platform-owned data, and not currently needed. The account-claim flow for a Person with no Account is "still undesigned".

**Risk.** The README's one-line statement of this condition is "revisit it before any WordPress identity is linked by CRM email" and does not say why. The reasoning below is **(inference from P8's own premise)**. P8 is acceptable *because* the primary CRM email carries no authority. If a WordPress user were linked to a Person by matching that email, the email would become an identity key, and an operator-editable, unverified, non-unique value would decide whose data a WordPress visitor sees. Editing it (which P8 allows a scoped manager to do, without step-up and with provenance but no security event) could then redirect or claim a link. The accepted consequence would stop being bounded by "no authority".

**G7 design-gate decision.**
1. Does G7 link any WordPress identity to a Person at all? ADR 0032 and the WordPress document say the current design avoids needing to.
2. If yes: what proves ownership of the link (ADR 0018 says platform-issued proof, not a match), and may a CRM contact value be used as a key, a hint, or never?
3. What happens to P8's accepted consequence, and to who may edit a value the link depends on (verification, a security event)?
4. Where the link lives (platform-owned data) and the account-claim flow for a Person with no Account.

**Supporting references.** [ADR 0038, P6, P8 and P9](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md); [ADR 0034](../../adr/0034-crm-enriches-identity-person.md); [WordPress integration](../../integrations/wordpress.md); G6-D15 and G6-D19.

**Trigger or dependency.** Before any code reads a CRM contact value to identify an external user. Depends on conditions 1 and 2.

---

## 5. Trusted proxies and request attribution

**Current fact (verified).**
- The platform **deliberately trusts no proxy**: `bootstrap/app.php` configures none, so `X-Forwarded-*` is never honoured and `$request->ip()` is `REMOTE_ADDR`. Trusted hosts are exactly the `APP_URL` host. The comment there says a proxy or CDN's addresses "must be listed here explicitly — never a wildcard — and the change reviewed on its own".
- That is correct **because of a measurement** (2026-09-21): Commons resolves straight to the origin with no proxy or CDN, while the WordPress apex is behind Sucuri ([trust boundaries, The edge](../../architecture/trust-boundaries.md#the-edge-no-proxy-in-front-of-commons-and-why-that-is-load-bearing)).
- Two controls rest on it: **per-address throttles** (login 30 per address and 5 failures per HMAC'd identifier; sign-in code 10 per Account and 30 per address; step-up 5 and 20; each per 15 minutes, with `block()` then `record()` as two cache operations, G6-D10) and **address attribution in `security_events`** (`ip`, `user_agent` on every row, via `ClientContext`). In development every request appears to come from the Caddy gateway, which is the safe direction.
- Today every caller is a browser on the same origin, so the observed address is the user's.

**Risk.** External clients add trust boundaries the same-origin case does not have.
- A server-to-server client (WordPress) presents the *client's* address, not the end user's. A per-address throttle then either throttles all of that site's users together or fails to bound one abuser.
- If the client forwards an end-user address, that value is client-asserted and must not be recorded as if observed.
- A proxy or CDN in front of Commons (for example for the external surface) turns per-address throttling effectively global and records the proxy in every audit row, unless its addresses are configured.
- `security_events` can name the acting Account but has no writer for `actor_client_id`, so a client's action would be attributed to nobody or to the wrong party.

**G7 design-gate decision.**
1. Will Commons, or a separate surface, sit behind a proxy or CDN? If so, the trusted addresses (never a wildcard), and the re-verification the trust-boundaries document lists (client-IP handling, throttling, attribution, caching).
2. What are the throttle keys for a service client and for a delegated action (client, client and Person, address)? What does the address mean when the caller is a server?
3. What `security_events` records for a non-session actor: `actor_client_id`, the observed address, and an asserted end-user address if any, labelled as asserted.
4. Whether G6-D10's check-then-count race must be closed before higher-volume external authentication traffic (its stated trigger).

**Supporting references.** [trust boundaries](../../architecture/trust-boundaries.md); [ADR 0016](../../adr/0016-guardian-console-same-origin-session-authentication.md), [ADR 0019](../../adr/0019-security-event-auditing-seam.md), [ADR 0026](../../adr/0026-production-browser-security-policy.md); [WP1 audit, section D](wp1-audit.md); G6-D10; `bootstrap/app.php`; `Identity\Infrastructure\Auth\CacheAttemptThrottle`.

**Trigger or dependency.** Any external caller, and any proxy or CDN decision. Follows conditions 2 and 3.

---

## 6. Step-up for externally visible publication

**Current fact (verified).**
- `resources.manage` is on the step-up exemption list, pinned to `Resources\Http` ([ADR 0037, decision 52](../../adr/0037-resources-are-audience-targeted-packs-of-cards.md); the list in `AdministrationRoutesTest` has five entries: `crm.people.manage`, `discussions.participate`, `resources.manage`, `guardians.manage`, `volunteers.manage`). Publishing, unpublishing, widening or narrowing a Published Pack's audiences, editing a Published Card and replacing its file take effect **immediately**, with no recent verification and no `security_events` row. Only the two permanent-delete routes require `security.verified`, and they record `resource.pack_deleted` and `resource.card_deleted`.
- Today the readers are signed-in, eligible Persons in the Console.
- The owner's WP2 ruling K3 ([wp2-triage.md](wp2-triage.md#k3-resources-security-events-and-step-up-exemptions)): **defer**; keep the approved step-up policy; add no step-up requirement or event type in WP2; "revisit event coverage before G7 exposes additional Resources consumption or management interfaces"; any change to step-up needs a separate owner decision under DP2. Recorded as G6-D13 with its mitigations (content-sniffed type allowlist, `attachment` plus `nosniff`, inline display only for PDF and raster images, CSP `default-src 'none'`).

**Risk.** The policy's premise is that a mistaken or hijacked publication reaches a known, authenticated audience that can be reversed by unpublishing. Content visible **outside** Commons changes that: the audience may be unauthenticated or unknown; copies can be cached, indexed or screen-captured, so unpublishing does not recall it; and it carries Commons's name and external links to people who never signed in **(inference)**. A hijacked second-factor session could then cause an externally visible change with no verification and no event to investigate.

**G7 design-gate decision.** (No policy is introduced here, and any change to step-up is the owner's DP2 decision.)
1. Does externally visible publication stay on the routine `resources.manage` path, or does it need a different gate? If different, which kind (recent verification, a separate capability, a separate approval) is the owner's decision, not made here.
2. How is "visible outside Commons" represented: a property of an audience, of a Pack, or of a distinct channel? This affects which actions the policy would apply to.
3. Whether publication, audience and file changes affecting external visibility need security events, and which.
4. How the answer interacts with condition 1 (which delivery) and G6-D13's trigger.

**Supporting references.** [ADR 0037](../../adr/0037-resources-are-audience-targeted-packs-of-cards.md), decisions 52 and 53; [ADR 0038, A5](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md); [WP2 audit, I2](wp2-audit.md) and [triage K3](wp2-triage.md#k3-resources-security-events-and-step-up-exemptions); G6-D13, G6-D14.

**Trigger or dependency.** Before any content can become visible outside the signed-in Console.

---

## 7. Rendering Resources on another origin

**Current fact (verified).**
- Card content is a **server-validated structured document**, never HTML: the ProseMirror-shaped document profile v1 (ADR 0037, decisions 25 to 29). The server **refuses, never strips**, anything outside the allowlist (nodes, marks, attributes), with `422 invalid_content`, and stores its own re-serialisation.
- Links are limited by `ExternalUri`: `https` or `http` with a host, at most 2,048 characters, no user-information, whitespace, control character, backslash, angle bracket or double quote; a link in content may also be `mailto:` with one plain address; `javascript:`, `data:`, `vbscript:`, `file:`, every other scheme and every relative reference are refused. The platform never fetches the address. Links open in a new browsing context with `rel="noopener noreferrer"` (stored attributes are fixed defaults, decision 29).
- The Console renders the document to React elements (`@tiptap/static-renderer`); **no HTML string is produced**. `guardrails.test.ts` fails on `dangerouslySetInnerHTML` or `.innerHTML =` in the Console. The production CSP is strict (`default-src 'none'`, no `unsafe-inline`, `frame-ancestors 'none'`), and the renderer re-checks every link's scheme as defence in depth.
- ADR 0037, decision 30, anticipates this condition: "HTML sanitization has a named tool and no Phase 1 caller" (`symfony/html-sanitizer`), to be added by "the first package that ... emits HTML for another client; the WordPress companion (G7) is the expected first, and there the server will generate HTML from the validated document and pass it through the sanitizer as the output boundary". It has not been added and is not decided for G7.
- File downloads need the Commons session today (`attachment`, `nosniff`, inline only for PDF and raster images), and CORS carries no credentials.

**Risk.** The link allowlist is a property of what is *stored*, and the Console's CSP and React rendering are properties of Commons's own origin. On another origin neither travels with the content: the renderer is external code and its page has its own CSP, so the document's link allowlist becomes the XSS boundary rather than one layer of several. If the output is HTML generated for another client (as decision 30 anticipates), it is **new HTML egress**, which the platform has never had. The profile permits `http:` links and `mailto:`, which may be acceptable in the Console and a different question in a third-party page **(inference)**. Authenticated file routes will not work for an external client without a decision about credentials.

**G7 design-gate decision.**
1. Who renders: a Commons-provided renderer or component, the raw document handed to external code, or server-generated HTML? Does decision 30's anticipated approach stand, or change?
2. Who owns XSS defence in each case, and what is enforced on the Commons side (output sanitization, link policy) versus asserted of the other origin?
3. Is the `ExternalUri` allowlist (http, `mailto:`) sufficient for external contexts, or does external rendering need a stricter rule?
4. How a client learns the document profile and `content_version`, and what it does with a version it does not know.
5. How files are delivered to an external reader, if at all.

**Supporting references.** [ADR 0037](../../adr/0037-resources-are-audience-targeted-packs-of-cards.md), decisions 24 to 31; [ADR 0026](../../adr/0026-production-browser-security-policy.md); `Resources\Domain\Content\DocumentProfile`, `Resources\Domain\ExternalUri`; `apps/guardian-console/src/guardrails.test.ts`; G6-D13.

**Trigger or dependency.** Before any Resource content is rendered outside the Console. Depends on condition 1.

---

## 8. A minimal `/v1` compatibility policy

**Current fact (verified).**
- [ADR 0007](../../adr/0007-versioned-rest-api-openapi.md): URL-versioned `/api/v1`; "breaking changes ship as a new version, not silent edits"; a hand-maintained OpenAPI 3.1 contract (`info.version: 1.0.0`) with a test that fails when served routes and spec paths differ. It also says the "response envelope, pagination and error format ... are not decided here; they will be decided with the first real resource endpoints". No later ADR or document decides them (searched).
- In practice the contract has **four error shapes**: `Message`, `ValidationError` (`message`, `errors`), `CodedMessage` (`message`, `code`) and `CodedValidationError`. `CodedMessage` states that `code` is "a stable, machine-readable reason. Clients branch on this, never on `message`." The convention that a `code` accompanies the 409 and 422 answers a client must distinguish comes from [ADR 0024](../../adr/0024-privileged-operator-administration.md). A throttled response is `429` with `Retry-After`.
- There is no written deprecation, compatibility or sunset policy, and no statement of what counts as breaking.
- The only consumer of the API today is the Console, built in the same repository and released with it.

**Risk.** While the Console is the only client, error identifiers and shapes can change in step with it. An external client (WordPress, built and deployed separately) cannot. Without a stated policy, an error `code`, a field or a status is changed under the same version number, and the breakage appears on someone else's site.

**G7 design-gate decision.** (Minimal; the contract rules out an expansive governance framework.)
1. Which error shape and which identifiers are promised to external clients, and is the `code` list (51 `'code'` sites in `app/`) a public contract or an internal detail for the Console?
2. What counts as a breaking change on `/v1` (removing or renaming a field, a code or a status; adding a required input), and what may change without a new version?
3. The minimum deprecation commitment before removing something an external client may use.
4. Whether the policy applies to the whole `/v1` surface or only to the endpoints G7 explicitly publishes to external clients.

**Supporting references.** [ADR 0007](../../adr/0007-versioned-rest-api-openapi.md); [ADR 0024](../../adr/0024-privileged-operator-administration.md) ("Errors are machine-readable"); `apps/platform/openapi/openapi.yaml` (components) and `OpenApiContractTest`.

**Trigger or dependency.** Before an external client depends on `/v1`. Depends on condition 2 (which surface the client sees).

---

## 9. Browser-workflow verification

**Current fact (verified).**
- Owner ruling **D7** ([contract](README.md#owner-rulings-and-amendments)): keep the local, four-worker, built-assets browser gate (`./flow test e2e --workers=4`); do not make the whole suite mandatory on every pull request; do not declare browser testing permanently local-only; **"revisit an on-demand or scoped GitHub workflow before or during G7"**; quarantine nothing without authorization. G6-D06 records it (MEDIUM, accepted for G6).
- `.github/workflows/e2e.yml` is manual (`workflow_dispatch`) with a 25-minute timeout; `gh run list --workflow e2e.yml` returns no runs (checked 2026-11-22). CI runs the routine `./flow check` on every push (a recent main run took 35m31s).
- [testing](../../development/testing.md) lists browser E2E as an acceptance step for "changes to sign-in, sessions, MFA, authorization surfaces or the Console".
- Two E2E intermittencies are open pending WP6 classification (G6-D01, G6-D02), so what a CI browser run would gate on is not yet settled.

**Risk.** G7 is the first milestone that changes the surface browsers meet (CORS, cookies, CSP, possibly a second origin) and the sign-in-adjacent code. The only evidence for browser behaviour is a local run by one operator, and the one workflow that could run elsewhere has never run, so it is unknown whether it even works.

**G7 design-gate decision.** (Not implemented or required here.)
1. Which of the choices, if any: first exercise the existing on-demand workflow once; a scoped subset (for example the sign-in, session, MFA and authorization journeys) on changes to named paths; a scheduled run; or keep local acceptance with recorded evidence. The owner's constraint is that the whole suite is not mandatory on every PR.
2. Whether G7's own changes need a browser test that the local gate cannot give, for example behaviour across two origins.
3. How the open G6-D01 and G6-D02 results (WP6) affect what may gate a PR.

**Supporting references.** [README, D7](README.md#owner-rulings-and-amendments); G6-D06, G6-D01, G6-D02, G6-D05; `.github/workflows/e2e.yml`; [testing](../../development/testing.md).

**Trigger or dependency.** "Before or during G7" (G6-D06). Unconditional as a decision; its scope depends on what G7 changes.

---

## 10. G7-triggered debt-register items

I read all 24 rows of the [register](debt-register.md). **Eleven** have a recorded trigger that names G7 or a decision G7 is expected to make. None is reopened here: the dispositions below are quoted, not changed. "Unconditional" means the trigger fires at G7's design gate whatever G7's scope; "scope-dependent" means it fires only if G7 chooses the stated scope.

| ID | Existing disposition | Why its trigger matters to G7 | Decision required at the design gate | Trigger |
| --- | --- | --- | --- | --- |
| **G6-D06** Browser E2E not in GitHub CI | Accepted by the owner (D7) for G6; not permanent | Condition 9 | The workflow choice | **Unconditional** ("before or during G7") |
| **G6-D07** Generic table-ownership scan | Defer (owner U2) | G7 is expected to add storage (for example a client record) and may add a module | Build the scan, or re-defer with a new trigger. State whether G7 adds a module or a table owner | **Unconditional at the gate** ("before a new module is added, or at G7's design gate, whichever comes first") |
| **G6-D08** `CASCADE` and cross-module FK scan | Defer (owner U2) | New tables may reference `people` and need [ADR 0021](../../adr/0021-cross-module-referential-integrity.md)'s `RESTRICT` rule | As D07, together with it | **Unconditional at the gate** (same) |
| **G6-D10** Throttles check, then count | Defer (owner, WP1 triage) | Condition 5: "higher-volume external authentication traffic", "a service identity, a proxy or CDN, a new throttle key" | Whether to close the race first, given the throttle keys G7 chooses | **Scope-dependent**: fires if G7 adds any of those |
| **G6-D11** `authenticatedVia` read by no decision | Accept as a G7 entry condition (owner, WP1 triage) | Condition 3 | Where strength is carried and enforced for a caller without `console.access` | **Scope-dependent**: fires on any such caller |
| **G6-D12** No tool removes an orphaned sourced grant | Accept (owner, WP2 triage) | "G7 external integration": an external consumer of eligibility or grants inherits the residual (a valid orphan is honoured and can confer only a provisionable role, today `console.access`) | Whether the integration relies on grants or eligibility such that the accepted residual is still acceptable, or a removal path is needed first | **Scope-dependent** |
| **G6-D13** Resources changes reach readers at once | Defer (owner, WP2 triage; any step-up change is DP2) | Condition 6: "before G7 exposes additional Resources consumption or management interfaces" | The condition 6 questions; revisit event coverage | **Scope-dependent**: fires if G7 exposes more Resources consumption or management |
| **G6-D14** Volunteer eligibility changes without step-up | Accept (owner, WP2 triage) | Trigger lists "G7": an external audience that includes Volunteer eligibility widens who a change reaches | Whether Volunteer-driven eligibility feeds anything external | **Scope-dependent** |
| **G6-D15** `crm.people.manage` can rename any Person | Accept (owner, WP2 triage) | Trigger: "G7 delegated callers". A delegated caller that reaches CRM edits widens who can rename. Also bears on condition 4 | Whether any delegated caller can reach CRM writes | **Scope-dependent** |
| **G6-D17** Resources delivery two-step read (unproven) | Investigate if triggered; never speculative changes | Trigger: "before G7 widens Resources consumption" | Whether to reproduce it before widening; if reproduced, stop for owner triage as a possible disclosure | **Scope-dependent** |
| **G6-D19** Dormant sourced grant becomes effective on Account creation | Accept (owner, WP2 triage) | Trigger: "G7 delegated invitation flows". Invitation authority is the effective authority over a dormant grant | Whether any G7 flow can invite or establish an Account | **Scope-dependent** |

**Checked and not G7-triggered:** G6-D01 to D05 (WP6 and test reliability; D05 would matter only if G7 changed the session lifetime or keep-alive, which is not a G7 trigger as written), D09, D16, D18 and D20 to D24 name no G7 trigger. **(Inference:)** D18 (corrupt stored Resources values) concerns operational hardening and a restore, and would be affected only indirectly if G7 widened Resources consumption.

---

## What this document does not do

- It does not choose among the options it names, approve any policy, or change an ADR, a register disposition or a test.
- It is not a G7 plan. Sequencing, work packages and implementation shape belong to G7's design gate.
- Production release readiness is a separate operational track ([next-release readiness](../../runbooks/g10-release-readiness.md)).
- It does not claim that any G7 decision has been made. Where an ADR already fixes a direction (ADR 0018, 0033, 0037 decision 30), the gate is asked to confirm or revise it, not assumed to inherit it.
