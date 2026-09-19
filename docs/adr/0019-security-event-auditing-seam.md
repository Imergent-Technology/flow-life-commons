# ADR 0019: Security event auditing seam

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Rule 16 of the [charter](../architecture/charter.md) requires that sensitive actions eventually have durable auditing. "Eventually" is a trap for identity specifically: if authentication and authorization ship without it, the system cannot answer who authenticated, by what means, who granted access, or when authorization-relevant state changed — and those answers cannot be reconstructed afterwards.

The full application audit architecture (tamper-evidence, retention, review tooling, delivery via the outbox) is a later concern. What must exist now is the seam, so that identity and access code never needs re-instrumenting.

## Decision

- A small **`Audit` module** owns an append-only `security_events` table and exposes `Audit\Application\RecordSecurityEvent`.
- **Identity and Access call it directly and synchronously**, inside the same database transaction as the state change, so an event exists if and only if the change committed.
- **Minimum events from the first epic:** authentication succeeded and failed, logout, rate limit triggered; password set, changed, reset requested and completed; account invited, invitation accepted, account disabled and re-enabled, email changed; role granted and revoked; administrator bootstrap executed.
- **Each record carries** UTC timestamp, event type, the Actor (account and/or client), the subject, IP, user agent, outcome, and a small JSON context.
- **Records never contain credentials, tokens or password hashes**, and a failed-authentication record stores a reason *class*, never the submitted secret.
- **`security_events` has no foreign keys at all.** Audit must outlive its subjects and must never block an operation.
- **`context` is stored as JSON but never queried into.** JSON query functions differ between MariaDB and PostgreSQL and are barred by the portability guardrail ([ADR 0005](0005-mariadb-with-postgresql-portability.md)).
- **Append-only is enforced in code** — no update or delete paths — because triggers are not available to us.

## Consequences

- From the first release the platform can explain every authentication and every authorization change.
- Recording inside the transaction means an audit write failure fails the operation. That is the intended trade for identity events: an unrecorded privilege change is worse than a failed one.
- Synchronous writes add a row per security event; negligible at our scale, and revisited if it ever is not.
- Later delivery through domain events and the transactional outbox can implement the same interface without touching callers.
- Append-only is a convention enforced by review and code shape, not by the database. Accepted, because the alternative is a banned engine-specific feature.

## Alternatives considered

- **Defer auditing to its own epic:** rejected — the events are unrecoverable once missed, and retrofitting means touching every identity path.
- **Build the full audit architecture now:** premature; tamper-evidence and review tooling have no consumer yet.
- **Log to files only:** not durable, not queryable, and lost on redeploy for shared hosting.
- **Asynchronous recording via a queue:** loses the atomic guarantee, and the database queue would itself be part of what we audit.
