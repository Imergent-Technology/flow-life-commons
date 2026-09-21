# Runbooks

Operational procedures live here: step-by-step, tested, written for someone under pressure.

| Runbook | Purpose |
| --- | --- |
| [Create the first (or a recovery) administrator](administrator-bootstrap.md) | `identity:create-administrator`: bootstrap and lockout recovery |
| [Recover a lost second factor](mfa-recovery.md) | An operator's reset in the Console, and `identity:reset-mfa` when no other administrator can |

Everything else is still to come. Production is not deployed and no other operational process has been designed. Deliberately deferred until designed properly (each becomes a runbook and, where a decision is involved, an ADR):

- Backup and restore (databases, uploaded files, secrets)
- Release and deployment to cPanel hosting (including the platform artifact, Guardian Console static build, migrations, cron for the scheduler/queue). The *topology* this must satisfy, and the hosting capabilities it assumes, are recorded in [architecture/deployment-topology.md](../architecture/deployment-topology.md); the procedure is not designed.
- Rollback
- Incident response and audit-trail review
- PostgreSQL migration rehearsal

`./flow` intentionally has no `backup`, `restore` or `release` commands, because a fake or unsafe one would be worse than none.

Template for new runbooks: purpose, when to use, prerequisites, numbered steps with expected output, verification, rollback, owner, last tested date.
