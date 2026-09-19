# ADR 0001: Modular monolith

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

The platform will grow across many domains (identity, access, membership, volunteering, events, publishing, workflow) that must share one authoritative dataset and one authorization model. The team is small, production is shared cPanel hosting (no containers, no resident daemons), and the system is meant to live a long time. We need clear internal boundaries without operational overhead we cannot host.

## Decision

Build **one deployable Laravel application** organised into **modules** under `app/Modules/<Module>/{Domain,Application,Infrastructure,Http}`, plus a deliberately tiny `app/Shared`. Modules own their business rules and their writes; they collaborate through each other's `Application` layer, not through each other's models, tables or HTTP layer. Boundaries are protected by architecture tests (`tests/Architecture`), not just convention. Layers are created only when needed.

## Consequences

- One deploy, one database, one transaction boundary: simple to host on cPanel and simple to reason about consistency.
- Boundaries are logical, so discipline matters; the architecture tests make the important violations fail CI.
- Modules can later be extracted if a real need appears, because dependencies already point through explicit interfaces.
- Some ceremony is added. We keep it small: Eloquent and Laravel facilities are allowed everywhere, and trivial code does not need every layer.

## Alternatives considered

- **Layered/"Laravel default" structure** (controllers, models, services by type): least ceremony but business rules drift into controllers and jobs, and there are no domain boundaries to protect.
- **Microservices:** unjustified operational cost, impossible on shared hosting, and it would spread one consistency boundary across the network.
- **Separate packages per module (Composer path repos):** stronger enforcement but heavy for a small team and premature before boundaries are proven.
