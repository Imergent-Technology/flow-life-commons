# G6 WP4: Module boundaries

- **Package:** WP4, module boundaries (demonstrated erosion only). Contract: [README, WP4](README.md#wp4--module-boundaries-demonstrated-erosion-only), decision D6, decision point DP3.
- **Base:** `main` at `c6b093d` (WP3c closeout, PR #32). **Branch:** `g6/wp4-module-boundaries`.
- **Outcome:** D6 corrected by the smallest practical change. `RelationshipDefinition` and `DefinitionSchema` moved from `Relationships\Domain` to `Relationships\Application`; `FieldValues` takes the field map. A generic Domain rule and a `Definitions/` scan guard against recurrence. No owner exception, no behaviour change. DP3 did not apply: the correction was proportionate.
- **Not in scope and unchanged:** G6-D07 and G6-D08 stay deferred; no recovery code, so no rehearsal run.

## 1. The demonstrated erosion

Exactly two files in any module's `Domain` imported another module's `Application`: `Relationships/Domain/RelationshipDefinition.php` and `DefinitionSchema.php`, both naming Access's `Capability` and `ProvisionableRole`. The `Relationships/Definitions/*.php` documents import the same two enums but are non-namespaced, class-less arrays, so Pest's architecture expectations cannot see them and no rule covered them.

## 2. Alternatives assessed

| Option | Verdict |
| --- | --- |
| **A. Neutral string keys in Domain, Application maps them to the enums** | Rejected. It changes the definition format and flips the tests that require a string capability or `default_role` to be refused. It would put a role-key literal in `Definitions/*.php`, which the Access role-key guard forbids, and it needs a mapping layer for about 13 `Capability` and 14 `defaultRole` consumers. |
| **B1. Move only `DefinitionSchema`** | Insufficient: `RelationshipDefinition` itself names the Access types. |
| **B2. Split the definition into a Domain core and an Application policy object** | Works but larger. It changes the public properties about 25 classes read. |
| **Loosen the properties to `BackedEnum`** | Rejected: a cast at every consumer, and a wrapper that removes nothing. |
| **C. A documented exception in ADR 0038, pinned by a test** | Valid fallback and zero code, but a permanent hole in the new generic rule for something this cheap to fix. Not needed. |
| **Chosen: move the two classes into `Application`, decouple `FieldValues`** | The format and every validation rule are untouched, because the documents still hold the real enum cases. |

## 3. Changed boundaries

- **Moved** (`git mv`, namespace and added `use` lines for the Domain classes they reference; no logic change): `RelationshipDefinition`, `DefinitionSchema`.
- **Domain:** `FieldValues::parse()` and `missingRequired()` take `array<string, RelationshipField>` instead of the definition. `$definition->field($key)` was `$fields[$key] ?? null`, and `->fields` is the same array, so the new body is the same expression.
- **Callers:** `EstablishRelationship` (2) and `UpdateRelationshipFields` (2) pass `$definition->fields`.
- **Imports only:** `RelationshipCatalog`, `DefaultRoleRequest`, `RelationshipPresentation`, `RelationshipTypeView` (same namespace now), `Http\RelationshipsPresenter` (repointed), `DefinitionSchemaTest`.
- **Not changed:** the authorization sites (they still pass `viewCapability` / `manageCapability` to `AuthorizeAction`), the default-role path, `DirectoryDefinitionSource`, every route, response, table and migration. Nothing outside the module imports the moved classes.
- **Module graph:** unchanged. Relationships to Access Application stays the one frozen edge.

## 4. Behaviour preservation

- **The logic is moved verbatim.** Typed `Capability` / `Capability` / `?ProvisionableRole` properties; a string capability or role key refused; only the four relationship-family capabilities accepted; view differing from manage; one capability per type across the catalog; a default role needing verified `intake`, `status` and `default_role`; unknown and malformed documents failing closed.
- **No test expectation was changed.** The existing `DefinitionSchemaTest` (81 refusal cases, the production-document load, the cross-definition collisions), `ApprenticeRelationshipTest`, `GuardianProvisioningTest`, `ProvisioningHintTest` and the Relationships feature, route, API and concurrency suites run unchanged, apart from the one import in `DefinitionSchemaTest`.
- **Added:** a reflection test pinning the property types (and the new namespace), so the boundary cannot later be "fixed" by loosening them; `FieldValues` cases for the new signatures (valid parse and canonicalization, null clears, empty input, unknown and non-string keys, invalid values naming the field, required-field detection from the map alone).

## 5. Guards and mutation evidence

**Rule A, `ModuleBoundariesTest`:** per module and per other module, `expect("{module}\Domain")->not->toUse("{other}\Application")`, one subject per expectation (the README's Pest limitation), modules discovered from disk.

| # | Check | Result |
| --- | --- | --- |
| A0 | The rule written before the move, on the original code | **Red**, 1 of 110: `Relationships\Domain` uses `Access\Application`. Green after the move |
| A1 | Planted: Relationships Domain `use`s Access `Capability`, import only | Red |
| A2 | Planted: same import with a typed property | Red |
| A3 | Planted: inline fully qualified name, no `use` | Red |
| A4 | Planted: Discussions Domain `use`s `Identity\Application\FindPeople` (import only; a different module pair) | Red, `Discussions Domain does not use Identity Application` |
| A5 | Planted: Crm Domain calls `\Access\Application\Capability::AssignRoles` inline (another pair) | Red |
| A6 | Planted: the class name held only in a string constant | **Not detected** (Pest reads class references, not strings). Not a dependency, so no second scanner was built |

**Rule B, `RelationshipsBoundariesTest`:** `Definitions/*.php` may name only `Access\Application\Capability` and `ProvisionableRole`. It reads the code through `SourceScan::code` (comments removed), sets string literals aside, and flags every other namespaced name, whether imported, grouped or inline. Twelve positive controls feed planted sources through the same function; the real-files test asserts it saw `guardian.php`, `volunteer.php` and exactly the two permitted names.

| # | Planted change (real files, reverted after each) | Result |
| --- | --- | --- |
| B1 | `guardian.php` imports Access `Role` | Red |
| B2 | `volunteer.php` imports `Relationships\Application\RelationshipDefinition` | Red |
| B3 | `guardian.php` names `\Relationships\Domain\RelationshipType` inline | Red |
| B4 | `guardian.php` group-imports `{Capability, Role}` | Red |
| B5 | Scan pointed at a misspelled directory | Error (the directory iterator throws), not a pass |
| B6 | Scan pointed at a real directory holding no documents (`Domain`) | Red: the file-set assertion fails |
| B7 | The helper made blind (returns no names) | Red in nine controls and the real-files test |

No planted violation was committed; `git status` was clean of them after each.

## 6. Verification

- **Full gate, first run (`./flow check all --pgsql`, on the working tree before the last test edit):** repo checks, Pint, TypeScript, ESLint, Prettier, Vitest (2276 tests), the production build and its verification all passed. Pest passed on MariaDB (3726 tests) and on PostgreSQL (3681 passed, 45 skipped, the engine-specific skips). **Larastan failed with two findings, both in my new `RelationshipsBoundariesTest` helper** (a missing `@return list<string>` and the consequent `mixed` concatenation). Fixed with a docblock.
- **After the fix, rerun:** Pint and Larastan (level max, `No errors`); `tests/Architecture` and `tests/Unit/Modules/Relationships` (765 passed); `./flow check repo` (all passed); `git diff --check` clean.
- **Not rerun in full:** the complete MariaDB and PostgreSQL backend suites were not repeated after the docblock-only change. The PR's CI runs the routine gate and is the check on that.
- **Not run, by design:** the recovery rehearsal and its mutation matrix (WP4 changes no recovery code), browser E2E, ShellCheck beyond what `check repo` ran.
- **Focused suites that exercised the move:** Relationships feature (routes, API, capability matrix, provisioning, hint, people access, schema integrity, demo seeder), `ApprenticeRelationshipTest`, `RelationshipsRaceTest`, `OpenApiContractTest`, Resources' `AudienceEligibilityTest` and Access's sourced-grant suites all ran in the full run, with no failure.

## 7. Remaining limitations

- Rule A sees class references, not class names in strings (A6). A module with no `Domain` directory (Health, Security, Release) is vacuously fine, because there is nothing to guard.
- Rule B allows the same two enums for every document in `Definitions/`; it cannot say which type may use which. That is the schema's job.
- Relationships still depends on Access's Application layer, the frozen, approved edge. This package removed an unapproved layer crossing, not that edge.
- Table ownership and `CASCADE` scans remain deferred (G6-D07, G6-D08).
