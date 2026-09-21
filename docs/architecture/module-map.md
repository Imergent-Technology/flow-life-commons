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
| `Access` | `role_assignments`: the platform-owned authorization mechanism, role mutation and the last-administrator invariant | `Domain` (`RoleAssignment`, its port), `Application` (`Capability`, `Role`, `Authorizer`, `AuthorizeAction`, `GrantRole`, `RevokeRole`, `AdministratorContinuity`, `BootstrapAdministrator`), `Infrastructure` (repository, Gate registration, the `identity:create-administrator` command). No `Http` yet. Other modules consume it only through `Application`, and ask for a `Capability`: `Role` is internal to Access |
| `Audit` | `security_events`: the append-only record of identity- and access-relevant occurrences | `RecordSecurityEvent` is its only public entry point. No update, delete, read or UI paths exist (ADR 0019) |
| `Security` | The browser security policy the production origin serves under (ADR 0026), and the production-readiness check. Operational, not a business module, like Health: it owns no table, no entity and no lifecycle, and no other module depends on it. `Application` (`BrowserSecurityPolicy`, `ProductionReadiness`), `Http` (the global header middleware), `Infrastructure/Console` (`security:headers`, `security:production-check`). The policy itself is `config/security.php`, single-sourced: Laravel applies it to every response, and the command generates the block the web server needs for the Console's static files |
| `Identity` | `people`, `accounts`, `account_invitations`, `sessions`: the registry of humans and their means of authenticating | All four layers exist. `Application`: `AuthenticateAccount`, `LogOut`, `ExpireSession`, `ResolveActor`, `GetCurrentAccount`, `DisableAccount`, `InviteAccount`, the `LoginThrottle`, `AccountDeactivationGuard`, `ActiveAccountQuery` and `AccountSessions` ports. `Http`: `login`, `logout`, `me` and the absolute-lifetime middleware. `Infrastructure` also holds the Laravel auth user provider and the cache-backed throttle. Invitation acceptance and password reset are later phases |

## Designed, not yet created

The Identity and Access design ([identity-and-access.md](identity-and-access.md)) defines three modules and their dependency direction. `Identity`, `Audit` and `Access` now exist (above).

| Module | Owns | Depends on |
| --- | --- | --- |
| `Identity` | The authoritative registry of humans (`people`) and their means of authenticating (`accounts`, `account_invitations`) | `Audit`, `Shared` |
| `Access` | Authorization: capabilities, roles and `role_assignments` | `Identity`, `Shared` (built); `Audit` arrives with the grant/revoke use cases |
| `Audit` | Append-only `security_events` | `Shared` (built) |

The direction is **Access → Identity → Audit → Shared** and must stay acyclic. `App\Shared\Domain` gains its first inhabitant, `Actor`, for a specific reason: Identity records events through Audit, so placing `Actor` in `Identity\Application` would make Audit import Identity and close a cycle.

Note for module authors: a module's `Domain` may not import its own `Application`, so anything other modules must name — `Capability`, for instance — belongs in `Application`, and so does everything that consumes it. This is enforced by `tests/Architecture/ModuleBoundariesTest.php`.

## Candidate modules (provisional, none created)

Working titles to frame discussion. **They are not commitments and no folders exist for them.** Each is created when its epic starts, and its boundaries are decided then.

| Candidate | Likely concern |
| --- | --- |
| Membership, Volunteering | Member and volunteer records and lifecycles |
| Events, Publishing | Later product domains |
| Workflow | Approval/workflow, separate from authorization ([ADR 0009](../adr/0009-authorization-separate-from-approval.md)) |

## Adding a module

1. Create `app/Modules/<Name>/` with only the layers you need.
2. Put routes in `Http/routes.php` and describe them in `openapi/openapi.yaml`.
3. Add migrations under `database/migrations` following [coding standards](../development/coding-standards.md) (ULID keys, UTC, portable schema).
4. Run `./flow check`. The architecture tests discover the module automatically.
