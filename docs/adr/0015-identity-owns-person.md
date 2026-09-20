# ADR 0015: Identity owns Person; Person is not Account

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0008](0008-platform-owned-authorization.md)
- **Clarified:** 2026-09-20. One consequence claimed that accepting an invitation proves control of the address. It does so only when the invitation was actually delivered to that address; see [ADR 0022](0022-password-policy-and-credential-handling.md). The decision itself is unchanged.

## Context

People interact with Flow Life in several capacities — community participant, member, volunteer, Guardian, operator, administrator — and accumulate or lose those capacities over time. They must not become separate accounts.

Four situations force the shape of the model:

- A contact may exist long before anyone logs in (an imported volunteer list).
- An account may exist before any rich profile.
- One human may hold several capacities at once, with one login.
- Losing a login must not destroy organizational history.

A single table that is both the credential store and the person-of-record cannot satisfy all four, and would have to be split later — the destructive redesign we are trying to avoid. A separate question is whether the module that owns this is merely *authentication*, or the platform's authoritative answer to "who is this human?".

## Decision

**The Identity module is the platform's authoritative registry of the humans it knows and of their means of authenticating.** This is deliberate, not an accident of where the login code went.

- **Person** — the canonical human. A thin anchor: ULID, display name, timestamps. Business modules reference a Person by `person_id`.
- **Account** — a Person's means of signing in: login identifier, local credential, status. At most one Account per Person; a Person may exist with none.
- **Person stays thin.** Contact attributes must **not** accumulate on it; CRM will own rich contact data keyed by `person_id`. Person is an identity anchor, not a profile.
- **Email is a mutable login identifier, never the identity key.** The ULID is the key. A separate lowercased `email_canonical` column carries the unique constraint and is the **sole** lookup key for every credential path.
- Business relationships (membership, volunteering, Guardian responsibility) attach to the **Person**, so disabling an Account never destroys them.
- **Accounts are created only by invitation.** There is no self-service registration: the Guardian Console is for Guardians, operators and administrators, who are invited by someone who already holds the capability. One Account per canonical email is accepted for the initial system; no second login identifier is introduced for shared-address cases.

`email_canonical` exists for a measured reason. On this project's two engines, a unique index on `email` alone does not behave the same way: inserting `person@example.org` then `Person@Example.org` is **rejected by MariaDB** (`utf8mb4_unicode_ci` is case-insensitive) and **accepted by PostgreSQL**, producing two accounts with the same address. Verified on MariaDB 10.11 and PostgreSQL 16; with the canonical column both engines reject the duplicate identically. Relying on the database's collation would make authentication semantics change silently during a future migration ([ADR 0005](0005-mariadb-with-postgresql-portability.md)).

## Consequences

- Account deactivation preserves the Person, their history and their relationships.
- Contact-before-account and account-before-profile both work without special cases.
- CRM extends the model by owning its own tables keyed by `person_id`, touching nothing in Identity.
- Every credential lookup must canonicalise input and query `email_canonical`; querying `email` reintroduces the divergence above. This constrains the Laravel user provider.
- Merging duplicate People is a later administrative operation. Identity's obligation now is only to keep it possible: references are by `person_id`, so a merge is a data operation rather than a redesign.
- Invitation being the only creation path means a separate email-verification flow is not needed initially, **provided** invitations are delivered to the address they invite: presenting a delivered invitation is then evidence of control of the mailbox, and only that evidence justifies setting `email_verified_at`. An invitation handed over by another route (the administrator bootstrap prints its token to a server operator) proves nothing about the mailbox, so accepting it activates the Account but leaves the email unverified.
- Members and volunteers are **not** Console users. When they eventually need platform access it arrives through WordPress as a delegated flow ([ADR 0018](0018-client-and-delegated-authentication.md)), not by opening registration here.
- **Main risk: Person becoming a god table.** Every future module will be tempted to add "just one column". The thinness rule is the mitigation and must be enforced in review.
- **Extraction trigger:** if a second module ever needs to create People independently of Identity (a CRM import, say), revisit ownership. Moving the table's owning module is a code move, not a data migration — cheap and reversible, which is why a People module is not created now.

## Alternatives considered

- **One `users` table (Laravel's default):** fastest, and the reason the foundation deliberately deleted the stock migration. It conflates credential with person, cannot represent a person who never logs in, and makes deactivation destructive.
- **A separate People module now:** conceptually tidy, but it would hold one thin table with a single consumer — premature modularisation. Deferred behind the explicit trigger above.
- **A `Party` abstraction covering people and organizations:** organizations do not authenticate. CRM can model them separately without Identity carrying the generality.
- **Natural key on email:** rejected outright; email changes, and the constraint would make identity depend on a mutable, engine-sensitive value.
