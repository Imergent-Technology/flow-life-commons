# Runbooks

Operational procedures live here: step-by-step, tested, written for someone under pressure.

| Runbook | Purpose |
| --- | --- |
| [Release and deployment](deployment.md) | First deployment, subsequent releases, rollback, backup, scheduler, verification |
| [Create the first (or a recovery) administrator](administrator-bootstrap.md) | `identity:create-administrator`: bootstrap and lockout recovery |
| [Recover a lost second factor](mfa-recovery.md) | An operator's reset in the Console, and `identity:reset-mfa` when no other administrator can |
| [Production readiness](production-readiness.md) | What must be true before serving real people, and who can establish each thing |
| [Rotate the application key](app-key-rotation.md) | `APP_KEY` and `APP_PREVIOUS_KEYS`, without locking anyone out of their authenticator |
| [Backup and restore](backup-and-restore.md) | The keyring pairing rule and the contract a restore must satisfy |

**The mechanisms are proven; the procedure is not yet rehearsed.** The host capabilities behind [ADR 0027](../adr/0027-release-and-deployment-model.md) were probed on the production account on 2026-09-21 and all passed, so the runbook no longer carries placeholders. **The procedure itself has never been executed end to end**, the artifact tooling exists but nothing on the host is automated, and **outbound mail authentication is open and deferred** ([production readiness](production-readiness.md), section 5).

Still to come, deliberately deferred until designed properly (each becomes a runbook and, where a decision is involved, an ADR):

- **Host-side release, backup and restore.** The procedures are designed and stay operator actions. The developer-side `./flow release build`, `inspect` and `migrations` now exist ([deployment §8](deployment.md#8-flow-release-the-developer-side-tooling)), as do `php artisan release:show` and the production environment template (`apps/platform/.env.production.example`) that `security:production-check` is tested against; nothing that touches the host does.
- Incident response and audit-trail review (including a retention policy for `security_events`, which is append-only and never pruned)
- PostgreSQL migration rehearsal

`./flow` intentionally has **no `backup`, `restore`, `rollback` or `release deploy` command.** Everything touching production stays an explicit operator action run from a runbook, holding no credentials in the repository, until the manual procedure has been performed on the real host enough times to be worth encoding ([ADR 0027](../adr/0027-release-and-deployment-model.md)).

Template for new runbooks: purpose, when to use, prerequisites, numbered steps with expected output, verification, rollback, owner, last tested date.
