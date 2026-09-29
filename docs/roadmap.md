# Flow Life Commons Product Roadmap

This document tracks product sequencing and implementation status. ADRs and architecture documents remain authoritative for technical and product decisions; where this page and one of them disagree about a decision, they win and this page is wrong.

**Status words:** *Complete* · *Active* (being built now) · *Next* · *Planned* · *Parked* (built or started, deliberately not being extended) · *Operational* (code exists, an operator task remains) · *Horizon* (an idea, not roadmap work). No dates, no percentages.

## Where things are

**Production runs release v0.3.0.** `main` is ahead of it: not every commit is released or deployed ([release vs deployment](runbooks/deployment.md#release-is-not-deployment)).

| Area | Status | In production (v0.3.0)? |
| --- | --- | --- |
| Identity and Account lifecycle (invitations, password reset and change, sessions) | Complete | Yes |
| Access: code-owned capabilities and roles, operator administration | Complete | Yes |
| Multi-factor authentication: enrollment, challenge, recovery, enforced for the Console ([ADR 0023](adr/0023-multi-factor-authentication.md)) | Complete | Yes |
| Membership: time-bounded grants, operator API and Console administration ([ADR 0028](adr/0028-membership-grants-derived-at-query-time.md)) | Complete | Yes |
| Guardian Console shell and visual foundation ([ADR 0030](adr/0030-guardian-console-visual-system.md)) | Complete | Yes |
| Browser security policy; release and deployment tooling ([ADR 0026](adr/0026-production-browser-security-policy.md), [ADR 0027](adr/0027-release-and-deployment-model.md)) | Complete | Yes |
| Member foundation, work packages 1–5: existing-Person invitation, `/my/` self-service shell, neutral credential presentation, end-to-end proof ([member access](architecture/member-access.md)) | Complete; further expansion Parked | No, on `main` only |
| Production transactional mail: authenticated-SMTP code foundation ([ADR 0031](adr/0031-production-transactional-mail-uses-authenticated-smtp.md)) | Complete (code) | No, on `main` only |
| CRM / People architecture, WP0 ([ADR 0034](adr/0034-crm-enriches-identity-person.md)) | Complete | Docs only |
| Identity People search and rename, WP1 | Complete | No, on `main` only. An Application seam with no route: nothing user-visible yet |

## Current focus: G1 — CRM / People (Active)

Make Commons genuinely useful to Guardians as a people and contact system. The boundary and owner decisions are in [ADR 0034](adr/0034-crm-enriches-identity-person.md); the module's design is not repeated here.

| Package | Scope | Status |
| --- | --- | --- |
| WP0 | CRM / Person architecture | Complete |
| WP1 | Identity People search and rename | Complete |
| WP2 | CRM profiles, contact methods and tags (introduces the CRM capabilities) | Next |
| WP3 | Notes and interactions backend | Planned |
| WP4 | Guardian People UI | Planned |
| WP5 | Notes timeline and tag-management UI | Planned |
| WP6 | End-to-end proof and demo closeout | Planned |

## Near-term usability and operations

These run alongside the milestones and are not milestones themselves.

- **MFA enrollment usability — Next (near-term, before the Council demonstration).** Who must use MFA is unchanged; this is about making policy-driven Guardian enrollment easy: a scannable QR code plus an accessible manual-setup fallback. Current state: the enrollment flow already draws a QR code in the browser and offers the manual key (shipped in v0.3.0), so the package should start by establishing what is still hard in practice.
- **Production transactional mail activation — Operational.** The code exists; choosing a provider, configuring DNS and credentials, and verifying a real send do not. **Production mail is not active** and still runs `MAIL_MAILER=log` ([production readiness](runbooks/production-readiness.md)).

## Guardian milestones

Domain ownership rule: Commons domains own durable business state and rules; the Guardian Console is the primary rich authoring surface; `/my/`, WordPress and any future client present Commons capabilities and own none ([charter](architecture/charter.md)). Each milestone gets its own design gate, and an ADR where a decision is durable.

| Milestone | Goal | Status |
| --- | --- | --- |
| **G1 — CRM / People** | A Guardian people and contact system that enriches Identity's Person | Active |
| **G2 — Guardian Discussions** | A Guardian-focused discussion capability owned by Commons; later projection to Members or Volunteers stays possible | Planned |
| **G3 — Event Planning** | Practical Guardian operational planning for Flow Life events | Planned |
| **G4 — Announcements / Publishing** | Guardian-authored announcements with outward publication targets to follow; timing may depend on production mail | Planned |
| **G5 — Knowledge / Resources** | SOPs, policies, training material, reference resources and curated links | Planned |

## Deliberately deferred foundations

Not scheduled. Each is built when a milestone actually needs it, not before ([charter rule 17](architecture/charter.md)).

- **Generated API client** — the original trigger has fired ([api-client README](../packages/api-client/README.md)); not yet picked up as its own piece of work.
- **Domain events and transactional outbox** — built with the first real consumer ([integration model](architecture/integration-model.md)).
- **Person merge and anonymisation / retention policy** — open ([data ownership](architecture/data-ownership.md)).
- **Volunteering domain model** — Volunteers are Members with elevated duties and privileges; the lifecycle, assignments and history are undecided ([member access](architecture/member-access.md)).
- **Deeper WordPress projection** — see below.
- **Shared audience model, unified search, generic entity references, file and media handling** — no design exists; each is decided by the first milestone that needs it.

## Members and WordPress

- The Member foundation exists and is **Parked**. `/my/` is the Commons-hosted self-service foundation ([ADR 0032](adr/0032-members-use-a-commons-hosted-surface.md)). Member expansion is not the next priority.
- Guardian-domain capabilities may later be offered to Members or Volunteers, most likely on `/my/`.
- The WordPress companion is a skeleton and remains a possible presentation client for selected Commons capabilities ([WordPress integration](integrations/wordpress.md)).
- Person-specific, delegated WordPress authentication stays deferred until a real product need meets the trigger in [ADR 0033](adr/0033-service-identity-and-delegated-human-authority-are-distinct.md).

## Horizon

Ideas only; nothing here is planned, designed or scheduled, and none of it should shape near-term work.

- Realtime chat, private messaging and presence
- Richer realtime collaboration and collaborative document editing
- Campaign automation and multi-channel publishing
- Advanced social and community experiences

Where an existing external tool already does a job well, integrating with it may be better than rebuilding it.
