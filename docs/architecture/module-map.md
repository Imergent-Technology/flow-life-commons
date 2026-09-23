# Module map

The platform is a **modular monolith** ([ADR 0001](../adr/0001-modular-monolith.md)): one deployable Laravel application whose code is organised into modules with explicit boundaries.

## Layout

```
apps/platform/app/
  Modules/
    <Module>/
      Domain/           business rules, entities, value objects (Eloquent is fine here)
      Application/      use cases; the module's public entry points for other code
      Infrastructure/   integrations, persistence details, external APIs
      Http/             controllers, requests/resources, routes.php
  Shared/               intentionally tiny cross-module kernel (today: the ULID identifiers, and Actor)
  Providers/            Laravel service providers
```

Create a layer folder only when it has something to hold; do not scaffold empty folders. Eloquent and Laravel facilities are fine everywhere. The point of the boundaries is that **controllers, jobs, HTTP endpoints, framework plumbing and external APIs never become the place where business rules live.**

## Dependency rules (enforced by architecture tests)

- `Domain` depends on neither `Application`, `Infrastructure` nor `Http`.
- `Domain` and `Application` do not use HTTP transport (`Illuminate\Http`, `Illuminate\Routing`, `Route`).
- `Application` and `Infrastructure` do not depend on `Http`.
- HTTP client libraries are used only in `Infrastructure`.
- A module does not use another module's `Domain`, `Infrastructure` or `Http`. Collaboration goes through the other module's `Application` layer.
- `Shared` never depends on a module.

These are a starting point. Change them by ADR when a real need appears, and update `tests/Architecture/`.

## Routes

Each module owns its endpoints in `Http/routes.php`. `routes/api.php` loads every `app/Modules/*/Http/routes.php` under the `/api/v1` prefix, so adding a module never edits a central route list. Every route must also be described in `openapi/openapi.yaml`; a test fails if they drift ([ADR 0007](../adr/0007-versioned-rest-api-openapi.md)).

## Current modules

| Module | Purpose | Notes |
| --- | --- | --- |
| `Health` | `GET /api/v1/health`: infrastructure verification | Operational, not a business module. Exists to prove the environment and to give the conventions something real to test against |
| `Access` | `role_assignments`: the platform-owned authorization mechanism, role mutation and the last-administrator invariant | `Domain` (`RoleAssignment`, its port), `Application` (`Capability`, `Role`, `Authorizer`, `AuthorizeAction`, `GrantRole`, `RevokeRole`, `AdministratorContinuity`, `BootstrapAdministrator`, plus the operator-administration use cases below), `Infrastructure` (repository, Gate registration, the `identity:create-administrator` command). `Http`: the operator-administration surface under `/api/v1/admin` — listing/showing accounts and roles, inviting and re-inviting operators, enabling/disabling accounts, resetting MFA, and granting/revoking role assignments — behind `auth:web`, `can:console.access`, per-operation capability checks and `security.verified` step-up on mutations (ADR 0024). Other modules consume it only through `Application`, and ask for a `Capability`: `Role` is internal to Access |
| `Audit` | `security_events`: the append-only record of identity- and access-relevant occurrences | `RecordSecurityEvent` is its only public entry point. No update, delete, read or UI paths exist (ADR 0019) |
| `Security` | The browser security policy the production origin serves under (ADR 0026), and the production-readiness check. Operational, not a business module, like Health: it owns no table, no entity and no lifecycle, and no other module depends on it. `Application` (`BrowserSecurityPolicy`, `ProductionReadiness`), `Http` (the global header middleware), `Infrastructure/Console` (`security:headers`, `security:production-check`). The policy itself is `config/security.php`, single-sourced: Laravel applies it to every response, and the command generates the block the web server needs for the Console's static files |
| `Release` | Which release this deployment is running (ADR 0027). Operational, not a business module, like Health and Security: no table, no entity, no lifecycle, nothing depends on it, and deliberately **no `Http` layer**, because release identity stays off the public surface. `Application` (`ReleaseIdentity`, which reads and validates the `release.json` beside `artisan`), `Infrastructure/Console` (`release:show`) | Fail-closed: a manifest that cannot state an identity field is an error, never "unknown". Derives nothing from git |
| `Identity` | `people`, `accounts`, `account_invitations`, `sessions`, `account_totp_factors`, `account_recovery_codes`, `password_reset_tokens`: the registry of humans and their means of authenticating | All four layers exist. `Application`: `AuthenticateAccount`, `LogOut`, `ExpireSession`, `ResolveActor`, `GetCurrentAccount`, `DisableAccount`, `EnableAccount`, `InviteAccount`, `RegisterPerson`, `AcceptInvitation`, `ChangePassword`, `RequestPasswordReset`, `ResetPassword`, the TOTP enrollment/replacement and recovery-code use cases, the `LoginThrottle`, `AccountDeactivationGuard`, `ActiveAccountQuery` and `AccountSessions` ports. `Http`: `login`, `logout`, `me`, `invitations/accept`, `password/forgot`, `password/reset`, `password/change`, the MFA challenge/enrollment/authenticator/recovery-codes endpoints, `security/verify`, and the absolute-lifetime/second-factor/security-generation middleware. `Infrastructure` also holds the Laravel auth user provider and the cache-backed throttle. `Identity\Application\PersonExists` exists; `InviteAccount` (Person + Account together) and `RegisterPerson` (Person alone, no Account) are now both Person-creation paths |
| `Membership` | `membership_grants`: time-bounded membership access grants, the temporal derivation, and the grant/revoke/query use cases (ADR 0028) | `Domain` (`MembershipGrant`, `MembershipGrantId`, `MembershipGrantSource`, `MembershipState`, `MembershipGrantRepository`), `Application` (`GrantMembershipAccess`, `RegisterPersonWithMembershipAccess`, `RevokeMembershipGrant`, `GetMembershipRecord`, `ListMembershipRecords`), `Infrastructure` (`DatabaseMembershipGrantRepository`, `MembershipServiceProvider`). **No `Http` yet**: this is the backend only. Depends only on `Access\Application` (authorization) and `Identity\Application` (Person operations); no direct `Audit` dependency |

## Built from the design

The Identity and Access design ([identity-and-access.md](identity-and-access.md)) defined three modules and their dependency direction. `Identity`, `Audit` and `Access` now exist and are running in production (above).

| Module | Owns | Depends on |
| --- | --- | --- |
| `Identity` | The authoritative registry of humans (`people`) and their means of authenticating (`accounts`, `account_invitations`, `sessions`, `account_totp_factors`, `account_recovery_codes`, `password_reset_tokens`) | `Audit`, `Shared` |
| `Access` | Authorization: capabilities, roles and `role_assignments` | `Identity`, `Audit`, `Shared` |
| `Audit` | Append-only `security_events` | `Shared` |

The direction is **Access → Identity → Audit → Shared** and stays acyclic. `App\Shared\Domain` holds `Actor` for a specific reason: Identity records events through Audit, so placing `Actor` in `Identity\Application` would make Audit import Identity and close a cycle.

Note for module authors: a module's `Domain` may not import its own `Application`, so anything other modules must name — `Capability`, for instance — belongs in `Application`, and so does everything that consumes it. This is enforced by `tests/Architecture/ModuleBoundariesTest.php`.

## `Membership`: backend built, no surface yet

The Membership Foundation design gate is complete ([ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md), [ADR 0029](../adr/0029-commerce-providers-own-payment-facts.md)) and its backend now exists (above, "Current modules"). **Not built:** any HTTP/admin surface, the Guardian Console Membership UI, WordPress integration, and Zeffy/Luma automation.

**Membership has no direct `Audit` dependency.** Access and Identity each depend on Audit, but that is *their* dependency: calling a module does not make you depend on what it calls. The chain must not be written as `Membership → Access → Identity → Audit → Shared`, which reads as though it did — an architecture test (`MembershipBoundariesTest`) pins the absence of that edge.

The module's own rules, which are the ordinary ones stated above applied to this case:

- Nothing outside Membership queries `membership_grants` or uses `Membership\Domain`.
- Membership does not query `people`, `accounts` or `role_assignments` directly, does not own Person persistence, and never names a role key.
- `RegisterPerson` — the use case that creates a Person with no Account — belongs to **`Identity`**, not Membership, so Identity remains the only module that creates People ([ADR 0015](../adr/0015-identity-owns-person.md), [ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md)). `Membership\Application\RegisterPersonWithMembershipAccess` calls it and holds only the resulting `PersonId` and display name afterward, never `Identity\Domain\Person` itself.
- `Membership\Application\RegisterPersonWithMembershipAccess` orchestrates *register a Person and grant membership* in one transaction, the way `Access\Application\BootstrapAdministrator` calls `Identity\Application\InviteAccount` inside one: if the grant fails, the new Person rolls back with it.

## Candidate modules (provisional, none created)

Working titles to frame discussion. **They are not commitments and no folders exist for them.** Each is created when its epic starts, and its boundaries are decided then.

| Candidate | Likely concern |
| --- | --- |
| Volunteering | Volunteer records and lifecycles |
| CRM | Rich contact data keyed by `person_id`, which Person deliberately does not carry ([ADR 0015](../adr/0015-identity-owns-person.md)) |
| Events, Publishing | Later product domains |
| Workflow | Approval/workflow, separate from authorization ([ADR 0009](../adr/0009-authorization-separate-from-approval.md)) |

## Adding a module

1. Create `app/Modules/<Name>/` with only the layers you need.
2. Put routes in `Http/routes.php` and describe them in `openapi/openapi.yaml`.
3. Add migrations under `database/migrations` following [coding standards](../development/coding-standards.md) (ULID keys, UTC, portable schema).
4. Run `./flow check`. The architecture tests discover the module automatically.
