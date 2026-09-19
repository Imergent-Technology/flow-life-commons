# Integration model

How the platform talks to other systems (WordPress, mail, later partners and services). This is direction plus rules; concrete mechanisms are built when there is a real consumer.

## Rules

1. **Explicit boundaries.** External systems are reached only from a module's `Infrastructure` layer (enforced for HTTP clients by the architecture tests). Domain and Application code depend on the module's own interfaces or use cases, not on an SDK or URL.
2. **No network calls inside database transactions.** Commit first, then call out (or record intent for the outbox). A slow or failing third party must never hold locks or roll back business state.
3. **Idempotent, retry-safe jobs.** Anything queued may run more than once. Design for it: idempotency keys or naturally idempotent operations, safe partial failure, bounded retries with backoff.
4. **Caches are not the source of truth** for integrated data; treat them as disposable.
5. **Inbound is untrusted.** Verify signatures, validate payloads, authorize the acting party; never trust a caller's claim about who the user is.
6. **Versioned contracts.** Outward-facing surfaces are versioned and described (OpenAPI for the REST API).

## Events and transactional outbox (direction)

- Modules publish meaningful **domain/application events** describing things that happened.
- A **transactional outbox** records events in the same database transaction as the state change, so an event exists if and only if the change committed.
- A separate relay delivers outbox events to consumers (internal listeners, jobs, integrations, notifications to WordPress) with retry safety.
- Consumers are idempotent (rule 3), so at-least-once delivery is acceptable.

This is the intended shape, not an implementation. It is **deliberately not built** until a real consumer exists to shape it. The relay must run within production constraints: driven by the scheduler/cron on shared hosting, not a resident daemon.

## Queues

Database-backed queue initially ([ADR 0010](../adr/0010-database-queue-redis-ready.md)). In development a worker container runs continuously; in production the scheduler tick drains the queue. Application code uses Laravel's queue abstraction and must not reach for Redis-specific features.

## Mail

Development mail goes to Mailpit (`mail.flowlife.localhost`). Production mail configuration is host-supplied.

## WordPress

See [WordPress integration](../integrations/wordpress.md).
