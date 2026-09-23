# Flow Life Platform

The long-lived organizational platform for Flow Life Global: the authoritative system for organizational data, identity, authorization and workflows, serving members, volunteers and Guardians. WordPress is a presentation adapter; it is never the source of truth.

> **Status: in active development, and live in production.** The foundation and the Identity and Access system are implemented, and the Guardian Console and Platform API run as real, deployed software: **v0.1.1** has been in production since 2026-09-22. Substantial product functionality beyond the foundation is still being built out incrementally.

## Quick start

Requires Git, Docker (Compose v2), Bash and an editor. On WSL2 use Docker Desktop with WSL integration and keep the repo on the Linux filesystem.

```bash
git clone <repo-url> flow-life-commons
cd flow-life-commons
./flow setup
./flow up
```

| | |
| --- | --- |
| Guardian Console | http://commons.flowlife.localhost:18080 |
| API health (same origin) | http://commons.flowlife.localhost:18080/api/v1/health |
| Mailpit | http://mail.flowlife.localhost:18080 |

`./flow doctor` diagnoses your machine; `./flow help` lists commands; `./flow check` runs everything CI runs.

## Repository

```
apps/platform/            Laravel 13 API (modular monolith), OpenAPI contract
apps/guardian-console/    React 19 + TypeScript + Vite + Tailwind 4
apps/wordpress-companion/ Thin WordPress adapter (skeleton)
packages/api-client/      Reserved for the generated TypeScript API client
infrastructure/docker/    Dockerfile and gateway config for local development
docs/                     Architecture, ADRs, development guides, security, integrations
scripts/  flow            The ./flow developer CLI
```

## Documentation

- [Architecture charter](docs/architecture/charter.md): direction and the rules everything is measured against
- [Getting started](docs/development/getting-started.md), [Docker environment](docs/development/docker.md), [testing](docs/development/testing.md), [coding standards](docs/development/coding-standards.md), [Git workflow](docs/development/git-workflow.md)
- [Architecture Decision Records](docs/adr/README.md)
- [Documentation index](docs/README.md)
