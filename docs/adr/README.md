# Architecture Decision Records

An ADR records one significant decision: what we chose, why, and what we gave up. They are short, dated and immutable in spirit: to change a decision, write a new ADR that supersedes the old one and update both statuses.

## When to write one

Write an ADR when a decision is hard to reverse, constrains future work, or when someone will later ask "why did we do it this way?". Deviating from a rule in the [charter](../architecture/charter.md) (for example using a MariaDB-only feature) **requires** an ADR. Do not write ADRs for generic coding preferences; those belong in [coding standards](../development/coding-standards.md).

## Format

Copy [template.md](template.md). Number sequentially (`NNNN-short-title.md`). Statuses: `Proposed`, `Accepted`, `Superseded by ADR NNNN`, `Deprecated`.

## Index

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-modular-monolith.md) | Modular monolith | Accepted |
| [0002](0002-laravel-php-platform.md) | Laravel on PHP 8.3 for the platform | Accepted |
| [0003](0003-react-guardian-console.md) | React, TypeScript and Vite for the Guardian Console | Accepted |
| [0004](0004-wordpress-adapter-not-authority.md) | WordPress is an adapter, not an authority | Accepted |
| [0005](0005-mariadb-with-postgresql-portability.md) | MariaDB first, PostgreSQL portability required | Accepted |
| [0006](0006-ulid-identifiers.md) | Application-generated ULID identifiers | Accepted |
| [0007](0007-versioned-rest-api-openapi.md) | Versioned REST API with an OpenAPI contract | Accepted |
| [0008](0008-platform-owned-authorization.md) | Platform-owned authorization | Accepted |
| [0009](0009-authorization-separate-from-approval.md) | Authorization is separate from approval | Accepted |
| [0010](0010-database-queue-redis-ready.md) | Database queue first, Redis-ready | Accepted |
| [0011](0011-docker-compose-development-environment.md) | Docker Compose development environment and `./flow` | Accepted |
| [0012](0012-tailwind-4-via-vite.md) | Tailwind CSS 4 via the Vite plugin | Accepted |
| [0013](0013-monorepo.md) | Monorepo | Accepted |
| [0014](0014-testing-and-database-compatibility-strategy.md) | Testing and database compatibility strategy | Accepted |
| [0015](0015-identity-owns-person.md) | Identity owns Person; Person is not Account | Accepted |
| [0016](0016-guardian-console-same-origin-session-authentication.md) | Guardian Console authenticates via a same-origin, host-only session cookie | Accepted |
| [0017](0017-capabilities-and-roles-in-code.md) | Capabilities and roles are defined in code; only assignments persist | Accepted |
| [0018](0018-client-and-delegated-authentication.md) | Client and delegated authentication keep WordPress non-authoritative | Accepted (direction) |
| [0019](0019-security-event-auditing-seam.md) | Security event auditing seam | Accepted |
| [0020](0020-administrator-bootstrap-and-last-administrator-invariant.md) | Administrator bootstrap and the last-administrator invariant | Accepted |
| [0021](0021-cross-module-referential-integrity.md) | Cross-module referential integrity | Accepted |
| [0022](0022-password-policy-and-credential-handling.md) | Password policy and credential handling | Accepted |
| [0023](0023-multi-factor-authentication.md) | Multi-factor authentication for the Guardian Console | Accepted |
| [0024](0024-privileged-operator-administration.md) | Privileged operator administration | Accepted |
| [0025](0025-account-security-generation.md) | The Account security generation | Accepted |
| [0026](0026-production-browser-security-policy.md) | Production browser security policy | Accepted |
| [0027](0027-release-and-deployment-model.md) | Release and deployment model | Accepted |
| [0028](0028-membership-grants-derived-at-query-time.md) | Membership access is time-bounded grants, derived at query time | Accepted |
| [0029](0029-commerce-providers-own-payment-facts.md) | Commerce providers own payment facts; Commons owns organizational entitlement | Accepted (provenance boundary built; provider automation not) |
| [0030](0030-guardian-console-visual-system.md) | The Guardian Console visual system | Accepted (implemented) |
| [0031](0031-production-transactional-mail-uses-authenticated-smtp.md) | Production transactional mail uses authenticated SMTP | Accepted (transport hardened; provider/DNS activation not yet done) |
| [0032](0032-members-use-a-commons-hosted-surface.md) | Members use a Commons-hosted surface | Accepted (Member foundation implemented; expansion parked) |
| [0033](0033-service-identity-and-delegated-human-authority-are-distinct.md) | Service identity and delegated human authority are distinct | Accepted (direction; nothing implemented) |
| [0034](0034-crm-enriches-identity-person.md) | CRM enriches Identity's Person | Accepted (implemented; G1 complete) |
| [0035](0035-guardian-discussions-are-durable-asynchronous-threads.md) | Guardian Discussions are durable asynchronous threads | Accepted (backend implemented; Console screens not) |

Domain events and the transactional outbox are a documented *direction* ([integration model](../architecture/integration-model.md)), not yet a decision: they get an ADR when the first real consumer shapes the design.

ADRs 0015–0021 record the Identity and Access design gate; ADR 0022 records the password policy and credential decisions made while implementing it, ADR 0024 the privileged operator administration that builds on them, and ADR 0023 the multi-factor authentication that the Console now requires. ADRs 0025 and 0026 come from the production-hardening phase: the first closes the last known stale-authentication window, the second states the browser security policy the production origin serves under. [ADR 0027](0027-release-and-deployment-model.md) is the first decision about *operating* the platform rather than building it: how a release is built, switched, verified and rolled back on the cPanel host. It records several behaviours measured from the current codebase, and is valid only while those hold. The consolidated design they refer to — vocabulary, module layout, schema, lifecycles and the scope of the first implementation epic — is in [architecture/identity-and-access.md](../architecture/identity-and-access.md). It is being implemented in phases; its *Implementation status* section records how far.

ADRs 0028 and 0029 are the **Membership Foundation** design gate, and the first decisions about a business domain rather than about the platform that carries it. [ADR 0028](0028-membership-grants-derived-at-query-time.md) decides how membership access is represented and why it is derived rather than stored; [ADR 0029](0029-commerce-providers-own-payment-facts.md) decides where the boundary sits between an external payment provider's facts and Commons' own interpretation of them. The `Membership` module, `membership_grants`, the temporal derivation, `Identity\Application\RegisterPerson`, the `membership.records.view`/`membership.records.manage` capabilities, an operator-only `/admin/members` HTTP surface (Work Package 5), and a Guardian Console Membership administration UI on top of it (Work Package 6) are now built (ADR 0028's Clarified note). **Not built:** a member-facing API, WordPress integration, service/delegated authentication, and Zeffy/Luma automation — of ADR 0029, the provenance boundary is built (`source`, the opaque `source_reference`, and no payment field in Membership), while the provider-facing parts (event ingestion and its idempotency, interpretation policy, Person matching, any Luma or Zeffy integration) remain a boundary for work that does not exist yet.

[ADR 0030](0030-guardian-console-visual-system.md) is the first decision about the Guardian Console's *presentation* rather than its behaviour, and answers the component-library question [ADR 0012](0012-tailwind-4-via-vite.md) deferred until real screens defined the need. It records only what is meant to be durable — semantic role tokens, CSS-variable theming through Tailwind 4, Light/Dark/System, one narrowly scoped local UI preference, the attached rail with a responsive secondary drawer, and same-origin self-hosted fonts. The mutable visual values it deliberately excludes — colours, type sizes, radii, shadows, spacing, breakpoint values — live in [design/guardian-console-visual-system.md](../design/guardian-console-visual-system.md), which that ADR names as their source of truth. **It is implemented:** the decision was recorded ahead of the work, which was delivered as seven work packages (the delivery table is in the specification).

[ADR 0031](0031-production-transactional-mail-uses-authenticated-smtp.md) records the target architecture for production outbound mail — authenticated SMTP via a transactional provider (not chosen here), sent from a dedicated Commons-owned subdomain, synchronous and unqueued at current scale — and hardens `security:production-check` from a blocklist (refusing only `sendmail`) to an explicit allowlist (`log` or `smtp`, nothing else). It refines rather than reopens [ADR 0010](0010-database-queue-redis-ready.md) (no queue worker) and [ADR 0024](0024-privileged-operator-administration.md) (delivery/failure semantics unchanged). **The code is ready; the provider is not chosen, DNS is not configured, and production remains `MAIL_MAILER=log`** until that separate activation work is done and its evidence is recorded in [production-readiness.md, section 5](../runbooks/production-readiness.md).

ADRs 0032 and 0033 are the **Member-Facing Access** design gate, deciding *where* an ordinary Member authenticates and *what*, if anything, WordPress is trusted with — not building either. [ADR 0032](0032-members-use-a-commons-hosted-surface.md) decides that the Member surface is served from Commons itself, on the session model ADR 0016 already built, with WordPress reduced to a link; it refines ADR 0004's context (member functionality is no longer expected to arrive "largely through WordPress") without touching ADR 0004's decision. [ADR 0033](0033-service-identity-and-delegated-human-authority-are-distinct.md) refines [ADR 0018](0018-client-and-delegated-authentication.md): it keeps that ADR's invariants, corrects an overclaimed compromise consequence, and settles that neither service authentication nor delegated human authority is built until a concrete consumer — specifically, person-specific data that must render *inside* WordPress — actually needs it. The consolidated design, the work-package sequence, and the current implementation status are in [architecture/member-access.md](../architecture/member-access.md).

[ADR 0034](0034-crm-enriches-identity-person.md) opens the **Guardian product** phase. It fixes the CRM boundary before the `Crm` module exists: CRM enriches Identity's Person and never creates a second human identity, the People directory lists every Person, contact methods are not unique across Persons, tags are labels only, CRM business history is not `security_events`, and renaming stays an Identity mutation that needs no step-up. It also records the milestone order (CRM, Discussions, Events, Publishing, Knowledge) without specifying the later domains. It is implemented, and G1 is complete.

[ADR 0035](0035-guardian-discussions-are-durable-asynchronous-threads.md) is the **G2 Guardian Discussions** design gate. It decides a standalone `Discussions` module of asynchronous threads (a title, an opening message and flat replies held in one sequence-ordered table), an Open/Resolved lifecycle any participant may change, author-only editing with explicit edit provenance, removal as a tombstone whose text is really gone, two capabilities (`discussions.view`, `discussions.participate`) and no moderation, no security events, no step-up for routine posting, and no notifications. It states the Phase 1 schema and HTTP contract, and lists what is deliberately deferred (nesting, official and collaborative posts, private threads, moderation, notifications, realtime). The `Discussions` backend (WP1) is built; the Console screens and the end-to-end proof are not.

Some ADRs carry a **Refined by** or **Amended by** line. Those decisions remain Accepted and unchanged; the pointer records that a later ADR adds detail within the same direction, as distinct from superseding it. A **Clarified** line records that the *wording* of a decision was corrected in light of implementation, with the decision itself unchanged.
