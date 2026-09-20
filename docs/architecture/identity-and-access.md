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

**Accounts are created only by invitation.** There is no self-service registration: the Console is for Guardians, operators and administrators, invited by someone holding the capability. Members and volunteers are not Console users; their eventual access arrives through WordPress as a delegated flow ([ADR 0018](../adr/0018-client-and-delegated-authentication.md)). Because accepting an invitation proves control of the address, no separate email-verification flow is needed initially.

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
| Mechanism | `account_invitations`: 32-byte random token, **stored hashed**, sent once in the URL | Laravel `PasswordBroker` + `password_reset_tokens` | None — requires the current password |
| Expiry | 7 days (configurable) | 60 minutes (framework) | n/a |
| One-time | Yes, via `accepted_at`; revocable by deleting the row | Yes, token deleted on success | n/a |
| State | `invited` + null password → **`active`**, password set, `email_verified_at` set | No status change; `password_updated_at` set | `password_updated_at` set |
| Sessions | New session on acceptance | **Invalidate all** | Invalidate others, keep current |
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
| **Hashing** | **bcrypt.** argon2id is available in the dev container but is not guaranteed on cPanel; Laravel rehashes on login if we switch later |
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
