# Secrets

**Never commit secrets.** No passwords, API keys, tokens, private keys, production hostnames or credentials, in code, tests, fixtures, docs or CI files.

## How the repo handles configuration

| What | Where | Committed |
| --- | --- | --- |
| Templates with safe placeholders | `.env.example`, `apps/platform/.env.example`, `apps/guardian-console/.env.example` | yes |
| Real local values | `.env`, `apps/platform/.env` (created by `./flow setup`) | **no**, gitignored (`.env` and `.env.*`, except `*.example`) |
| Production configuration | Supplied on the production host | never in the repo |

`APP_KEY` is generated per environment by `./flow setup`. It is empty in the example file.

**`APP_KEY` protects every enrolled authenticator.** TOTP secrets are stored encrypted under it ([ADR 0023](../adr/0023-multi-factor-authentication.md)), so **rotating it without keeping the old value in `APP_PREVIOUS_KEYS` makes every stored secret unreadable and locks out everyone who has one** (they then fail with a decryption error, deliberately not "wrong code"). Treat it as a long-lived, backed-up secret; rotate it only with the previous value retained. Recovery-code digests do not depend on it. The other holders of the key are session and cookie encryption, and the digest that binds a half-finished sign-in to the password proved (lasting minutes).

The **procedure** is [app-key-rotation.md](../runbooks/app-key-rotation.md), and its central claims are proved by tests rather than asserted: old ciphertext keeps working while the old key is a previous key, new ciphertext is written under the current key, and retiring the old key breaks the old ciphertext loudly. A test also asserts that **nothing else in the schema is ciphertext**, so the runbook's list of what a rotation touches stays complete.

**A database backup is worthless without the key it was taken under.** Restoring one under a different `APP_KEY` leaves every enrolled authenticator intact and permanently unreadable. See [backup and restore](../runbooks/backup-and-restore.md).

## Development credentials

The MariaDB and PostgreSQL passwords in `compose.yaml` and the `.example` files (`flowlife_dev_only`, `flowlife_root_dev_only`) are **throwaway values for a local, loopback-only database**. Do not reuse them anywhere real. They are the only credentials in the repository; there are no production credentials and none should be invented.

## Debug vs production

`.env.example` is a **development** configuration (`APP_ENV=local`, `APP_DEBUG=true`, Mailpit, dev database host). Production must run with `APP_ENV=production` and `APP_DEBUG=false`, with its own host-supplied database credentials, mail settings and `APP_KEY`. `./flow db fresh` refuses to run unless `APP_ENV=local`.

## Frontend

Everything prefixed `VITE_` is embedded in the public JavaScript bundle. Never put a secret, token or credential in a `VITE_*` variable; only public values such as the API base URL.

## Practices

- Keep secrets out of shell history and CI logs; prefer the platform's secret store for CI.
- Rotate anything suspected leaked, and remove it from history rather than just deleting the file.
- Production secrets on cPanel live outside the web root or in host-managed environment configuration (to be specified in the deployment runbook).

## Dependencies

Advisories against the packages this platform depends on are audited by `./flow audit` and a weekly workflow, deliberately outside the deterministic gate. See [dependencies](dependencies.md) for how a finding is judged and what the current findings are.

## Production configuration

`php artisan security:production-check` (`./flow doctor --production`) reads the configuration a deployment is running under and refuses the dangerous development values — most importantly `APP_DEBUG=true` and `IDENTITY_COMPROMISED_PASSWORD_CHECK=none`, both of which are in the development example file and reach production by being copied. It also prints what it cannot see; see the [production readiness runbook](../runbooks/production-readiness.md).

## Not in place yet

Secret scanning (for example a pre-commit hook or a CI scanner such as gitleaks) is a recommended next step but is not configured; today the protection is `.gitignore`, placeholder-only examples and review.
