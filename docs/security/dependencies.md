# Dependency vulnerability auditing

**Two ecosystems, one command:**

```
./flow audit              # both
./flow audit backend      # composer audit --locked
./flow audit frontend     # npm audit
```

A scheduled [GitHub Actions workflow](../../.github/workflows/security-audit.yml) runs the same command weekly, whenever a lockfile changes, and on demand.

## Why it is not part of `./flow check`

Every other check in this repository is a pure function of the source tree: the same commit gives the same answer on any machine, today or next year. **An audit is not.** It asks Packagist's and npm's advisory databases what they *currently* know, so:

- it can go red without a single line changing — which is the whole point, and exactly what CI cannot express; and
- it needs the public network, so an outage at either registry would look like a broken branch.

Folding it into the deterministic gate would cost both properties to save one command. A test in `scripts/tests/cli.sh` asserts that `./flow check` does not reach an advisory database, so this cannot drift back.

## What the command does and does not do

- `composer audit --locked` audits the **lockfile**, which is what a deployment installs, rather than whatever happens to be in `vendor/` on the machine running it.
- `npm audit --omit=dev` runs first, because a finding in a **runtime** dependency is one that reaches a browser. That one fails the audit.
- `npm audit` over everything runs second. Findings in build and test tooling are printed and do **not** fail: they cannot reach a browser or a server, and whether they matter (could this compromise a developer's machine, or the build?) needs a person.
- Abandoned Composer packages are reported and do not fail. Abandonment is not a vulnerability; it is the slow-moving risk that nobody will publish a fix, and it belongs in a review rather than a gate.
- **Nothing is ever fixed automatically.** No `composer update`, and emphatically no `npm audit fix --force`, which installs major versions to make a number go down and can change behaviour silently.

## How to judge a finding

In this order, and record the answer:

1. **Runtime or development-only?** A finding in Vite, Playwright, ESLint or a test library does not reach production. It may still matter — build tooling runs on a developer's machine with their credentials — but it is a different question.
2. **Is this application on the affected path?** Advisories describe a vulnerable *function*, not a vulnerable package. A parser flaw in a code path nothing here calls is not an exposure; say which code path was checked.
3. **Is there a safe, compatible upgrade?** Take it. A patch or minor release within the same major is the ordinary answer.
4. **If only a major release fixes it**, do not take it as part of an audit. It is a change with its own review and its own tests.
5. **If nothing fixes it**, record the accepted risk here with a date and what would change the decision.

## Current findings

Audited 2026-09-23, against the committed lockfiles:

| Ecosystem | Result |
| --- | --- |
| Composer (`--locked`) | **No advisories.** No abandoned packages. |
| npm, runtime only | **No advisories.** |
| npm, including development tooling | **No advisories.** |

The dependencies most worth watching, because they are recent additions on security-relevant paths:

| Package | Version | Why it is on this list |
| --- | --- | --- |
| `spomky-labs/otphp` | 11.5.0 | Generates and verifies every TOTP code. Behind an Identity port, and checked against an independent RFC 4226/6238 implementation in the test suite, so a behavioural regression would be caught here rather than in production. |
| `paragonie/constant_time_encoding` | 3.1.3 | otphp's base32. Constant-time by design; a flaw here would be a timing question. |
| `laravel/framework` | 13.32.0 | Session handling, encryption, CSRF, routing. The largest single dependency and the one whose advisories matter most. |
| `qr` (npm) | 0.7.0 | Draws the enrolment QR code **in the browser**, from a value that contains a secret. Runtime, and on a secret's path — which is exactly why the QR code is not fetched from a service. It is used to produce a module matrix, never markup, so it has no HTML injection surface. |
| `react-router` | 8.4.0 | Reads and scrubs the secret-bearing fragments of the reset and invitation links. Runtime. |
| `react` / `react-dom` | 19.3.0 | Runtime. |
| `axe-core` | 4.13.0 | Development only; accessibility checks. |
| `@playwright/test` | 1.63.0 | Development only. Kept in step with the Playwright image tag in `compose.yaml`. |
| `vite` | 8.3.0 | Development and build only. Produces the artifact, so a compromise here is a supply-chain question rather than a runtime one. |

**"No vulnerabilities" above means the audit said so on that date.** It is not a claim about code nobody has looked at, and it goes stale — which is why the workflow runs weekly.

## Not in place

Secret scanning (a pre-commit hook or a CI scanner such as gitleaks) is still a recommended next step and is not configured ([secrets](secrets.md)). No third-party SaaS is used for any of this, and none is needed.
