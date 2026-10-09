# Member-facing access

**Status: WP1–WP5 complete.** The Member foundation is built and end-to-end validated; substantive Member-facing product expansion is parked (see "End-to-end validation" below), and product-development focus returns to Guardian-facing value. This is the consolidated design for how an ordinary Member (and later a Volunteer) reaches Commons, referred by [ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md) and [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md), in the style [identity-and-access.md](identity-and-access.md) uses for the Identity and Access design: the ADRs are the frozen decisions, this page is the living design and the record of how far implementation has gone.

## Where this sits relative to what already exists

Nothing here reopens Identity, Access or Membership. It composes what those design gates already built:

- **Authentication** is unchanged: the same-origin `__Host-` session cookie, database sessions, CSRF handling ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)).
- **The MFA policy mechanism** is unchanged: `SecondFactorRequirement`/`ConsoleMultiFactorPolicy` already tie enrolment to holding `console.access`, not to any role name ([ADR 0023](../adr/0023-multi-factor-authentication.md)). A Member simply never trips that policy, because a Member does not hold `console.access`.
- **Membership derivation** is unchanged: active membership is a query over `membership_grants` at request time, never a stored status or a role ([ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md)).
- **Authorization** is unchanged: capabilities are code-owned, resolved fresh on every request, default-deny ([ADR 0017](../adr/0017-capabilities-and-roles-in-code.md)).
- **WordPress's trust position** is unchanged: an untrusted client, never the authority ([ADR 0004](../adr/0004-wordpress-adapter-not-authority.md)).

What is new is *where* an ordinary Member's own experience is served, and the explicit statement that it does not need any of the delegation machinery ADR 0018 anticipated to exist first.

## Topology

```
flowlifeglobal.org (WordPress, Sucuri)          commons.flowlifeglobal.org (direct origin)
  public content, a "Member sign in" link  ──►  /login, /accept-invitation,
  no Commons credential, no Commons data          /forgot-password, /reset-password
                                                   (neutral Flow Life Commons presentation)
                                                 /my/…          Member self-service shell
                                                 /…             Guardian Console (console.access)
                                                 /api/v1/…      one Laravel API, one session guard
```

`/my/` is a working name for the route prefix, not a decided product label. It is a real route area as of Work Package 4 (below): `MemberShell` at `/my`, `/my/membership`, `/my/security`.

## `/my/` versus active membership — the distinction that must not collapse

**Reaching the member shell is not itself evidence of active membership, and must never be read as such.**

The member area is an authenticated self-service surface, open to any signed-in Account that has no higher-privilege destination. That includes, without distinction at the routing layer:

- a Member whose grant is active right now;
- a former or lapsed Member, whose grant has ended;
- a future Account type that has never held a membership grant at all.

Consequently:

- `GET /my/membership` (built, Work Package 2) answers truthfully for whichever of those is true — "no membership on record", "active, ends `<date>`", "active, open-ended", or "ended `<date>`" — and a Member-facing page must render all of those states, not assume the first one.
- Self-account operations (profile, password, security) are subject-based on the signed-in Account/Person, exactly as `/me` and `/password/change` already are, and need no membership check at all.
- **A genuinely Member-only resource**, when one exists, is authorized by an actual capability derived from *current* membership state — not by the fact that the request reached `/my/`, and not by a durable role. This is the mechanism [ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md) already reserved: Access defines the port, Membership implements it, and `Authorizer::capabilitiesOf` combines it with role-derived capabilities on every call, so a lapsed grant loses the capability on the very next request with no sweep and no stale session to worry about.

**No such capability is defined by this document.** One is added, per [ADR 0017](../adr/0017-capabilities-and-roles-in-code.md)'s rule, only when a real resource needs it — not speculatively, and not invented here to exercise the mechanism.

## Volunteer extension seam

**Decided, as corrected by [ADR 0036](../adr/0036-business-relationships-are-independent-and-not-access-roles.md):** Volunteering and Membership are separate business relationships; a Person may be a Volunteer, a Member, both or neither. (This page previously said Volunteers are Members with elevated duties or privileges; that is superseded.) A Volunteer is not a second identity type and Volunteering is not merely an Access role. A Volunteer with an Account reaches the same `/my/` surface any signed-in Account reaches, whether or not they are also a Member, and Volunteer-specific capabilities, when they exist, extend it, gated by capability (derived from the Volunteer relationship, never standing in for it) rather than by any special routing. A Volunteer relationship never implies `console.access`: Console admission comes only from Access grants, and [ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md) gives the Volunteer type no default role. (The `volunteers.view` and `volunteers.manage` capabilities that ADR 0038 proposes are for *managing* Volunteers in the Console, not Volunteer-facing tools.)

**Proposed by [ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md) (G10), not yet accepted or built:** the Volunteer relationship is owned by a `Relationships` module, as a durable relationship with Pending, Active and Inactive states, status history, reactivation and verified deletion, and only Active Volunteers qualify for the `volunteer` Resource audience.

**Still deliberately undecided, and not designed here:**

- duties, assignments, schedules, hours, or any other volunteer business data;
- Volunteer-facing tools on `/my/`, and whether any capability for them is derived from the relationship at query time (the mechanism above) — never a stored role standing in for the relationship.

Whatever shape Volunteering eventually takes, it composes with what this document decides — an authenticated Account reaching a capability-gated area of the same surface — without requiring this document to be revisited.

## WordPress's role

Exactly what [ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md) decided: public content, and a link into Commons. No plugin feature is required for that link — an ordinary WordPress menu item or button pointing at `https://commons.flowlifeglobal.org/login` (or wherever sign-in lives) needs no code in `apps/wordpress-companion`.

`apps/wordpress-companion` stays the skeleton it is today: no API call, no credential, no authenticated shortcode, no user mapping, no storage. [`scripts/tests/wordpress-boundary.php`](../../scripts/tests/wordpress-boundary.php) — run by `./flow check repo` — is expected to keep passing without modification through every work package below; it is written to fail loudly and require a deliberate, named revision the day a service client or delegated flow actually gets built (see [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md)), which is not this phase.

## Neutral credential presentation (implemented, Work Package 3)

The credential pages and mail copy used to assume every recipient was a Guardian Console operator: the shared `Wordmark` (`apps/guardian-console/src/ui/Brand.tsx`, moved there in Work Package 4 so the Member surface can use it too) always showed "Guardian Console" under "Flow Life Commons", `/login`'s intro named "Guardians and operators" specifically, and the invitation mail's subject and body said "You have been invited to the Flow Life Guardian Console".

**What changed:** `Wordmark` now takes an optional `subtitle`; the shared credential surfaces (`AuthLayout`, so `/login`, `/accept-invitation`, `/forgot-password`, `/reset-password` and the MFA screens reached from `/login`) render it without one, showing "Flow Life Commons" alone. The signed-in Guardian Console shell (`ConsoleShell`, `NavSheet`) still passes `subtitle="Guardian Console"`, since by the time it renders that is genuinely where the person is. `InvitationMail`'s subject and body now say "You have been invited to Flow Life Commons"; `PasswordResetMail` already carried no Guardian Console claim and needed no change. A source-discipline rule in `apps/guardian-console/src/guardrails.test.ts` (`allowedIn` naming the shell files and `ForbiddenPage`, which genuinely is Guardian-only) now keeps "Guardian Console" out of the shared surfaces going forward.

**Deliberately unchanged:** `ForbiddenPage` still says "this account cannot use the Guardian Console" — accurate and Guardian-specific, since it is shown only after `RequireConsoleAccess` has already refused that one surface — and `MfaEnrollment` still says "Access to the Console needs a second step"/"Continue to the Console", because enrolment genuinely is reached only by an Account gaining `console.access` (`SecondFactorRequirement`/`ConsoleMultiFactorPolicy`, ADR 0023): neither is the false universal assumption this package removes.

**Deferred at the time WP3 shipped, now done (Work Package 4):** the post-login destination logic described above.

## The Member self-service surface (implemented, Work Package 4)

`MemberShell` (`apps/guardian-console/src/member/`) is the authenticated self-service frame every signed-in Account without `console.access` reaches, and any Account may visit regardless. Deliberately simpler than `ConsoleShell`: one header at every width (brand, three flat nav links, the shared account menu), no rail, no secondary drawer, no capability-filtered navigation — there are only three destinations and nothing to filter yet.

**Routes** (`src/App.tsx`), all behind `RequireAuthentication` alone — no `console.access`, no capability, no membership check:

| Route | Page | Backend |
| --- | --- | --- |
| `/my` | Home: a greeting, the session (shared `SessionSummary`), a membership summary, links onward | `GET /me` (already used), `GET /my/membership` |
| `/my/membership` | Full membership state and grant history | `GET /my/membership` (Work Package 2, unchanged) |
| `/my/security` | Password change; two-step verification management ONLY if already enrolled | `POST /password/change`, `POST /mfa/recovery-codes`, `POST /mfa/authenticator(/confirm)` — all already `auth:web`-only |

**Post-login destination** (`src/auth/destination.ts`, `returnPath.ts`): `defaultDestination(current)` returns `/` for `console.access`, `/my` otherwise — one function, consulted from the one place a successful sign-in resolves (`LoginPage`; the MFA challenge and enrolment steps re-render through the same component, so nothing else needed its own copy). `returnPathFrom` honors a safe internal return path only when this Account can actually use it: `console.access` honors any safe internal path, unrestricted; an Account WITHOUT it is trusted with `/my` and `/my/...` alone (an allow-list of its own surface), falling back to `defaultDestination` for anything else — a growing inventory of Guardian-only routes is never something this module needs to keep pace with.

**The Guardian root** (`RequireConsoleAccess`) now distinguishes the wrong LANDING surface from an attempted PRIVILEGED one: at exactly `/`, an Account without `console.access` is sent to `/my` instead of refused; every other Console route (`/account/security`, `/admin/*`) still shows the existing `ForbiddenPage` outright. A Guardian may visit `/my/` directly too — holding `console.access` refuses nothing there — though signing in still lands a Guardian in the Console by default.

**No new backend capability.** Every `/my/*` route and every operation `/my/security` exposes was already available to any authenticated Account before this package; WP4 only gives them a Member-facing home. Nothing here uses membership state to decide whether `/my/` itself may be entered — reaching it is still not evidence of active membership, exactly as this document requires above.

**MFA stays sign-in-time-only.** `/my/security` shows two-step verification management (regenerate recovery codes, replace the authenticator) only when `current.mfa.enrolled` is already true; an unenrolled Account sees password change alone. First-time enrolment remains part of signing in (`MfaEnrollment`), triggered only by gaining `console.access` (`SecondFactorRequirement`/`ConsoleMultiFactorPolicy`, ADR 0023) — this page does not invent a second way in.

**Import boundary.** `src/member/**` may import `src/ui/**`, `src/api/**` and `src/auth/**`, never `src/pages/**`, `src/shell/**` or `src/admin/**` — enforced by `src/member/import-boundary.test.ts`, which scans real import specifiers (a static `from` clause, a bare side-effect import, and a dynamic `import()` alike) rather than trusting review. Four small extractions made the neutral pieces `/my/*` needed reachable without crossing that line: `Wordmark`/`Badge` (`shell/Brand.tsx` → `ui/Brand.tsx`, `Wordmark`'s `subtitle` already optional since Work Package 3), the account menu (`shell/AccountMenu.tsx` → `ui/AccountMenu.tsx`, now taking a `securityPath` prop so each surface points at its own security page), the generic async-load hook (`admin/useLoad.ts` → `ui/useLoad.ts`, which never had any admin-specific logic), and `MfaSection` (`pages/MfaSection.tsx` → `ui/MfaSection.tsx`, added in this package's own post-audit remediation once the guard was tightened to forbid `pages/**` outright rather than carry a one-file exception for it). The password-change panel was extracted directly into a new shared `ui/ChangePasswordPanel.tsx` rather than duplicated, since `AccountSecurityPage` and the Member `SecurityPage` need the identical operation.

## The existing-Person invitation, exposed in the Console (implemented, a WP1 UI follow-up)

WP1 built `POST /admin/people/{person}/invitation` (`Access\Application\InviteExistingPerson`) with no Guardian UI to reach it — a real gap discovered starting WP5, whose Journey A needs an operator to actually invite an existing Person through the product, not a raw API call. This closes that gap narrowly, on the existing Member detail page (`pages/admin/MemberDetailPage.tsx`), rather than building a new admin surface.

**Membership's own responses are untouched.** An existing invariant, `MembershipDisclosureTest` (Membership's own Work Package 7), scans every response Membership's admin surface returns and refuses the literal word "account" anywhere in it, under any key, at any depth — a deliberate, already-shipped boundary, not something this package gets to carve an exception into. So `GET /admin/members/{person}` gained nothing: `Member`'s schema, `MembershipPresenter` and `ShowMemberController` are exactly as they were before this package.

**Whether a Person has a Commons Account yet is Access's own fact, on its own route.** `GET /admin/people/{person}/commons-access` (`Access\Http\ShowCommonsAccessController`, backed by `Access\Application\DescribeCommonsAccess`) answers `commons_access: { state, can_invite }`, where `state` is `not_invited | invited | active | disabled` (`Access\Application\CommonsAccessState`, distinct from Identity's own `AccountStatus`: `not_invited` is the one state an Account, which must already exist to have a status, cannot represent) and `can_invite` is `true` exactly when `state` is `not_invited` — derived once in the use case, from the same precondition `InviteExistingPerson` itself enforces, so the Console never has to re-encode that rule. Never an Account id, an email or a capability. Guarded by `identity.invitations.issue`, the same capability `POST /admin/people/{person}/invitation` needs: the one reason to ask this question is deciding whether that action may run, so nothing here is shown to an operator who could not act on the answer anyway. Fails closed — an unknown Person is a `404 person_not_found`, never a false `not_invited`.

**Composed on the page, not in either response.** `InviteToCommonsSection` (`pages/admin/InviteToCommonsSection.tsx`) self-fetches `GET /admin/people/{person}/commons-access` given only a `personId`, the same "a section composes a cross-context fact given just an id" shape `AccountMembershipSection` already uses in the other direction (Membership data self-fetched onto the Account page). Shown on the Member detail page only to an operator holding `identity.invitations.issue`: an email field and a submit button when `can_invite` is `true`, or a one-line status ("Invited: has not set a password yet.", "Has an active Commons Account.", "Has a Commons Account, currently disabled.") when it is not. A successful invitation shows the same one-time confirmation `InviteOperatorPage` shows (sent, or created-but-undelivered), updating the section's own local state to the known-true `invited` outcome directly rather than re-fetching — the confirmation is never at risk of being replaced by a race with a background re-read, because there is no second read to race. No new Person is created and no role is assigned (WP1's own distinction from "Invite an operator"), and step-up (`security.verified`) is unchanged: the existing `useAdminAction` seam handles it exactly as `InviteOperatorPage` already does.

**Reading Identity's account directory, without widening who may.** An architecture test (`AccessBoundariesTest`) keeps Identity's `AccountDirectory` read only through Access's own enumerated presenting use cases (`AccountViews`, `ListManagedAccounts`, `GrantRoleToAccount`, `RevokeRoleFromAccount`); `DescribeCommonsAccess` joins that same list as one more of Access's own. `AccountDirectory` gained one new port method to support it, `findByPersonId` (mirroring its existing `find(AccountId)`), implemented in `DatabaseAccountDirectory` the same way. `DescribeCommonsAccess` also composes Identity's `FindPeople` (a batched name lookup, reused here only for its existence check) to fail closed on an unknown Person — the one other module beyond Membership\Http this port is now used from, both for the same reason its own doc comment gives: "an admin surface for another module... asks this instead of reading Identity's tables."

## End-to-end validation (WP5, implemented)

`e2e/member-foundation.spec.ts` proves the whole foundation this document describes works together, in real Chromium through the real gateway, as two independent journeys.

**Journey A — an existing Person gains a Commons Account.** A fresh Person with a real, currently-active Membership grant and no Account (created through the real `POST /admin/members` API, the same operation `membership.spec.ts`'s own "Add member" UI journey drives — never faked in the browser) is opened on its real Member detail page by an operator holding `identity.invitations.issue`. The Commons-access state loads separately from the Membership record and reads `not_invited`; "Invite to Commons" is used for real, and Mailpit receives the invitation — neutral copy (`Flow Life Commons`, never `Guardian Console`), the TTL, and the token in the link's fragment, confirmed against the real message including its subject line. The invitee follows the real link, sets a password on the neutral acceptance page, and the Account is active before any sign-in happens at all. Signing in (one step: WP1's existing-Person invitation assigns no role, so there is no second-factor challenge) lands at `/my`, where the SAME Membership grant seeded before the Account existed — compared by instant, not by re-reading the same post-Account state — is visible through self-service with no admin-only field disclosed. `/my/security` offers only password self-service, with no first-time MFA prompt. Signing out through the real Member menu ends the session.

**Journey B — an existing password-only Account is promoted to console.access.** A dedicated fixture (`E2eAccountSeeder::PROMOTION_*`, active, no role, no authenticator, read by no other spec) signs in and lands at `/my` with an ordinary password-only session. A separate operator grants it the Guardian role through the real Account detail page's own "Give them access" seam (`administration.spec.ts`'s established UI, exercised here for the first time against a previously non-Console Account). The already-open password-only session does not silently become privileged: the backend's own policy (`EnforceSecondFactorWhereDue` / `EndSessionWithoutSecondFactor`, ADR 0023) ends it on its very next request — proved live, in the still-mounted page, not by a hard reload (which would just remount fresh and skip the notice) — and `GET /me` genuinely answers 401. Signing in again reaches only the existing MFA enrolment screen, never the Console, until a real authenticator is set up and its recovery codes acknowledged; only then does the Account land at `/` in the Guardian shell by default.

Both journeys' mutated identities are dedicated and read by nothing else (the WP4 audit lesson, applied here): Journey A creates a random-suffixed Person and invitee email every run; Journey B's fixture is reset every run. The full suite (207 tests, `--workers=4`, plus the serial `@maintenance` pass) runs clean with these ten tests included, proving no shared-fixture collision. Representative negative controls (breaking Person continuity on invitation, restoring `Guardian Console` wording in the shared mail, breaking the non-Console/Console landing split, revoking the seeded grant, disabling the session-end enforcement, disabling the MFA policy, and misrouting the post-enrolment Guardian) were each applied, confirmed to make the relevant assertion fail, and reverted; none are in the tree.

**Substantive Member-facing product expansion is parked here.** The Member foundation (WP1–WP5) is complete and stays available for future capabilities, but the next product-development focus returns to Guardian-facing value.

## Delegation and service authentication: not built, and why that's fine

[ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md) settles this in full; the summary relevant here is that **nothing in this design needs either mechanism**. Every capability this document describes — sign-in, self-service, own-membership status, a future capability-gated Member resource — is served directly from Commons to a browser that already holds a Commons session. WordPress participates only as a link, which requires proving nothing.

## Work-package sequence

Each package is independently shippable and independently revertible; none depends on a later one existing.

| Package | Scope | Depends on |
| --- | --- | --- |
| **WP0** (done) | ADR 0032, ADR 0033, this document, stale-documentation reconciliation | — |
| **WP1** (done) | A generic Identity capability: invite an *existing* Person who has no Account (`Identity\Application\InviteAccountForPerson`, exposed as `POST /admin/people/{person}/invitation` — an Identity use case, not a Membership one; Membership already creates Persons with no Account via `RegisterPerson`, and had no way to give one an Account afterwards). The backend shipped with no Guardian UI for it; a small follow-up package, started ahead of WP5's Journey A, added one — see "The existing-Person invitation, exposed in the Console" above | WP0 |
| **WP2** (done) | `GET /api/v1/my/membership` (`Membership\Application\GetCurrentMembership`) — subject strictly the authenticated session's own Person, answering the full active/lapsed/none/open-ended range described above, with a privacy-minimized `CurrentMembership` DTO distinct from the admin one | WP0 (independent of WP1) |
| **WP3** (done) | Neutral Flow Life Commons credential-page and mail copy (`/login`, `/accept-invitation`, `/forgot-password`, `/reset-password`, `InvitationMail`) — see "Neutral credential presentation" above. Post-login destination logic is deliberately NOT activated here: there is no `/my/` destination yet to route a non-Console Account to | WP0 |
| **WP4** (done) | `MemberShell` and the `/my/*` route area (home, own membership status, own account/security) in the existing React application, with an enforced import boundary from Console/admin code (`import-boundary.test.ts`); activates member-aware post-login routing (`console.access` → the Console, everyone else → `/my/`) and the Guardian-root-to-`/my/` redirect for a non-Console Account — see "The Member self-service surface" above | WP1–WP3 |
| **WP5** (done) | Two real-browser vertical journeys (`e2e/member-foundation.spec.ts`): an existing Person is invited, accepts through real Mailpit mail, and reaches `/my/` with their Membership record intact; a password-only Account is promoted to `console.access` and the backend's own session and MFA policy — not the test — carries it through re-authentication and enrolment into the Console — see "End-to-end validation" above | WP1–WP4 |

**Explicitly not scheduled**, and built only on the trigger ADR 0033 names — person-specific data that must render *inside* a WordPress experience: a service client, `api_clients`, any WordPress-held credential, and delegated human authority.

**Also explicitly not decided by this sequence:** whether or when any real (for example Luma-era) legacy member is actually invited through WP1's capability. That is an operational and product decision gated on production mail being active ([ADR 0031](../adr/0031-production-transactional-mail-uses-authenticated-smtp.md)) and on legacy records having been reviewed — both outside this document's scope.
