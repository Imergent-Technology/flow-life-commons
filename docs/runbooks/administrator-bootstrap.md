# Runbook: create the first (or a recovery) administrator

- **Purpose:** create a platform administrator when none exists, or when every administrator is locked out ([ADR 0020](../adr/0020-administrator-bootstrap-and-last-administrator-invariant.md)).
- **Owner:** whoever holds server access to the platform.
- **Last tested:** 2026-09-20, against the development stack (MariaDB), by hand and by the automated tests.
- **Status:** the command works and issues the invitation. **Accepting the invitation, which is how the new administrator sets their password and becomes able to sign in, is not built yet.** Until that phase ships, the administrator exists and holds the role but cannot sign in.

## Why this is a command

There is no default administrator, no superuser flag and no environment variable that confers authority. The root of trust is that **you can run commands on the server**, which is already total, so the command confers no new authority and leaves no standing credential. It is deliberately **not** reachable over HTTP and is not a mode of role administration.

## Prerequisites

- A shell on the platform (`./flow artisan ...` in development; `php artisan ...` on the host).
- The migrations have run.
- The person's email address (printable ASCII; internationalised domains as punycode) and display name.

## Steps

1. Run the command. The email is the argument and the name is `--name`:

   ```bash
   ./flow artisan identity:create-administrator ada@example.org --name "Ada Lovelace"
   ```

   Run from a terminal with either omitted, it asks. In a non-interactive run (a script, CI) both must be given.

2. Read the output. On success it prints the Person and Account ids, the address, the expiry (7 days by default, `IDENTITY_INVITATION_TTL_DAYS`) and **the invitation token**:

   ```
   Administrator invited.
     Person       01...
     Account      01...  (status: invited; no password is set)
     Email        ada@example.org
     Expires      2026-09-27 12:50:14 UTC

   Invitation token. It is shown ONCE, is not stored, and cannot be recovered:

     EubKGCEDCggW92mhvrJFCQhm3a540VtvXVCsqR9VNyc
   ```

3. **Deliver the token to the administrator yourself, over a channel you trust.** Nothing is emailed. It is shown once, only after everything has been written and committed; it is stored only as a hash, and it is not in the audit trail or the logs. If it is lost, you cannot recover it (see *Rollback* below).

## What it does, and does not, do

It creates, in **one transaction**, a Person, an **invited** Account, a single-use expiring invitation, and a `platform_administrator` assignment, and records `account.invited`, `role.granted` and `administrator.bootstrapped`. If any write or any audit write fails, **none of it remains and no token is shown.**

It never sets or accepts a password. It never repurposes or changes an existing account.

## When an administrator already exists

The command **refuses**, changes nothing, and says how many administrator assignments exist and how many of those accounts can currently sign in. An invited or disabled administrator still counts as existing: recovery is an explicit act, not an inference.

To recover from a total lockout, re-run with `--force` **from an interactive terminal**:

1. `--force` on its own is **not enough**, and a non-interactive run refuses outright, so a script cannot bypass the protection by adding a flag.
2. The command warns that this creates *another* administrator and asks you to **type the new address back**. Anything else aborts with nothing changed.
3. It then behaves as above, records `recovery: true`, and leaves every existing account exactly as it was.

If no administrator exists, `--force` is unnecessary and the command says so.

## When the address is already in use

It fails, changes nothing, and tells you. An existing account is never taken over, merged or promoted by this command, so if the person already has an account you must use a different address.

## Verification

- `security_events` has `account.invited`, `role.granted` and `administrator.bootstrapped` for the new person, none with a token in them.
- `accounts` shows the new account with `status = invited` and `password_hash` empty.
- `account_invitations` has one row with a 64-character `token_hash` and no `accepted_at`.
- `role_assignments` has one `platform_administrator` row for that person.

## Rollback

Nothing has been sent and nothing is usable yet, so there is little to undo. There is no operator command yet to revoke an invitation, re-issue a lost token or disable an account: those arrive with the administration workflow. If a token is lost, run the command again with `--force` and a *different* address (the address of the unaccepted invitation is still in use); the lost invitation simply expires.

## Notes

- The command is not serialised against a second bootstrap running at the same instant (there is no row to lock while none exists). The worst outcome is two administrators, each created by an authorised operator and each audited.
- Once an administrator is *active*, the platform refuses to revoke their role or disable their account if that would leave no active administrator ([ADR 0020](../adr/0020-administrator-bootstrap-and-last-administrator-invariant.md)); recovery is for when that safety net has already failed for another reason.
