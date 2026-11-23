# G6 WP5: G7 entry conditions, acceptance record

- **Package:** WP5 ([contract](README.md#wp5--g7-entry-conditions-documentation-only-nothing-built)). **Deliverable:** [`g7-entry-conditions.md`](g7-entry-conditions.md). Documentation only: no ADR, register disposition, code, migration, test or infrastructure changed.
- **Base:** `main` at `8efce28` (G6 WP4, PR #33; post-merge CI green). **Branch:** `g6/wp5-g7-entry-conditions`. Status: **for owner acceptance**. The contract's acceptance is "the owner accepts it".
- **Method:** source inspection of the facts each condition rests on, plus the G6 audit, triage and register records and ADRs 0007, 0018, 0024, 0032, 0033, 0034, 0037 and 0038. No new audit was run, and no test was run (documentation only).

## Contract coverage

| # | Condition | Fact | Risk | G7 decision | Where the fact comes from |
| --- | --- | --- | --- | --- | --- |
| 1 | Person-scoped delivery outside Commons | Yes | Yes | Yes | `Audience` enum, route surface, `AudienceEligibility`, ADR 0032/0033 |
| 2 | First service identity | Yes | Yes | Yes | `MembershipRouteSurfaceTest`, `MembershipTrustBoundariesTest`, `ResolveActor`, `Authorizer`, `security_events.actor_client_id` |
| 3 | Authentication strength | Yes | Yes | Yes | `Actor`, `AuthenticationMethod`, WP1-05, `CatalogTest` |
| 4 | ADR 0038 P8 | Yes | Yes (inferred, marked) | Yes | ADR 0038 P6, P8, P9; the `contact_methods` migration |
| 5 | Trusted proxies and attribution | Yes | Yes | Yes | `bootstrap/app.php`, trust-boundaries, WP1 audit D, `security_events` |
| 6 | Step-up for external publication | Yes | Yes | Yes | ADR 0037 d52/d53, `AdministrationRoutesTest`, WP2 K3, G6-D13 |
| 7 | Rendering on another origin | Yes | Yes | Yes | `DocumentProfile`, `ExternalUri`, ADR 0037 d24 to d31, `guardrails.test.ts`, CSP |
| 8 | Minimal `/v1` policy | Yes | Yes | Yes | ADR 0007, ADR 0024, OpenAPI components |
| 9 | Browser workflow | Yes | Yes | Yes | D7, G6-D06, `e2e.yml`, `gh run list` |
| 10 | G7-triggered debt | Yes | Yes | Yes | all 24 register rows read; 11 are G7-triggered |

## Verification

- **Every condition is represented** with a fact, a risk and a G7 decision (table above); each also has references and a trigger.
- **Links and anchors:** every relative link and heading anchor in `g7-entry-conditions.md` was resolved by script against the files and headings in the repository.
- **Register dispositions are unchanged.** The register file is untouched, and the new document quotes dispositions rather than restating them as new.
- **No G7 decision is claimed as made.** Where an ADR already fixes a direction (ADR 0018, 0033, 0037 decision 30), the document says the gate is to confirm or revise it.
- **Inferences are marked** "(inference)" in the document. Facts were read from the named source.
- **`git diff --check`** (on the staged diff) clean. **`./flow check repo`** exited 0 ("All checks passed"). No backend, browser, mutation or PostgreSQL run, as the contract provides for a documentation-only change.

## Points for the owner

1. **Condition 4's rationale is inferred.** The contract says "ADR 0038 P8. Revisit it before any WordPress identity is linked by CRM email" and gives no reason. P8 itself says nothing about WordPress; it accepts that a Volunteer manager may edit a Person's primary CRM email because those fields "carry no authority". The document reads the condition as: email-based identity linking would give that email authority, defeating P8's premise. Please confirm that is the intended reading, or correct it.
2. **Condition 1 depends on G4.** The roadmap's G7 scope names "announcements and resources", while G4 (*Announcements / Publishing*) has "ownership under review" and no implementation. G7's first delivery is therefore not only a design question.

## Discrepancies found in the existing documentation (nothing changed)

- **G6-D11's wording is stale.** It says "WP2's planned `console.access` invariant test"; the test exists (`CatalogTest`, from WP2, [wp2-record](wp2-record.md)). The disposition is unaffected.
- **The roadmap's G6 table is stale:** WP0 is still "Active" and WP1 to WP6 "Planned", though WP0 to WP4 are done. The contract assigns the roadmap update to WP6's closeout.
- **A point-in-time figure:** "all 96 `/admin` routes carry `can:console.access`" is the WP1 audit's count, cited as such; it was not re-counted.

## Limitations

- Facts are as of `8efce28`. The G6-D01 and G6-D02 outcomes (WP6) are not known and bear on condition 9.
- The document names options only where the existing record already contains them. It does not recommend between them, because that is the design gate's job.
