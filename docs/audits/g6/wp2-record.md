# G6 WP2 — remediation record

- **Package:** WP2 Authorization, sourced grants and disclosure. Remediation of the [independent audit](wp2-audit.md) as ruled in the [owner's triage](wp2-triage.md).
- **Base:** `main` at `18d059ec5d98f9c96147c96dea98fe754ff9db67` (PR #11, G6 WP1 squash-merged; post-merge CI run `38806979095` green). **Branch:** `fix/g6-wp2-authorization-disclosure`. Local only: not pushed, no PR, nothing deployed. Production stays on v0.3.0.
- **Status:** implemented and validated locally; **awaiting the independent Opus verification** (§11) and the owner's integration decision. This record is evidence, not the independent review.
- **Audit and triage files:** the audit is committed as `wp2-audit.md` from its heading (`# G6 WP2: authorization…`) to its closing verdict line, unedited; the one-line "Written for:" hand-over note that preceded it was addressed to the owner and is not in the file. The owner's rulings are `wp2-triage.md`, verbatim.

## 1. What I verified in the audit before changing anything

I checked the audit's descriptions against the source and the route table instead of taking them on trust.

- **Route table:** `artisan` with the HTTP kernel built (the router's aliases are registered by it) lists **111 routes, exactly the 10 without `auth:web`** the audit names, with the middleware the audit describes (`api` only for the four stateless routes; `api` + `stateful` for the five session routes; none for `/up`).
- **Sourced-grant defect:** reproduced before the fix (§4): a planted `sourced_role_grants` row with `platform_administrator` gave an ordinary Account all **18** capabilities, and `PeopleHoldingCapability` counted it.
- **One imprecision, corrected in the documentation work (§8):** the audit says OpenAPI documents `POST /logout` with `security: sessionCookie`. The spec **already** declared `[sessionCookie, {}]`, OpenAPI's form for *authentication optional*, which is the right declaration for an idempotent route. I therefore kept the declaration, made the route guard understand the three forms (`required`, `optional`, `none`), and added a sentence to the operation's description. No behaviour or response changed.
- **Packs have no audience mode.** The triage lists "Pack audience mode" among the corrupt values. Only **Cards** have `audience_mode`; a Pack's audience is a set of keys in `resource_pack_audiences`, which `Rows::audiences` already drops when unknown. The Pack-level analogue is therefore tested as corrupt audience *keys* (§6).

## 2. WP2-01: the global route-authentication guard

**Files:** `tests/Support/RouteSurface.php` (the judgement and the reviewed list), `tests/Feature/RouteAuthenticationSurfaceTest.php` (17 tests). No production change, as the triage required.

**How it works.**
- It walks `Route::getRoutes()` and resolves each route's **effective** middleware with the router's own `gatherRouteMiddleware`, which expands aliases and groups and applies `withoutMiddleware`. The HTTP kernel is built first, because that is when the framework's aliases (`auth`, `can`) are registered with the router. A route is authenticated only if the result contains `Illuminate\Auth\Middleware\Authenticate:web`.
- `RouteSurface::PUBLIC_ROUTES` lists the ten reviewed routes by `"METHOD uri"` (the implicit `HEAD` ignored), each with its **kind** (`framework`, `stateless`, `session`), the form OpenAPI must document it in (`none`, `optional`) and a reason.
- Rules: every unauthenticated route is listed; every listed route is unauthenticated, registered, and works as its kind says (`stateless`: no `StartSession`, `PreventRequestForgery` or `EncryptCookies`; `session`: `StartSession` and `PreventRequestForgery`; `framework`: only `GET up`, no session); every authenticated route is on the session surface; **every route outside `/api/v1` other than `GET up` is a violation**, authenticated or not; OpenAPI agrees in **both** directions (an authenticated route must be documented `required`; a listed route must be documented as its entry says; an operation documented as anything but `required` must be on the list).
- The judgement is a pure function over routes and spec paths, so the tests feed it hand-built `Illuminate\Routing\Route` objects and hand-built specs.

**Positive controls (committed tests, 14 of the 17):** a route that loses authentication, an unreviewed `stateful` route, `withoutMiddleware('auth:web')`, a pending sign-in route that loses the session surface, a stateless route that starts a session, a stale list entry, an entry whose route became authenticated, an external route (guarded and unguarded), a misused `framework` entry, an authenticated route without the session surface, OpenAPI disagreeing in each direction (including both extreme readings of logout), the three-way reading of `security`, and a clean hand-built surface.

**Mutation evidence on the real tree** (each planted as an uncommitted edit, `registers exactly the reviewed public routes…` run, the file restored with `git checkout`; MariaDB):

| # | Planted violation | Result | First reported violation |
| --- | --- | --- | --- |
| M1 | `GET me` without `->middleware('auth:web')` | killed | `GET api/v1/me is not authenticated and is not in the public list` |
| M2 | `Route::post('preferences', …)` added to Identity's `stateful` group, no `auth:web` | killed | `POST api/v1/preferences is not authenticated and is not in the public list` |
| M3 | `->withoutMiddleware('auth:web')` on `security/verify` | killed | `POST api/v1/security/verify is not authenticated and is not in the public list` |
| M4 | `mfa/challenge` with `->withoutMiddleware('stateful')` | killed | `POST api/v1/mfa/challenge is listed as 'session' but lacks StartSession or PreventRequestForgery` |
| M5 | `->middleware('stateful')` added to `health` | killed | `GET api/v1/health is listed as 'stateless' but starts a session, cookies or CSRF handling` |
| M6 | a stale entry added to `PUBLIC_ROUTES` | killed | `POST api/v1/legacy/token is in the public list but no such route is registered (stale entry)` |
| M7 | `Route::get('/debug-oops', …)` registered beside `/up` | killed | `GET debug-oops is not authenticated and is not in the public list` |

The auto-mode classifier did **not** refuse these (the audit's did). M6 edits an untracked file, so it was reverted by deleting the planted line, and M7 was then re-run alone for clean evidence. Nothing planted is committed (`git status` after each showed only the intended files).

## 3. WP2-02: the human-role Console-admission invariant

**File:** `tests/Unit/Modules/Access/CatalogTest.php` (two tests; no database). `humanRolesGrantingWithoutConsoleAccess(array<string, list<Capability>>)` returns one sentence per role that grants something but not `console.access`. The test applies it to **`Role::cases()`** (so a role added later is covered with no edit here) and to every `ProvisionableRole` role, and the scope comment says that `Role` *is* the human-role catalog, that a service identity (ADR 0033) is a separate model, and that modelling one as a `Role` case would require revising this test on purpose (with G6-D11's authentication-strength decision). Positive controls: `['x' => [ViewPeople]]` is rejected by name; `['y' => []]` is allowed; `['z' => [ConsoleAccess, ViewPeople]]` is allowed; a mixed table reports only the offender. The role catalog is untouched.

**Mutations** (uncommitted edits to `Role.php`, restored after each; `CatalogTest`):

| Planted change | Result |
| --- | --- |
| `ConsoleAccess` removed from the shared Guardian bundle | the new test fails: `role 'guardian-full' grants crm.people.view, crm.people.manage, … without console.access` and the same for `guardian-senior`; the exact-bundle test fails too |
| a temporary `Auditor` role with only `ViewPeople` | the new test fails: `role 'auditor' grants crm.people.view without console.access`; the exact-roles test fails too (recorded, as the audit asked) |
| `GuardianInitiate` granting only `resources.view` | the new test fails: `role 'guardian-initiate' grants resources.view without console.access` (3 tests fail in all) |

## 4. WP2-03: the sourced-grant read side

**Production change (Access only, plus one read-only diagnostic):**
- `ProvisionableRole::fromRoleKey(string): ?self` and `ProvisionableRole::roleKeysGranting(Capability): list<string>` are the one place the read side learns which stored keys a relationship may confer. Neither consumer hard-codes `guardian-initiate`.
- `Authorizer::capabilitiesOf` still reads independent assignments through `Role::tryFrom` (the whole catalog) but reads **sourced** grants through `ProvisionableRole::fromRoleKey(...)?->role()`. An unknown, retired, mis-cased or non-conferrable key grants nothing and never affects the Person's other roles.
- `PeopleHoldingCapability` counts independent holders against every role that grants the capability, and sourced holders only against `ProvisionableRole::roleKeysGranting($capability)`.
- `CheckRelationships` (read-only, no new contract) now also reports `sourced grant <id> role is not provisionable` for an **orphan** row whose role no relationship can confer, which previously escaped because the check stopped at "has no relationship". The finding names the source id, never the role key. A row whose relationship exists was already caught by "role is not the current default role".

**Tests:** `tests/Feature/Modules/Access/SourcedGrantReadSideTest.php` (18 cases, planting rows past the write path with the new `Access::plantSourced`): a sourced `platform_administrator` (all 18 capabilities before the fix; none after, and not a holder of any capability, with the real administrator still the only holder); `guardian-full`, `guardian-senior` and `console-participant` (real roles, not provisionable); a valid `guardian-initiate` (still `console.access`, still counted); eight unknown, retired or wrongly written keys (`guardian`, `bogus-role`, `Platform_Administrator`, `PLATFORM_ADMINISTRATOR`, `Guardian-Initiate`, `GUARDIAN-INITIATE`, `platform_administrator ` and ` guardian-initiate`); an invalid sourced row beside an independent `guardian-full` (the independent role keeps exactly its capabilities, and nothing is removed or repaired); an independent role plus two valid sourced grants (one capability set, one holder); an independent administrator beside bogus sourced rows (all capabilities); the last-administrator protection (a sourced administrator row is never a second administrator: revoking the only real one still throws `LastAdministratorRequired`); and an **agreement** test: across eight kinds of sourced row, a Person is a holder of a capability exactly when the Authorizer allows it, for every capability. `GuardianProvisioningTest` gained one case for the `relationships:check` diagnostic.

**The defect before the fix.** The 18 tests were written first and run against the unmodified `app/` tree: **6 failed, 12 passed** (MariaDB), the failures being the sourced `platform_administrator` row (all 18 capabilities: `access.roles.assign`, `console.access`, `crm.people.manage`, … `volunteers.view`), the three non-provisionable roles, the mixed independent-plus-invalid case, and the last-administrator case's assertion that the row's owner holds nothing. The agreement test and the unknown-key cases pass on the original code by design: they guard the fix from one consumer drifting from the other.

**Mutation evidence** (uncommitted edits, restored from a saved copy; MariaDB):

| # | Planted change | Result |
| --- | --- | --- |
| S1 | `Authorizer` reads sourced keys through `Role::tryFrom` again | **7 of 18 fail** (admin row, the three non-provisionable roles, mixed, last-administrator, agreement) |
| S2 | `PeopleHoldingCapability` counts sourced rows against every role granting the capability again | **6 of 18 fail** (admin row, the three non-provisionable roles, mixed, agreement) |
| S3 | the orphan check in `CheckRelationships` disabled | the new `GuardianProvisioningTest` case fails |

**Last-administrator and other consumers.** `AdministratorContinuity` locks and reads **independent** `platform_administrator` assignments only and was not changed; the new test pins that a sourced row is no second administrator. `ListSourcedRoleGrants` and `SourcedRoleGrantsOf` (Relationships' own reads) and the write paths are unchanged.

**Observation, not changed.** `AccountViews::many` lists a sourced grant for the administration screen using `Role::tryFrom` for its label, so a planted `platform_administrator` row would be *shown* as a sourced "Platform administrator" grant although it now confers nothing. This is display only, within Access, and the owner's ruling named the two consumers, so I left it; it arguably helps an operator spot the row. Listed for the verifier (§11).

## 5. Authorization consistency

- Independent assignments keep the full catalog: an independent `platform_administrator` still holds all capabilities beside bogus sourced rows, and `AuthorizerTest`, `SourcedRoleGrantTest` and `LastAdministratorTest` pass unchanged.
- Valid sourced `guardian-initiate`: `console.access`, counted as a holder once even with an independent role or several sources.
- Holder counting and the Authorizer agree for every capability across eight kinds of row.
- Orphaned but valid sourced grants behave as before (accepted, ADR 0038 K11); there is no cleanup, no per-check relationship revalidation, no schema change.

## 6. WP2-05: corrupt Resources values

**File:** `tests/Feature/Modules/Resources/ResourcesCorruptionDisclosureTest.php` (19 tests). No production change; strict parsing is kept.

It builds, over HTTP through the real use cases, a live Pack for Guardians and Members (a text Card, a File Card, a Card narrowed to Members, a Draft Card) and a Draft Pack holding two published Cards; each fact carries a unique marker, and the file's bytes carry one. For **each of 18 corruptions** it changes the stored value directly, then reads as a Person with no relationship, a Guardian, a Member, a Console participant without `resources.view` or `resources.manage`, and the privileged Guardian, over the audience routes (Policy C: list, Pack, file), the privileged library (A) and management (B).

The asserted invariants (no status is pinned; a 404 or a 500 are both fine, a 3xx is not):
1. A reader never receives what their audience withholds (nothing at all for the Person with no relationship; no Draft, no Draft Pack and no Members-only Card for a Guardian; no Draft for a Member).
2. A value the code cannot read is never treated as Published or Inherit: the item is withheld from every audience reader (`hides`).
3. The capability gate holds: a participant without the Resources capabilities gets **403** on every library and management route, whatever is stored.
4. No response, to anyone, names a stored file's key (body or headers).

**The corruptions:** a Draft Pack whose state reads `PUBLISHED`, `published ` (trailing space), `archived` or empty; a Published Pack whose state reads `Published` or `retired`; a Draft Card whose state reads `PUBLISHED` or `published `; a Published Card and a Published File Card whose state is unknown; a narrowed Card whose mode reads `NARROWED`, `inherit ` or `everyone`; an inheriting Card whose mode is unknown; a narrowed Card that lost its audience rows (mode never means Inherit); a Pack whose audience keys are all unknown, which has an extra unknown key, or are in the wrong case. A separate test pins the uncorrupted fixture (what each audience is entitled to) and that ordinary missing, Draft, wrong-audience and no-file answers are one identical 404.

**Mutation evidence** (a deliberately fail-open parse planted in each place; **MariaDB**, uncommitted, restored with `git checkout`):

| # | Planted change | Result |
| --- | --- | --- |
| Z1 | `DatabasePackRepository` parses an unknown state as Published | **6 fail**: e.g. `a Draft Pack whose state reads PUBLISHED: a Guardian received MARKER-PACK-DRAFT from /api/v1/admin/my-resources` |
| Z2 | `DatabaseCardRepository` parses an unknown card state as Published | **4 fail**: e.g. `a Published File Card whose state is unknown: a Guardian received MARKER-FILE-BYTES from …/packs/…` |
| Z3 | an unknown audience mode parsed as Inherit | **4 fail**: e.g. `a narrowed Card whose audience mode is unknown: a Guardian received MARKER-CARD-NARROWED` |
| Z4 | an unknown audience key mapped to Guardian | **2 fail**: `a Pack whose audience keys are all unknown: a Guardian received MARKER-PACK-LIVE` |
| Z5 | a pack state compared loosely (trim and lower-case) | **3 fail** (a Draft Pack reading `PUBLISHED` or `published ` is shown) |

The same five on **PostgreSQL** fail the same number of tests (6, 4, 4, 2, 3), with the same messages.

**What each reader actually gets** (measured with a throwaway probe, deleted, on both engines; status codes only, and the security invariants above held in every row):

| Corruption (one stored value) | Person with no relationship / Guardian, audience routes | Privileged library, Pack, management |
| --- | --- | --- |
| Draft Pack state `PUBLISHED`, `published `, `archived`, empty | Live Pack: 404 / 200 (unaffected); the corrupt Draft Pack: **500**; Guardian's list: 200, except **500 on MariaDB** for `PUBLISHED` and `published ` (they pass the SQL filter) | library list **500**; its Pack 200; management 200 |
| Published Pack state `Published` or `retired` | the corrupt Pack: **500** (also for the Person with no relationship, where a missing Pack is a uniform 404); list 200, except **500 on MariaDB** for `Published` | all three **500** |
| Draft or Published Card state corrupt; narrowed or inheriting Card audience mode corrupt | the Pack that holds it: **500**; Guardian's list **500** | all three **500** |
| Narrowed Card that lost its audience rows | Pack 200 for the Guardian with the Card **withheld**; 404 for the Person with no relationship | 200 |
| Pack audience keys all unknown, or in the wrong case | **404** for both (nobody matches) | 200 |
| Pack with one extra unknown key beside its own | Guardian 200, Person with no relationship 404 | 200 |

Two availability consequences are worth knowing, both already inside the accepted fail-closed behaviour: one corrupt Draft Pack state makes the **privileged library list** answer 500 on both engines, and on MariaDB it can make a **Guardian's own list** answer 500.

**The corruption edge, documented.** A corrupt value is answered by a controlled error (today a 500 for the surface that reads it) or by being hidden, never by content. A corrupt Pack opened by an ineligible reader may answer 500 where a missing one answers the uniform 404, so it reveals the Pack exists, only under corruption and only to someone who already knows its id (audit WP2-05). On MariaDB `state = 'published'` is case- and trailing-space-insensitive, so `PUBLISHED` and `published ` pass the SQL filter and are stopped by parsing; on PostgreSQL the SQL filter stops them. The test accepts both. Recorded as [G6-D18](debt-register.md).

**Header comparison (audit §I proof gap).** `ResourcesFilesApiTest` › *gives every reason a reader's file is not theirs the SAME answer* now also collects every response header (except the clock, and cookie *values*, which are per-response tokens; cookie names are compared) for its nine cases and requires exactly one distinct set, containing `content-type`, `cache-control` and `x-content-type-options` and none of `content-disposition`, `etag` or `last-modified`. Mutation: a header `X-Probe` carrying the throw site's line number on the not-found renderer makes it fail (3 distinct header sets instead of 1); restored.

## 7. WP2-06: CRM authorization before lookup

**File:** `tests/Feature/Modules/Crm/CrmAuthorizationOrderTest.php` (33 tests). One table-driven test calls **each of the 16 CRM use cases that take an Actor** with a Person, note, tag and contact-method id that exist nowhere, as an Account with no role and as a Console participant (no CRM capability), and requires `AccessDenied`, not `UnknownPerson`, `TagNotFound`, `InteractionNotFound` or `ContactMethodNotFound`, and that nothing was created. A second test discovers every `Application` class whose `__invoke` takes an `Actor` and fails if one is missing from the table, so a use case added later cannot skip the rule; `PersonRegistration` is the single explicit carve-out (it authorizes nothing by design, pinned in `CrmBoundariesTest`).

**Mutation evidence** (the lookup planted above the authorization; uncommitted, restored): `AddContactMethod` (`personExists`), `DeleteTag` (tag lookup), `GetPersonRecord` (the record read) and `ListInteractions` (`personExists`): **each fails 2 of the 33** (one per kind of stranger).

## 8. WP2-04: documentation

- `docs/architecture/trust-boundaries.md`: "Public, unauthenticated surface today" now lists all **ten** routes by kind (framework liveness, stateless public, pre-authentication session endpoints, idempotent logout), then the authenticated Member self-service routes and the privileged administration routes, and names `RouteAuthenticationSurfaceTest` and `RouteSurface::PUBLIC_ROUTES` as the enforcement.
- `openapi/openapi.yaml`: `POST /logout`'s description states that authentication is optional and that it is one of the ten unauthenticated routes. Its `security` (`sessionCookie` or none) was already correct, so it is unchanged.
- `Relationships/Http/routes.php` comment: the verified operations now include the default-role decision. ADR 0038: the stale `DatabasePackRepository.php:252` reference now reads `:267`.
- `debt-register.md`: rows **G6-D12 to G6-D19** for the audit's I1 (orphan removal), I2 (Resources immediacy; G7 condition 6 trigger), I3, I4, I5, I7, the corrupt-value availability concern, and dormant sourced grants.

## 9. What was not changed

Step-up policy, security events, the role catalog, tolerant parsing, orphan cleanup, the Discussions repository, and every delivery path are as they were. No migration. No production middleware: the guard found no enforcement gap.

## 10. Validation

| Check | Result |
| --- | --- |
| `./flow check all --pgsql` (first run, at the final tree) | Repo checks, Composer, Pint and Larastan (level max, tests included) **green**. Pest on MariaDB **3421 passed, 26,811 assertions, 617 s**. Pest on PostgreSQL **3421 passed, 26,808 assertions, 747 s**. Build and `verify:build` green. **One failure: Vitest, `src/pages/resources/cards.test.tsx › adding a Card › has no accessibility violations, for each Type`, 5,235 ms against the 5 s test timeout** (2,275 of 2,276 passed) |
| Classification of that failure | Load-sensitive timing in a file and a Console this branch does not touch (`git diff origin/main -- apps/guardian-console` is empty). It is the same family as [G6-D03](debt-register.md) (a load-sensitive Console library test failing under the full gate; this one is an accessibility loop over every Card type, not a first-render `findBy`), and the file passed **3 of 3** run alone (40 tests each). No timeout was raised and nothing was retried to hide it |
| `./flow check frontend` (rerun) | **green**: typecheck, ESLint, Prettier, Vitest **2276 passed (89 files)**, build, `verify:build` |
| Focused new and affected tests on PostgreSQL | 294 passed (7,122 assertions) |
| `git diff --check` | clean |
| Browser suite (`./flow test e2e`) | **not run**, by the brief: no Console, E2E or browser-gate-trigger change. The only OpenAPI change is prose in one description |

Mutation evidence for every new guard is in §2 to §7. Every planted violation was an uncommitted edit restored immediately; `git status` was checked after each batch.

## 11. For the independent Opus reviewer

Verify from the code at the branch head, not from this record:

1. **Sourced-grant interpretation.** `Authorizer::capabilitiesOf` and `PeopleHoldingCapability` agree: independent keys against `Role`, sourced keys only against `ProvisionableRole`. Look for a third consumer that reads `sourced_role_grants` and interprets a role: I found `AccountViews::many` (display only; §4) and none other (`grep sourcedGrants|SourcedRoleGrantRepository`). Is leaving the display as is right?
2. **`ProvisionableRole::fromRoleKey` compares the stored key with `role()->value`**, not with the enum's own value. They are equal today; confirm that is the key the write side stores (`GrantSourcedRole` stores `$role->role()->value`).
3. **The route guard.** That `gatherRouteMiddleware` after building the HTTP kernel is what a real request resolves (including `withoutMiddleware` of a group name), and whether any route shape escapes it (a controller-defined middleware, a route registered after boot, a `Route::fallback`). Try a mutation of your own.
4. **`PUBLIC_ROUTES` and OpenAPI**: that the three readings of `security` (`required`, `optional`, `none`) cannot make a weaker declaration pass for an authenticated route.
5. **The Console-admission invariant** covers `Role::cases()` and is not tied to the five names; scope comment present.
6. **Resources corruption tests**: that the marker search cannot pass vacuously (the fixture test shows each reader sees what its audience entitles it to), and that the `hides` lists are right for each corruption. Try a corruption of your own, for example a card `type`.
7. **CRM**: the discovery test excludes only `PersonRegistration`.
8. **Scope**: no production change besides `ProvisionableRole`, `Authorizer`, `PeopleHoldingCapability` and `CheckRelationships`, plus one comment each in `Relationships/Http/routes.php` and the OpenAPI description.

