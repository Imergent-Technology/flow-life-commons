# Runbooks

Operational procedures live here: step-by-step, tested, written for someone under pressure.

| Runbook | Purpose |
| --- | --- |
| [Create the first (or a recovery) administrator](administrator-bootstrap.md) | `identity:create-administrator`: bootstrap and lockout recovery |
| [Recover a lost second factor](mfa-recovery.md) | An operator's reset in the Console, and `identity:reset-mfa` when no other administrator can |
| [Production readiness](production-readiness.md) | What must be true before serving real people, and who can establish each thing |
| [Rotate the application key](app-key-rotation.md) | `APP_KEY` and `APP_PREVIOUS_KEYS`, without locking anyone out of their authenticator |
| [Backup and restore](backup-and-restore.md) | The constraints a backup process must satisfy — not a procedure |

Everything else is still to come. Production is not deployed and no other operational process has been designed. Deliberately deferred until designed properly (each becomes a runbook and, where a decision is involved, an ADR):

- Backup and restore tooling (databases, uploaded files, secrets). The *constraints* it must satisfy are now recorded in [backup-and-restore.md](backup-and-restore.md); the procedure and the tooling are not designed.
- Release and deployment to cPanel hosting (including the platform artifact, Guardian Console static build, migrations, cron for the scheduler/queue). The *topology* this must satisfy, and the hosting capabilities it assumes, are recorded in [architecture/deployment-topology.md](../architecture/deployment-topology.md); the procedure is not designed.
- Rollback
- Incident response and audit-trail review (including a retention policy for `security_events`, which is append-only and never pruned)
- PostgreSQL migration rehearsal

`./flow` intentionally has no `backup`, `restore` or `release` commands, because a fake or unsafe one would be worse than none.

Template for new runbooks: purpose, when to use, prerequisites, numbered steps with expected output, verification, rollback, owner, last tested date.
