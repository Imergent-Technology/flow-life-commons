# Documentation

| Area | What is in it |
| --- | --- |
| [architecture/](architecture/) | Charter and guardrails, system context, module map, data ownership, trust boundaries, integration model, the Identity and Access design, deployment topology |
| [adr/](adr/) | Architecture Decision Records: the *why* behind decisions, with alternatives considered |
| [development/](development/) | Getting started, Docker environment, testing, coding standards, Git workflow |
| [security/](security/) | Authorization model (direction) and secrets handling |
| [integrations/](integrations/) | How external systems connect; WordPress first |
| [runbooks/](runbooks/) | Operational procedures: deployment, administrator bootstrap, MFA recovery, key rotation, backup, production readiness |

Start with the [charter](architecture/charter.md), then [getting started](development/getting-started.md).

Identity and Access is implemented ([architecture/identity-and-access.md](architecture/identity-and-access.md)). The current work is **operational**: the release and deployment model is decided in [ADR 0027](adr/0027-release-and-deployment-model.md) and written up in the [deployment runbook](runbooks/deployment.md), but **no part of it has been run against the production host** and no release tooling exists. The gate is [production readiness](runbooks/production-readiness.md).
