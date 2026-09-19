# Runbooks

Operational procedures live here: step-by-step, tested, written for someone under pressure.

**None yet.** Production is not deployed and no operational process has been designed. Deliberately deferred until designed properly (each becomes a runbook and, where a decision is involved, an ADR):

- Backup and restore (databases, uploaded files, secrets)
- Release and deployment to cPanel hosting (including the platform artifact, Guardian Console static build, migrations, cron for the scheduler/queue)
- Rollback
- Incident response and audit-trail review
- PostgreSQL migration rehearsal

`./flow` intentionally has no `backup`, `restore` or `release` commands, because a fake or unsafe one would be worse than none.

Template for new runbooks: purpose, when to use, prerequisites, numbered steps with expected output, verification, rollback, owner, last tested date.
