# ADR 0020: Administrator bootstrap and the last-administrator invariant

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Refined by:** [ADR 0024](0024-privileged-operator-administration.md), which adds the operator-administration surface over the same invariant (and a server command for a lost second factor) without changing the bootstrap or the invariant

## Context

A platform with no accounts needs a first administrator, and a platform with administrators needs to not lose all of them. Both problems are usually solved badly: a hard-coded superuser, an environment variable that permanently confers authority, or a seeded default account that outlives its usefulness.

The second problem is harder here because it spans modules. "At least one active administrator exists" depends on **Access** (who holds the role) and on **Identity** (whose account can still authenticate). Access does not own account state, so it cannot enforce the invariant alone, and a mutual dependency between the two modules would be a design failure.

## Decision

**Bootstrap** — a console command, `identity:create-administrator`:

- Its root of trust is **server access**, which is already total, so it confers no new authority and leaves no standing credential.
- It creates a Person and Account, assigns `platform_administrator`, and issues a **single-use, expiring invitation** for the operator to set their own password. The command never sets a password, so no secret reaches shell history or logs.
- It **refuses if an administrator already exists**, unless `--force` with explicit confirmation, so privilege cannot be minted silently.
- It records a security event.

**The invariant** — enforced with a one-way dependency, **Access → Identity, never the reverse**:

- **Identity owns account state transitions, so Identity owns the extension point.** It defines `AccountDeactivationGuard`, an interface it consults inside `DisableAccount` and every future closure or anonymisation path.
- **Access implements that interface** as a last-administrator guard and registers it from its own provider. Identity remains unaware of Access; there is no cycle.
- **Identity exposes `ActiveAccountQuery`** — given person identifiers, which have accounts that can still authenticate — so Access can evaluate "active administrator" without reading Identity's tables.
- An **active administrator** holds `platform_administrator` *and* has an account that is active and not disabled.
- Both paths are covered: revoking the last administrator's role is refused by Access; disabling the last administrator's account is refused by Identity's guard chain.
- **The check and the mutation run in one transaction using `SELECT … FOR UPDATE`** over that role's assignment rows. Without it, two concurrent revocations can each observe that another administrator remains and both succeed. Row locking of this form is portable across MariaDB and PostgreSQL.

**Recovery** — ordinary credential loss uses password reset. Total lockout is recovered by re-running the bootstrap command. Server access *is* the break-glass mechanism, and it is sufficient.

## Consequences

- No permanent superuser, no authorization in environment variables, no seeded default account.
- Every future path that removes the ability to authenticate must go through Identity's use cases to inherit the guard chain. That is the point of placing it there rather than in the disable path.
- The guard interface has exactly one implementor, which would usually invite the speculation objection. It earns its place as the minimum mechanism that keeps the module graph acyclic.
- Recovery requires someone with server access; for an organization of this size that is proportionate.
- Transactional locking makes revocation slightly heavier. Correctness wins.

## Alternatives considered

- **A superuser flag or email list in the environment:** permanent, invisible to audit, and unrevocable through the application.
- **A seeded default administrator:** a known credential that outlives installation; the classic forgotten back door.
- **Access enforcing the invariant alone:** it does not control account status, so it could prevent role revocation while an administrator was disabled into lockout anyway.
- **Mutual calls between Identity and Access:** would work and would pass the architecture tests, but a dependency cycle between the two most security-sensitive modules is exactly what makes such systems hard to reason about.
- **An enterprise break-glass facility:** unjustified at this stage, and a standing liability.
