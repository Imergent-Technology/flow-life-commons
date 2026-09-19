# ADR 0002: Laravel on PHP 8.3 for the platform

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

The platform must run on shared cPanel hosting, where PHP is the realistic runtime and we cannot run Node services, long-running workers or containers. We want a mature, conventional framework with strong tooling for testing, queues, migrations and security defaults.

## Decision

Use **Laravel 13 on PHP 8.3** (`composer.json` pins `platform.php` to 8.3.0 so dependency resolution matches the host). Use Laravel conventionally (Eloquent, queues, scheduler, validation, policies later) rather than hiding it behind abstractions. The application is an **API-only** Laravel app: the skeleton's Blade, Vite and Node tooling are removed so the backend has no Node dependency at all.

## Consequences

- Runs on commodity PHP hosting; deployment is "copy files, run migrations".
- Large ecosystem and a well-known structure for new contributors.
- We accept framework upgrade work over time (Laravel major versions).
- Some skeleton defaults were removed or overridden on purpose: no default `users` table/model (identity is designed in its own epic), no SQLite, `SESSION_DRIVER=file`.
- Laravel merges its default database connections back into config, so restricting engines is enforced in tests, not by deleting config ([ADR 0014](0014-testing-and-database-compatibility-strategy.md)).

## Alternatives considered

- **Symfony:** excellent, but less turnkey for queues/scheduler/testing ergonomics we want, and a smaller pool of familiar developers here.
- **Node/TypeScript backend:** one language across the stack, but it cannot run on the production host.
- **Go/other compiled services:** not deployable to shared hosting.
- **Headless WordPress as the platform:** rejected in [ADR 0004](0004-wordpress-adapter-not-authority.md).
