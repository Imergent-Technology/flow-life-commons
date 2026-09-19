# ADR 0010: Database queue first, Redis-ready

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Production is shared cPanel hosting: no permanent worker processes, no Redis, no daemons. Background work still needs to exist (mail, integrations, eventually the outbox relay). Development can run richer infrastructure, but the application must not come to depend on it.

## Decision

- Use Laravel's **database queue** (`QUEUE_CONNECTION=database`) and **database cache** (`CACHE_STORE=database`) initially; sessions use files. Nothing requires Redis.
- In **development** a `queue` container runs `queue:listen` and a `scheduler` container runs `schedule:work`.
- In **production** the queue is intended to be drained from the scheduler tick under cron (for example a scheduled `queue:work --stop-when-empty`), not by a resident worker. That scheduling is **not implemented yet** because no jobs exist.
- The architecture stays **Redis-ready**: application code uses Laravel's queue/cache abstractions and must not use Redis-specific features unless their semantics are genuinely required (then by ADR). An optional `redis` Compose profile exists for experimentation; nothing consumes it. A test fails if `.env.example` points queue/cache/session at Redis.
- **Caches are never authoritative storage.** Jobs will be designed idempotent and retry-safe ([integration model](../architecture/integration-model.md)).

## Consequences

- Runs anywhere Laravel and a database run; zero extra infrastructure to host.
- Queue latency in production is bounded by the cron interval; acceptable for our workloads, and a reason to keep jobs asynchronous-tolerant.
- Database queues add write load to the primary database; fine at our scale, and a clear reason to move to Redis later if needed.
- Moving to Redis later is a configuration change, provided we keep to Laravel's abstractions.

## Alternatives considered

- **Redis from day one:** better performance, but not available in production and would make development diverge from it.
- **Synchronous processing only:** simplest, but ties user-facing latency and failure to slow external calls.
- **Supervisor-managed workers:** not possible on shared hosting.
