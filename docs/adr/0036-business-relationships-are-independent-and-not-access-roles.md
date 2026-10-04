# ADR 0036: Business relationships are independent of each other and are not Access roles

- **Status:** Accepted (a principle; no code changes with it)
- **Date:** 2026-10-04
- **Supersedes:** none. **Amends** the Volunteer relationship stated in [ADR 0034](0034-crm-enriches-identity-person.md) ("Note on Volunteers") and in [member access](../architecture/member-access.md) ("Volunteer extension seam")
- **Superseded by:** none
- **Related:** [ADR 0015](0015-identity-owns-person.md), [ADR 0017](0017-capabilities-and-roles-in-code.md), [ADR 0028](0028-membership-grants-derived-at-query-time.md), [ADR 0032](0032-members-use-a-commons-hosted-surface.md), [ADR 0037](0037-resources-are-audience-targeted-packs-of-cards.md)

## Context

Several documents recorded, as settled, that **"Volunteers are Members with elevated duties and privileges"**: ADR 0034's note on Volunteers, the member-access design's Volunteer extension seam, the module map's Volunteering row, and the roadmap. The product owner has since corrected that: Membership and Volunteering are separate business relationships, and a Person may hold either, both or neither.

The Resources design gate ([ADR 0037](0037-resources-are-audience-targeted-packs-of-cards.md)) is the first place the difference changes a design. Resources targets audiences, and "deliver to Members" would silently include every Volunteer if one relationship implied the other. The correction is not specific to Resources, though: it is how every domain must treat Persons' relationships to Flow Life, so it is recorded here, once, rather than inside one module's ADR.

The repository already distinguishes some of this. Membership is time-bounded grants derived at query time and is explicitly never represented solely by a role assignment ([ADR 0028](0028-membership-grants-derived-at-query-time.md)). Roles are code-owned bundles of capabilities, internal to Access, and no other module may name one ([ADR 0017](0017-capabilities-and-roles-in-code.md); an architecture test enforces it). What was missing is the general rule.

## Decision

1. **A business relationship is how a Person relates to Flow Life**: Member, Volunteer, Partner, Vendor, Artist, and future ones. It is about the Person (Identity's Person, [ADR 0015](0015-identity-owns-person.md)), never a second identity.
2. **Relationships are independent and may overlap.** None implies another. In particular: Volunteer does not imply Member, Member does not imply Volunteer, Artist does not imply Partner, Vendor does not imply Partner, and Guardian does not imply Member. A Person may be a Member only, a Volunteer only, both, a Partner and an Artist, a Vendor, a Member, Volunteer and Artist, or none of them.
3. **Each relationship has one authoritative owner, the domain that holds its facts.** Membership owns Member (`membership_grants`, derived at query time). Volunteer will be owned by a Volunteering domain that does not exist yet. Partner, Vendor and Artist have no owner yet. No other module stores a copy of a relationship's truth, and no module invents a stand-in for one it needs (a "volunteer" flag, a tag, a role) because its owner has not been built.
4. **Roles and capabilities decide what an Account may do in the software. They are not business relationships.** A role assignment is not evidence that a Person is a Member, Volunteer or anything else, and granting a role never creates a relationship.
5. **The direction is one way: a relationship may result in Access grants; an Access grant never establishes a relationship.** When a relationship should confer software capability (a Volunteer's tools on `/my/`, say), the grant is derived from the relationship's current state by its owner and the Access mechanism, as ADR 0028 already requires for Membership, and a role assignment is never the sole source of truth for a relationship that can lapse.
6. **A consumer asks the owner.** A module that needs to know whether a Person holds a relationship (Resources, deciding an audience) asks the owning domain's `Application` layer, at the time it needs the answer. It does not read the owner's tables, and it does not ask Access, because the question is about the relationship and not about software permission.
7. **Guardian is, for now, represented only in Access.** There is no Guardian business relationship in the platform: being a Guardian is holding the Guardian role, and code outside Access learns it only by asking for a capability, never by naming the role. This ADR does not create a Guardian relationship. If one is ever needed (for instance a term on the Guardian Council with its own history), it is designed then, by its own gate, and consumers that today ask for a Guardian capability are re-pointed at it.
8. **What stays true of Volunteers.** A Volunteer is a Person, not a second identity type, and Volunteering is not merely an Access role: both statements in the earlier documents remain correct. What is withdrawn is only "Volunteers are Members". The Volunteering domain (lifecycle, assignments, duties, history) remains undesigned.

### What this changes in existing documents

- [ADR 0034](0034-crm-enriches-identity-person.md)'s "Note on Volunteers" is amended by this ADR. Its other content is unchanged.
- [Member access](../architecture/member-access.md): a Volunteer reaches the same `/my/` surface as any signed-in Account, and Volunteer capabilities, when they exist, extend it, gated by capability. That design stands; it no longer assumes the Volunteer is also a Member.
- The [module map](../architecture/module-map.md) and the [roadmap](../roadmap.md) are corrected in the same change.

## Consequences

- Audience-targeted features (Resources first) must treat each relationship as its own audience, combined by union, and can never infer one from another.
- A Volunteer who is not a Member has no membership grant, sees nothing Member-only, and must not be given a membership grant to make something work.
- The Volunteering domain must exist, with an `Application` read for "is this Person a Volunteer now", before anything is delivered to Volunteers. Nothing may fake it in the meantime.
- Guardian eligibility stays a capability question until a Guardian relationship is designed, so a Platform Administrator, who holds every capability, is treated as a Guardian wherever a Guardian capability is asked for. That is the existing behaviour and is accepted.
- Some future relationships may have no lapse (an Artist credit, say) and others may (Membership); each owner decides its own temporal model. ADR 0028's rule, that lapsing eligibility is never a durable role alone, applies to all of them.

## Alternatives considered

- **Keep "Volunteers are Members with elevated duties".** Rejected by the product owner: Flow Life has volunteers who are not members, and a hierarchy would deliver Member content to them and force a membership grant on people who have not taken one.
- **Model relationships as Access roles.** Rejected: a role does not lapse (ADR 0028), it is about software permission rather than organizational fact, and roles are internal to Access, so other domains could not ask about them without breaking ADR 0017's boundary.
- **A generic `relationships` table now, owned by Identity or a new module.** Rejected (charter rule 17): only Membership exists today, the others have different lifecycles and data, and a generic table would be designed without a second real consumer. Each relationship's owner is decided when it is built.
- **Record the correction inside the Resources ADR.** Rejected: it governs every domain, and a platform-wide rule should not arrive as a side effect of one module's design.
