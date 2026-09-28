# Member-facing access

**Status: WP0 — architecture recorded, nothing implemented.** This is the consolidated design for how an ordinary Member (and later a Volunteer) reaches Commons, referred by [ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md) and [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md), in the style [identity-and-access.md](identity-and-access.md) uses for the Identity and Access design: the ADRs are the frozen decisions, this page is the living design and the record of how far implementation has gone.

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

`/my/` is a working name for the route prefix, not a decided product label.

## `/my/` versus active membership — the distinction that must not collapse

**Reaching the member shell is not itself evidence of active membership, and must never be read as such.**

The member area is an authenticated self-service surface, open to any signed-in Account that has no higher-privilege destination. That includes, without distinction at the routing layer:

- a Member whose grant is active right now;
- a former or lapsed Member, whose grant has ended;
- a future Account type that has never held a membership grant at all.

Consequently:

- `GET /my/membership` (not yet built) answers truthfully for whichever of those is true — "no membership on record", "active, ends `<date>`", "active, open-ended", or "ended `<date>`" — and a Member-facing page must render all of those states, not assume the first one.
- Self-account operations (profile, password, security) are subject-based on the signed-in Account/Person, exactly as `/me` and `/password/change` already are, and need no membership check at all.
- **A genuinely Member-only resource**, when one exists, is authorized by an actual capability derived from *current* membership state — not by the fact that the request reached `/my/`, and not by a durable role. This is the mechanism [ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md) already reserved: Access defines the port, Membership implements it, and `Authorizer::capabilitiesOf` combines it with role-derived capabilities on every call, so a lapsed grant loses the capability on the very next request with no sweep and no stale session to worry about.

**No such capability is defined by this document.** One is added, per [ADR 0017](../adr/0017-capabilities-and-roles-in-code.md)'s rule, only when a real resource needs it — not speculatively, and not invented here to exercise the mechanism.

## Volunteer extension seam

**Decided:** Volunteers are Members with elevated duties or privileges, not a second identity type. Volunteer-specific capabilities, when they exist, can extend the same `/my/` surface a Member already reaches, gated by capability rather than by any special routing. Holding a volunteer capability never implies `console.access`; the Guardian Console remains Guardian/operator-only, unaffected by this design.

**Deliberately undecided, and not designed here:**

- whether Volunteering eventually gets its own domain aggregate, distinct from a plain Access role;
- the lifecycle or history of volunteer status (durable assignment, term-bounded like membership grants, or something else);
- duties, assignments, schedules, or any other volunteer business data;
- whether a future Access role for volunteers mirrors or derives from some later Volunteering relationship, or is independent of it.

Whatever shape Volunteering eventually takes, it composes with what this document decides — an authenticated Account reaching a capability-gated area of the same surface — without requiring this document to be revisited.

## WordPress's role

Exactly what [ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md) decided: public content, and a link into Commons. No plugin feature is required for that link — an ordinary WordPress menu item or button pointing at `https://commons.flowlifeglobal.org/login` (or wherever sign-in lives) needs no code in `apps/wordpress-companion`.

`apps/wordpress-companion` stays the skeleton it is today: no API call, no credential, no authenticated shortcode, no user mapping, no storage. [`scripts/tests/wordpress-boundary.php`](../../scripts/tests/wordpress-boundary.php) — run by `./flow check repo` — is expected to keep passing without modification through every work package below; it is written to fail loudly and require a deliberate, named revision the day a service client or delegated flow actually gets built (see [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md)), which is not this phase.

## Neutral credential presentation (design intent; wording deferred)

The existing credential pages and mail copy assume every recipient is a Guardian Console operator:

- `/login`, `/accept-invitation`, `/forgot-password`, `/reset-password` (`apps/guardian-console/src/pages/*`) render or link to "Guardian Console" branding.
- The invitation and password-reset mail templates (`apps/platform/app/Modules/Identity/Infrastructure/Mail/`) say "You have been invited to the Flow Life Guardian Console" and similar.

**Decided:** these become neutral Flow Life Commons presentation — a credential page or a mail message must not claim the recipient is entering an operator console when they may be an ordinary Member. **Not decided here:** the exact copy, tone or visual treatment. That is a smaller, later decision (Work Package 3, below), deliberately left open so it isn't chosen ahead of a real design pass.

## Delegation and service authentication: not built, and why that's fine

[ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md) settles this in full; the summary relevant here is that **nothing in this design needs either mechanism**. Every capability this document describes — sign-in, self-service, own-membership status, a future capability-gated Member resource — is served directly from Commons to a browser that already holds a Commons session. WordPress participates only as a link, which requires proving nothing.

## Work-package sequence

Each package is independently shippable and independently revertible; none depends on a later one existing.

| Package | Scope | Depends on |
| --- | --- | --- |
| **WP0** (this) | ADR 0032, ADR 0033, this document, stale-documentation reconciliation | — |
| **WP1** | A generic Identity capability: invite an *existing* Person who has no Account (an Identity use case, not a Membership one — Membership already creates Persons with no Account via `RegisterPerson`, and has no way today to give one an Account afterwards) | WP0 |
| **WP2** | `GET /api/v1/my/membership` — subject strictly the authenticated session's own Person, answering the full active/lapsed/none/open-ended range described above | WP0 (independent of WP1) |
| **WP3** | Neutral Commons credential-page and mail copy; post-login destination logic (an Account with `console.access` lands in the Console, everyone else lands in `/my/`) | WP0 |
| **WP4** | `MemberShell` and an initial `/my/*` route area (home, own membership status, own account/security) in the existing React application, with an enforced import boundary from Console/admin code | WP1–WP3 |
| **WP5** | A real-browser vertical journey: an operator registers or adopts an existing Person, invites them, the invitation is caught by Mailpit, accepted, the Member signs in, reaches `/my/`, sees their own membership, and signs out | WP1–WP4 |

**Explicitly not scheduled**, and built only on the trigger ADR 0033 names — person-specific data that must render *inside* a WordPress experience: a service client, `api_clients`, any WordPress-held credential, and delegated human authority.

**Also explicitly not decided by this sequence:** whether or when any real (for example Luma-era) legacy member is actually invited through WP1's capability. That is an operational and product decision gated on production mail being active ([ADR 0031](../adr/0031-production-transactional-mail-uses-authenticated-smtp.md)) and on legacy records having been reviewed — both outside this document's scope.
