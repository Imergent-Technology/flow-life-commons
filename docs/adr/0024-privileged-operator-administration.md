# ADR 0024: Privileged operator administration

- **Status:** Accepted
- **Date:** 2026-09-22
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0008](0008-platform-owned-authorization.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0020](0020-administrator-bootstrap-and-last-administrator-invariant.md), [ADR 0022](0022-password-policy-and-credential-handling.md), [ADR 0023](0023-multi-factor-authentication.md)

## Context

Until now nothing on the platform could change another person's access. Roles were granted by tests and by the bootstrap command, an Account could be disabled by a use case nothing exposed, and a person who lost their authenticator and every recovery code was locked out for good ([ADR 0023](0023-multi-factor-authentication.md) said so). Phase 8 adds the first surface that can alter someone else's access, so the decisions that keep it safe have to be made once, and made to survive later phases:

- **Who may do it, and how that is asked.** Authorization is capabilities ([ADR 0017](0017-capabilities-and-roles-in-code.md)), and Identity cannot depend on Access. Every mutation already has an Identity use case that says it "does not authorize its caller"; something has to, and it must not be forgotten by one controller.
- **How strong the proof must be.** A stolen session cookie must not be enough to hand out access. [ADR 0023](0023-multi-factor-authentication.md) built `security.verified` for exactly this and nothing used it.
- **What an invitation proves.** Phase 5 settled that `email_verified_at` means demonstrated control of the mailbox, and that no invitation was delivered to an address, so nothing could set it. The first delivered invitation changes that.
- **What happens when a mail server is down**, without pretending SMTP is part of a database transaction.
- **Recovering someone whose second factor is gone**, without a back door and without distorting the last-administrator invariant.

Three facts were measured or read rather than assumed:

- **A pending sign-in is not attributable to an Account.** It lives in a session row that has no `user_id` (nobody is signed in), so "end every session of the Account" cannot reach it. What stops one is that completing it re-reads the Account under a row lock and needs a live factor ([ADR 0023](0023-multi-factor-authentication.md)); a reset that removes the factor therefore defeats it without a parallel mechanism. Verified by a race test that pauses a reset mid-transaction.
- **An invitation's raw token is only ever a value in memory**, returned from the transaction that created it. That is what lets delivery be a separate step after the commit.
- **Removing the invitation lock from a reissue does not change the outcome of any race**, because the Account lock still serialises them. It changes the lock *order*, which decides whether a reissue and an acceptance can deadlock, so the order is pinned by its own test.

## Decision

### Capabilities: four, named for what they govern

`identity.accounts.view`, `identity.accounts.manage`, `identity.invitations.issue`, `identity.mfa.recover`. `access.roles.assign` continues to govern role changes. Each exists because something checks it. `platform_administrator` receives all of them automatically (its bundle is derived from the catalog). **`guardian` gains none**: being allowed into the Console is not being allowed to administer it. There is no `admin` capability and no `is_admin` anywhere. Viewing and managing are split because reading the account list is a materially smaller power than disabling someone.

`identity.mfa.recover` mentions MFA, and [ADR 0023](0023-multi-factor-authentication.md) said there is no `mfa.*` capability. That refers to *the requirement to have a second factor*, which stays the surface's and never a capability or a role (a test still forbids one). This capability is the power to **recover someone else's**: an ordinary administrative act, checked like any other, whose one permitted name the architecture test now spells out.

### Access owns the administration surface; Identity keeps the mutations

The administration HTTP layer lives in **Access** (`Access\Http`, `Access\Application`), because Access may depend on Identity and Identity may not depend on Access. Each operation is an Access use case that **authorizes first** (a capability, against current persisted state) and then calls the Identity use case. Identity's use cases are unchanged in meaning: they decide lifecycle rules and take locks; they never ask "may this caller". Architecture tests pin that the Identity mutation use cases (`DisableAccount`, `EnableAccount`, `ResetMultiFactor`, `ReissueInvitation`, `InviteAccount`, `DeliverInvitation`) each have named callers, which are Access's Application use cases (and, for the reset, Identity's own server command), and never an HTTP controller, so a controller cannot skip the question. Identity's `AccountDirectory` read model is reached the same way. An invitation's channel is an Identity Domain concept, so Access chooses it by which method it calls (`InviteAccount::byEmail`, not the plain invocation the bootstrap uses), and never names the type.

Access's Domain stays free of Identity. It reaches Identity's Application layer only, so what crosses is plain strings and read-model values, never an Identity Domain type.

### Every mutation needs the capability AND recent verification

Routes sit behind `stateful`, `auth:web` and `can:console.access` (so the whole surface is inside the boundary where a second factor is already required), the mutating ones additionally behind `security.verified`, and the use case decides the specific capability. The capability is checked in both the route and the use case: the route so a caller without it is refused before being asked to prove anything, and the use case so no other caller can skip it. Reads need the capability but not a fresh proof.

The Console reacts to `403 verification_required` with a real proof prompt (password plus second factor, the existing `POST /security/verify`). **It never retries the action by itself:** after a successful proof the person confirms again. That keeps a mutation from happening on a click they made before the prompt, and never stores the proof.

### Roles are Access's, and the Console displays what Access says

The role catalog is served by Access (`GET /admin/roles`: key, display name, description, capabilities) and the Console renders it as data. No system role key appears in Console source (a scan pins it). The wire uses the word `assignments` for what a person holds, so the Console's existing scan for role-shaped code stays meaningful and nothing in the browser reads a role property. The backend's rule that role keys live only in Access is narrowed, not weakened: Access's own Http layer may serialise Access's own catalog, and nothing else may.

### An invitation carries how it was delivered, and that decides email verification

`account_invitations.channel` is `operator` or `email`, fixed when the invitation is issued. Accepting an **email** invitation, which the platform sent to the address, sets `email_verified_at`; accepting an **operator** invitation (the administrator bootstrap, whose token an operator prints and hands over) does not. `Account::verifyEmail` is its own explicit operation, as [ADR 0022](0022-password-policy-and-credential-handling.md) said it would be, and the acceptance records the channel in its event. There is no general verification-provenance model and no argument on `activate`.

### Delivery is after the commit, outside it, and visible when it fails

`InviteAccount` and `ReissueInvitation` create state and return the one-time secret from a committed transaction; `DeliverInvitation` then sends it. The message is not queued (a queued job would store the token). If the mail system refuses, **the state stays committed**: the Account exists, its invitation exists, and the caller is told delivery failed (`delivery.status: failed`, `invitation.delivery_failed` recorded, no secret anywhere). The unsent token is unrecoverable, so the remedy is one coherent operation, **reissue**: it deletes every unaccepted invitation of the Account, issues one fresh invitation and delivers it. There is never more than one usable invitation. It works only for an *invited* Account. The administration response never contains the secret. The token appears in exactly three places (the mail adapters and the operator's bootstrap console output), and a source scan pins that.

### Re-enabling returns the door, not the person

`EnableAccount` puts a disabled Account back to the status it had (active if it ever chose a password, invited if it did not), in one transaction that locks and re-reads the Account. It creates no session, sets no password, changes no role, and does **not** bypass MFA: an enabled Console user still presents a second factor, or enrols if theirs was reset. Enabling an Account that is not disabled is refused (`409`), so an operator is told what happened.

### Administrative MFA recovery is a separate, narrow operation

`ResetMultiFactor` removes another Account's authenticator (active or pending) and every recovery code, ends every session of the Account, and records `mfa.administratively_reset`. It leaves the password, the status, the roles and the Person alone, generates no secret and shows no code. The next password sign-in walks the person into enrolment ([ADR 0023](0023-multi-factor-authentication.md)). **Resetting your own second factor through it is refused** at the use case, not only in the UI: self-service replacement already exists for someone who still holds a factor, and an operator who lost everything is another operator's or the server's problem. It needs `identity.mfa.recover` and recent verification.

It is not a way to remove administrator authority, so **the last-administrator invariant is left exactly as it was**. A sole administrator who loses their second factor is recovered by the server command, which is what the command exists for.

### The server-level recovery command

`identity:reset-mfa` is rooted in server execution access, like the bootstrap ([ADR 0020](0020-administrator-bootstrap-and-last-administrator-invariant.md)), and confers no new authority. It runs the same use case with no Actor and records `mfa.reset_from_server`. It refuses to run non-interactively (no flag overrides that), shows who the target is and what will happen, and requires the operator to type the canonical email back. It never sets a password, changes a role or a status, or prints a secret. It is Identity's command because it needs only Identity, unlike the bootstrap.

### Errors are machine-readable where the Console needs to tell them apart

`403` with `verification_required: true` (unchanged), `403` denied, and a `code` on the `409` and `422` answers the Console must distinguish: `last_administrator_required`, `email_already_in_use`, `invitation_not_issuable`, `account_not_disabled`, `mfa_not_enrolled`, `self_mfa_reset_prohibited`, `unknown_role`. The Console never parses message text.

## Consequences

- An operator with the right capability *and* a fresh proof can invite, reinvite, disable, enable, change roles and recover a second factor, all audited, all going through the use cases the earlier phases proved.
- **A committed reset does not reach a session the transport creates a moment later.** The challenge commits before the session is established; a reset that lands in between finds no session to end, and the session then exists. The window is the time between the challenge's commit and the end of that request, and it needs an attacker who holds the password *and* a working authenticator to be finishing a sign-in at the instant an operator resets it. Closing it would mean holding the Account lock across session establishment, which the framework's session lifecycle makes invasive; it is recorded as a known limit rather than solved here.
- **A pending sign-in is neutralised, not deleted** (see Context). Its row remains until it expires.
- **Nothing rate limits how often an operator may reissue an invitation.** Each reissue mails one message to an address an authorized, freshly verified operator already knows. It is audited. A cooldown can be added without changing anything decided here.
- Disable and enable racing each other are decided on committed state, but `DisableAccount` reads the status once before its transaction to exit early: an enable that has not committed yet is invisible to it, so the disable reports "already disabled" and the enable then commits. That is a valid ordering of two opposite requests, and is stated rather than hidden.
- The Console is now the first surface that renders data about other people. It shows administration-relevant fields only; it is not a contact record.
- **The browser suite gained a test-only seam** (sessions the platform mints by its own sign-in, for journeys that are not about signing in), so it does not multiply password-and-second-factor logins as set-up. It is local and testing only, unreachable from the application, and its output is git-ignored; tests pin each. It is described in [testing](../development/testing.md).

## Alternatives considered

- **Put the administration endpoints in Identity and let it ask Access.** Rejected: it closes the dependency cycle ADR 0020 exists to prevent.
- **Authorize in the controller.** Rejected: a use case reachable from two adapters (a controller and a command) would depend on each remembering. The use case authorizes, and a scan forbids a controller from calling the Identity mutation directly.
- **A role check (`administrator`) for the sensitive routes.** Rejected for the reasons of ADR 0017; it would also have made the last-administrator invariant the only thing between a guardian and administration.
- **Retry the action automatically after the proof.** Rejected: it acts on a click made before the person re-proved who they are.
- **Roll back the Account if the invitation email fails.** Rejected: it makes a mail outage look like a failed operation and loses the audit trail of who was invited; a reissue is the honest repair.
- **A `verified_by` provenance field on the Account.** Rejected: one boolean-shaped fact (was the token mailed) belongs on the invitation that carries it, not on a general model nothing else needs.
- **Storing or encrypting the invitation token so it can be resent.** Rejected: a stored secret is what the design forbids; a fresh one is cheap.
- **Letting an operator reset their own MFA through the administrative route.** Rejected: it would turn a stolen, freshly verified session into a way to swap the second factor for one the thief controls, with no old factor required.
- **A generic emergency-access framework.** Not built: one command, one narrow use case.
