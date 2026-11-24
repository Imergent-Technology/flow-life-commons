# Dependency vulnerability auditing

**Two ecosystems, one command:**

```text
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

**Audited 2026-11-23 (G6 WP6B), against the committed lockfiles: findings were open at that audit and the table further below is stale; the follow-up [remediation](#g6-d26-remediation-2026-11-23) leaves no Composer advisory once merged.** `./flow audit` reports **nine Composer advisories: eight on runtime packages** (`laravel/framework` 13.32.0, `symfony/http-foundation` 7.4.19, `symfony/http-kernel` 7.4.19, `symfony/mime` 7.4.19, `symfony/routing` 7.4.18) **and one on the development-only `symfony/yaml` 7.4.18**, none on an abandoned package. `npm audit` reports none for the Console's runtime dependencies and 15 for development tooling. The weekly `Security audit` workflow has failed since 2026-11-02. They were assessed read-only, per the questions above, in [the G6 WP6B dependency advisory triage](../audits/g6/wp6b-dependency-advisories.md), and recorded as [G6-D26](../audits/g6/debt-register.md); **no dependency was changed**. In short: no advisory is shown to be reachable in production, patched same-major releases exist, and the update is its own package, authorized separately. **The owner classified [G6-D26](../audits/g6/debt-register.md) as MEDIUM (application exposure not demonstrated) and accepted it temporarily under DP4 only to close G6 (2026-11-23); that does not authorize deploying from the existing lockfile.** The runtime audit must be clean before the next release unless a separate, advisory-specific acceptance is authorized ([release readiness](../runbooks/g10-release-readiness.md)). This page's earlier "no advisories" results below are the 2026-10-04 audit, kept as it was written.

### G6-D26 remediation (2026-11-23)

A bounded follow-up to the [G6 triage](../audits/g6/wp6b-dependency-advisories.md), on branch `fix/dependency-security-g6-d26` from `main` at `8204e53`. `composer.json` is **unchanged**; no major version moved; no unrestricted `composer update` was used (a plain update of the six advisory packages is not resolvable, because `laravel/framework` 13.40 and later requires `laravel/prompts` 0.3.25 or later, so that one companion was added: seven packages in all).

| Package | Class | Before | After | Why |
| --- | --- | --- | --- | --- |
| `laravel/framework` | runtime | 13.32.0 | **13.43.0** | Fixed from 13.40.0 (PKSA-yxbx-3c9s-cjt9). Composer resolves the highest patch within `^13.17`; the constraint was not edited to pin 13.40.0 |
| `laravel/prompts` | runtime | 0.3.24 | 0.3.27 | Required by `laravel/framework` 13.40 and later (`^0.3.25`). A patch-level companion |
| `symfony/http-foundation` | runtime | 7.4.19 | **7.4.23** | Four advisories |
| `symfony/http-kernel` | runtime | 7.4.19 | **7.4.23** | One advisory |
| `symfony/routing` | runtime | 7.4.18 | **7.4.23** | One advisory |
| `symfony/mime` | runtime | 7.4.19 | **7.4.22** | One advisory (CVE-2026-45070) |
| `symfony/yaml` | development only | 7.4.18 | **7.4.23** | One advisory (CVE-2026-123792) |

No other package changed (the earlier broad dry run's 53 updates, including `brick/math` 0.19 to 1.0, were avoided).

**Advisories.** Before: 9 (eight on runtime packages, one on development-only `symfony/yaml`). After: **0**. `./flow audit` exits 0: "No security vulnerability advisories found", no abandoned package, and the Console's runtime npm dependencies have 0 advisories. The 15 npm findings in development tooling (11 moderate, 4 high) are **unchanged and still not judged** for build impact; they do not fail the audit.

**One compatibility effect.** Laravel 13.40.0 changed the docblocks of `Illuminate\Database\Seeder::$container` and `::$command` to `|null` (no code change). Static analysis at level max then rejected 67 existing uses in the eight development seeders, so each now re-declares the property with the type it has whenever a seeder runs (`Container`, and `Command` in the one that uses it). The property is untyped with a null default, as the parent's; nothing changes at run time. It is the only application-code change, and it is unavoidable for any release that fixes the Laravel advisory.

**What was verified (local, 2026-11-23).**
- `./flow check --pgsql` at `b58f641`: passed. MariaDB 3,743 tests, PostgreSQL 3,698 passed and 45 skipped (the MariaDB-only tests, unchanged), Vitest 2,276, Pint and Larastan clean, the repo checks including the new Apache probes.
- `./flow test e2e --workers=4` on the built-assets origin: 448 + 10 + 1 passed, 0 failed (the run also exercises the edited seeders), `relationships:check` consistent.
- Route cache: `route:cache` gives the same 111 routes as uncached, and the route-surface tests pass with the cache in place. The cache was cleared afterwards.
- **New regression tests** (`tests/Unit/Modules/Security/RequestPathHandlingTest.php`, `tests/Feature/Modules/Security/ConsoleLinksAndMailTest.php`, and probes in `scripts/tests/apache-surface.sh`): a script name is not a path prefix; a `//` URI cannot steer the base URL or host; no forwarded header from a public, loopback, private, IPv4-mapped or 6to4 address is trusted; the trusted host is exactly the application URL's; invitation links come from `APP_URL` under a hostile request; the two mails are plain text with a fixed subject and no extra header or attachment; a recipient with a line break is refused. **Run against the old versions (Symfony 7.4.19), the three script-name tests fail and the rest pass,** which matches the triage: only that advisory was exposed on these paths, and it was reachable only if the web server handed PHP such a path. The real-Apache probes (production `.htaccess`, stubbed front controller) show that `/index.phpadmin`, `/index.phpapi…`, `/index.php.bak` and `//example.test/…` never reach PHP (they get the Console shell, or the maintenance page during maintenance), and that `/index.php/api/…` and `//api/…` do reach it.

**Still not established: host-specific, for the release (none of this was done, and no production access was used).**
1. **The production host's real behaviour** for the `/index.php…` and `//` request forms under its Apache and LSAPI build and its script-name variables. The local Apache container proves the repository's `.htaccess` logic, not the host. A read-only probe of those request forms belongs in the release's host checks.
2. **The dependencies installed on the host.** The release builder installs from `composer.lock` with `--no-dev`; what the host runs is whatever the built release carries. Confirm the built release holds the versions above and re-run `./flow audit` at release time.
3. **Route cache after the deploy.** The routing fix is in the compiled-route dumper, so a route cache built by the old version must not survive; the deployment runbook already runs `route:cache` for every release, and the release builder checks that the cache commands succeed.
4. **No proxy or CDN in front of production** is an assumption the application's design records, not something verified here.
5. The 15 development-tooling npm findings, and the scheduled `Security audit` workflow's alerting (a separate operational follow-up, not done).

Audited 2026-10-04, against the committed lockfiles after remediation:

| Ecosystem | Result |
| --- | --- |
| Composer (`--locked`) | **No advisories.** No abandoned packages. |
| npm, runtime only | **No advisories.** |
| npm, including development tooling | **No advisories.** |

### Recent remediation

On 2026-10-04 the security audit reported two advisories affecting the locked `league/commonmark` 2.10.1 dependency:

| Advisory | Severity | Issue |
| --- | --- | --- |
| `PKSA-m2dq-1fhr-29b1` / `GHSA-97jj-33gv-5xf9` | Medium | `DisallowedRawHtml` could be bypassed when a disallowed tag name ended the raw-HTML literal. |
| `PKSA-m4t9-vsgq-8khn` / `GHSA-3q6v-r5mr-hxv8` | High | Quadratic-time denial of service in the GitHub Flavored Markdown table-extension block-start scan. |

`league/commonmark` is an indirect runtime dependency required by `laravel/framework` (`^2.8.1`). A compatible patch upgrade was available, so the lockfile was updated from `league/commonmark` 2.10.1 to 2.10.3.

Composer also resolved `symfony/polyfill-php80` from 1.37.0 to 1.43.0 as part of that update. No application dependency constraint changed.

The post-upgrade Composer and npm audits report no known advisories. No exception or accepted-risk entry was required.

The dependencies most worth watching, because they are recent additions or sit on security-relevant/runtime paths:

| Package | Version | Why it is on this list |
| --- | --- | --- |
| `spomky-labs/otphp` | 11.5.0 | Generates and verifies every TOTP code. Behind an Identity port, and checked against an independent RFC 4226/6238 implementation in the test suite, so a behavioural regression would be caught here rather than in production. |
| `paragonie/constant_time_encoding` | 3.1.3 | otphp's base32. Constant-time by design; a flaw here would be a timing question. |
| `laravel/framework` | 13.32.0 | Session handling, encryption, CSRF, routing. The largest single dependency and the one whose advisories matter most. |
| `league/commonmark` | 2.10.3 | Runtime Markdown parser required by Laravel. It produced two actionable advisories in the 2026-10-04 audit and was patched from 2.10.1 to 2.10.3. Parser, raw-HTML and complexity advisories here deserve prompt review. |
| `qr` (npm) | 0.7.0 | Draws the enrolment QR code **in the browser**, from a value that contains a secret. Runtime, and on a secret's path — which is exactly why the QR code is not fetched from a service. It is used to produce a module matrix, never markup, so it has no HTML injection surface. |
| `react-router` | 8.4.0 | Reads and scrubs the secret-bearing fragments of the reset and invitation links. Runtime. |
| `react` / `react-dom` | 19.3.0 | Runtime. |
| `axe-core` | 4.13.0 | Development only; accessibility checks. |
| `@playwright/test` | 1.63.0 | Development only. Kept in step with the Playwright image tag in `compose.yaml`. |
| `vite` | 8.3.0 | Development and build only. Produces the artifact, so a compromise here is a supply-chain question rather than a runtime one. |

**"No vulnerabilities" above means the audit said so on that date.** It is not a claim about code nobody has looked at, and it goes stale — which is why the workflow runs weekly.

## Not in place

Secret scanning (a pre-commit hook or a CI scanner such as gitleaks) is still a recommended next step and is not configured ([secrets](secrets.md)). No third-party SaaS is used for any of this, and none is needed.