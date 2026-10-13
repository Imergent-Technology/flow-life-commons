# G10 demo: Guardians, Volunteers and Resource audiences

How to walk through G10 ([ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)) on a development stack. Development only: every seeder named here refuses to run outside `local` and `testing`.

## Set it up

```bash
./flow up
./flow test e2e --workers=4          # seeds everything below, then runs the browser suite
```

Or seed without running the suite:

```bash
./flow artisan db:seed --class=E2eAccountSeeder     # fixture Accounts, the G10 personas and the demo operator
./flow artisan db:seed --class=ResourcesDemoSeeder  # five Categories, ten Packs
./flow artisan db:seed --class=RelationshipsDemoSeeder
./flow artisan relationships:check                  # "Relationships are consistent with their definitions."
```

Both demo seeders can be run again safely. A Pack, relationship, invitation or Membership that already exists is left as it is. To see a changed dataset, remove the demo rows first.

`RelationshipsDemoSeeder` writes as the **demo operator** (`e2e.demo.operator@example.org`), a Console administrator that `E2eAccountSeeder` creates once and never recreates, so the history it signs never names an Account that has gone. Without it, the seeder uses the first active administrator by email.

## The people (`RelationshipsDemoSeeder`)

Everything is written through the use cases an operator's requests reach. The history, decisions, grants and withdrawals are the platform's own.

| Person | Guardian | Volunteer | Member | Account | What it shows |
| --- | --- | --- | --- | --- | --- |
| Amara Okafor | Active, Initiate **declined** | — | Active | none | A Guardian with no role at all; field edits with their editor; a Guardian who is a Member |
| Bernard Lindqvist | **Inactive** (was Active, Initiate granted) | — | — | none | A former Guardian: the decision is kept, the grant was withdrawn by inactivation and is not restored by itself |
| Chiara Benedetti | Active, Initiate **granted** | Active | — | **invited** | Relationship-managed Guardian Initiate access; a Guardian who is a Volunteer; a Volunteer manager's phone correction (contact provenance) |
| Desmond Achterberg | Active, Initiate granted | — | — | invited | Guardian Full (an independent role, from Access's catalog) **and** relationship-managed Guardian Initiate, listed apart |
| Esi Mensah | Active, Initiate **granted** | Active (was Pending) | Active | **none** | A granted decision with no Account: Access holds an inert grant; no Account is made. Guardian, Volunteer and Member at once |
| Farah Haddad | — | **Pending** | — | none | Pending does not qualify |
| Gideon Park | — | Active | — | none | Fields: interests and availability |
| Hana Kowalczyk | — | **Inactive** (Pending → Active → Inactive) | — | none | Inactive does not qualify; three history entries |
| Idris Barzani | — | Active (Active → Inactive → **Active**) | — | none | Reactivated on the **same** relationship (one row, revision 3) |
| Joaquin Reyes | — | Active | Active | none | A Volunteer who is a Member |

The two invited Accounts' invitations go to Mailpit (`http://mail.flowlife.localhost:18080`). An invitation is valid for 7 days. Accepting one and enrolling an authenticator is the real way to see a dormant grant take effect (K6). Reissue an expired invitation from the Account page.

## The Resources (`ResourcesDemoSeeder`)

The eight G5 Packs are unchanged. G10 adds a **Volunteering** Category:

- **Community Day Briefing**, for Guardians and Volunteers. It has a Card for both, *Volunteer stations* narrowed to Volunteers, *Guardian hosting notes* narrowed to Guardians, and a Draft Card, *Rain plan*.
- **Volunteer Welcome**, for Volunteers only. It has a File Card (a CSV sign-up sheet).

Readers get the union of their audiences, Card by Card, and never a Draft:

| Reader | Packs served (policy C) | In Community Day Briefing |
| --- | --- | --- |
| Guardian | Guardian Onboarding (3 of 4 Cards), Opening and Closing Checklist, Sanctuary Operations (3 of 4), Room Booking Calendar, Sanctuary Floor Plan, Fire Safety Orientation, Community Day Briefing | Plan for the day, Guardian hosting notes |
| Volunteer | Community Day Briefing, Volunteer Welcome | Plan for the day, Volunteer stations |
| Member | Guardian Onboarding (the Member Card included), Member Circle Handbook | — |
| Guardian + Volunteer + Member | All nine Published Packs | Plan for the day, Volunteer stations, Guardian hosting notes |
| No audience | Nothing: "There are no Resources for you yet." | — |
| `resources.view` holder (policy A) | Every Pack, Drafts marked, every audience shown | All four, *Rain plan* marked Draft |

## Personas you can sign in as

`E2eAccountSeeder` makes them, and recreates them on every run. Passwords and TOTP secrets are public and worthless, and are in that file. The recovery codes are `E2E<a>-<b>C00-0000-000<n>` (see `E2eAccountSeeder::recoveryCodes`). Code 9 is spent minting the browser suite's session, so use 0–8 by hand. Alternatively, compute a code with `oathtool --totp -b <secret>`.

| Persona | Account | Role | Relationships, Membership | Expect |
| --- | --- | --- | --- | --- |
| A. Role, no relationship | `e2e.persona.roleonly@example.org` | Guardian Full | none | The role's capabilities (it reads the library as the privileged viewer); `my-resources` is empty; not in the Guardian directory |
| B. Restricted Guardian | `e2e.persona.initiate@example.org` | none; Guardian Initiate **through the relationship** | Active Guardian | Console admission; Guardian Resources only; no viewer, management or Relationships route |
| C. Console Participant | `e2e.persona.participant@example.org` | Console Participant | none | Admitted; the empty library |
| D. Participant + Volunteer | `e2e.persona.volunteer@example.org` | Console Participant | Active Volunteer | Volunteer Resources; no Guardian eligibility |
| E. Guardian, Volunteer, Member | `e2e.persona.multi@example.org` | none; Initiate through the relationship | Active Guardian, Active Volunteer, Active Member | The union of all three |
| Guardian without admission | `e2e.persona.unadmitted@example.org` | none (declined) | Active Guardian | A Guardian, eligible, and not admitted: the Console refuses, `/my` opens |
| Former Guardian | `e2e.persona.former@example.org` | Console Participant | Inactive Guardian, Pending Volunteer | Admitted; nothing served |

The lifecycle personas (`e2e.persona.lifecycle.*`) and the cleanup probe belong to their journeys. Do not use them by hand while the suite runs.

## A walkthrough

1. Sign in as an administrator. Your own, or a fixture administrator such as `e2e.admin.read@example.org`, with a recovery code tagged `H`.
2. **Relationships → Guardians.** Filter by status: Amara, Chiara, Desmond and Esi are Active; Bernard is Inactive. **Volunteers**: Farah Pending, Hana Inactive, Idris Active (reactivated).
3. Open **Esi Mensah**, then her Person record. There are two tabs, Guardian and Volunteer. The Guardian Initiate panel says the decision is granted. She has no Account, so the grant takes effect only once she signs in with one.
4. Open **Idris Barzani**: the status history has three entries on one relationship. Open **Bernard Lindqvist**: the grant was withdrawn while he is not active.
5. **Administration → Accounts → Chiara Benedetti**: *Access through a source* lists Guardian Initiate, granted through a relationship, with no revoke button. **Desmond Achterberg** also has Guardian Full under *Access*.
6. **Resources → Community Day Briefing**: the audiences are Guardians and Volunteers, two Cards are narrowed and one is a Draft. Preview it.
7. Sign in as **persona B** and open the Resource Library. You get Guardian Packs only; in Community Day Briefing, only *Plan for the day* and *Guardian hosting notes*.
8. Compare it with **persona A** or any `resources.view` holder. They read every Card of the same Pack, with *Rain plan* marked Draft.

The browser suite proves each of these steps: `e2e/g10-demo.spec.ts`, `e2e/g10-personas.spec.ts` and `e2e/g10-lifecycle.spec.ts`.
