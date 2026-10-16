# G6 WP2: authorization, sourced grants and disclosure (independent audit)

## A. Executive verdict

Authorization and disclosure are in good shape. I found **no BLOCKER, no HIGH and no MEDIUM**.

- **Every business use case checks its own capability.** All 49 use cases in Discussions, CRM, Resources and Relationships, plus the Access ones, call `AuthorizeAction` before any read or write. The route middleware is a second, independent layer.
- **Identity always comes from the session.** No route takes the acting identity, audience or reader mode from the request.
- **Resources never revealed content it shouldn't, even with corrupted data.** I probed every corrupt stored value I could think of on both engines. Each one either failed with a 500 or stayed hidden; none was shown to the wrong reader.
- **Sourced grants are atomic.** Granting and withdrawing happen inside the relationship's own locked transaction, and a grant always needs `access.roles.assign`.

What I did find: six LOW items and some INFORMATIONAL notes.

- **Two are the contract's required deliverables, which don't exist yet:**
  - a test that fails when any route outside an explicit public list lacks authentication;
  - a test that every human role granting a capability also grants `console.access`.
- **One is a hardening gap.** The Authorizer accepts *any* catalog role from the sourced-grants table, not just the roles a relationship is allowed to grant. A row planted there would make someone a platform administrator. Revoking that role reports success and changes nothing, and only `relationships:check` notices.
- The rest are proof gaps and documentation drift.

The design choices the brief asked about (no step-up for Resources and Volunteer actions, orphaned grants staying effective, invitation authority deciding who uses a dormant grant) are documented and accepted. I list their remaining risk for your attention but don't count them as defects.

## B. Repository checkpoint

- **Audited commit:** `18d059ec5d98f9c96147c96dea98fe754ff9db67` (`origin/main`, PR #11). Post-merge CI run `38806979095` succeeded on exactly that commit.
- **How I pinned it:** in a detached worktree in my scratchpad, not in the shared checkout. Three other Claude sessions were open on this repo, so I left the shared checkout where it was (branch `fix/g6-wp1-identity-session` at `773ac11`, clean). Its tree is identical to `18d059e` (empty diff).
- **Production:** not touched. It stays on v0.3.0.

## C. Findings, most severe first

### WP2-01 — LOW (required deliverable) — Nothing fails when a route outside `/admin` loses authentication

**Where:**
- Every test that walks the route table looks only at `api/v1/admin/`: [AdministrationRoutesTest.php:96](apps/platform/tests/Feature/Modules/Access/AdministrationRoutesTest.php#L96), lines 138 and 159, and [OpenApiContractTest.php:158](apps/platform/tests/Feature/OpenApiContractTest.php#L158).
- `MembershipRouteSurfaceTest` covers Membership's routes only.
- `OpenApiContractTest:86` requires every route to be documented, but never checks that a non-admin route's documented `security` matches its real middleware.

**What's at stake:** "everything not on the public list is authenticated by default" ([trust-boundaries.md:33](docs/architecture/trust-boundaries.md#L33)).

**Scenario:** a future self-service route, say a `preferences` endpoint, is added inside Identity's `stateful` group without `auth:web`, and documented in OpenAPI as using the session cookie. As far as I can find, no existing test would fail, and the route would be reachable without signing in.

**Today:** no exposure. All 10 unauthenticated routes are legitimate (section D).

**Evidence limit:** I tried to prove this by planting exactly that route in my scratch worktree and running the suite. The session's permission classifier refused, and I did not work around it. So this finding rests on reading the tests, not on a measured run.

**Fix and test:** specified in section D. Not a release blocker, but G6 can't close without it (exit criterion 3).

### WP2-02 — LOW (required deliverable) — No test states the rule "every capability-granting human role also grants `console.access`"

**Where:** [CatalogTest.php](apps/platform/tests/Unit/Modules/Access/CatalogTest.php) pins each role's exact capability list. No test states the general rule.

**Why it matters:** second-factor enforcement is tied to `console.access` ([ConsoleMultiFactorPolicy](apps/platform/app/Modules/Access/Application/ConsoleMultiFactorPolicy.php)). A future role that grants, say, `crm.people.view` without `console.access` would give a password-only session that authority through any use case. WP1-05 / G6-D11 depends on this rule holding.

**Today:** the rule holds. All five roles grant `console.access`.

**Fix and test:** section E. Same blocking status as WP2-01.

### WP2-03 — LOW (hardening) — The Authorizer honours any catalog role in `sourced_role_grants`, not just provisionable ones

**Where:** [Authorizer.php:65](apps/platform/app/Modules/Access/Application/Authorizer.php#L65) and [line 76](apps/platform/app/Modules/Access/Application/Authorizer.php#L76): sourced keys go through `Role::tryFrom`, exactly like independent assignments. The only thing stopping a non-provisionable role is the write path ([GrantSourcedRole.php:48](apps/platform/app/Modules/Access/Application/GrantSourcedRole.php#L48) and the `ProvisionableRole` type).

**What's at stake:** ADR 0038 K2 says a relationship source must never grant `platform_administrator` or any role that administers access or identity. Today that holds only on the write path. The read side doesn't enforce it.

**Evidence (disposable probe, MariaDB, since deleted):** I planted a `sourced_role_grants` row with `role_key = platform_administrator` for an ordinary active Account.
- The Authorizer then gave that Account **all 18 capabilities**.
- `RevokeRoleFromAccount(admin, account, 'platform_administrator')` returned normally, and the Account kept `access.roles.assign`.
- `PeopleHoldingCapability(AssignRoles)` counted it as a second holder.
- `relationships:check` reported only `sourced grant … has no relationship`, because it stops checking a row once the relationship is missing ([CheckRelationships.php:75](apps/platform/app/Modules/Relationships/Application/CheckRelationships.php#L75)).
- Unknown or retired keys (`guardian`, `bogus-role`, wrong case, trailing space) correctly granted nothing and left the person's other roles alone.

**Precondition:** a write to the database outside the use cases: a manual edit, a mismatched restore, or a future defect. An attacker who can write to the database has already won, so the point is containment and fail-closed design, not a bug anyone can trigger today.

**Fix (Access only):** read sourced keys through the provisionable allowlist, not the full catalog. That is, `ProvisionableRole::tryFrom($key)?->role()` for sourced rows; independent assignments stay as they are. Optionally, make `relationships:check` also report a non-provisionable role on an orphan row. Nothing a user can see changes, because no legitimate path writes such a row.

**Test:** plant a sourced `platform_administrator` row → `capabilitiesOf` is `[]`. Plant a sourced `guardian-initiate` row → `[console.access]`. Mutation: revert to `Role::tryFrom` → the test fails.

**Blocking:** neither G6 closeout nor the release.

### WP2-04 — LOW (documentation) — The trust-boundaries public list leaves out five pre-authentication routes, and one OpenAPI entry is wrong

- **trust-boundaries.md:** [line 33](docs/architecture/trust-boundaries.md#L33) lists only `/up`, `health`, `invitations/accept`, `password/forgot` and `password/reset`. It omits `login`, `logout`, `mfa/challenge`, `mfa/enrollment` and `mfa/enrollment/confirm`. Those five are on the session surface (cookie, CSRF) but deliberately run without `auth:web`.
- **OpenAPI:** it documents `POST /logout` with `security: sessionCookie`, but the route has no `auth:web`. That's deliberate: logout is idempotent and answers 204 with no session.
- **Fix:** correct both, documentation only. The WP2-01 test will pin them.

### WP2-05 — LOW (robustness, no disclosure) — A corrupt stored Resources value takes down whole reading surfaces

**Where:** `PublicationState::from` and `AudienceMode::from` at [DatabasePackRepository.php:267](apps/platform/app/Modules/Resources/Infrastructure/DatabasePackRepository.php#L267) and [DatabaseCardRepository.php:392](apps/platform/app/Modules/Resources/Infrastructure/DatabaseCardRepository.php#L392), lines 422 and 432. Nothing maps the resulting `ValueError` to anything but a 500.

**Evidence (disposable probe, both engines, 7 cases each):**

| Corruption | What readers got |
| --- | --- |
| Pack `state` set to `PUBLISHED`, `published ` or `archived` | No content leaked to any reader, on either engine |
| Corrupt Card `state` or `audience_mode` in a Published Pack | A 500 for the whole audience library for every eligible reader, and for the privileged library too |
| A corrupt Pack, opened by an ineligible reader | A 500, where a missing Pack gives the uniform 404. That reveals the Pack exists, but only under corruption, and only to someone who already knows its id |

- **Engine difference:** on MariaDB, `PUBLISHED` and `published ` pass the SQL `state = 'published'` filter (collation), then fail with a 500. On PostgreSQL the listing simply leaves them out.
- **Debug output:** with `APP_DEBUG` on, the 500 message names the bad enum value. In production it is a generic "Server Error".
- **Judgement:** fails closed, as the contract defines it. The cost is availability, not disclosure.
- **Fix:** add the missing regression test (corrupt values through every Policy A, B and C route on both engines: a 500 or hidden, never shown). Whether to make parsing tolerant (treat an unknown value as Draft/hidden and log it) is a behaviour choice; see K2.

### WP2-06 — LOW (proof gap) — CRM never proves the capability is checked before the record lookup

Every CRM "stranger" test uses ids that exist (for example `PersonRecordTest:63`, `InteractionsTest:256`). A mutant that moved `personExists` or the lookup above `authorize` would survive, and an outsider would then get 404 versus 403 as an existence signal. The code order is correct; I read all 16 use cases. Discussions already has this test (`DiscussionsTest:589`).

**Test:** a stranger Actor calls each CRM use case with a non-existent Person, interaction or tag id and expects `AccessDenied`, not a not-found exception.

### INFORMATIONAL

- **I1 — no tool removes an orphaned grant.** An operator has no supported way to remove a sourced grant whose relationship is gone. The [runbook](docs/runbooks/guardian-adoption.md#L82) says "a reviewed migration"; disabling the Account is the immediate containment. This is accepted residual risk under ADR 0038 K11; WP2-03 limits how much an orphan can grant.
- **I2 — Resources content integrity without step-up or audit.**
  - Publishing, widening a Published Pack's audiences, editing a Published Card, and replacing a Published Card's file all take effect immediately, with no step-up and no security event. Only the provenance columns change.
  - This is accepted (ADR 0037, decisions 52, 55, 65 and 67).
  - **Mitigations:** the file type is checked against an allowlist by content sniffing (the client's MIME type is ignored), downloads are `attachment` plus `nosniff`, inline display is limited to PDF and raster images, and CSP `default-src 'none'` applies.
  - It matters more once content leaves Commons (G7 entry condition 6).
- **I3 — Volunteer eligibility without step-up.** Volunteer intake straight to `active`, and Volunteer status changes, add or remove Volunteer Resource eligibility without step-up. That is accepted (ADR 0038 A5) and asymmetric with Guardian intake. It only matters for Persons who already hold `console.access`, and every `volunteers.manage` holder already holds `resources.manage`.
- **I4 — anyone with `crm.people.manage` can rename any Person, administrators included** (also `volunteers.manage` through Volunteer basics). The new name shows in Access screens, `/me` and authorship. It is audited as `person.renamed` and confers no authority. Accepted in ADR 0034, but worth your explicit acknowledgement as a social-engineering vector.
- **I5 — Discussions removal write.** `saveRemoval` has no author predicate, and the edit write's author predicate compares the row with itself ([DatabaseDiscussionMessageRepository.php:84-100](apps/platform/app/Modules/Discussions/Infrastructure/DatabaseDiscussionMessageRepository.php#L84-L100)). That is safe only because nothing ever updates `author_person_id`. Optional hardening: `where author_person_id = actor`.
- **I6 — stale comments.** [Relationships routes.php:26-27](apps/platform/app/Modules/Relationships/Http/routes.php#L26-L27) omits `default_role` from the verified operations; the code and `RelationshipsRoutesTest` are stricter. ADR 0038's line reference `DatabasePackRepository.php:252` is now `:267`.
- **I7 — unproven race hypothesis.** Audience delivery judges visibility from Card outlines, then loads content in a second read outside a transaction. A narrowing and a content edit committed in the same millisecond could serve the newer text. I didn't demonstrate this, and no test covers delivery-read consistency.

## D. Public routes and authentication

There are 111 routes: 94 `/admin` routes, 16 other `/api/v1` routes, and `/up`. Every module's `routes.php` is loaded by a glob, and the list comes from `artisan route:list` at the pinned commit.

**Routes with no `auth:web` (exactly 10):**

| Kind | Routes | Middleware |
| --- | --- | --- |
| Framework liveness | `GET /up` | none (global headers only) |
| Stateless public | `GET health`, `POST invitations/accept`, `POST password/forgot`, `POST password/reset` | `api` only: no session, no CSRF; secret in the body |
| Before sign-in, on the session surface | `POST login`, `POST mfa/challenge`, `POST mfa/enrollment`, `POST mfa/enrollment/confirm` | `api`, `stateful` (CSRF); work on the pending sign-in, which is not authentication |
| Allowed without a session | `POST logout` | `api`, `stateful`; idempotent 204 |

**The other authenticated routes:**
- **Signed in, no Console capability needed (7):** `GET me`, `GET my/membership`, `POST password/change`, `POST mfa/recovery-codes`, `POST mfa/authenticator`, `POST mfa/authenticator/confirm`, `POST security/verify`.
- **Admin (94):** all carry `stateful`, `auth:web` and `can:console.access`, plus exactly one catalog capability, except the four console-only reads ADR 0038 A6 allows. This is already pinned.

**Proposed guard** (new `tests/Feature/RouteAuthenticationSurfaceTest.php`):
1. **Allowlist:** a `PUBLIC_ROUTES` constant keyed by `"METHOD uri"` (HEAD ignored). Each entry records how the route is meant to work: `framework`, `stateless` or `session`, with a one-line reason.
2. **The walk:** for every registered route, resolve its middleware with `app('router')->gatherRouteMiddleware($route)`. That resolves aliases and groups and applies `withoutMiddleware`. A route counts as authenticated only if the result contains `Illuminate\Auth\Middleware\Authenticate:web`. Every unauthenticated route must be in `PUBLIC_ROUTES`.
3. **Each public route works the way it says:**
   - `stateless`: no `StartSession`;
   - `session`: `StartSession` and `PreventRequestForgery`;
   - `framework`: only `up`.
4. **No stale entries:** every allowlist entry must match a route.
5. **No stray routes:** every route outside `api/v1/` must be `up`. That catches `web.php`, package routes and the framework's local-storage serving route.
6. **OpenAPI tie-in:** an operation documented with `security: []` must be in `PUBLIC_ROUTES`, and the reverse. That forces the logout correction in WP2-04, or an explicit exception.
7. **Positive controls:** the rule is a pure function, tested against hand-built route objects.

**Mutation evidence to record** (each planted only in a scratch copy):

| # | Planted change | Expected failure |
| --- | --- | --- |
| M1 | Drop `auth:web` from `GET me` | Fails, naming `GET api/v1/me` |
| M2 | Add an unauthenticated `stateful` route to Identity | Fails |
| M3 | `->withoutMiddleware('auth:web')` on `security/verify` | Fails (proves the check sees exclusions) |
| M4 | Remove `stateful` from `login` | Fails on how the route is meant to work |
| M5 | Add `stateful` to `health` | Fails on how the route is meant to work |
| M6 | A stale allowlist entry | Fails |
| M7 | A route registered outside `api/v1` | Fails |

## E. Human-role catalog invariant

**What enforces it today:**
- every role's exact bundle is pinned (`CatalogTest:57-115`);
- administrative capabilities stay on the administrator (`:109`, `:250`);
- manage implies view (`:177`, `:193`, `:218`);
- the `Role` type is confined to Access (`AccessBoundariesTest:117`), and role keys are scanned out of every other module (`:223`).

Invariants 1–8 from the brief all hold, and the Authorizer tests prove them, including the disabled Account (`AuthorizerTest:153`), a forged Person (`:178`, `:189`), next-call revocation (`:105`), union and independence (`SourcedRoleGrantTest:119`) and the Person-bound grant (`:196`). The stated rule itself is not tested.

**Proposed test** (in `tests/Unit/Modules/Access/CatalogTest.php`, so no database):
1. A pure function `rolesGrantingWithoutConsoleAccess(array<string, list<Capability>>): list<string>`.
2. Assert it returns `[]` for `Role::cases()` mapped to their capabilities, and for every `ProvisionableRole::cases()->role()`.
3. Positive controls:
   - `['x' => [ViewPeople]]` gives `['x']`;
   - `['y' => []]` gives `[]` (a role granting nothing is allowed);
   - `['z' => [ConsoleAccess, ViewPeople]]` gives `[]`.
4. **Scope comment:** the `Role` enum *is* the human-role catalog. A service identity (ADR 0033) is a separate model, not a `Role` case. If one is ever modelled as a `Role` case, this test must be revised on purpose in that change.

**Mutations to record:**

| Planted change | Expected failure |
| --- | --- |
| Remove `ConsoleAccess` from the shared Guardian bundle | Fails, naming `guardian-full` and `guardian-senior` |
| Add a temporary `Auditor` role with `[ViewPeople]` | Fails, naming `auditor`; the exact-list test fails too, so record both |
| Change `GuardianInitiate` to `[ViewResources]` | Fails |

## F. Step-up matrix (from the real route table)

**What applies throughout:**
- Every mutation's use case re-checks its capability through `AuthorizeAction`.
- **No use case checks recent verification.** That lives only in the HTTP middleware, and the route tables pin it: `AdministrationRoutesTest:24-30,136`, `ResourcesRoutesTest:65-99`, `RelationshipsRoutesTest:14-56`, `PeopleApiTest:32-64`.

| Family | Mutations | Step-up | What it changes |
| --- | --- | --- | --- |
| **Resources** (`resources.manage`) | Category create, rename, reorder and delete; Pack create and edit; card order; Card create and edit | No | Content and order. A Published edit is live at once. Category delete is refused while any Pack uses it |
| | `PUT packs/{p}/audiences`, `PUT …/cards/{k}/audiences` | No | **Changes who can read immediately**, Published Packs included; a Card can only narrow within its Pack; not audited (I2) |
| | Pack and Card publish and unpublish | No | Delivers to or hides from audiences; reversible; not audited |
| | `POST …/cards/{k}/file` | No | **Swaps the file readers download, live**; allowlisted type, at most 20 MiB, not audited (I2) |
| | `DELETE packs/{p}`, `DELETE …/cards/{k}` | **Yes** | Permanent; audited |
| **Guardian** relationships (`guardians.manage`, administrator only) | Intake, status, `default-role`, delete | **Yes** | Guardian eligibility; grant or withdraw `guardian-initiate`, where a grant also needs `access.roles.assign` |
| | `PATCH …/fields` | No | Descriptive only; checked: no status, decision or eligibility effect |
| **Volunteer** relationships (`volunteers.manage`, Guardian roles) | Intake, status | No | **Volunteer Resource eligibility** (I3); never a role |
| | `PATCH basics`, `PATCH fields` | No | Display name (Identity), primary CRM email and phone; never the login email |
| | Delete | **Yes** | Removes eligibility; audited |
| **CRM** (`crm.people.manage`) | 15 maintenance mutations | No | Display name and CRM data. The login email is never touched; invitations use an address typed by the operator, so CRM can't redirect one |
| **Discussions** (`discussions.participate`) | 7 mutations | No | Author-only for edit, remove and retitle; anyone may resolve or reopen (ADR 0035 d.9) |

**Remaining risk:** a hijacked `resources.manage` session (a second-factor-protected Guardian session, now bounded by the WP1 fix) can publish or replace content for every eligible reader, instantly and without an audit event. This is accepted policy. Changing it needs your ruling under DP2 (see K3).

## G. Authorization inside the use cases

- **Discussions:** all 10 use cases authorize first, before any lookup (`DiscussionsTest:589` proves it with ids that don't exist). Ownership compares Person ids from the Actor with the stored author ([EditOwnMessage:49](apps/platform/app/Modules/Discussions/Application/EditOwnMessage.php#L49), RemoveOwnMessage:43, RetitleOwnDiscussion:43-46). The author always comes from the Actor. A removed message's body is set to NULL and never presented.
- **CRM:** all 16 use cases authorize first. The delegated services authorize nothing by design, and their callers are pinned (`CrmBoundariesTest:127-215`). Mutation responses would show view-level data to a manage-only role, but `CatalogTest:177` and `:193` make such a role impossible. That accepted assumption holds. Notes can be edited by any manager (ADR 0034). Proof gap: WP2-06.
- **Resources:** all 23 management use cases check `ManageResources`, and the three library use cases check `ViewResources`. Audience delivery uses no capability, only the Person's eligibility (R14).
- **The only other callers** are the demo seeders, which run only in local and testing. No console command or job calls these use cases.

## H. Sourced grants

- **Atomic:**
  - Status changes and deletion lock the relationship row, then withdraw in the same transaction ([ChangeRelationshipStatus:85](apps/platform/app/Modules/Relationships/Application/ChangeRelationshipStatus.php#L85), [DeleteRelationship:60](apps/platform/app/Modules/Relationships/Application/DeleteRelationship.php#L60)).
  - A grant re-checks that the locked row qualifies, and `GrantSourcedRole` checks `access.roles.assign` inside a savepoint; a refusal rolls back everything.
  - The grant's Person comes from the locked row.
- **Concurrency:** decisions, status changes and deletion serialize on the relationship row, and `SourcedRoleGrantRaceTest` passed on both engines.
- **Re-creating a relationship can't revive a stale grant:** the grant is keyed on the relationship's own id, deletion withdraws it, and reactivation requires an explicit `default_role` (`DefaultRoleRequest::guard`); `grant` needs `access.roles.assign` again.
- **Indirect provisioning without role-assignment authority:** not possible. Without it an operator can only `decline` or `defer`, and those only remove.
- **Dormant grants and invitations (K6):** a grant on a Person with no Account becomes effective once `InviteExistingPerson` (`identity.invitations.issue` plus step-up, operator-chosen mailbox) leads to acceptance.
  - That is accepted. Today all three capabilities belong only to the administrator.
  - Accounts are never deleted, one Account per Person is enforced, and a reissued invitation can't change the email. So independent role assignments can't become dormant and be revived the same way.
  - With WP2-03, a dormant sourced grant could never be more than a provisionable role (`console.access`).
- **Orphans:** the Authorizer honours them (accepted, K11). `relationships:check` runs only when an operator runs it (it isn't scheduled; see `routes/console.php`). There's no supported removal tool (I1).

## I. Resources disclosure

**The three access paths (A: privileged library, B: management, C: audience reading):**
- **Separate:** A, B and C each have their own route capability and use-case check. Management doesn't imply library access, or the reverse. In practice the Guardian roles hold both, and the restricted roles hold neither.
- **A (`resources.view`)** deliberately shows Drafts and every audience (ADR 0038 R4, R6). That is wider than the brief's "published, Guardian-directed" framing, and the code matches the ADR.
- **C** is fresh, active-only eligibility per request (`AudienceEligibility`). Identity is re-resolved first; no capability stands in for eligibility, and no eligibility stands in for a capability (`AudienceEligibilityTest`).

**How visibility is decided:**
- **One rule.** A Card is shown only if its Pack is Published, the Card is Published, and its effective audience (inherited, or narrowed within the Pack) overlaps the viewer's. File download uses exactly the same predicate as showing the Pack.
- **Corrupt values:** see WP2-05. No disclosure on either engine.

**Hidden items:**
- **One 404 for audience reading:** missing, Draft, wrong audience, empty after filtering, a Card not in the Pack, and no file all give the same `resource_pack_not_found` 404. `asset_unavailable` comes only after the Card is known to be visible. The exception is corruption (WP2-05).
- **Proof gap:** the file-route uniformity test (`ResourcesFilesApiTest:337`) doesn't compare headers. Every response goes through the same global middleware, so I expect none differ.

**File headers:**
- `Content-Type` is the stored canonical type, never the client's.
- `Content-Disposition` is `attachment`, except PDF and raster images when inline is asked for, using a sanitized RFC 6266 filename.
- `nosniff`, `Cache-Control: private, no-store`, CSP `default-src 'none'; frame-ancestors 'none'`, `X-Frame-Options: DENY` and CORP same-origin.
- SVG, HTML and XML are never accepted. I found no stored-XSS path. A CSP `sandbox` on file responses would be optional defence in depth.

## J. Test evidence

| Run | Engine | Result |
| --- | --- | --- |
| Focused suite (Access feature and unit, OpenAPI contract, `AccessBoundariesTest`, `GuardianProvisioningTest`, `SourcedRoleGrantRaceTest`, Resources access control, audience eligibility, `MyResources`, Resources and Relationships route tables) | MariaDB | **492 passed** (4,072 assertions), 77 s |
| Same suite | PostgreSQL | **492 passed** (4,072 assertions), 96 s |
| Probe: sourced grants (planted role keys, revoke, holders, check) | MariaDB | 6 cases, results as in WP2-03 |
| Probe: corrupt Resources values (Policies A and C, eligible, ineligible and other-audience readers) | MariaDB and PostgreSQL | 7 cases × 2 engines; **no disclosure**; results as in WP2-05 |

- Both probe files lived only in the scratch worktree and were deleted.
- **What I did not prove:**
  - the WP2-01 gap by a planted route (refused by the classifier);
  - the I7 race;
  - inline PDF rendering under the CSP;
  - the sourced-grant probe on PostgreSQL (it is pure PHP logic, so engine-independent).
- I did not run the full gate or the browser suite, as the brief asked.
- **Help from subagents:** three read-only subagents gathered evidence for Resources, for Discussions and CRM, and for the step-up matrix. They didn't run tests or touch databases. I checked their key claims against the code before including them.

## K. Decisions you need to make

- **K1 — WP2-03.** Approve limiting sourced grants to `ProvisionableRole` in the Authorizer. Access only, and nothing a user can see changes.
- **K2 — WP2-05.** Either keep the 500 and pin it with tests (recommended now), or authorize tolerant fail-closed parsing (unknown → hidden, plus a log). The second changes behaviour.
- **K3 — I2 and I3 (optional).** Whether to record security events for publish, unpublish, audience changes and file replacement. That would be invisible to users and cheap. Or accept them as they are until G7 condition 6. Adding step-up to any of these is a DP2 change, and I don't recommend it in G6.
- **K4 — acknowledge the accepted residuals:** I3 (Volunteer eligibility without step-up), I4 (renaming any Person), and K6 invitation authority. That covers what remains after WP2-03 lands.

## L. Proposed remediation, in reviewable units (highest risk first)

1. **Guards, tests only:** the WP2-01 route-surface test and the WP2-02 human-role invariant, each with the mutation records from sections D and E.
2. **Sourced-grant hardening (if K1 is approved):** the Authorizer change and its test, and optionally the `relationships:check` addition.
3. **Proof tests:**
   - the WP2-05 corrupt-value regression on both engines (or the tolerant parsing, per K2);
   - the WP2-06 CRM capability-before-lookup test;
   - header comparison in the file-route uniformity test.
4. **Documentation:**
   - trust-boundaries public routes, and the OpenAPI `logout` security (WP2-04);
   - the Relationships `routes.php` comment and the ADR 0038 line reference (I6);
   - debt-register rows for I1, I2, I3, I5 and I7.

Each unit stays inside one module's public contract, so DP5 is not triggered.

## M. Repository integrity

- No tracked file changed; the shared checkout is still clean at `773ac11` on `fix/g6-wp1-identity-session`.
- No commits, pushes, PRs, merges or deployments, and no production requests.
- The scratch worktree is removed and pruned, including root-owned test output, which I deleted from a container.
- I started the development PostgreSQL service for the probe and stopped it afterwards.
- Remaining scratch files are logs and the route dump only, in `/tmp/claude-1000/-home-tennyson-development-flow-life-commons/776682a2-1abf-4eb2-8985-cfa4a9a2177b/scratchpad/`.

**G6 WP2 AUDIT: READY FOR OWNER TRIAGE**
