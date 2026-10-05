# Backup and restore: what must be kept together

**Purpose.** The security-critical facts a backup must respect, and the contract a restore must satisfy before it touches the database.

**Owner.** Whoever holds the hosting account.

**Status.** The *contract* below is decided ([ADR 0027](../adr/0027-release-and-deployment-model.md)). The procedure for **taking** a backup is in the [deployment runbook](deployment.md#5-taking-a-backup). **No backup or restore tooling exists**, and `./flow` has no `backup` or `restore` command; both are operator actions, deliberately ([runbooks](README.md)). The **dump command** below is verified on the production host (2026-09-21); the restore contract has never been exercised there.

---

## The one rule

> **A database backup is worthless without the encryption keys that were in use when it was taken.**

Not the current key — the **keyring**. A dump can contain ciphertext written under any key that was ever current, which is exactly the situation during and after a rotation, and a rotation is when a restore is most likely to be needed.

Enrolled authenticators are stored as ciphertext under the application key ([ADR 0023](../adr/0023-multi-factor-authentication.md)). Restoring `accounts` and `account_totp_factors` under a *different* key gives every enrolled person an unreadable secret: they cannot pass their second factor, and no administrator can decrypt it for them. The data is intact and permanently meaningless.

So a backup is a **pair**: the database dump, and the keyring it corresponds to. Store them so that both are available together, and so that restoring the first without the second is not a step anyone can take by accident.

Dating them identically is no longer the mechanism. Every backup carries a manifest recording **non-secret fingerprints of the complete keyring** it depends on, and the restore checks them before touching the database (see [The restore contract](#the-restore-contract)). The fingerprints identify which keys are needed; they do not contain them, and they are not a substitute for key custody.

This is survivable, but only because of the escape hatch below. It is still a bad day.

## What must be restored consistently with each other

These are not independent tables. A restore that takes some at one point in time and some at another produces states the application's invariants say cannot exist.

| Records | Why they belong together |
| --- | --- |
| `people` and `accounts` | An Account cannot exist without its Person ([ADR 0015](../adr/0015-identity-owns-person.md)); the foreign key is `RESTRICT` and the application asserts it. |
| `role_assignments` | Authorization is derived live from these. Restoring accounts without them leaves nobody able to administer anything; restoring them without accounts leaves grants pointing at nothing. **Including the last-administrator invariant**: a restore that loses the administrator's assignment locks the platform out of its own administration, recoverable only by `identity:create-administrator` on the server. |
| `account_totp_factors` | Ciphertext, readable only under the matching keyring (above). Restored *older* than `accounts`, a person who replaced their authenticator gets the old one back. |
| `account_recovery_codes` | Digests, key-independent. Restored older than the factors, **already-spent codes become usable again** — each is single-use by a `used_at` column, and an old backup un-spends them. |
| `account_invitations` | An invitation restored after it was accepted becomes usable again, which is a way to set someone's password. Accepted invitations are deleted, so a stale restore resurrects them — which is why reconciliation below is a required step, not a suggestion. |
| `membership_grants` | Durable business data, restored with data ([ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md)). `person_id` is a `RESTRICT` foreign key to `people`, so grants and people must come from the same point in time. Revocation is a later update to the same row (`revoked_at`): restored *older* than the rest, a grant revoked in between is live again. |
| `security_events` | Append-only history ([ADR 0019](../adr/0019-security-event-auditing-seam.md)). Restoring it *older* than the rest silently erases the record of whatever happened in between — including whatever caused the restore. |
| `resource_*` tables **and the Resource files** | Durable business data ([ADR 0037](../adr/0037-resources-are-audience-targeted-packs-of-cards.md)), and the one case where the database alone is not the record: `resource_assets` names files that live **outside the database**. Restored without the files, every File Card answers `asset_unavailable`; restored with files from a *different* point in time, some Cards lose their file and some files belong to nothing. See [Resource files](#resource-files). |

**Outside the database:** `APP_KEY` and `APP_PREVIOUS_KEYS`, which live in the environment file and are not in any dump. Back them up deliberately and separately from the database, because a single compromised store holding both the ciphertext and the key protects nothing. **And the Resource files** (below), which are part of the same backup as the dump, not a separate concern.

## Resource files

**What they are.** Each File Card owns one file ([ADR 0037](../adr/0037-resources-are-audience-targeted-packs-of-cards.md), decisions 62-68). The file's metadata is a row in `resource_assets` (in the dump, with data, like every durable table); its **bytes** are a file in the private `resources` disk:

```
/home/<user>/commons/shared/storage/app/private/resources/<storage key>
```

One file per asset, named by the asset's id (26 lowercase characters, no extension), mode `0600`, nothing else in the directory. It is under `shared/storage`, so it survives releases ([ADR 0027](../adr/0027-release-and-deployment-model.md)); it is outside the document root and never served except by an authorized Resources route. A stored file is **never changed**: replacing a Card's file writes a new one and removes the old, so a copy of the directory is consistent file by file.

**Taking it, with the dump.** Inside the same maintenance window, immediately after the two-pass dump ([deployment runbook §5](deployment.md#5-taking-a-backup)), so the files and the rows describe one moment (nothing writes while maintenance is up):

```bash
tar -C /home/<user>/commons/shared/storage/app/private -czf "$B/resource-files.tar.gz" resources
sha256sum "$B/resource-files.tar.gz"
```

and record `resource_files_sha256` and the file count in the manifest. A dump without its Resource files is not a full backup of Resources.

**Restoring it.** With maintenance mode up throughout (the scheduler does not run `resources:assets:prune` in maintenance mode, which is what makes this order safe):

1. Restore the database exactly as [the restore contract](#the-restore-contract) says.
2. Move the live directory aside, never delete it: `mv .../app/private/resources .../app/private/resources.before-restore-$(date -u +%Y%m%dT%H%M%SZ)`.
3. Verify `resource-files.tar.gz` against `resource_files_sha256`, then extract it into `.../shared/storage/app/private/`, giving `resources/` back.
4. **Check before anything removes a file:** `php artisan resources:assets:prune --dry-run`. Expect *Asset rows whose file is missing* to be **0** (every Card has its file) and *orphaned, would be removed* to be **0** (no file without a row). Anything else means the files and the dump are not from the same backup: stop and find the right pair.
5. Only then bring the application up. Remove the moved-aside directory once the restore is accepted, not before.

**The prune after a restore.** `resources:assets:prune` removes files no row refers to, older than a day. After restoring an *older* database over *newer* files, every file uploaded since that backup is exactly such a file, and the prune removes it, **irreversibly**. That is correct for the restored database and wrong if you might yet go back to the newer one. That is why step 2 moves the directory aside, why step 4 is a dry run, and why the daily schedule cannot fire during the maintenance window.

**The host's own backups are supplementary.** The authoritative restore pair for Resources is the Commons backup above: the dump, the Resource files archive and the manifest, taken together in one maintenance window. Provider or cPanel home-directory backups, if they exist, are not relied on for Resources, because nothing makes them consistent with a dump. Whether they cover `/home/<user>/commons/shared` is **not confirmed**. When checked on 2026-10-05, the account had no user-level backup exclusion, but listed no retained backups to inspect ([production readiness §4c, F4](production-readiness.md#4c-resources-managed-files-wp3)). Do not plan a Resources restore around them.

## Transient state does not come back

`sessions`, `password_reset_tokens`, the cache tables and the queue tables are **not restored with data**. A restored session, a restored queued job and a restored reset token are each a live thing resurrected from the past, and the last of those is a credential.

This is enforced by **how the dump is produced**, not by an instruction someone is expected to follow at the worst possible moment: the dump is written in two passes so that every table is recreated but only durable tables carry rows ([deployment runbook](deployment.md#5-taking-a-backup)). Restoring it verbatim is therefore the correct action. A full dump paired with a written warning is a trap, and this replaces one.

The cost is small and worth naming: anyone mid-password-reset requests a new link, and everyone signs in again. The security generation ([ADR 0025](../adr/0025-account-security-generation.md)) would have refused the old sessions regardless.

This is a **restore policy, not a retention policy**. Nothing here prunes or ages out durable data; see *Audit growth* below.

## The restore contract

Checked **before the database is touched**, in this order. Each step is a stop, not a warning.

1. **Checksum.** `sha256sum` the dump and compare it with `dump_sha256` in the manifest. A backup you have not verified is a hope.
2. **Compute the live keyring fingerprints** from `APP_KEY` and `APP_PREVIOUS_KEYS` in the environment you are restoring into.
3. **Subset test — the load-bearing one.** Every key fingerprint the backup requires must be present in the live keyring:
   - **Live ring contains all of them** → proceed. A live ring that is a strict superset is the normal post-rotation case and is safe.
   - **Any fingerprint missing** → **stop.** Name the missing fingerprint and find that key before going further. Restoring anyway leaves every authenticator enrolled under it permanently unreadable, and no administrator can undo that.
4. **Restore** the dump.
5. **Reconcile invitations.** List invitations whose account is already `active` and revoke them: the restore may have resurrected an invitation that was already accepted, and an unreconciled one is a way to set someone's password.
6. **Verify** as after a deployment ([health and release verification](deployment.md#7-health-and-release-verification)), then have someone sign in.

If step 3 fails and the key genuinely cannot be found, the platform is still recoverable and no passwords are lost — see the next section — but every enrolled authenticator must be reset and re-enrolled.

## If a database is restored under the wrong key

Not fatal, and worth knowing before it happens:

1. Everyone with an enrolled authenticator fails their second factor with a **server error**, not "wrong code" — the platform distinguishes these deliberately, so the cause is visible.
2. They sign in with a **recovery code** instead: those are digests and do not depend on the key.
3. Anyone with no recovery code left is recovered by another administrator, or by `identity:reset-mfa` on the server for the last administrator ([MFA recovery](mfa-recovery.md)).
4. Everyone enrols a new authenticator.

Passwords are unaffected throughout: they are bcrypt hashes.

## Audit growth

`security_events` is **append-only and never pruned** — the scheduled maintenance explicitly leaves it alone. It grows with every sign-in, failure, rate limit and administrative act. At the scale this platform is designed for (an invite-only operator directory) that is tens of rows a day, and a backup that grows slowly is the right trade against losing security history.

It is not unbounded attention-free forever. Recorded as a production follow-up, with no work in this phase:

- a retention policy, decided rather than assumed, with archival or export rather than deletion;
- an operator-facing audit viewer, which will need indexes this schema does not have (`occurred_at`, and probably `(subject_account_id, occurred_at)`);
- whatever legal retention obligations a charity's records carry, which is not an engineering decision.

**Do not solve this by deleting security history.**
