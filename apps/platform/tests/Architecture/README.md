# Architecture tests

Executable guardrails for `docs/architecture/` and the ADRs. They run with the
rest of the suite (`./flow test backend`) and in CI.

- `ModuleBoundariesTest.php`: layer direction, module isolation, no HTTP clients
  outside Infrastructure, no debug/dangerous functions, no `env()` outside config.
  Modules are discovered from `app/Modules`, so new modules are covered automatically.
- `DatabasePortabilityTest.php`: bans MariaDB-only schema/SQL unless a line carries
  `// portability-exception: ADR-NNNN`. The definitive check is running the suite on
  PostgreSQL (`./flow test backend --pgsql`).

## Proving a rule can fail

A rule that cannot fail is worse than no rule. When you add or change one, plant a
violation temporarily (for example a `Domain` class importing `Illuminate\Http\Request`),
confirm the test goes red, then delete it.

Known Pest quirk: an **array of subject namespaces** in `expect([...])->not->toUse(...)`
or `->not->toBeUsedIn(...)` passes vacuously. Use one subject per expectation (loop over
them) as the existing tests do. Arrays of *targets* are fine.
