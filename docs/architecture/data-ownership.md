# Data ownership

## Principles

1. **The platform database is the system of record** for organizational data. Nothing of record lives in WordPress tables, options or user meta, in browser storage, or in caches.
2. **A module owns its tables.** Only that module's code writes to them. Other modules read through the owning module's `Application` layer, not by querying its tables or models directly (see [module map](module-map.md)).
3. **Identifiers** for platform aggregates are application-generated ULIDs ([ADR 0006](../adr/0006-ulid-identifiers.md)); they are created in code, not by the database, so they can be known before insert and behave the same on every engine.
4. **Timestamps** are persisted in UTC. Presentation converts to a local zone at the edge.
5. **Caches are never authoritative.** Anything in a cache must be reconstructible from the database, and correctness must not depend on a cache hit.
6. **Schema stays portable** between MariaDB and PostgreSQL ([ADR 0005](../adr/0005-mariadb-with-postgresql-portability.md)): no database enums, triggers, stored procedures, proprietary SQL or generated columns without an ADR.

## External copies

Data mirrored to other systems (for example a WordPress user display name) is a **projection** of platform data. The platform wins on any disagreement, and projections must be rebuildable from the platform. External identities are *linked to* platform identities, never the other way round.

## Current state

Platform-owned tables exist for Identity, Access, Audit and Membership ([module map](module-map.md)):

- **Identity**: `people`, `accounts`, `account_invitations`, `sessions`, `account_totp_factors`, `account_recovery_codes`, `password_reset_tokens`.
- **Access**: `role_assignments`.
- **Audit**: `security_events`.
- **Membership**: `membership_grants`. Backend plus an operator-only `/admin/members` HTTP surface (Work Package 5); no Console UI yet.

Platform aggregates use application-generated ULID primary keys, per the principle above. Framework infrastructure created by Laravel's stock migrations — `migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` — keeps Laravel's default keys because it is not platform aggregate data. There is deliberately no `users` table: Identity's `people`/`accounts` split is the human registry ([authorization model](../security/authorization-model.md)).

## Answered by the Identity and Access design gate

- **Table naming:** unprefixed. Module ownership is recorded in the [module map](module-map.md) and the module's own documentation, not encoded in table names.
- **Cross-module foreign keys:** permitted, deliberately, where the reference is a fundamental invariant — with `RESTRICT`, never `CASCADE`, and never for provenance or audit references. Write ownership, code dependency and referential integrity are three independent concerns ([ADR 0021](../adr/0021-cross-module-referential-integrity.md)).
- **Where the audit trail lives:** a small `Audit` module owning an append-only `security_events` table with no foreign keys, so it outlives its subjects and never blocks an operation ([ADR 0019](../adr/0019-security-event-auditing-seam.md)).

## Membership (ADR 0028, ADR 0029)

- **`Membership` owns one table, `membership_grants`**, holding time-bounded grants of membership access. Whether a Person is a member *now* is **derived from those rows at query time**, not stored: there is no status column and no expiry job.
- **Grants are a non-deleting history with one-way revocation.** Nothing but `revoked_at`/`revoked_by_account_id` is ever changed, and no row is deleted.
- **`person_id` carries a cross-module foreign key with `RESTRICT`**; `granted_by_account_id` and `revoked_by_account_id` are provenance and carry none — the calls [ADR 0021](../adr/0021-cross-module-referential-integrity.md) already defines. Membership's migrations therefore run after Identity's.
- **Payment facts stay out of the schema.** Amount, currency, payment method, provider status, subscription mechanics, campaign fields and receipt state belong to the commerce provider, never to `membership_grants` ([ADR 0029](../adr/0029-commerce-providers-own-payment-facts.md)). Provider identity may appear only as provenance, in `source`/`source_reference`, which Membership never parses and which carry no uniqueness constraint.
- **Membership access is never represented solely by a role assignment**, because a role assignment does not expire and membership lapses by the passage of time ([ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md)).

## Still open

- Soft deletes, retention and anonymisation policy for personal data. Anonymisation is designed as acting on the Person while preserving referential history, but the policy itself is undecided.
- How the audit trail is protected from tampering by the modules it audits; append-only is currently a code convention, since triggers are barred by the portability rule.
- Contact data for People without an Account. Person is deliberately thin and carries no email ([ADR 0015](../adr/0015-identity-owns-person.md)), so an account-less member cannot be matched by email from an external system. A future CRM/contact-data decision, triggered when integration volume justifies it ([ADR 0029](../adr/0029-commerce-providers-own-payment-facts.md)).
