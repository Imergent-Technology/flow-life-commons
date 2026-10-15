# G6 WP1 — Owner Triage Decisions

**Milestone:** G6 Core Audit / Stabilization  
**Package:** WP1 Identity, Sessions, MFA and Credentials  
**Audit baseline:** `b5d37ad2e2823e184268d6cf43ea874784d88ff0`  
**Decision:** Authorize bounded remediation, subject to independent focused verification.

## Executive decision

Accept the independent Opus audit's overall assessment: zero BLOCKER, zero HIGH, one MEDIUM, three LOW and one INFORMATIONAL finding.

The audit's findings are preserved unchanged in `docs/audits/g6/wp1-audit.md`. These rulings belong separately in `docs/audits/g6/wp1-triage.md`.

### WP1-01 — MEDIUM: Password change extends an MFA session indefinitely

**Disposition: FIX. Both protections approved.**

**M1 / DP2 owner ruling:**

1. A successful password change must preserve the current session's original `authenticated_at` and absolute expiration. It must not restart the 12-hour lifetime. This applies to both MFA-enrolled and password-only Accounts.
2. An Account with an active enrolled authenticator must supply the current password AND a fresh second-factor proof in the password-change request.
3. Accept either TOTP or an unused recovery code through the platform's existing second-factor verification mechanism.
4. An Account without an enrolled authenticator may continue changing its password with its current password alone, including through `/my/security`.
5. Determine MFA enrollment and verify the proof against authoritative server state, under the appropriate account lock. Client-provided enrollment status is never authoritative.
6. Keep the existing successful password-change effects: new password, other-session revocation, security-generation advancement, current-session rebind and rotation, fresh CSRF token, and `password.changed` audit event.
7. A failed change must not alter the password, revoke sessions, advance security generation or consume a recovery code or TOTP step.
8. Do not silently turn the password change into a new sign-in, refresh its absolute lifetime or broaden the recent-security-verification window.

Implement the password-and-factor requirement using existing platform security abstractions rather than creating a parallel verification system.

**Validation:** Demonstrate the original flaw before remediation and prove both protections afterward, on MariaDB and PostgreSQL. Include appropriate concurrency, failure-atomicity and browser-facing regression tests.

**Release impact:** The issue exists in production v0.3.0. Its correction is required before G6 WP1 closeout and should be included in the next authorized release. No deployment is authorized by this ruling.

### WP1-02 — LOW: Rate-limit check/count race

**Disposition: DEFER WITH DOCUMENTED RISK.**

The audit identifies a plausible concurrent-request allowance beyond the nominal rate limit but does not quantify it with a measured burst.

Record it in the G6 debt register with the relevant endpoints, cache behavior and attack prerequisites.

Revisit if targeted measurement indicates material exploitation, before introducing higher-volume external authentication traffic, or when G7 revisits authentication infrastructure.

Do not alter rate-limiter semantics in this remediation package unless a concrete security concern justifies revisiting this decision.

### WP1-03 — LOW: Stale Identity documentation

**Disposition: FIX IN WP1.**

Correct the documentation describing password-change session behavior, MFA requirements and the unimplemented Account login-email change.

Distinguish CRM contact emails from Account login credentials. Preserve historical design discussion where appropriate and correct misleading statements about current behavior.

Update the implementation status and MFA recovery runbook where identified by the audit.

### WP1-04 — LOW: Direct proof gaps

**Disposition: ADD TARGETED REGRESSION TESTS.**

Prioritize the auditor's four recommended tests:

- Pending sign-in refusal on privileged MFA and security-verification endpoints.
- MFA enrollment-confirm lockout after the configured number of failed codes.
- The recent-security-verification clock-skew boundary.
- Administrative MFA reset invalidating existing sessions through security-generation advancement.

Preserve existing coverage and avoid duplicating tests already providing equivalent discrimination.

### WP1-05 — INFORMATIONAL: `Actor::$authenticatedVia`

**M2 owner ruling: ACCEPT AS A G7 ENTRY CONDITION.**

The current role catalog requires `console.access` for existing human roles and enforces a second factor for Console access. The auditor found no present authentication-strength bypass.

Record this dependency for G7's service-identity and delegated-access design gate.

Do not redesign Actor authentication provenance in WP1.

## Remediation boundary

Authorized changes are limited to:

- WP1-01 security correction.
- Shared password-change UI and API contract updates required by that correction.
- Focused WP1-04 regression tests.
- WP1-03 documentation corrections.
- The WP1 audit, triage, validation and debt records.

Do not implement WP2, WP3 or G7 work.

## Verification and integration

Sonnet implements the authorized remediation.

Every MEDIUM correction requires a discriminating regression test demonstrating failure before the fix and success afterward.

The complete dual-engine quality gate and relevant built-assets browser journeys must pass.

An independent Opus reviewer must then verify WP1-01, including the new MFA proof requirement, preservation of absolute session lifetime and failure atomicity.

Do not push or merge remediation before this review and the owner's integration decision.

**G6 WP1 TRIAGE: APPROVED FOR REMEDIATION**

**G6 RELEASE: NOT AUTHORIZED**
