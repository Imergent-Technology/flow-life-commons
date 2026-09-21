# Runbook: recover a lost second factor

**Purpose.** A person who has lost their authenticator **and** every recovery code cannot sign in to the Guardian Console. This is how they get back in, without a back door: their second factor is removed, and they enrol a new one after proving their password. Decisions and alternatives: [ADR 0024](../adr/0024-privileged-operator-administration.md).

**Use it when** the person cannot use their authenticator *and* has no recovery code left. If they still hold either, do not use this: they can replace the authenticator themselves under **Account security** (it needs their current password and a working factor), or regenerate their recovery codes.

There are two paths. Use the first whenever another administrator can do it.

## Path 1: another administrator, in the Console

**Prerequisites.** An administrator (one who holds the account-administration capabilities, in practice `platform_administrator`) other than the person being recovered, signed in to the Console. You will be asked for your **own** password and a code once, because this changes someone else's access.

1. Check the request really comes from the person. The reset ends their sessions and removes their second factor; nothing else stands between whoever asks and their account. Use a channel you already trust (in person, a known phone number), not the email address the request arrived from.
2. Console → **Accounts** → find them → open the account.
3. Under **Two-step verification**, choose **Reset two-step verification**.
4. Read the dialog: it says their authenticator and recovery codes stop working, they are signed out everywhere, and they must sign in with their password and set it up again. Choose **Reset two-step verification**.
5. If asked to **Confirm it is you**, enter your password and a code, then press the button again. (The Console never repeats an action for you after the check.)
6. The account now reads **Not set up**. Tell the person to sign in with their password: they will be taken to **Set up two-step verification**, and must save the new recovery codes before continuing.

You cannot do this to your own account: the button is not offered and the server refuses it. Ask another administrator, or use Path 2.

**What it changes:** their authenticator and recovery codes are removed and their sessions ended. **What it does not:** their password, their status, their roles, their history. It shows you no secret and no code.

## Path 2: the server operator (when no other administrator can)

The case this exists for: the **only** administrator has lost their second factor. Its authority is that you can run commands on the server; there is no HTTP route to it.

**Prerequisites.** A shell on the platform's server, in the application directory, in an **interactive** terminal.

```
php artisan identity:reset-mfa the.person@example.org
```

1. The command shows who the address belongs to (name, address, account id, status, and whether an authenticator is set up).
2. It tells you what it will do. To continue, **type the address again**. Anything else stops it with nothing changed.
3. It ends with `Second factor reset. N session(s) ended.` and records `mfa.reset_from_server`.
4. Tell the person to sign in with their password; they will set up a new authenticator.

It refuses to run non-interactively, and no flag changes that. It never sets a password, changes a role or a status, or prints a secret.

## Verification

- The account shows **Not set up** in the Console (or the command reported a reset).
- The person signs in with their password and is taken to **Set up two-step verification**, not to a code prompt. Their old authenticator and old recovery codes are refused.
- The audit trail has `mfa.administratively_reset` (with the acting administrator) or `mfa.reset_from_server`.

## If it goes wrong

- *"That account has no two-step verification to reset"* (`mfa_not_enrolled`): there was nothing to reset. They may not be enrolled; they will be asked to enrol at sign-in.
- *Asked to confirm it is you, and the check fails*: your own second factor is being asked for. If you have lost yours, another administrator or Path 2 recovers you.
- *The person cannot sign in even after the reset*: their password may be forgotten (the password reset by email applies) or the account may be **Disabled** (re-enable it first: Accounts → the account → **Re-enable this account**).

## Rollback

There is none, by design: a removed factor cannot be restored, and should not be (that would restore a credential someone reported lost). Their new authenticator is set up fresh.

## Notes

- **Not the last-administrator rule.** Resetting a second factor does not remove administrator authority, so it is not blocked when the person is the only administrator; that is precisely when Path 2 is needed.
- **A sign-in in flight.** If the person had already entered their password and was on the code screen when the reset happened, it can no longer be completed (there is no factor to check it against). A very narrow window remains for a sign-in that had *just* completed; see the known limits in [identity and access](../architecture/identity-and-access.md).
- **Owner:** whoever holds server access for the deployment. **Last tested:** with Phase 8 (backend suite on both engines, and the browser journey in `e2e/administration.spec.ts`).
