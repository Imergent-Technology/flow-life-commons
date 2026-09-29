# Architecture charter

Flow Life Global is building a long-lived organizational platform for **members, volunteers, Guardians**, and eventually partners and other audiences. This charter states the direction and the rules that every later change is measured against. Decisions and their alternatives live in the [ADRs](../adr/README.md); this page is the summary.

## Direction

- The **platform** (`apps/platform`) is authoritative for organizational data, identity relationships, authorization, workflows and business rules.
- **Member and volunteer self-service is a Commons-hosted surface**, on the same origin and session model as the Guardian Console, distinct from it ([ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md); design in [member access](member-access.md); the Member foundation is built). WordPress hosts the public site and links into Commons; it is a thin companion plugin (`apps/wordpress-companion`) away from doing anything else, and is an adapter and presentation surface, never a source of truth ([ADR 0004](../adr/0004-wordpress-adapter-not-authority.md)). This supersedes this charter's earlier direction that member/volunteer experiences would be exposed *substantially through WordPress*.
- Guardian operations are exposed through a separately hardened **Guardian Console** (`apps/guardian-console`).
- WordPress must be replaceable or supplementable later without redesigning the platform core.

## Settled technology

| Concern | Decision | ADR |
| --- | --- | --- |
| Backend | Laravel 13, PHP 8.3, modular monolith | [0001](../adr/0001-modular-monolith.md), [0002](../adr/0002-laravel-php-platform.md) |
| API | REST/JSON, versioned from `/api/v1`, OpenAPI contract | [0007](../adr/0007-versioned-rest-api-openapi.md) |
| Guardian Console | React 19, TypeScript, Vite | [0003](../adr/0003-react-guardian-console.md) |
| Styling | Tailwind CSS 4 via `@tailwindcss/vite` | [0012](../adr/0012-tailwind-4-via-vite.md) |
| Database | MariaDB 10.11 first; PostgreSQL portability is a hard requirement | [0005](../adr/0005-mariadb-with-postgresql-portability.md) |
| Identifiers | Application-generated ULIDs | [0006](../adr/0006-ulid-identifiers.md) |
| Queues | Database-backed; Redis-ready, never required | [0010](../adr/0010-database-queue-redis-ready.md) |
| Dev environment | Docker Compose, `./flow` CLI | [0011](../adr/0011-docker-compose-development-environment.md) |
| Repository | Monorepo | [0013](../adr/0013-monorepo.md) |
| Identity | Person (the human) separate from Account (sign-in); Identity is the authoritative human registry | [0015](../adr/0015-identity-owns-person.md) |
| Console authentication | Same-origin, host-only `__Host-` session cookie; database sessions; 30-minute inactivity and 12-hour absolute bounds | [0016](../adr/0016-guardian-console-same-origin-session-authentication.md) |
| Authorization | Capabilities and roles defined in code; only assignments persist | [0017](../adr/0017-capabilities-and-roles-in-code.md) |
| Referential integrity | Cross-module foreign keys permitted for fundamental invariants, `RESTRICT` never `CASCADE` | [0021](../adr/0021-cross-module-referential-integrity.md) |

## Production constraint

Production initially runs on **shared cPanel hosting** ([deployment topology](deployment-topology.md)). Production must not depend on permanent worker processes, Redis, Docker, Node.js or long-running daemons. Queues are therefore intended to be drained from the scheduler tick (cron) rather than by a resident worker, the Guardian Console ships as static files, and Docker exists for development only. Development may provide richer infrastructure than production, but nothing may be built that *only* works with it.

How a release reaches that host — immutable release directories behind a `current` symlink, artifacts built off-host from an exact tag, migrations inside a short maintenance window — is decided in [ADR 0027](../adr/0027-release-and-deployment-model.md). The procedure has been **exercised successfully, manually, end to end, on the real host, and repeated since** ([deployment runbook](../runbooks/deployment.md#11-later-deployments), [production readiness](../runbooks/production-readiness.md)); a release is not the same thing as a deployment, and not every release is deployed ([release vs deployment](../runbooks/deployment.md#release-is-not-deployment)). Host-side deployment stays a manual, explicit operator procedure — there is no automatic deploy and no `./flow` command holding production credentials — until a dedicated deployment-automation design package changes that, which is a reasonable future project but is not authorized; outbound mail authentication remains open.

## Foundational rules

Status: **Encoded** = a test, config or tool fails when broken; **Partial** = enforced in part; **Documented** = a convention until there is code to enforce it.

| # | Rule | Status | Where |
| --- | --- | --- | --- |
| 1 | WordPress is an adapter, not an authority. | Documented | ADR 0004; the plugin skeleton contains no logic |
| 2 | Platform identity, authorization, organizational roles and business data belong to the platform. | Documented | ADR 0008 |
| 3 | The application is a modular monolith. | Encoded | `tests/Architecture/ModuleBoundariesTest.php` |
| 4 | Modules own their business rules and writes. | Partial | Modules cannot use each other's Domain/Infrastructure/Http (architecture test); "writes only via the owner" needs review discipline |
| 5 | No cross-module direct model/table mutation. | Partial | As above |
| 6 | Authorization happens server-side; UI visibility is never security. | Encoded | `Authorizer`, capability route middleware (`can:`) and tests. See [authorization model](../security/authorization-model.md) |
| 7 | Authorization and approval/workflow are separate concepts. | Documented | ADR 0009 |
| 8 | External integrations live behind explicit application/infrastructure boundaries. | Partial | HTTP clients (`Http` facade, `Illuminate\Http\Client`, Guzzle) are forbidden outside a module's `Infrastructure` (architecture test) |
| 9 | No external network calls inside database transactions. | Documented | [Integration model](integration-model.md) |
| 10 | Background jobs are eventually idempotent and retry-safe. | Documented | [Integration model](integration-model.md); no jobs exist yet |
| 11 | Caches are never authoritative storage. | Documented | [Data ownership](data-ownership.md) |
| 12 | Application code does not depend on Redis unless its semantics are genuinely required. | Encoded | The default stack has no Redis (an optional, unused profile only); a test fails if `.env.example` points queue/cache/session at Redis |
| 13 | MariaDB/PostgreSQL portability is actively protected; no DB-specific enums, triggers, procedures, proprietary SQL or generated columns without an ADR. | Encoded | Portability scan (`DatabasePortabilityTest`), tests refuse non-MariaDB/PostgreSQL drivers, PostgreSQL run of the suite in `./flow check --pgsql` and CI |
| 14 | Persisted timestamps are UTC. | Encoded | `config/app.php`, MariaDB and PostgreSQL connection time zones, dev MariaDB `--default-time-zone=+00:00`, config test |
| 15 | Secrets are never committed. | Partial | `.gitignore` excludes `.env*` (except `*.example`); examples hold placeholders only. No secret scanner yet. See [secrets](../security/secrets.md) |
| 16 | Sensitive actions require durable auditing. | Encoded | `RecordSecurityEvent`, written synchronously in the same transaction as the change. See [authorization model](../security/authorization-model.md) |
| 17 | No speculative shared abstractions or frameworks before real consumers exist. | Documented | Review discipline; `Shared` is deliberately tiny (the ULID identifiers and `Actor`, each with real consumers on day one) and never depends on a module |

Other rules that *are* encoded: no `env()` outside config, no debug or dangerous functions, `declare(strict_types=1)` throughout `app/`, and Larastan at level `max`.

## Identifiers

Platform aggregate identifiers are **application-generated ULIDs** unless a future ADR establishes a reason otherwise ([ADR 0006](../adr/0006-ulid-identifiers.md)). `Person`, `Account`, `AccountInvitation`, `RoleAssignment`, `SecurityEvent` and the other Identity/Access/Audit aggregates all demonstrate this in code; framework-owned infrastructure tables (cache, jobs) keep Laravel's defaults.

## Domain events and transactional outbox (direction)

Meaningful domain/application events and a **transactional outbox** are expected architectural primitives: state changes and the events describing them commit atomically, and a separate relay delivers them to consumers (jobs, integrations, WordPress notifications) with retry safety. **Nothing is built yet**; the first real cross-module or external consumer will drive the design. See the [integration model](integration-model.md).

## Auditing

Sensitive actions and privileged access require **durable audit records** (who, what, when, from where, on whose authority). The seam is built: a small `Audit` module owning an append-only `security_events` table, written synchronously inside the same transaction as the change it records ([ADR 0019](../adr/0019-security-event-auditing-seam.md)). Identity and Access events are audited today — authentication, MFA, invitations, password lifecycle, role grant/revoke and operator administration. Tamper-evidence, retention and review tooling remain undesigned.

## Identity and Access

The design gate is complete and recorded in [ADRs 0015–0021](../adr/README.md), with the operative reference in [identity-and-access.md](identity-and-access.md). **Identity and Access are implemented and running in production**: password login, invitations, password reset/change, sessions, TOTP MFA with recovery codes, administrator bootstrap and operator administration (role grant/revoke, account enable/disable, MFA reset), the last behind a real HTTP/admin surface. Rules 2, 6 and 16 above are encoded there.

## Membership Foundation (backend, operator API and Console UI built)

The foundation is complete, and the first business domain built on it is **Membership**. Its design gate is frozen and recorded in [ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md) and [ADR 0029](../adr/0029-commerce-providers-own-payment-facts.md). The `Membership` module, `membership_grants`, `Identity\Application\RegisterPerson`, the `membership.records.view`/`membership.records.manage` capabilities, an operator-only `/admin/members` HTTP surface (Work Package 5), and a Guardian Console Membership administration UI on top of it (Work Package 6) are built. The Member foundation is built too (Work Packages 1–5: the existing-Person invitation, `GET /my/membership`, neutral credential presentation, the `/my/` shell, and end-to-end validation) and further Member-facing expansion is parked. **Not built:** service/delegated authentication and Zeffy/Luma automation. Member access does not route through WordPress — see the Member-Facing Access design gate ([ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md), [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md), [member access](member-access.md)).

Two decisions constrain later work and belong here rather than only in the ADRs:

- **Membership access is time-bounded grants, derived at query time** — no stored status, no expiry job. In particular, **temporal membership eligibility is never represented solely by a durable role assignment**, because a role assignment does not expire and would outlive the term silently. This does not bar member, volunteer or partner roles later; it bars a non-expiring row being the sole source of truth for access that lapses with time.
- **Commerce providers own payment facts; Commons owns organizational entitlement.** Amounts, payment status, receipts and subscription mechanics stay with the provider and out of the Membership schema; Commons decides what a payment entitles someone to.

## Out of scope for the foundation (historical), and the Guardian roadmap

*This section was written when the foundation was being built.* It said CRM, volunteer management, events, publishing, workflows and AI features were out of scope so they would not be started early. That was the foundation's scope, not the roadmap: Membership has since been built (above), and the foundation is complete.

Product development is now **Guardian-first**: give Guardians enough genuinely useful capability that the Guardian Council can see why to adopt Commons. The current sequence, with domain ownership in [ADR 0034](../adr/0034-crm-enriches-identity-person.md):

| Milestone | Domain | Status |
| --- | --- | --- |
| G1 | CRM / People | Active. Enriches Identity's Person; does not replace it |
| G2 | Discussions | Planned |
| G3 | Events | Planned |
| G4 | Publishing / Announcements | Planned |
| G5 | Knowledge / Resources | Planned |

Commons domains own durable business state and rules; the Guardian Console is the primary rich authoring surface; `/my/`, WordPress and any future client present Commons capabilities and own none. Volunteering (a domain in its own right, not merely a role), workflows and AI features remain unscheduled, and Member-facing expansion is parked.
