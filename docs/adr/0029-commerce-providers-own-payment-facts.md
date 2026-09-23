# ADR 0029: Commerce providers own payment facts; Commons owns organizational entitlement

- **Status:** Accepted (design; not yet implemented)
- **Date:** 2026-09-23
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0028](0028-membership-grants-derived-at-query-time.md)

## Context

Membership is largely paid for. Money arrives through an external commerce provider — today most likely Zeffy for annual Flow Life membership and Sanctuary All-Access, with other providers and other arrangements plausible later — and something has to decide what that payment *means* for the organization.

[ADR 0028](0028-membership-grants-derived-at-query-time.md) decided how membership access is represented. It deliberately left one column pair undefined: `source` and `source_reference`, the provenance of a grant. Defining them badly is how a membership schema turns into a shadow payments ledger.

The pull toward that is real and worth naming. The moment a grant records *why* it exists, it is tempting to also record how much was paid, in what currency, by what method, whether the charge recurred, whether it was refunded, and which campaign it came from. Each field looks harmless. Together they make Commons a second, worse, permanently stale copy of the provider's ledger — one that nobody reconciles, that answers financial questions wrongly, and that acquires obligations (receipts, refunds, tax treatment) it was never built to carry.

There is also a boundary question that must be settled before any automation is written: when a payment arrives, who decides which human it belongs to?

## Decision

**Authority is split, and the split is the decision.**

### The provider is authoritative for money

The external commerce/payment provider owns, and Commons does not duplicate:

- money actually received, the amount and the currency;
- payment method;
- payment status;
- recurring-charge mechanics and retries;
- receipts;
- donor-facing payment management.

If someone asks "did this payment succeed, and for how much?", the answer comes from the provider. Commons must not be able to give a different one.

### Commons is authoritative for interpretation

Commons owns, and no provider decides:

- who has membership access;
- the term of that access;
- why Commons granted it;
- the organizational relationships and future benefits that follow from it.

A payment is evidence. **The grant is Commons' own conclusion about what that evidence entitles someone to**, and that conclusion is the thing worth being authoritative about.

### What must never enter the Membership schema

`membership_grants` must **not** carry payment amount, currency, payment method, provider payment status, subscription or recurring-charge mechanics, campaign fields, or receipt state. Nor may equivalents arrive later wearing different names.

Provider identity **may** be recorded, as provenance only. The invariant, stated so it survives paraphrase:

> **Provider identity may be recorded as provenance, but provider-specific business fields and semantics do not enter the Membership domain schema.**

### `source`

`source` says why Commons granted the access. It is a code-owned vocabulary, not a provider enumeration. Phase 1 needs two values:

| Value | Means |
| --- | --- |
| `operator` | A Commons operator decided to grant this access |
| `luma_legacy` | Reconciled from the legacy Luma records during the Membership Foundation migration |

Later values may include `zeffy`, `partnership` or other providers and arrangements. Adding one is a code change with a test, not a schema change.

### `source_reference`

`source_reference` is an **opaque provenance handle**, nullable. It exists so a human investigating a grant can find the thing it came from.

It is **not** a Person identity, **not** a cross-system foreign key, and **never parsed by Membership**. Membership does not read structure out of it, branch on it, or resolve it against anything.

**It carries no uniqueness constraint in Phase 1.** `unique(source, source_reference)` is explicitly rejected, and the rejection is a decision rather than an omission:

- Legitimate flows refer to the same business reference more than once. A refund, a correction, a revoke-and-regrant with adjusted dates, or a term split across two grants can all honestly point at one payment.
- **Provenance and idempotency are different concerns.** A uniqueness constraint on a provenance column is a database-level idempotency mechanism smuggled into the wrong layer, where it constrains operators doing correct manual work in order to guard against an automation that does not exist yet.
- Idempotency belongs to provider-event ingestion, when that is built (below).

### The future automation seam

Direction only. **Nothing here is built, and this ADR does not authorize building it.**

```
provider payment/event
        ↓
verified, idempotent ingestion
        ↓
explicit Commons application policy
        ↓
membership grant or revocation
```

Webhooks may provide low-latency signals; provider read APIs provide reconciliation. Inbound remains untrusted: signatures verified, payloads validated ([integration model](../architecture/integration-model.md)).

When ingestion is built it owns **event idempotency**, most likely through an external-event record keyed by the provider's own event id. That table is **not created now**.

Interpretation — *this offering, purchased this way, means this much membership access* — is **explicit code with tests**, not a rules engine and not configuration. Concrete qualification and pricing rules are not final and are deliberately not recorded here: illustrative policies of the "first month grants a month, the second payment grants a year" kind are **product policy, not architecture**, and must not be frozen into an ADR. This ADR's job is to leave room for whatever that policy turns out to be.

### Person matching fails closed

When automation eventually maps provider data to a human, it **fails closed**:

- no guessing;
- no automatic grant when the match is ambiguous;
- a provider's customer id is never used as Person identity;
- **no Person is created automatically merely because a payment arrived.**

A known, live limitation makes this more than caution: **a Person has no email unless an Account exists** ([ADR 0015](0015-identity-owns-person.md) keeps Person thin; email is a login identifier on the Account). Account-less People — which is most members — therefore **cannot** be matched by provider email today.

**This ADR does not solve that by adding email to Person.** Doing so would breach ADR 0015's thinness rule for the convenience of an integration that does not exist. It is recorded as a future **CRM / contact-data** decision, triggered when automation volume justifies it, and taken then with contact data in view rather than as a side effect of a payment integration.

### Luma

Legacy membership data lives in Luma. There are only a handful of members and Luma is transitional, so the migration path is deliberately unautomated:

- **no Luma API integration and no import script;**
- manual reconciliation through the Guardian Console;
- create the Person where one does not exist;
- create a backdated grant with `source = luma_legacy`, and an opaque Luma record identifier in `source_reference` where one is useful.

Reconciling the real Luma members by hand is part of the Membership Foundation acceptance ceremony. Writing an importer for a handful of rows would cost more than the rows, and would have to be correct about a system being retired.

## Consequences

- **Commons never has to answer a financial question, and must never try.** Amount, status and receipts have exactly one home, and disagreement between the two systems is structurally impossible because Commons stores nothing to disagree with.
- **Reconciliation stays possible.** `source` plus an opaque `source_reference` is enough for a human to trace a grant to the payment behind it without Commons modelling payments.
- **Refunds are interpretation, not deletion.** A refund becomes a revocation decided by Commons policy — visible in the grant history with who decided it ([ADR 0028](0028-membership-grants-derived-at-query-time.md)) — rather than a payment field being mutated.
- **Providers become replaceable.** Nothing in the Membership domain is shaped by one provider's vocabulary, so changing or adding providers is a new `source` value and new interpretation code, not a migration.
- **Operators are not fought by the schema.** Without a uniqueness constraint on provenance, correcting a term or splitting one payment across two grants is ordinary work rather than something to be worked around.
- **Duplicate-grant protection is deferred with it.** Nothing stops two grants citing one payment, so Phase 1 relies on an operator being present for every grant. Automation must bring its own idempotency, at ingestion, where it belongs.
- **Automated membership from payments cannot ship until Person matching is solved.** That is a real, accepted cost of keeping Person thin, and the trigger for revisiting it is written down.

## Alternatives considered

- **Store payment amount and status on the grant.** The reflex, and the reason this ADR exists. Rejected: it creates an unreconciled second ledger, makes Commons answer money questions it cannot answer correctly, and drags receipt and refund obligations into a membership table.
- **`unique(source, source_reference)`.** Considered seriously and rejected: it breaks legitimate refund, correction and regrant flows, and it implements idempotency in the schema for an ingestion layer that does not exist. Idempotency will be owned by provider-event ingestion, keyed by provider event id.
- **Make the ADR Zeffy-specific.** Rejected: Zeffy is a current commercial choice, not an architectural one. Naming it in the decision would make replacing it a schema problem.
- **Encode qualification rules now** ("first month grants a month"). Rejected: product policy that is not final, does not belong in an ADR, and would be cited as architecture the moment it was written down.
- **A rules engine for offering-to-entitlement mapping.** Rejected: one organization, a few offerings, and rules that must be auditable. Explicit code with tests is clearer, testable and cheaper than configuring a general engine.
- **Add `email` to Person so provider matching works.** Rejected: it breaches ADR 0015's thinness rule, and it would be the first "just one column" that ADR warns about — driven by an integration that does not yet exist. Deferred to a CRM/contact-data decision.
- **Automatically create a Person on payment.** Rejected: it would let an external system create human records in the platform's authoritative registry, on evidence that does not identify a human.
- **Import Luma via its API.** Rejected at this scale: more code, and more risk of being wrong about a system being retired, than reconciling a handful of members by hand.
