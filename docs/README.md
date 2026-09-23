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

Identity and Access is implemented ([architecture/identity-and-access.md](architecture/identity-and-access.md)). The release and deployment model is decided in [ADR 0027](adr/0027-release-and-deployment-model.md) and written up in the [deployment runbook](runbooks/deployment.md); its host assumptions were **probed on the production account on 2026-09-21**, and the **first production deployment was carried out end to end on 2026-09-22** ([closure record](runbooks/deployment.md#10-first-production-deployment-closure-2026-09-22)), bringing v0.1.1 live at `commons.flowlifeglobal.org`. Every local piece of the release contract exists — the artifact tooling (`./flow release build`, `inspect`, `migrations`), the composed public surface, `php artisan release:show`, and the production environment template checked by `security:production-check` — and it has now been exercised once, manually, on the real host. **Nothing on the host is automated**, and outbound mail authentication is deliberately open ([production readiness](runbooks/production-readiness.md)).
