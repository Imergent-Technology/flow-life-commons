import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { deferred, pageBody } from '../test/deferred.ts'
import { empty, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { bodyOf, dialog, prove, verificationRefused } from '../test/relationshipPages.ts'
import {
  candidate,
  candidateList,
  codedInvalid,
  PERSON,
  PERSON_2,
  refusal,
  serveRelationships,
  wireApprenticeType,
  wireDefaultRole,
  wireGuardianType,
  wireManagement,
  wireRecord,
  wireVolunteerType,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const VOL = '/api/v1/admin/relationships/volunteers'
const GUA = '/api/v1/admin/relationships/guardians'
const location = () => screen.getByTestId('location').textContent

type User = ReturnType<typeof userEvent.setup>

async function search(user: User, text: string) {
  const box = await screen.findByRole('searchbox', { name: 'Name, email or phone' })
  await user.clear(box)
  await user.type(box, text)
  await user.click(screen.getByRole('button', { name: 'Search' }))
}

/** Serves a type's candidate lookup and the record it lands on. */
function volunteerIntake(candidates = candidateList([candidate(PERSON, 'Ada Lovelace', 'email')])) {
  const api = serveRelationships([wireVolunteerType()])
  api.on(`GET ${VOL}/candidates`, json(candidates))
  api.on(
    `GET ${VOL}/${PERSON}`,
    json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })),
  )
  api.on(
    `GET ${VOL}/${PERSON}/management`,
    json(
      wireManagement({
        type: 'volunteer',
        status: 'pending',
        fields: {},
        default_role: null,
        transitions: ['active'],
      }),
    ),
  )
  return api
}

describe('finding the person', () => {
  it('searches only when submitted, and only with three characters or more', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake()
    renderApp('/relationships/volunteers/new')
    await screen.findByRole('heading', { level: 1, name: 'Record a Volunteer' })

    await search(user, 'ad')
    expect(await screen.findByText('Type at least 3 characters.')).toBeVisible()
    expect(api.calls.filter((c) => c.path.startsWith(`${VOL}/candidates`))).toHaveLength(0)

    await search(user, 'ada@example.org')
    await screen.findByRole('list', { name: 'People found' })
    const calls = api.calls.filter((c) => c.path.startsWith(`${VOL}/candidates`))
    expect(calls.map((c) => c.path)).toEqual([`${VOL}/candidates?q=ada%40example.org`])
    await expectNoAxeViolations(pageBody())
  })

  it("shows a candidate's name and what matched, and nothing hidden: no contact value, Account, role or other relationship", async () => {
    const user = userEvent.setup()
    volunteerIntake()
    renderApp('/relationships/volunteers/new')
    await search(user, 'ada@example.org')
    const list = await screen.findByRole('list', { name: 'People found' })
    expect(within(list).getByText('Ada Lovelace')).toBeVisible()
    expect(within(list).getByText('matched the email address')).toBeVisible()
    expect(list.textContent).not.toMatch(/@|account|role|guardian|membership|\+1/i)
  })

  it('does not let a person be chosen by a name that two people share, and asks for an exact email or phone instead', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake(
      candidateList([
        candidate(PERSON, 'Sam Lee'),
        candidate(PERSON_2, 'Sam Lee'),
        candidate('01J0000000000000000PERSON3', 'Priya Rao'),
      ]),
    )
    renderApp('/relationships/volunteers/new')
    await search(user, 'sam')
    const list = await screen.findByRole('list', { name: 'People found' })

    // The two with the same name cannot be told apart, so neither can be chosen; the third can.
    expect(within(list).getAllByText('Sam Lee')).toHaveLength(2)
    expect(within(list).queryByRole('button', { name: 'Choose Sam Lee' })).not.toBeInTheDocument()
    expect(within(list).getByRole('button', { name: 'Choose Priya Rao' })).toBeVisible()
    expect(
      screen.getByText(/Search again with an exact email address or phone number/),
    ).toBeVisible()
    // The page invents no way to tell them apart.
    expect(list.textContent).not.toMatch(/\d{3}|@/)

    // The exact lookup finds one of them by email, and that is choosable.
    api.on(`GET ${VOL}/candidates`, json(candidateList([candidate(PERSON, 'Sam Lee', 'email')])))
    await search(user, 'sam@example.org')
    expect(await screen.findByRole('button', { name: 'Choose Sam Lee' })).toBeVisible()
  })

  it('sends someone who already holds the type to their record instead of recording them again', async () => {
    const user = userEvent.setup()
    volunteerIntake(candidateList([candidate(PERSON, 'Ada Lovelace', 'email', 'active')]))
    renderApp('/relationships/volunteers/new')
    await search(user, 'ada@example.org')
    const link = await screen.findByRole('link', { name: "Open Ada Lovelace's record" })
    expect(link).toHaveAttribute('href', `/relationships/volunteers/${PERSON}`)
    expect(screen.getByText(/already Active/)).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Choose Ada Lovelace' })).not.toBeInTheDocument()
  })

  it('says when there is no match and when more match than are shown', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake(candidateList([]))
    renderApp('/relationships/volunteers/new')
    await search(user, 'nobody')
    expect(await screen.findByText(/No one matches/)).toBeVisible()
    api.on(`GET ${VOL}/candidates`, json(candidateList([candidate(PERSON, 'Ada Lovelace')], true)))
    await search(user, 'ada')
    expect(await screen.findByText(/More people match than are shown/)).toBeVisible()
  })
})

describe('recording an existing person', () => {
  it('opens a confirmation naming them first, records nothing by choosing, and sends the confirmation only on the final step', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake()
    api.on(
      `POST ${VOL}`,
      json(
        wireManagement({
          type: 'volunteer',
          status: 'pending',
          fields: {},
          default_role: null,
          transitions: ['active'],
        }),
        201,
      ),
    )
    renderApp('/relationships/volunteers/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))

    const confirm = await dialog('Record Ada Lovelace?')
    expect(within(confirm).getByText(/creates a volunteer relationship for them/)).toBeVisible()
    // Volunteers' basics can be kept up through the relationship, and the confirmation says so.
    expect(
      within(confirm).getByText(/keep their name, primary email and primary phone up to date/),
    ).toBeVisible()
    expect(api.callsTo(`POST ${VOL}`)).toHaveLength(0)
    await expectNoAxeViolations(pageBody())
    await user.click(within(confirm).getByRole('button', { name: 'Continue' }))

    // Nothing is chosen for the operator: the status starts empty, and they must pick it.
    const status = screen.getByRole('combobox', { name: 'Initial status' })
    expect(status).toHaveValue('')
    expect(
      within(status)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Choose…', 'Pending', 'Active'])
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    expect(await screen.findByText('Choose a status.')).toBeVisible()
    expect(api.callsTo(`POST ${VOL}`)).toHaveLength(0)

    await user.selectOptions(status, 'pending')
    await user.type(screen.getByRole('textbox', { name: 'Interests and skills' }), 'Gardening')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    const review = await dialog('Record Ada Lovelace as a Volunteer?')
    expect(within(review).getByText('Pending')).toBeVisible()
    expect(within(review).queryByText(/Access/)).not.toBeInTheDocument() // a Volunteer has no access decision
    expect(api.callsTo(`POST ${VOL}`)).toHaveLength(0) // a review is not a recording
    await user.click(within(review).getByRole('button', { name: 'Record' }))

    await waitFor(() => {
      expect(location()).toBe(`/relationships/volunteers/${PERSON}`)
    })
    expect(bodyOf(api, `POST ${VOL}`)).toEqual({
      person_id: PERSON,
      confirm_existing_person: true,
      status: 'pending',
      fields: { interests: 'Gardening' },
    })
    expect(await screen.findByText('Ada Lovelace was recorded as a Volunteer.')).toBeVisible()
  })

  it('does not mention maintaining basic details for a type that cannot', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    api.on(
      `GET ${GUA}/candidates`,
      json(candidateList([candidate(PERSON, 'Ada Lovelace', 'email')])),
    )
    renderApp('/relationships/guardians/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    const confirm = await dialog('Record Ada Lovelace?')
    expect(confirm.textContent).toContain('creates a guardian relationship')
    expect(confirm.textContent).not.toMatch(/keep their name/)
  })

  it('can be cancelled at the confirmation without choosing anyone', async () => {
    const user = userEvent.setup()
    volunteerIntake()
    renderApp('/relationships/volunteers/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    await user.click(
      within(await dialog('Record Ada Lovelace?')).getByRole('button', { name: 'Cancel' }),
    )
    expect(screen.queryByRole('form', { name: 'Record a Volunteer' })).not.toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'People found' })).toBeVisible()
  })

  it('opens on the confirmation when it was reached from the Person record, which still records nothing', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake()
    renderApp({
      pathname: '/relationships/volunteers/new',
      state: { person: { id: PERSON, displayName: 'Ada Lovelace' } },
    })
    const confirm = await dialog('Record Ada Lovelace?')
    expect(confirm).toBeVisible()
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(0)
    // Nobody is chosen until the confirmation is given: behind it there is still the search, and no form.
    expect(screen.queryByRole('form', { name: 'Record a Volunteer' })).not.toBeInTheDocument()
    await user.click(within(confirm).getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('form', { name: 'Record a Volunteer' })).not.toBeInTheDocument()
    expect(screen.getByRole('searchbox', { name: 'Name, email or phone' })).toBeVisible()
  })

  it('does not present a request that is still pending as a recording', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake()
    const held = deferred<Response>()
    api.on(`POST ${VOL}`, () => held.promise)
    renderApp('/relationships/volunteers/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    await user.click(
      within(await dialog('Record Ada Lovelace?')).getByRole('button', { name: 'Continue' }),
    )
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))

    const review = screen.getByRole('dialog', { name: /Record Ada/ })
    expect(within(review).getByRole('button', { name: 'Working…' })).toBeDisabled()
    expect(location()).toBe('/relationships/volunteers/new')
    expect(screen.queryByText(/was recorded as/)).not.toBeInTheDocument()

    held.resolve(
      json(
        wireManagement({ type: 'volunteer', status: 'active', fields: {}, default_role: null }),
        201,
      ),
    )
    await waitFor(() => {
      expect(location()).toBe(`/relationships/volunteers/${PERSON}`)
    })
  })

  it('reports a relationship that already exists, with a link to it, and records nothing', async () => {
    const user = userEvent.setup()
    const api = volunteerIntake()
    api.on(`POST ${VOL}`, refusal('relationship_exists', 409))
    renderApp('/relationships/volunteers/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    await user.click(
      within(await dialog('Record Ada Lovelace?')).getByRole('button', { name: 'Continue' }),
    )
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('This person is already recorded as a Volunteer.')
    expect(within(alert).getByRole('link', { name: 'Open their record' })).toHaveAttribute(
      'href',
      `/relationships/volunteers/${PERSON}`,
    )
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it("puts the server's word for a field on that field and takes the operator back to the form", async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()], undefined)
    api.on(
      `GET ${GUA}/candidates`,
      json(candidateList([candidate(PERSON, 'Ada Lovelace', 'email')])),
    )
    api.on(
      `POST ${GUA}`,
      json(
        {
          message: 'Bad date.',
          code: 'invalid_relationship_field',
          errors: { recognized_on: ['That date is in the future.'] },
        },
        422,
      ),
    )
    renderApp('/relationships/guardians/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    await user.click(
      within(await dialog('Record Ada Lovelace?')).getByRole('button', { name: 'Continue' }),
    )
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'inactive')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))

    await screen.findByText('That date is in the future.')
    expect(screen.getByLabelText('Guardian since')).toHaveAccessibleDescription(
      /That date is in the future/,
    )
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})

describe('recording a Guardian-like type (an access decision, and recent verification)', () => {
  async function toReview(
    user: User,
    api: ReturnType<typeof serveRelationships>,
    state: 'active' | 'inactive',
  ) {
    api.on(
      `GET ${GUA}/candidates`,
      json(candidateList([candidate(PERSON, 'Ada Lovelace', 'email')])),
    )
    renderApp('/relationships/guardians/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    await user.click(
      within(await dialog('Record Ada Lovelace?')).getByRole('button', { name: 'Continue' }),
    )
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), state)
  }

  const created = (overrides: Record<string, unknown> = {}) =>
    json(wireManagement({ default_role: wireDefaultRole(), ...overrides }), 201)

  it('starts the Active choice checked for an operator who may provision, and sends grant only after the final confirmation', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    api.on(`POST ${GUA}`, created())
    api.on(`GET ${GUA}/${PERSON}`, json(wireRecord()))
    api.on(`GET ${GUA}/${PERSON}/management`, json(wireManagement()))
    await toReview(user, api, 'active')

    const option = screen.getByRole('checkbox', { name: 'Grant Guardian Initiate access' })
    expect(option).toBeChecked()
    expect(option).toBeEnabled()
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    const review = await dialog('Record Ada Lovelace as a Guardian?')
    expect(within(review).getByText('Guardian Initiate access will be granted.')).toBeVisible()
    expect(api.callsTo(`POST ${GUA}`)).toHaveLength(0) // preselected is not confirmed
    await user.click(within(review).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(location()).toBe(`/relationships/guardians/${PERSON}`)
    })
    expect(bodyOf(api, `POST ${GUA}`)).toEqual({
      person_id: PERSON,
      confirm_existing_person: true,
      status: 'active',
      fields: {},
      default_role: 'grant',
    })
  })

  it('sends decline when the operator unchecks it, and says access will not be granted', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    api.on(`POST ${GUA}`, created())
    api.on(`GET ${GUA}/${PERSON}`, json(wireRecord()))
    api.on(`GET ${GUA}/${PERSON}/management`, json(wireManagement()))
    await toReview(user, api, 'active')
    await user.click(screen.getByRole('checkbox', { name: 'Grant Guardian Initiate access' }))
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    const review = await dialog(/Record Ada/)
    expect(within(review).getByText('Guardian Initiate access will not be granted.')).toBeVisible()
    await user.click(within(review).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(api.callsTo(`POST ${GUA}`)).toHaveLength(1)
    })
    expect((bodyOf(api, `POST ${GUA}`) as { default_role: string }).default_role).toBe('decline')
  })

  it('is unavailable to an operator who may not provision, says why, and sends defer, never grant', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([
      wireGuardianType({
        actions: { view: true, manage: true, delete: true, provision_default_role: false },
      }),
    ])
    api.on(`POST ${GUA}`, created())
    api.on(`GET ${GUA}/${PERSON}`, json(wireRecord()))
    api.on(`GET ${GUA}/${PERSON}/management`, json(wireManagement()))
    await toReview(user, api, 'active')

    const option = screen.getByRole('checkbox', { name: 'Grant Guardian Initiate access' })
    expect(option).toBeDisabled()
    expect(option).not.toBeChecked()
    expect(screen.getByText(/needs permission to assign roles/)).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    const review = await dialog(/Record Ada/)
    expect(
      within(review).getByText(/will not be granted: you do not have permission to assign roles/),
    ).toBeVisible()
    await user.click(within(review).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(api.callsTo(`POST ${GUA}`)).toHaveLength(1)
    })
    expect((bodyOf(api, `POST ${GUA}`) as { default_role: string }).default_role).toBe('defer')
  })

  it('omits the access decision entirely for an Inactive Guardian, and offers none', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    api.on(`POST ${GUA}`, created({ status: 'inactive' }))
    api.on(`GET ${GUA}/${PERSON}`, json(wireRecord()))
    api.on(`GET ${GUA}/${PERSON}/management`, json(wireManagement()))
    await toReview(user, api, 'inactive')
    expect(screen.queryByRole('checkbox', { name: /Grant/ })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    const review = await dialog(/Record Ada/)
    expect(within(review).queryByText(/Access/)).not.toBeInTheDocument()
    await user.click(within(review).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(api.callsTo(`POST ${GUA}`)).toHaveLength(1)
    })
    expect('default_role' in (bodyOf(api, `POST ${GUA}`) as object)).toBe(false)
  })

  it('re-offers the access choice from its starting point when the status is changed back to Active', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    await toReview(user, api, 'active')
    await user.click(screen.getByRole('checkbox', { name: 'Grant Guardian Initiate access' }))
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'inactive')
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'active')
    expect(screen.getByRole('checkbox', { name: 'Grant Guardian Initiate access' })).toBeChecked()
  })

  it('uses the shared recent-verification prompt, and records only on a deliberate second press', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    let verified = false
    api.on(`POST ${GUA}`, () => (verified ? created() : verificationRefused()))
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return empty()
    })
    api.on(`GET ${GUA}/${PERSON}`, json(wireRecord()))
    api.on(`GET ${GUA}/${PERSON}/management`, json(wireManagement()))
    await toReview(user, api, 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))
    await prove(user)
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
    })
    expect(api.callsTo(`POST ${GUA}`)).toHaveLength(1)
    expect(location()).toBe('/relationships/guardians/new')
    expect(await screen.findByText(/Nothing has been recorded yet: confirm again/)).toBeVisible()
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(location()).toBe(`/relationships/guardians/${PERSON}`)
    })
    expect(api.callsTo(`POST ${GUA}`)).toHaveLength(2)
  })

  it('says a refused grant changed nothing, and does not record', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    api.on(`POST ${GUA}`, refusal('role_provisioning_not_permitted', 403))
    await toReview(user, api, 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Nothing was changed.')
    expect(location()).toBe('/relationships/guardians/new')
  })

  it('names the code when the server wants a decision the page did not send, and does not guess one', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireGuardianType()])
    api.on(`POST ${GUA}`, codedInvalid('default_role_decision_required'))
    await toReview(user, api, 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Ada/)).getByRole('button', { name: 'Record' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'A decision about Guardian Initiate access is needed',
    )
    expect(api.callsTo(`POST ${GUA}`)).toHaveLength(1)
  })
})

describe('recording someone new', () => {
  async function toNewPerson(user: User, api: ReturnType<typeof serveRelationships>) {
    api.on(`GET ${VOL}/candidates`, json(candidateList([])))
    renderApp('/relationships/volunteers/new')
    await search(user, 'zoe')
    await screen.findByText(/No one matches/)
    await user.click(screen.getByRole('button', { name: 'Add someone new' }))
  }

  it('collects only a name, an email and a phone, and sends a new person without the existing-person flag', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    api.on(
      `POST ${VOL}`,
      json(
        wireManagement({ type: 'volunteer', status: 'active', default_role: null, fields: {} }),
        201,
      ),
    )
    api.on(
      `GET ${VOL}/${PERSON}`,
      json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })),
    )
    api.on(
      `GET ${VOL}/${PERSON}/management`,
      json(wireManagement({ type: 'volunteer', fields: {}, default_role: null })),
    )
    await toNewPerson(user, api)

    const form = screen.getByRole('form', { name: 'Record a Volunteer' })
    expect(
      within(form)
        .getAllByRole('textbox')
        .map((box) => box.getAttribute('name')),
    ).toEqual(expect.arrayContaining(['display_name', 'email', 'phone']))
    // Not CRM's full registration: no profile fields.
    expect(within(form).queryByLabelText(/How we know them|Affiliation/)).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    expect(await screen.findByText('Enter a name.')).toBeVisible()
    await user.type(within(form).getByRole('textbox', { name: 'Display name' }), 'Zoe Zed')
    await user.type(within(form).getByRole('textbox', { name: 'Email' }), 'zoe@example.org')
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    const review = await dialog('Record Zoe Zed as a Volunteer?')
    expect(within(review).getByText(/Zoe Zed \(new\)/)).toBeVisible()
    await user.click(within(review).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(api.callsTo(`POST ${VOL}`)).toHaveLength(1)
    })
    expect(bodyOf(api, `POST ${VOL}`)).toEqual({
      new_person: { display_name: 'Zoe Zed', email: 'zoe@example.org', phone: null },
      status: 'active',
      fields: {},
    })
  })

  it("gives CRM's duplicate advice, records nothing, and makes the operator decide: record anyway, choose one of them, or go back", async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    let confirmedDistinct = false
    api.on(`POST ${VOL}`, (call) => {
      confirmedDistinct = (call.body as { confirm_distinct?: boolean }).confirm_distinct === true
      return confirmedDistinct
        ? json(
            wireManagement({ type: 'volunteer', status: 'active', default_role: null, fields: {} }),
            201,
          )
        : json(
            {
              message: 'Looks known.',
              code: 'possible_duplicate',
              candidates: [
                { id: PERSON_2, display_name: 'Zoe Zedd', matched_on: ['display_name'] },
              ],
            },
            409,
          )
    })
    api.on(
      `GET ${VOL}/${PERSON}`,
      json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })),
    )
    api.on(
      `GET ${VOL}/${PERSON}/management`,
      json(wireManagement({ type: 'volunteer', fields: {}, default_role: null })),
    )
    await toNewPerson(user, api)
    await user.type(screen.getByRole('textbox', { name: 'Display name' }), 'Zoe Zed')
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Zoe/)).getByRole('button', { name: 'Record' }))

    const advice = await screen.findByRole('list', { name: 'Possible duplicates' })
    expect(within(advice).getByText('Zoe Zedd')).toBeVisible()
    expect(within(advice).getByText('(same name)')).toBeVisible()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(location()).toBe('/relationships/volunteers/new')
    await expectNoAxeViolations(pageBody())

    // Nobody was picked for them. "Record anyway" is a fresh, deliberate decision, confirmed once more.
    await user.click(
      screen.getByRole('button', { name: 'This is a different person: record anyway' }),
    )
    expect(api.callsTo(`POST ${VOL}`)).toHaveLength(1)
    await user.click(within(await dialog(/Record Zoe/)).getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(api.callsTo(`POST ${VOL}`)).toHaveLength(2)
    })
    expect(bodyOf(api, `POST ${VOL}`, 1)).toMatchObject({
      confirm_distinct: true,
      new_person: { display_name: 'Zoe Zed' },
    })
  })

  it('lets the operator choose a suggested person instead, through the same confirmation as any existing person', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireVolunteerType()])
    api.on(
      `POST ${VOL}`,
      json(
        {
          message: 'Looks known.',
          code: 'possible_duplicate',
          candidates: [{ id: PERSON_2, display_name: 'Zoe Zedd', matched_on: ['email'] }],
        },
        409,
      ),
    )
    await toNewPerson(user, api)
    await user.type(screen.getByRole('textbox', { name: 'Display name' }), 'Zoe Zed')
    await user.selectOptions(screen.getByRole('combobox', { name: 'Initial status' }), 'active')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(within(await dialog(/Record Zoe/)).getByRole('button', { name: 'Record' }))
    await screen.findByRole('list', { name: 'Possible duplicates' })

    await user.click(screen.getByRole('button', { name: 'Record Zoe Zedd instead' }))
    await dialog('Record Zoe Zedd?')
    expect(api.callsTo(`POST ${VOL}`)).toHaveLength(1)
  })

  it('is offered only where the definition allows creating a person', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireApprenticeType()])
    api.on('GET /api/v1/admin/relationships/apprentices/candidates', json(candidateList([])))
    renderApp('/relationships/apprentices/new')
    await screen.findByRole('heading', { level: 1, name: 'Record an Apprentice' })
    expect(screen.queryByRole('button', { name: 'Add someone new' })).not.toBeInTheDocument()
    await search(user, 'nobody')
    expect(await screen.findByText(/No one matches/)).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Add someone new' })).not.toBeInTheDocument()
  })
})

describe('a type the Console has never seen', () => {
  it('records through the same screens: its single initial state needs no choice, and a required field is checked', async () => {
    const user = userEvent.setup()
    const api = serveRelationships([wireApprenticeType()])
    const path = '/api/v1/admin/relationships/apprentices'
    api.on(
      `GET ${path}/candidates`,
      json(candidateList([candidate(PERSON, 'Ada Lovelace', 'email')])),
    )
    api.on(
      `POST ${path}`,
      json(
        wireManagement({
          type: 'apprentice',
          status: 'applied',
          default_role: null,
          fields: { track: 'clay' },
          transitions: ['training'],
        }),
        201,
      ),
    )
    api.on(
      `GET ${path}/${PERSON}`,
      json(
        wireRecord({
          type: 'apprentice',
          status: {
            key: 'applied',
            since: '2026-09-01T10:00:00Z',
            by: { id: 'p', display_name: 'G' },
          },
          fields: {},
          default_role: null,
          basics: null,
        }),
      ),
    )
    api.on(
      `GET ${path}/${PERSON}/management`,
      json(
        wireManagement({
          type: 'apprentice',
          status: 'applied',
          fields: { track: 'clay' },
          default_role: null,
          basics: null,
          transitions: [],
        }),
      ),
    )
    renderApp('/relationships/apprentices/new')
    await search(user, 'ada@example.org')
    await user.click(await screen.findByRole('button', { name: 'Choose Ada Lovelace' }))
    await user.click(
      within(await dialog('Record Ada Lovelace?')).getByRole('button', { name: 'Continue' }),
    )

    expect(screen.getByRole('combobox', { name: 'Initial status' })).toHaveValue('applied')
    expect(screen.queryByRole('checkbox', { name: /Grant/ })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    expect(await screen.findByText('This field is required.')).toBeVisible() // the required choice
    await user.selectOptions(screen.getByRole('combobox', { name: 'Track (required)' }), 'clay')
    await user.click(screen.getByRole('button', { name: 'Review and record' }))
    await user.click(
      within(await dialog('Record Ada Lovelace as an Apprentice?')).getByRole('button', {
        name: 'Record',
      }),
    )
    await waitFor(() => {
      expect(location()).toBe(`/relationships/apprentices/${PERSON}`)
    })
    expect(bodyOf(api, `POST ${path}`)).toEqual({
      person_id: PERSON,
      confirm_existing_person: true,
      status: 'applied',
      fields: { track: 'clay' },
    })
  })

  it('says so when a type can be neither looked up nor added to', async () => {
    serveRelationships([wireApprenticeType({ features: [] })])
    renderApp('/relationships/apprentices/new')
    expect(await screen.findByText(/cannot be recorded as apprentices from here/)).toBeVisible()
  })
})

describe('who may record', () => {
  it('refuses the page, and asks for nothing, to an operator who may only view the type', async () => {
    const api = serveRelationships([
      wireVolunteerType({
        actions: { view: true, manage: false, delete: false, provision_default_role: false },
      }),
    ])
    renderApp('/relationships/volunteers/new')
    expect(await screen.findByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    expect(api.calls.filter((c) => c.path.includes('/candidates'))).toHaveLength(0)
  })

  it('serves a manager who cannot view: the way to record, and no directory', async () => {
    const api = serveRelationships([
      wireVolunteerType({
        actions: { view: false, manage: true, delete: true, provision_default_role: false },
      }),
    ])
    api.on(`GET ${VOL}/candidates`, json(candidateList([])))
    renderApp('/relationships/volunteers/new')
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Record a Volunteer' }),
    ).toBeVisible()
    expect(screen.queryByRole('link', { name: 'Cancel' })).not.toBeInTheDocument()
    expect(api.calls.some((c) => c.method === 'GET' && c.path === VOL)).toBe(false)
  })

  it('does not read "new" as a person, and still opens a person\'s record by their id', async () => {
    const api = volunteerIntake()
    renderApp('/relationships/volunteers/new')
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Record a Volunteer' }),
    ).toBeVisible()
    expect(
      api.calls.some((c) => c.path.endsWith('/new') || c.path.endsWith('/new/management')),
    ).toBe(false)
  })
})
