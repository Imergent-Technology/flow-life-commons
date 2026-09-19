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
  Shared/               intentionally tiny cross-module kernel (does not exist yet)
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

## Candidate modules (provisional, none created)

Working titles to frame discussion. **They are not commitments and no folders exist for them.** Each is created when its epic starts, and its boundaries are decided then.

| Candidate | Likely concern |
| --- | --- |
| Identity | Platform identities and their links to external identities (WordPress users, etc.) |
| Access | Authorization: roles, permissions, policies ([ADR 0008](../adr/0008-platform-owned-authorization.md)) |
| Membership, Volunteering | Member and volunteer records and lifecycles |
| Events, Publishing | Later product domains |
| Workflow | Approval/workflow, separate from authorization ([ADR 0009](../adr/0009-authorization-separate-from-approval.md)) |
| Audit | Durable audit trail |

## Adding a module

1. Create `app/Modules/<Name>/` with only the layers you need.
2. Put routes in `Http/routes.php` and describe them in `openapi/openapi.yaml`.
3. Add migrations under `database/migrations` following [coding standards](../development/coding-standards.md) (ULID keys, UTC, portable schema).
4. Run `./flow check`. The architecture tests discover the module automatically.
