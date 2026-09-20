# Identity and Access design

The consolidated design the first Identity implementation epic works from. Decisions and their alternatives live in [ADRs 0015–0021](../adr/README.md); this page is the operative reference.

> **Status: architecture frozen; implementation in progress** on the phased Identity and Access epic (see [Implementation status](#implementation-status)). Nothing here may be built ahead of the epic scope in the last section.

## Vocabulary

These names spread through the codebase, so each is defined by what it is *not* as well.

| Term | Definition | Deliberately not |
| --- | --- | --- |
| **Person** | The canonical human. A thin anchor: ULID, display name. Business modules reference `person_id`. | Not a contact profile — attributes must not accumulate here. CRM later owns rich data keyed by `person_id`. |
| **Account** | A Person's means of signing in: login identifier, credential, status. | Not the person. The word *User* is avoided entirely because it conflates the two. |
| **Credential** | The secret proving control of an Account — initially password fields *on* the Account. | Not a table yet. Further factors arrive as their own tables. |
| **ExternalIdentity** | A link from an Account to an identity at an external provider. *Designed, not built.* | Never an authority; linking in only. |
| **ApiClient** | A registered calling application with its own credential and narrow capabilities. *Designed, not built.* | Not a Person or Account; cannot hold roles. |
| **Actor** | Per-request value object: the authenticated subject. | Not persisted; carries no capability snapshot. |
| **Capability** | A named permission to attempt an action, e.g. `access.roles.assign`. | Not a database row. |
| **Role** | A named bundle of capabilities, assignable to a Person. | Not a capability; not an approval state. |
| **RoleAssignment** | A currently-active grant. Row exists ⇒ grant is active. | Not history — that is the audit trail. |
| **SecurityEvent** | Append-only record of an identity- or access-relevant occurrence. | Not the general application audit architecture. |

Rejected from IAM literature: *Principal* (Actor covers it), *ServiceAccount* (ApiClient is clearer), *Party* (organizations do not authenticate), *Permission* as a table.

## Modules and dependency direction

```
Shared\Domain          Actor, AccountId, PersonId      (depends on no module)
        ▲
Audit\Application      RecordSecurityEvent             ──► Shared
        ▲
Identity\Application   Person, Account, auth use cases ──► Audit, Shared
        ▲
Access\Application     Capability, Role, Authorizer    ──► Identity, Audit, Shared
```

**Acyclic and one-way: Access → Identity → Audit → Shared.**

`Actor` lives in `Shared\Domain` for a concrete reason rather than a stylistic one: Identity calls Audit to record events, so placing `Actor` in `Identity\Application` would force Audit to import Identity and close a cycle. Shared is the only placement that keeps the graph acyclic, and `Actor` has three real consumers on day one — satisfying charter rule 17 rather than violating it.

### Layer placement

Verified against `tests/Architecture/ModuleBoundariesTest.php` by building the structure and running the suite. `Capability` **must** sit in `Access\Application`, because the guardrail permits cross-module imports only from `Application` — and therefore everything consuming it must sit at `Application` or above. An `Authorizer` in `Access\Domain` consuming `Capability` **fails** the test.

| Module | Domain | Application (public API) | Infrastructure | Http |
| --- | --- | --- | --- | --- |
| `Identity` | `Person`, `Account`, `EmailAddress` (canonicalisation), `AccountStatus` | `AuthenticateAccount`, `ChangePassword`, `RequestPasswordReset`, `InviteAccount`, `DisableAccount`, `ResolveActor`, `ActiveAccountQuery`, `AccountDeactivationGuard` (interface) | Eloquent models, Laravel user-provider adapter | auth controllers, middleware, `routes.php` |
| `Access` | `RoleAssignment` (`role_key` as a plain string, so Domain needs nothing from Application) | `Capability`, `Role`, `Authorizer`, `AssignRole`, `RevokeRole`, `AuthorizeAction` | Gate registration, repositories | role administration, `can:` middleware, `routes.php` |
| `Audit` | `SecurityEvent` | `RecordSecurityEvent` | append-only writer | — |

Proven-allowed edges: `Application → Domain`, `Infrastructure → Application`, and another module's `Http`/`Application` → `Access\Application`. Proven-forbidden: `Domain → Application`, and any module reaching another module's `Domain`.

### What other modules use

| Need | Use | Never |
| --- | --- | --- |
| Who is acting | `Actor` | Query `accounts` |
| May they do X | `Access\Application\AuthorizeAction`, or the Gate/`can:` middleware | Read `role_assignments`, or check role *names* |
| Who is this person | `Identity\Application` lookup by `person_id` | Join to `people` |
| Record something sensitive | `Audit\Application\RecordSecurityEvent` | Write `security_events` |

Checking a role rather than a capability is the main way this design rots. Business code asks "may they publish?", never "are they a Guardian?".

## Human identity model

```
                 ┌──────────┐
  business ─────►│  Person  │◄──── memberships, volunteering, Guardian
  modules        └────┬─────┘      responsibilities, CRM contact data
  (by person_id)      │ 0..1       (all later, all keyed by person_id)
                 ┌────▼─────┐
                 │ Account  │──── password (columns on the account)
                 └────┬─────┘
                      │ 0..*  (later)
              ┌───────▼─────────┐
              │ ExternalIdentity │
              └──────────────────┘
```

| Case | Resolution |
| --- | --- |
| Contact exists before account | Person without an Account; an Account is added later by invitation |
| Account before rich profile | Person holds a display name; CRM enriches later without touching Identity |
| Multiple capacities | Capacities are relationships to the Person; identity is unchanged when they change |
| Email changes | Update `email` and `email_canonical`, record a security event, invalidate other sessions |
| Duplicate reconciliation | A later administrative operation; possible because references are by `person_id` |
| Two humans share an address | One Account per canonical email is accepted for now; no username login is added for this |

**Every credential lookup canonicalises input and queries `email_canonical`, never `email`** ([ADR 0015](../adr/0015-identity-owns-person.md)).

**Accounts are created only by invitation.** There is no self-service registration: the Console is for Guardians, operators and administrators, invited by someone holding the capability. Members and volunteers are not Console users; their eventual access arrives through WordPress as a delegated flow ([ADR 0018](../adr/0018-client-and-delegated-authentication.md)). A separate email-verification flow is not built initially. Accepting an invitation *delivered to the address* is evidence the holder controls the mailbox, but an invitation handed over some other way (the administrator bootstrap prints its token to a server operator) is not, so `email_verified_at` is set only when there is such evidence, and acceptance alone never sets it ([ADR 0022](../adr/0022-password-policy-and-credential-handling.md)).

## Authentication

| Surface | What authenticates | How | What the platform trusts |
| --- | --- | --- | --- |
| **Guardian Console** | The human | Same-origin session cookie, `__Host-` prefixed, with CSRF | Only its own session record |
| **WordPress service client** *(later)* | The application | Client credential over TLS → service Actor | That the caller is the companion — nothing about any human |
| **WordPress delegated user** *(later)* | The application **and** a platform-issued person credential | Both proofs, independently verified | Never a client-asserted `person_id` |
| **External IdP** *(deferred)* | The human, at the provider | Platform is the relying party; verified subject links to an Account | The provider for authentication only, never authorization |

**Background jobs authenticate as nothing.** A job carries an explicit Actor captured at enqueue time, or runs as a declared system actor. Ambient "current user" inside a queued job is forbidden.

### Console topology

```
https://commons.flowlifeglobal.org/          → Console (static build)
https://commons.flowlifeglobal.org/api/v1/   → Platform API
```

Cookie: `__Host-` prefix, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, **no `Domain` attribute** (host-only). Sessions in the database. Request-forgery protection is Laravel 13's `PreventRequestForgery`: a request the browser marks `Sec-Fetch-Site: same-origin` may pass without a token, otherwise `X-XSRF-TOKEN` is verified, and `same-site` (WordPress) is never accepted as same-origin. The session middleware is a named `stateful` stack applied explicitly to the Console's session-authenticated routes; **the API as a whole stays stateless**, so public and future service endpoints acquire no browser session or CSRF requirement. The endpoints are `POST /api/v1/login`, `POST /api/v1/logout` and `GET /api/v1/me`. **No CORS for the Console** and **no Sanctum** (not installed); see [ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md) for the measured evidence that a compromised WordPress can neither receive nor shadow this cookie, and [deployment topology](deployment-topology.md) for the hosting requirements this implies.

### Session lifetime

| Bound | Value | Mechanism |
| --- | --- | --- |
| Sliding inactivity | 30 minutes | `SESSION_LIFETIME=30`; Laravel's `last_activity` handling is already a sliding timeout |
| Absolute | 12 hours | `authenticated_at` recorded at login; middleware invalidates past that age regardless of activity. Re-authentication starts a new absolute lifetime |

**The 30 minutes measure session request inactivity, not human idleness.** Any authenticated request refreshes `last_activity`, so a future background poll from the Console could keep a session alive with nobody at the keyboard. This is accepted because the 12-hour cap bounds the session independently of activity. Do **not** build browser activity tracking or other idle detection unless implementation shows a concrete need, and do not describe the 30-minute value as guaranteed human-idle detection.

Sessions are also invalidated on password reset (all) and password change (all but the current one). `expire_on_close` stays off. Step-up re-authentication for sensitive actions belongs with MFA, not here.

## Authorization

Capabilities and roles are code-defined enums in `Access\Application`; **the `role → capabilities` mapping is a method on the `Role` enum and exists nowhere else**. Only assignments persist. There is no `roles` table and no `role_capabilities` table ([ADR 0017](../adr/0017-capabilities-and-roles-in-code.md)).

Resolution: load the person's assignments → `Role::tryFrom(role_key)` → union capabilities → test membership. A `role_key` whose case no longer exists grants **nothing**.

`platform_administrator` resolves to every capability and is the only such role. There is no `is_admin` boolean. Gates and Policies are the enforcement edge and delegate to the Authorizer.

**Authorization is not approval** ([ADR 0009](../adr/0009-authorization-separate-from-approval.md)):

| Scenario | Authorization | Approval |
| --- | --- | --- |
| Volunteer submits an event | Holds `events.submit` | Item enters `submitted` |
| Guardian publishes it | Holds `events.publish` | Item must be `approved` |
| Board approves a new Guardian | An administrator assigns the role — a separate, audited act | The board's decision is a workflow record |

**Deactivation:** `accounts.status` (a VARCHAR backed by a PHP enum; a database `ENUM` would fail the portability guardrail) plus `disabled_at`. Disabling blocks authentication and invalidates sessions; the Person, their assignments and their history are untouched.

## Actor semantics

- Present on **authenticated requests only**. Public endpoints take no Actor; no anonymous Actor is fabricated, and the absence of one is not an authorization outcome.
- Carries **identity and provenance only**: `account_id`, `person_id`, `authenticated_via`. **Never a capability snapshot** — that is what would let a captured Actor preserve revoked privileges.
- Authorization is always evaluated against **current persisted state** at the moment of the check.
- **Background jobs** serialize only the identity, then re-resolve and re-authorize at execution, including re-checking that the account is still active. A job enqueued by someone since revoked or disabled fails at execution.
- The first epic ships one shape, `Actor::user()`. Service and delegated forms are designed but not built.

## Schema

ULIDs are `CHAR(26)`, application-generated. Timestamps UTC. No database `ENUM`, triggers or generated columns. Foreign keys follow [ADR 0021](../adr/0021-cross-module-referential-integrity.md): permitted across modules for fundamental invariants with `RESTRICT`, never `CASCADE`, and never for provenance or audit. No table prefixes.

**Identity**

| Table | Key fields | Keys and constraints |
| --- | --- | --- |
| `people` | ULID, `display_name`, timestamps | — |
| `accounts` | ULID, `person_id`, `email`, `email_canonical`, `email_verified_at`, `password_hash` (nullable), `password_updated_at`, `status`, `disabled_at`, `last_login_at` | FK `person_id → people.id` RESTRICT; `unique(email_canonical)`; `unique(person_id)` |
| `account_invitations` | ULID, `account_id`, `token_hash`, `expires_at`, `accepted_at`, `invited_by_account_id` | FK `account_id → accounts.id` RESTRICT; `unique(token_hash)`; no FK on `invited_by_account_id` (provenance) |
| `sessions` | framework; **`user_id` as `string(26)`, not `foreignId`** | index on `user_id`; no FK |
| `password_reset_tokens` | framework, transient | — |

**Access**

| Table | Key fields | Keys and constraints |
| --- | --- | --- |
| `role_assignments` | ULID, `person_id`, `role_key`, `granted_by_account_id`, `granted_at` | **FK `person_id → people.id` RESTRICT** (deliberate cross-module); `unique(person_id, role_key)`; no FK on `granted_by_account_id` |

**Audit**

| Table | Key fields | Keys |
| --- | --- | --- |
| `security_events` | ULID, `occurred_at`, `type`, `actor_account_id`, `actor_client_id`, `subject_person_id`, `subject_account_id`, `ip`, `user_agent`, `outcome`, `context` (JSON, stored but never queried into) | **No foreign keys at all** |

Designed but not built: `account_external_identities` (`unique(provider, subject)`), `api_clients`.

Identity's migrations must carry earlier timestamps than Access's, or the cross-module foreign key cannot be created.

## Credential lifecycles

| | **Invitation** | **Forgotten password** | **Authenticated change** |
| --- | --- | --- | --- |
| Mechanism | `account_invitations`: 32-byte random token, **stored hashed**, delivered once, and presented to the API in the request **body** (never a URL path) | Laravel's database token repository (the `PasswordBroker`'s storage primitive) over `password_reset_tokens`, behind Identity's own port | None — requires the current password |
| Expiry | 7 days (configurable) | 60 minutes (framework) | n/a |
| One-time | Yes, via `accepted_at`; revocable by deleting the row | Yes, token deleted on success | n/a |
| State | `invited` + null password → **`active`**, password set; `email_verified_at` stays null unless the invitation was delivered to the address *(see Phase 5 refinements)* | No status change; `password_updated_at` set | `password_updated_at` set |
| Sessions | **None created**: accepting sets the password and activates the Account, then the user performs the ordinary login, which establishes the session ([ADR 0022](../adr/0022-password-policy-and-credential-handling.md)) | **Invalidate all** | Invalidate others, keep current |
| Events | `account.invited`, `invitation.accepted`, `invitation.revoked` | `password.reset_requested`, `.reset_completed`, `.reset_failed` | `password.changed` |

Invitations do not use signed URLs: a signed URL cannot be revoked, is replayable until expiry, and leaves no auditable object. They do not reuse `password_reset_tokens` either — that broker is built for accounts that already have a password, and conflating the two is how "reset" quietly becomes an account-provisioning path.

Password reset must return a **uniform response** whether or not the address is known, and is rate-limited by IP *and* identifier.

## The last-administrator invariant

Spans two modules, enforced with a one-way dependency — **Access → Identity, never the reverse** ([ADR 0020](../adr/0020-administrator-bootstrap-and-last-administrator-invariant.md)).

- Identity owns account state, so Identity defines `AccountDeactivationGuard` and consults it inside `DisableAccount` and every future closure or anonymisation path.
- Access implements that interface and registers it from its own provider. Identity stays unaware of Access.
- Identity exposes `ActiveAccountQuery` so Access can evaluate "active administrator" without reading Identity's tables.
- Check and mutation run in one transaction with `SELECT … FOR UPDATE` over that role's assignment rows; otherwise two concurrent revocations can each see another administrator and both succeed.

## Auditing

`Audit\Application\RecordSecurityEvent` is called synchronously, inside the same transaction as the state change. Minimum events from day one: authentication succeeded/failed, logout, rate limit triggered; password set/changed/reset requested/reset completed; account invited, invitation accepted, account disabled/re-enabled, email changed; role granted/revoked; administrator bootstrap executed. Never record credentials, tokens or hashes. Event types are dotted lowercase names, `noun.verb` (for example `authentication.failed`, `session.absolute_expired`), which Identity and Access define and Audit stores as plain strings.

## Laravel mapping

| Facility | Decision |
| --- | --- |
| **Session guard**, `config/auth.php` | **Yes** — provider points at `Account`; lookups canonicalised |
| **Hashing** | **bcrypt**, with the framework hasher's `limit` at 72 bytes so it refuses rather than truncates ([ADR 0022](../adr/0022-password-policy-and-credential-handling.md)). argon2id is available in the dev container but is not guaranteed on cPanel; Laravel rehashes on login if we switch later |
| **Rate limiting** | **Yes** — per IP *and* identifier on login, reset and invitation acceptance; database cache store, no Redis |
| **Gates / Policies** | **Yes, as the enforcement edge only**, delegating to the Authorizer |
| **Sanctum** | **Not used.** Its SPA feature solves cross-origin statefulness, which same-origin removes. Revisit when API clients arrive; note its token table's morph columns default to bigint and need ULID-compatible types |
| **Fortify** | **Not adopted.** It *is* headless and SPA-compatible, and the Person/Account split is not a blocker — but its concentrated value is in features we have deferred (TOTP) or disabled (registration), while its cost lands on what we are building now: routes registered outside module-owned `routes.php`, response bindings for our JSON contracts, and OpenAPI entries for routes we do not control. Our endpoints call the same primitives it wraps |
| **Signed URLs** | **Not used in the first epic** — invitations carry their own revocable token, and email verification is deferred |

**Consequence of deferring Fortify:** its two-factor support is wired into its own login pipeline, so adopting it later for MFA means moving login onto Fortify then. The MFA follow-up should be scoped as a genuine fork — adopt Fortify, or add a TOTP library to our own flow.

## First implementation epic

### Build now

1. **Development topology first** — single-origin gateway routing, so authentication is developed against the production model from the first commit.
2. **Identity** — `people`, `accounts`, `account_invitations`; `Account` as `Authenticatable`; canonical-email lookup.
3. **Audit** — `security_events`, `RecordSecurityEvent` (before anything auditable exists).
4. **Session authentication** — the `stateful` session/CSRF stack on the Console's routes, database sessions, `__Host-` cookie, CSRF; `login`, `logout`, `me`; rate limiting; the 30-minute inactivity and 12-hour absolute bounds.
5. **Access** — `Capability` and `Role` enums, `role_assignments`, `Authorizer`, Gate wiring, `can:` middleware.
6. **Bootstrap** — administrator command, invitation acceptance, guard chain and last-administrator invariant.
7. **Password reset and change.**
8. **Role administration endpoints.**
9. **Guardian Console** — login, authenticated shell, logout, 401/403 handling.
10. **Guardrails** — architecture test that business modules never import Identity or Access `Domain`; OpenAPI entries; both engines green; update the CORS and e2e assertions displaced by the topology change.

### Design now, build later

External identities; `api_clients` and service authentication; delegated WordPress access; scoped assignments; runtime-editable roles; **MFA (early security follow-up, before privileged access expands substantially)**; standalone email verification; anonymisation and deletion; session listing and "sign out everywhere".

### Deferred completely

OIDC/OAuth provider role; external identity providers; WordPress member-facing authentication; passkeys; organization and partner identities; a generic policy engine; person-merge tooling; tamper-evident audit chaining.

## Implementation status

The epic is delivered in reviewable phases. This records what exists, and the small decisions taken while implementing that refine, but do not change, the design above.

**Phase 1 — done:** development topology (build step 1) and the Identity persistence foundation (part of step 2): `people`, `accounts`, `account_invitations`, `Account` as an `Authenticatable` model seam, canonical-email lookup. **Not built:** everything else in the epic, including any login, session, guard, authorization, bootstrap or password-reset behaviour.

Refinements made in Phase 1:

- **Layers.** `Domain` holds `Person`, `Account`, `AccountInvitation`, `EmailAddress`, `AccountStatus`, `InvitationToken`, the ports (`PersonRepository`, `AccountRepository`, `AccountInvitationRepository`) and their domain errors. `Infrastructure` holds the Eloquent records (`AccountRecord` is the `Authenticatable`), the repositories and `IdentityServiceProvider`. There is no `Application` or `Http` layer yet: the module map creates layers only when they have something to hold. `PersonId` and `AccountId` live in `Shared\Domain` as this design places them.
- **Statuses** are exactly `invited`, `active`, `disabled`. `Account` models their state and invariants (an invited account has no credential; an active one has one; a disabled one records when). The use cases that drive the transitions are later phases.
- **Email is ASCII-only.** `EmailAddress` accepts printable ASCII (internationalised domains as punycode) and canonicalises by trimming and lowercasing. This is what makes MariaDB and PostgreSQL identical: MariaDB's `utf8mb4_unicode_ci` also folds accents, so a non-ASCII address could collide there and not on PostgreSQL. No provider-specific rewriting (dots, `+tags`) is applied.
- **Instants are `DATETIME`, not `TIMESTAMP`.** Application code writes UTC; `DATETIME` has no implicit defaults or `ON UPDATE`, no dependence on the connection time zone, and no 2038 ceiling on MariaDB.
- **`created_at`/`updated_at`** exist on `people` and `accounts`, written from the domain rather than by Eloquent. `account_invitations` has none, matching the schema above; issue time is the `account.invited` security event's `occurred_at`.
- **`invited_by_account_id` is nullable**, because the bootstrap command (ADR 0020) issues an invitation with no inviting account. It has no foreign key (ADR 0021).
- **`account_invitations.account_id` is indexed explicitly** so both engines have the same index (PostgreSQL does not create one for a foreign key).
- **Invitation acceptance is not atomic in the repository.** Claiming an invitation under concurrent acceptance (lock, check, set) belongs to the acceptance use case, which owns the transaction.
- **A violated constraint aborts the surrounding transaction on PostgreSQL** (not on MariaDB). Repositories translate a unique violation into a domain error, and callers treat it as terminal for the transaction.

**Phase 2 — done:** the Audit seam (build step 3) and Guardian Console session authentication (step 4): `login`, `logout`, `me`, database sessions, the `__Host-` cookie, CSRF, rate limiting, and the 30-minute inactivity and 12-hour absolute bounds. **Not built:** Access, bootstrap, invitation acceptance, password reset and change, role administration, and the Console UI.

Refinements made in Phase 2:

- **No Sanctum, per the design.** The Laravel-mapping table and ADR 0016 say Sanctum is not used, so it is not installed and there is no `statefulApi()`. There is no stateful-host configuration to maintain either: the flow is same-origin.
- **The session middleware is a named `stateful` stack applied to the Console's routes, not to the whole `api` group.** Applied to the whole group, every API request would start a session, write a `sessions` row and set a cookie (including the public health endpoint), and every `POST` would need a CSRF token, which would contradict service clients that authenticate with a credential rather than a cookie ([ADR 0018](../adr/0018-client-and-delegated-authentication.md)). Cookie-authenticated routes join it with `->middleware('stateful')`. A test asserts that stateless endpoints get no cookie and no session row. This wording was clarified in [ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md).
- **`PreventRequestForgery` is the CSRF middleware.** In Laravel 13 `ValidateCsrfToken` is a deprecated alias of it. It validates the `X-XSRF-TOKEN` header against the session token, **and also accepts a request the browser marks `Sec-Fetch-Site: same-origin`** (a header page scripts cannot set). A sibling host such as WordPress arrives as `same-site`, which is deliberately not accepted (`allowSameSite` stays false), so it is refused unless it carries a valid token; the ADR records this ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)). Laravel skips CSRF entirely under PHPUnit, so the tests switch the environment to make it real.
- **Route paths** are `POST /api/v1/login`, `POST /api/v1/logout` and `GET /api/v1/me`: the bare names this design gives, with no prefix invented. A first `GET /me` (anonymous, `401`) is how a client obtains its session and `XSRF-TOKEN` cookies before signing in, so no Sanctum-style csrf-cookie endpoint is needed.
- **Middleware order is pinned:** session, CSRF, absolute lifetime, authentication. Laravel's priority list names the `AuthenticatesRequests` interface, not the `Authenticate` class; anchoring on the class silently appends to the end, which put authentication first. That would have refused an anonymous request before it was ever issued an `XSRF-TOKEN`, and let an expired session authenticate. A test resolves the real order.
- **The absolute lifetime fails safe.** `authenticated_at` is set at sign-in and never touched by requests. An authenticated session whose `authenticated_at` is missing, not an integer or in the future is treated as expired. Expiry ends the session, records `session.absolute_expired` (with a reason class) and the request continues anonymous.
- **Only an `active` Account with a credential may authenticate**, decided once, by `Account::canAuthenticate()`. The Laravel provider asks it, so a disabled Account stops resolving on its next request whatever its session says. The provider is the only place `email_canonical` is looked up for authentication.
- **Account enumeration.** Unknown address, wrong password, invited and disabled Accounts get one `401` body, and every path performs exactly one password check (against a throwaway hash when there is no eligible Account) so response time does not reveal which it was. A test counts the checks.
- **Rate limiting** is Laravel's limiter over the cache store: per source address (30 attempts per 15 minutes, successful or not) and per normalised identifier (5 failures per 15 minutes from anywhere, cleared by a success). Both are configurable (`config/identity.php`). The identifier counter treats known and unknown addresses identically. A caller can therefore lock an identifier out for one window: the accepted trade for a small invite-only user base. An engaged limit is audited once per minute per key, so an attacker cannot grow `security_events` by hammering a blocked endpoint. Behind a reverse proxy, configure trusted proxies or every request appears to come from the proxy.
- **Event types** (Identity owns the vocabulary, Audit stores strings): `authentication.succeeded`, `authentication.failed`, `authentication.logout`, `authentication.rate_limited`, `session.absolute_expired`. Outcomes are `success`, `failure` and `blocked`. A failed attempt records a reason *class* (`unknown_account`, `wrong_password`, `account_not_active`) and the identifier the caller **claimed** (a syntactically valid, canonicalised email); for a known Account the Account and Person are the *subject*, never the actor. No identity is invented for an unknown address. Audit additionally refuses secret-named context keys and secret-shaped values.
- **`me` and `login` return identity and session information only:** `account`, `person` and `session`. There is deliberately no `roles` or `capabilities` field: Access does not exist, and an empty or invented value would be a misleading contract. It is additive when Access arrives.
- **The `sessions` table** holds `user_id` as `string(26)` (a ULID, never `foreignId`), indexed, with no foreign key. An anonymous `GET /me` creates a session row, as any first request does in Laravel; the framework's lottery removes expired rows.
- **The session cookie is fixed in configuration**, not read from the environment. Chromium was measured to accept and return it unchanged on plain-HTTP `commons.flowlife.localhost`, so development does not differ from production ([docker](../development/docker.md#session-cookies-locally)).
- **Logout is idempotent** but requires CSRF like any `POST`: with no authenticated session it records nothing and answers `204`; a stale token gets `419`, and the client should treat itself as signed out and fetch a new token with `GET /me`.
- **Not done, on purpose:** rehash-on-login, "sign out everywhere", session listing, remember-me, and any browser activity tracking. Password verification uses `bcrypt` through Laravel's hasher.

**Phase 3 — done:** Access (build step 5): the code-owned catalog, `role_assignments`, the `Authorizer`, and the Laravel Gate edge. **Not built:** administrator bootstrap, the last-administrator guard, role grant/revoke (use cases and endpoints), and any authorization-decision auditing.

Refinements made in Phase 3:

- **Layers.** `Access\Domain`: `RoleAssignment` (with `role_key` a plain string, as the layer table requires) and its `RoleAssignmentRepository` port. `Access\Application`: `Capability`, `Role`, `Authorizer`, `AuthorizeAction`, `AccessDenied`. `Access\Infrastructure`: the query-builder repository, the Gate registration and `CapabilityGate`. There is no `Access\Http` yet: the `can:` middleware is Laravel's own, and no role-management endpoint exists.
- **Initial catalog** (minimal: a capability exists when something checks it). Capabilities: `console.access` (may use the Guardian Console) and `access.roles.assign` (may grant and revoke role assignments, the narrowest capability the administration workflow needs). Roles: `platform_administrator` (every capability, **derived from `Capability::cases()`**, so a new capability reaches it without editing the role, and it is the only role that does) and `guardian` (the Console's ordinary user: `console.access`). `guardian` is included because the design names Guardians as Console users and a role bundle of one capability is the smallest that lets "an assignment grants only its role's capabilities" be tested against a real narrower role. Nothing exists for events, CRM, membership or any other module that does not.
- **`role_assignments`**: `id`, `person_id`, `role_key`, `granted_by_account_id` (nullable), `granted_at`. FK `person_id -> people.id` RESTRICT (a deliberate cross-module invariant, ADR 0021; this migration therefore follows Identity's); `unique(person_id, role_key)`; **no** FK on `granted_by_account_id` (provenance). No `revoked_at`, soft delete, scope or history. No `roles` or `role_capabilities` table, no database ENUM. Query builder, no Eloquent model.
- **The decision path.** `Authorizer::allows(Actor, Capability)` (and `capabilitiesOf`, which it is defined in terms of): (1) re-resolve the Actor through Identity's `ResolveActor`, so an Account that can no longer authenticate has no authority; (2) use the person the Account belongs to *now*, and deny an Actor that disagrees; (3) read the person's assignments fresh on every call; (4) map each stored key through the catalog, ignoring any the catalog does not know (**fail closed**, without disturbing the person's valid roles); (5) default deny. Nothing is cached, snapshotted into the Actor or the session, or read from the client. A test asserts the stored session contains no capability or role data.
- **Gate as an edge.** One Gate ability per capability, generated from the enum (`can:console.access`); each delegates to `CapabilityGate`, which resolves the Actor through Identity and asks the `Authorizer`. The Gate defines no rule, has no ability named after a role, and denies guests. Business code calls `AuthorizeAction` (throws `AccessDenied`, rendered as `403`) or uses `can:`.
- **`/me` and login report `capabilities`** (identifiers, alphabetical, derived fresh on every request; never role names). Identity's HTTP layer serves `/me` but must not depend on Access, so Identity defines `EffectiveCapabilities` (opaque identifiers) and Access implements and registers it from its own provider: the same inversion as ADR 0020's `AccountDeactivationGuard`. Identity's default grants nothing, and a test asserts the platform registers Access's.
- **Revocation is deliberately not executable yet.** The port can read and add assignments and has no removal, because revoking a role must honour the last-active-administrator invariant and be audited, and both arrive with the administration phase. Tests set assignments up through the port and delete rows directly, exactly as a future use case's effect would look. Authorization decisions themselves are not audited (that would be enormous, low-value volume); grants and revocations will be, when they are executable.
- **Dev fixture.** `./flow test e2e` grants the seeded fixture account the `guardian` role through the port, since no grant use case exists. It is the one place outside Access that names `Role`, an explicit, narrow exception in the architecture test that should go when that use case exists.

**Phase 4 — done:** role mutation, the last-administrator invariant, account deactivation, and the administrator bootstrap. **Not built:** invitation acceptance (so a bootstrapped or invited administrator cannot yet set a password or sign in), password reset and change, role-management HTTP endpoints, and re-enabling an Account. Nothing here has an HTTP surface: the only new operational adapter is the console command.

Refinements made in Phase 4:

- **`GrantRole` and `RevokeRole`** (Access Application) require `access.roles.assign` from the real Authorizer, decided before their transaction opens. They take a `Role` from the catalog (never a string) and offer no way to skip authorization. **Idempotent**: granting a held role and revoking an absent one succeed as no-ops and record nothing; only a real change writes `role.granted` / `role.revoked`, in the same transaction as the assignment. `granted_by_account_id` is the acting Account. Not exposed over HTTP.
- **Active administrator** = holds `platform_administrator` **and** the Account can still authenticate (Identity's `Account::canAuthenticate`). An assignment row alone proves nothing: invited, disabled, account-less and wrongly-cased-key administrators do not count.
- **One authority, `AdministratorContinuity`**, for everything that can remove administrator authority: `RevokeRole` (for that role) and `DisableAccount` (through the guard below). Inside the caller's transaction it (1) takes `SELECT … FOR UPDATE` on every administrator assignment, ordered by id; (2) only then reads, because a locking read returns the latest *committed* state whereas a plain read on InnoDB returns the transaction's earlier snapshot, which is exactly the stale view the lock exists to prevent; (3) locks the surviving administrators' Accounts, ordered by id, so a survivor cannot be disabled while the decision stands. It refuses to run outside a transaction. Plain row locks: no advisory, PHP or cache locks, identical on MariaDB and PostgreSQL. A concurrent *grant* may cause a conservative refusal; it can never cause a wrong permit, because a grant only adds administrators. New migration: an index on `role_assignments.role_key`, so the lock is narrow on InnoDB.
- **Deactivation guard chain (ADR 0020).** Identity owns `AccountDeactivationGuard` and consults everything tagged `identity.account-deactivation-guards` inside `DisableAccount`, *before* the transaction touches anything else, so every path takes its locks in the same order. Access supplies `LastAdministratorDeactivationGuard`, which adds no rule of its own and asks `AdministratorContinuity`. Identity does not import Access.
- **`DisableAccount`** (Identity Application), one transaction: guards, re-read the Account **with a lock**, disable and save, **delete every session of the Account** (sessions are database rows, so revocation is a delete), record `account.disabled`. The Person, role assignments and history are untouched. Idempotent. It does **not authorize its caller** (Identity cannot ask Access); any future adapter must require an Access-defined capability first. Re-enabling is not built.
- **A defect found and fixed on the way (separate commit).** `AuthenticateAccount` read an Account, verified the password, then saved the *whole* aggregate back; a disable that committed in between was silently undone by the sign-in. It now re-reads under a lock inside its transaction and re-checks eligibility. Rule for later phases: **any flow that saves an Account it read earlier (invitation acceptance, password change or reset) must re-read it under a lock first.**
- **Bootstrap (`identity:create-administrator`).** The name is the frozen one, but the class is Access's (`Access\Infrastructure\Console`), because it needs both modules and only Access may depend on Identity. Identity provides `InviteAccount` (Person, *invited* Account, hashed single-use expiring invitation, `account.invited`), speaking in plain strings so no Identity Domain type crosses the boundary; Access's `BootstrapAdministrator` calls it and grants `platform_administrator`, in **one transaction**. Its root of trust is server access, so it is a separate use case and not a mode of `GrantRole`. It refuses if any administrator assignment exists unless it is an explicit recovery; the command adds the friction (interactive terminal, the operator typing the new address back) and the use case enforces the rule itself. Recovery creates a *new* Person and Account and never repurposes one: an address in use fails and changes nothing. No password is ever accepted. The invitation token is returned only in the result of a *committed* transaction, shown once, and is not stored, logged or audited. Not serialised against a second bootstrap at the same instant (no row to lock while none exists); the operator is trusted and the worst case is two audited administrators. Runbook: [administrator-bootstrap](../runbooks/administrator-bootstrap.md).
- **Events** added: `role.granted`, `role.revoked`, `account.disabled`, `account.invited`, `administrator.bootstrapped`. A bootstrap records `account.invited`, `role.granted` and `administrator.bootstrapped`. Only real state changes are recorded.
- **Invitation output.** The command prints the token, not a URL: acceptance is a later phase and no route exists to link to. The design does not use signed URLs, so none is invented.
- **`EffectiveCapabilities` stays presentation-only.** It is Identity's outbound port for the current-account projection: Access implements it, Identity's Domain never sees it, and its output is informational. Authorization never flows through it. An architecture rule pins the only classes that may use it.
- **Seeder exception removed.** The development seeder now asks Access's `ConsoleUserFixture` for "a Console user" and names no role. The `Role` architecture rule has no exception, and the role-key literal scan now covers `database/seeders`.

**Phase 5 — done:** the credential lifecycle: the password policy, invitation acceptance, forgotten-password reset, and authenticated change. **Not built:** role-management HTTP/UI, the Console login UI and shell, MFA, WordPress authentication, service identities and external identity providers. The policy and its reasoning are [ADR 0022](../adr/0022-password-policy-and-credential-handling.md); what follows records what exists and the small decisions taken that refine, but do not change, the design above.

Refinements made in Phase 5 (the policy, invitation acceptance, password recovery, and authenticated change):

- **One password policy, one normalisation boundary.** `Domain\PlainPassword` normalises to NFC and nothing else; `Application\PasswordPolicy` decides what is acceptable; `Application\PasswordHasher` is the only thing that hashes or verifies. Login, acceptance, reset and change all use them, so they cannot drift. 15 code points minimum, 72 bytes maximum, no composition rules, refused rather than truncated. A source scan pins that nothing else in Identity hashes a password.
- **Three lines of defence for the byte limit:** the policy, `PasswordHasher`, and `config/hashing.php`'s `limit` (the framework hasher throws rather than truncate). There was no `config/hashing.php` before, so `BCRYPT_ROUNDS` had been ignored and bcrypt ran on framework defaults; it is now real (and the test suite uses cost 4).
- **Verification is hardened too, not just hashing.** Measured on PHP 8.3.33: `password_verify` matches a candidate by its first 72 bytes and by what precedes a NUL byte. `PasswordHasher::matches` refuses both, so at sign-in `stored + anything` no longer verifies.
- **The breached-password check is a port that fails closed.** Laravel's `Password::uncompromised()` fails open (measured, and pinned by a test), so it is not used. `PwnedPasswordsRange` (Infrastructure, the only user of the HTTP client in Identity) sends only a five-character hash prefix. An outage, a bad status or an unintelligible answer is a retryable `503`, never "safe". It runs **before** any credential transaction, and only for a password that already passed the offline rules. `none` exists for development and tests and is refused by the container outside `local` and `testing`.
- **`POST /api/v1/invitations/accept`** takes `token`, `password`, `password_confirmation` in the body, on a **stateless** route (no session, cookie or CSRF: the caller has no account to forge a request as). It answers `204` and **does not sign the caller in**. The lifecycle is: invitation accepted → password established → Account activated → **no authenticated session** → the person performs the ordinary `POST /login` → session established. (The design's earlier wording, "new session on acceptance", is superseded.) The reasons, briefly: establishing a credential and authenticating are distinct operations; the ordinary login is the one canonical authentication path, so every session gets the same throttling, auditing and fixation handling; acceptance is stateless and need not touch browser session state; and nothing requires the convenience, so a one-time token is never made into a login credential.
- **One answer for every unusable invitation** (`422`, error on `token`): unknown, malformed, expired, used, revoked, or its Account not `invited`. The password is judged first, so a weak password gets the same answer whatever the token is.
- **The acceptance transaction** (after the breach check and hashing, so no network call and no slow work inside it) locks and re-reads the invitation, then locks and re-reads the Account, and requires both still usable: the Phase 4 rule applied. The Account must still be `invited`, so acceptance can never resurrect a disabled Account. Two simultaneous acceptances of one token cannot both succeed. There are two independent layers behind that (the invitation row lock with `accepted_at`, and the Account's state under its own lock), each tested; the domain's `activate` is a third.
- **`email_verified_at` means one thing:** the platform has evidence that the Account holder demonstrated control of that email mailbox. Choosing a password is not that evidence, and neither is holding a token. **Accepting the bootstrap invitation activates the Account and sets its password but leaves `email_verified_at` null**: its token is printed to a server operator, not delivered to the address. Accepting an invitation that was actually delivered to the address *may* establish it; nothing delivers invitations to an address yet, so nothing can, and `Account::activate` takes no flag for it (an unused parameter was removed at the start of Phase 6 rather than kept as speculative surface). Whatever builds that delivery introduces its own explicit operation for recording mailbox verification. There is no verification-provenance subsystem and no separate verification flow, the field is not renamed, and an unverified email does **not** stop an Account signing in (no frozen policy makes verification a condition of authentication). `invitation.accepted` records who issued the invitation (`issued_by`: `platform` or `account`). An earlier revision of this document (and ADR 0015) equated accepting any invitation with verifying the mailbox; that was too strong and is corrected.
- **A credential replaced mid sign-in is caught** (separate corrective commit): the sign-in's locked re-read now also requires the stored hash to be the one it verified.
- **Rate limiting** uses a small port, `AttemptThrottle`, with its own counters per action (`identity.credential_throttle`), so hammering one endpoint for an identifier cannot lock that identifier out of another. Acceptance is limited by source address only: the token carries 256 bits, so there is nothing to brute-force, only volume to bound. An engaged limit is recorded once per interval as `authentication.rate_limited` with an `action`.
- **Events** added: `invitation.accepted`. The context holds `issued_by` and nothing secret; Audit additionally refuses secret-named keys and secret-shaped values, and the classes that write these events cannot even name a `PlainPassword` or an `InvitationToken` (an architecture rule).
- **Not audited:** a failed acceptance. The frozen catalog has no event for it, the token is unguessable, and the route is rate limited.
- **Password recovery is two public, stateless endpoints:** `POST /api/v1/password/forgot` (`email`) and `POST /api/v1/password/reset` (`email`, `token`, `password`, `password_confirmation`). Neither has a session, cookie or CSRF token, neither signs anyone in, and secrets travel in the body.
- **Laravel's token repository, not its broker flow.** `DatabaseTokenRepository` provides the hashed tokens, expiry (60 minutes) and one-row-per-address rule over the new `password_reset_tokens` table (never the cache repository; no raw token is stored). The `PasswordBroker`'s `sendResetLink`/`reset` are not used: they own the flow (a callback, a status string, deleting the token outside the caller's transaction), and the use cases need the token check, the password change, the token deletion, the session deletion and the audit event in *one* transaction. The repository is reached only through Identity's `PasswordResetTokens` port, from one Infrastructure adapter; the Application layer names no framework class (an architecture rule).
- **The table is keyed by the canonical email.** Laravel keys a reset by the identifier it is given; `ResetSubject` gives it `email_canonical`, so the same address in any case is the same row on MariaDB and PostgreSQL. (`password_reset_tokens.email` therefore holds the canonical form, never the address as entered.)
- **Enumeration safety.** `forgot` answers `202` with one body for an active, an invited, a disabled and an unknown address, and holds every request to a minimum duration (`identity.password_reset.response_floor_ms`, default 1500): an address with an account does more work (a lock, a hash, an email) than one without, and answering as fast as the work allows would say which is which. Only an *eligible* Account (active, with a credential) gets a token. An invited Account is deliberately not eligible: it has no password to forget, and letting reset serve it would make "reset" an account-provisioning path around the invitation. **Residual:** if a mail transport ever takes longer than the floor, the difference is observable to someone timing it; set the floor above the slowest realistic send.
- **`reset` gives one answer for every failure** (`422` on `token`): an unknown address, an Account that cannot be reset, and a missing, wrong, used or expired token. Each does one bcrypt check, so timing says nothing either: the framework's `exists()` skips the hash when there is no row, so `AccountResetTokenRepository` (a small subclass) always runs one, against a throwaway hash when there is nothing to check.
- **The requests are serialised per Account.** The request transaction takes the Account's row lock first, so two simultaneous requests cannot both insert a token row (the second would otherwise fail on the primary key: a `500` that also reveals the address is real); the second waits and sees one was just issued. At most one message a minute reaches an Account.
- **The reset transaction** starts with the Account's lock (a plain read first would fix an InnoDB snapshot that a lock taken afterwards does not move past) and then re-checks eligibility and the token: the Phase 4 rule again. Two resets with one token serialise and the second finds it gone; a disable that commits first is seen and wins; nothing is resurrected. It then changes the password, deletes the token, deletes **every** session of the Account, and records `password.reset_completed`, atomically. The policy (including the breach check) and the hashing run first, so a refused password does not spend the token, and no network call happens inside.
- **Delivery.** The message is sent through Laravel's mail (Mailpit in development; production's transport is host configuration, no provider is baked in), **after** the transaction commits, synchronously (a queued message would put the raw token in the `jobs` table). It never throws: a delivery failure is logged without the link, and the public answer is unchanged. It is plain text and carries only the link and its lifetime: no identifier, status or name.
- **Reset link contract** (for the Console's reset page, a later phase): `<APP_URL>/reset-password#token=<token>&email=<address>`. The secrets are in the URL **fragment**, which a browser never sends to a server, so they stay out of access logs and `Referer` headers. The page reads `location.hash` and posts to `/api/v1/password/reset`.
- **Rate limiting** (`AttemptThrottle`, separate counters): requests are limited per source address and per identifier (defaults 10 and 3 per 15 minutes); completions likewise (20 and 10). They are separate so someone flooding requests for an address cannot stop its owner finishing a reset they already hold, nor signing in. As with login, a caller can exhaust an identifier's allowance for one window: the accepted trade for a small invite-only user base.
- **Events** added: `password.reset_requested` (success for an issued token; otherwise a failure with a reason class, `unknown_account`, `account_not_eligible` or `recently_requested`, and the claimed identifier, and no subject or actor invented for an unknown address), `password.reset_completed` (with how many sessions ended) and `password.reset_failed` (`invalid_token`, `account_not_eligible`, `unknown_account`). None carries a token.
- **Not done, on purpose:** pruning expired token rows (one row per address at most, replaced on the next request, and expired rows are refused); a delivery-failure event (the frozen catalog has none).
- **`POST /api/v1/password/change`** (`current_password`, `password`, `password_confirmation`) is on the **stateful** surface with `auth:web`: the session cookie, CSRF token and absolute-lifetime check all apply first, and any signed-in Account may use it (authentication is the whole requirement, plus the current password). It answers `204`.
- **The current password is the re-authentication control** until step-up authentication arrives with MFA. It is verified against the credential **as it is inside the transaction, under the Account's lock**, not as the session began: the transaction's first statement is the lock, then it requires the Account still able to sign in and still the Actor's Person (a disable that committed first is seen: `401`), then the current password, then the change. A reset or change that committed a moment earlier is honoured, not overwritten. The policy (with the breach check) and the hashing of the new password run first, outside it.
- **Sessions.** The Account's **other** sessions are deleted in the same transaction (`AccountSessions::revokeAllExcept`, an opaque id that is never recorded). After it commits the transport **rotates the current session**: a new id and CSRF token with the old row **destroyed**. The framework's `regenerate()` without `destroy` leaves the old row valid, so the id a thief may have copied would survive; `ConsoleSession::reauthenticate` passes `true`, and a test replays the old cookie. A wrong current password or a refused new password changes nothing, session included.
- **`authenticated_at` is restarted.** A fresh proof of the credential is a fresh authentication, and ADR 0016 already says re-authentication starts a new absolute lifetime, so the 12 hours run from the change (`GET /me` shows it). A wrong current password does **not** restart it. An attacker holding a session and the current password gains nothing by this: they already hold the credential.
- **Guessing the current password is rate limited** per Account and per source address (`password_change`: 5 per Account and 20 per address per 15 minutes by default), audited as `authentication.rate_limited` when engaged. Failed attempts are not audited individually (the frozen catalog has no event for one).
- **Event** added: `password.changed`, with the Account as actor and subject and how many other sessions ended. No password, hash or session id is in it.
- **Known residuals** (stated plainly rather than papered over):
  - **A sign-in can still outlive a reset by a hair.** The Phase 5 fix makes the sign-in's own transaction refuse a password that was replaced while it waited. But the transport issues the session cookie *after* that transaction commits; a reset that commits in that gap deletes the Account's sessions before this one exists, so it survives. It is reachable only by a caller who held the *old* password at that exact instant, and it is not closed here because closing it means binding a session to a credential version, which is more machinery than this exposure justifies. Nothing proves or disproves it deterministically.
  - **The response floor is a floor, not a guarantee.** If a mail transport ever takes longer than `identity.password_reset.response_floor_ms`, an address that has an account can be told from one that has not by timing alone.
  - **Failed attempts are not individually audited** for acceptance or a wrong current password: the frozen catalog has no event for them, the tokens are unguessable, and the routes are rate limited (an engaged limit is audited).
  - **The live breached-password service is not in the deterministic gate.** Development and CI use the no-op checker (`.env.example`), `./flow test e2e` refuses to run otherwise, and every backend test fakes the HTTP (a test forbids stray requests). The real adapter is tested against a fake alone and behind each endpoint that sets a password (clean, breached, 4xx, 5xx, malformed, empty, connection failure: each failure a `503` that changes nothing; call made outside the transaction; only the five-character prefix sent). A separate **manual** smoke test, `tests/Live` (`./flow test backend -- tests/Live`), checks the real service and skips, saying so, when offline. Validation was also run with the provider's hostname blocked inside the platform container.
  - **Expired reset-token rows are not pruned.** There is at most one row per address, replaced on the next request, and an expired row is refused.
- **Deviations from the frozen text**, each the design's intent kept rather than changed: acceptance creates no session ("new session on acceptance" became "the invitee then signs in", above, and is now the design); acceptance does not set `email_verified_at` (ADR 0015's "accepting an invitation proves control of the address" is clarified); the reset uses Laravel's token repository rather than the `PasswordBroker`'s flow (above); the ADR's "password set" event is `invitation.accepted` (one act, one event); and the invitation-token delivery is described as body-presented rather than "in the URL" (the API takes it in the body; how the invitee is *given* it remains the issuing channel's business).
