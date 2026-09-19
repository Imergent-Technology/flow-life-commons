# Git workflow

Lightweight on purpose.

- **`main` stays releasable.** Everything that lands passes `./flow check`.
- **Short-lived branches**, cut from `main` and merged back quickly: `feat/...`, `fix/...`, `chore/...`, `docs/...`. Example: `feat/identity-external-mapping`.
- **No permanent `develop` branch.** No long-lived release branches until a release process exists.
- **Pull requests** into `main`; CI must be green. Keep them small and focused. Squash-merge is fine; the PR title should read like a commit message.
- **Conventional Commit** messages: `type(scope): summary`, imperative, no trailing period. Types: `feat`, `fix`, `docs`, `test`, `refactor`, `chore`, `ci`, `build`, `perf`. Scope is a module or area.

```
feat(identity): add external identity mapping
fix(access): deny unauthorized publication
docs(adr): define database portability policy
test(events): cover approval permissions
```

- Breaking API changes are called out in the body and require a new API version ([ADR 0007](../adr/0007-versioned-rest-api-openapi.md)).
- Never commit secrets, `.env` files (only `*.example`), `vendor/`, `node_modules/` or `dist/`.
- Decisions that deviate from the [charter](../architecture/charter.md) need an [ADR](../adr/README.md) in the same PR.

Nothing enforces these yet (no commit hooks, no branch protection tooling); add tooling only when the team feels the pain.
