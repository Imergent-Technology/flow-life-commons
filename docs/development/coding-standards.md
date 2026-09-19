# Coding standards

Only what is specific to this codebase. Generic advice ("write clean code") is not repeated. Formatters and linters are the arbiter: run `./flow check`.

## Style and tooling

- **PHP:** Laravel Pint with the `laravel` preset plus `declare_strict_types`. Larastan at level `max`, tests included. Every PHP file under `app/` declares `strict_types`.
- **TypeScript/React:** strict `tsconfig`, ESLint `strictTypeChecked`, Prettier (no semicolons, single quotes, 100 columns, Tailwind class sorting).
- Match the surrounding code. Comments explain *why*, not what.

## Structure

- Follow the [module map](../architecture/module-map.md). Business rules live in `Domain`/`Application`, never in controllers, jobs or routes files.
- Controllers are thin: validate, call an `Application` use case, shape the response.
- Do not add layers, base classes or `Shared` code for hypothetical reuse. Wait for a second real consumer.
- Modules do not reach into each other; go through the other module's `Application` layer.
- External calls (HTTP, mail providers, APIs) belong in `Infrastructure`, never inside a database transaction, and background work must be idempotent.

## Database

- Use the schema builder and Eloquent/query builder. **No** `enum` columns, `set`, generated columns, triggers, procedures, raw DDL or MariaDB-only SQL without an ADR and a `// portability-exception: ADR-NNNN` marker. The portability test enforces this.
- Aggregate primary keys are application-generated ULIDs ([ADR 0006](../adr/0006-ulid-identifiers.md)). Do not use auto-increment IDs for aggregates.
- Store timestamps in UTC. Do not rely on case-insensitive collation behaviour; normalise in code.
- Never edit a migration that has been merged; add a new one.
- Use `config()` in app code, never `env()` (tests enforce this).

## API

- Under `/api/v1`, described in `apps/platform/openapi/openapi.yaml` in the same change as the route (a test enforces it).
- Authorization is decided server-side on every endpoint; UI visibility is never security. New endpoints must state their authorization; public ones need a reason.

## Frontend

- No hand-written API calls beyond the marked-temporary health call; a generated client replaces them ([ADR 0007](../adr/0007-versioned-rest-api-openapi.md)).
- Never put secrets in `VITE_*` variables: they are public in the bundle.
- Use Tailwind utilities; customise through CSS (`@theme`), not a `tailwind.config.js` ([ADR 0012](../adr/0012-tailwind-4-via-vite.md)).

## Secrets and debugging

- No secrets in the repo, tests or fixtures ([secrets](../security/secrets.md)).
- No `dd()`, `dump()`, `var_dump()`, `console.log` left behind (PHP ones fail the architecture tests).
