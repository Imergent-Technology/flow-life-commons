# ADR 0009: Authorization is separate from approval

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Many organizational processes involve both "who is allowed to do this?" and "has this been signed off?" (for example, a volunteer may be allowed to *submit* an event, and a Guardian must *approve* it before publication). Conflating the two produces permission models full of special cases such as "can publish only if approved" baked into roles, and workflows that cannot change without changing permissions.

## Decision

Treat them as **two separate concepts** with separate models:

- **Authorization** answers whether an actor may attempt an action on a resource, decided from identity, roles and policy.
- **Approval/workflow** tracks the state of a specific item through steps (draft, submitted, approved, published) and who decided each step.

An action can require *both*: the actor must be authorized to approve, and the item must be in a state that allows approval. Neither model encodes the other. Workflow is its own module ([module map](../architecture/module-map.md)); the details are designed with the Workflow epic, not now.

## Consequences

- Permissions stay stable while processes evolve; workflows can change without re-cutting roles.
- Approval history is a first-class, auditable record.
- Two things to design and test instead of one, in exchange for much less special-casing later.

## Alternatives considered

- **Encode approval states as permissions/roles** ("approved-publisher"): simple to start, brittle as processes change.
- **Single combined policy engine:** conflates static access rules with per-item state.
