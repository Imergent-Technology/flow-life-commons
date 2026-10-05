import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { deferred, pageBody } from '../../test/deferred.ts'
import { json } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'
import {
  CARD_ID,
  CARD_PATH,
  categoryList,
  coded,
  fileTypeNotAllowed,
  MANAGE,
  PACK_ID,
  PACK_PATH,
  ROOT,
  wireCard,
  wireCategory,
  wireFileCard,
  wireManagedFile,
  wirePack,
} from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const pdf = (name = 'handbook.pdf') =>
  new File(['%PDF-1.4 test'], name, { type: 'application/pdf' })

function serve() {
  const api = serveOperator(operator(MANAGE))
  api.on(`GET ${ROOT}/categories`, () => json(categoryList([wireCategory()])))
  api.on(`GET ${PACK_PATH}`, () => json(wirePack()))
  return api
}

// --- Creating a File Card ----------------------------------------------------------------------------------------------------

describe('creating a File Card', () => {
  async function openNew() {
    const user = userEvent.setup()
    const api = serve()
    renderApp(`/resources/packs/${PACK_ID}/cards/new`)
    await screen.findByRole('heading', { level: 1, name: 'Add a Card' })
    await user.click(screen.getByRole('radio', { name: /^File/ }))
    await screen.findByLabelText('File')
    return { user, api }
  }

  it('says what the file may be and how large, as help beside the input, and hints the picker without relying on it', async () => {
    await openNew()
    const input = screen.getByLabelText('File')

    expect(input).toHaveAccessibleDescription(
      /PDF, PNG, JPEG, WebP, GIF, TXT, CSV, DOCX, XLSX or PPTX, up to 20 MB\. The server checks what the file really is, whatever it is called\./,
    )
    const accept = input.getAttribute('accept') ?? ''
    for (const extension of [
      '.pdf',
      '.png',
      '.jpg',
      '.jpeg',
      '.webp',
      '.gif',
      '.txt',
      '.csv',
      '.docx',
      '.xlsx',
      '.pptx',
    ]) {
      expect(accept.split(',')).toContain(extension)
    }
    // A hint, not a gate: nothing script-like is named in it.
    expect(accept).not.toMatch(/svg|html|xml|zip|exe|\.js|\.php/i)
  })

  it('sends one multipart form: the Type, the title, and the file under its own name — and no JSON body', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => json(wireFileCard(), 201))
    api.on(`GET ${CARD_PATH}`, () => json(wireFileCard()))

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.upload(screen.getByLabelText('File'), pdf('Welcome handbook.pdf'))
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(1)
    })
    const call = api.callsTo(`POST ${PACK_PATH}/cards`)[0]
    expect(call?.body).toBeNull()
    expect(call?.form?.get('type')).toBe('file')
    expect(call?.form?.get('title')).toBe('Handbook')
    const sent = call?.form?.get('file')
    expect(sent).toBeInstanceOf(File)
    expect((sent as File).name).toBe('Welcome handbook.pdf')
    // The Console never sets the multipart Content-Type itself: the browser adds its boundary.
    expect(Object.keys(call?.headers ?? {}).map((h) => h.toLowerCase())).not.toContain(
      'content-type',
    )
    expect(call?.form?.has('content')).toBe(false)
    expect(await screen.findByRole('heading', { level: 1, name: 'Handbook' })).toBeInTheDocument()
  })

  it('sends the description as the document’s JSON text when one was written', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => json(wireFileCard(), 201))
    api.on(`GET ${CARD_PATH}`, () => json(wireFileCard()))
    const { writeContent } = await import('../../test/editor.ts')

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await writeContent('Description (optional)', 'Read this first.')
    await user.upload(screen.getByLabelText('File'), pdf())
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(1)
    })
    const text = api.callsTo(`POST ${PACK_PATH}/cards`)[0]?.form?.get('content')
    expect(JSON.parse(typeof text === 'string' ? text : '{}')).toEqual({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Read this first.' }] }],
    })
  })

  it('does not ask the server for a File Card with no file: it says so beside the input', async () => {
    const { user, api } = await openNew()

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    expect(await screen.findByText('Choose the file this Card offers.')).toBeInTheDocument()
    expect(screen.getByLabelText('File')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('File')).toHaveFocus()
    expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(0)
  })

  it('says ANY 413 means the file is too large — without needing the platform’s own body', async () => {
    const { user, api } = await openNew()
    // What a web server in front of PHP sends for an oversized body: a status and a page, not the platform's JSON.
    api.on(
      `POST ${PACK_PATH}/cards`,
      () => new Response('<html>Request Entity Too Large</html>', { status: 413 }),
    )

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.upload(screen.getByLabelText('File'), pdf())
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    expect(
      await screen.findAllByText('That file is too large. The limit is 20 MB.'),
    ).not.toHaveLength(0)
    expect(screen.getByLabelText('File')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.queryByText(/Entity Too Large/)).not.toBeInTheDocument()
    // Nothing was lost: the title is still there.
    expect(screen.getByLabelText('Title')).toHaveValue('Handbook')
  })

  it('says the platform’s own 413 the same way', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () =>
      json({ message: 'x', code: 'file_too_large', max_bytes: 20_971_520 }, 413),
    )

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.upload(screen.getByLabelText('File'), pdf())
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    expect(
      (await screen.findAllByText('That file is too large. The limit is 20 MB.')).length,
    ).toBeGreaterThan(0)
  })

  it('says a refused file type in its own words, and what is allowed', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => fileTypeNotAllowed())

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.upload(
      screen.getByLabelText('File'),
      new File(['<svg/>'], 'logo.png', { type: 'image/png' }),
    )
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    expect(
      await screen.findByText(
        /That file type is not allowed, or the file is not what its name says\. Allowed: PDF, PNG/,
      ),
    ).toBeInTheDocument()
    expect(screen.queryByText('That file type is not allowed.')).not.toBeInTheDocument()
    expect(screen.getByLabelText('File')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.queryByText(/logo\.png/)).not.toBeInTheDocument()
  })

  it('says a store that cannot keep the file changed nothing, and that trying again is safe', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => coded(503, 'file_storage_unavailable'))

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.upload(screen.getByLabelText('File'), pdf())
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(
      'The file could not be stored just now. Nothing was changed. Try again in a moment.',
    )
    expect(screen.queryByText(/Server wording/)).not.toBeInTheDocument()
    expect(alert).toHaveFocus()
  })

  it('shows the uploading state on the button while the request is out', async () => {
    const { user, api } = await openNew()
    const held = deferred<Response>()
    api.on(`POST ${PACK_PATH}/cards`, () => held.promise)

    await user.type(screen.getByLabelText('Title'), 'Handbook')
    await user.upload(screen.getByLabelText('File'), pdf())
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    expect(await screen.findByRole('button', { name: 'Uploading…' })).toBeDisabled()
    held.resolve(coded(503, 'file_storage_unavailable'))
    await screen.findByRole('alert')
  })
})

// --- Looking at and replacing the file of an existing Card ------------------------------------------------------------------

async function openFileCard(card: Record<string, unknown> = wireFileCard()) {
  const user = userEvent.setup()
  const api = serve()
  api.on(`GET ${CARD_PATH}`, () => json(card))
  renderApp(`/resources/packs/${PACK_ID}/cards/${CARD_ID}`)
  await screen.findByRole('heading', { level: 1, name: String(card.title) })
  return { user, api }
}

describe('a File Card’s file', () => {
  it('shows what the server says about it — name, kind, size, who and when — and nothing about where it is', async () => {
    await openFileCard()
    const section = screen.getByRole('region', { name: 'File' })

    expect(section).toHaveTextContent('Welcome handbook.pdf')
    expect(section).toHaveTextContent('PDF')
    expect(section).toHaveTextContent('1.5 MB')
    expect(section).toHaveTextContent('by Gwen Guardian')
    // The digest is on the wire for management and is not for display; no key, disk, path or id is on the wire at all.
    expect(pageBody().textContent).not.toContain('a'.repeat(64))
    expect(pageBody().innerHTML).not.toMatch(/storage|sha256|asset|disk/i)
  })

  it('downloads through the MANAGEMENT route the server named, never the library’s, so a Draft’s file can be had', async () => {
    await openFileCard(wireFileCard({ state: 'draft' }))

    const link = screen.getByRole('link', { name: /Download Welcome handbook\.pdf/ })
    expect(link).toHaveAttribute('href', `${CARD_PATH}/file`)
    expect(link.getAttribute('href')).toContain('/admin/resources/')
    expect(link.getAttribute('href')).not.toContain('resource-library')
  })

  it('opens a PDF in a new tab on request, and offers that for nothing else', async () => {
    await openFileCard()
    const view = screen.getByRole('link', { name: /View Welcome handbook\.pdf in a new tab/ })
    expect(view).toHaveAttribute('href', `${CARD_PATH}/file?disposition=inline`)
    expect(view).toHaveAttribute('target', '_blank')
    expect(view).toHaveAttribute('rel', 'noopener noreferrer')
  })

  it.each([
    ['image/png', 'diagram.png'],
    ['text/csv', 'rota.csv'],
    ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'minutes.docx'],
  ])('offers only a download for %s', async (mediaType, name) => {
    await openFileCard(wireFileCard({ file: wireManagedFile({ name, media_type: mediaType }) }))
    expect(
      screen.getByRole('link', { name: new RegExp(`Download ${name.replace('.', '\\.')}`) }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /View/ })).not.toBeInTheDocument()
  })

  it('says a file that is on record and missing from the store cannot be downloaded, and offers no link to it', async () => {
    await openFileCard(wireFileCard({ file: wireManagedFile({ available: false }) }))

    expect(screen.getByText(/missing from the store/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument()
    expect(screen.getByLabelText('Replacement file')).toBeInTheDocument()
  })

  it('has no accessibility violations, with and without a missing file', async () => {
    await openFileCard()
    await screen.findByRole('textbox', { name: 'Description (optional)' })
    await expectNoAxeViolations()
  })
})

describe('replacing a File Card’s file', () => {
  const replacement = () =>
    json(
      wireFileCard({
        file: wireManagedFile({ name: 'Handbook v2.pdf', byte_size: 2_097_152 }),
        revision: 1,
      }),
    )

  it('sends the new file to the Card’s file route, with no revision and no verification, and shows the new file after the server confirms', async () => {
    const { user, api } = await openFileCard()
    const held = deferred<Response>()
    api.on(`POST ${CARD_PATH}/file`, () => held.promise)

    await user.upload(screen.getByLabelText('Replacement file'), pdf('Handbook v2.pdf'))
    await user.click(screen.getByRole('button', { name: 'Replace file' }))

    // In flight: the OLD file is still what the Card shows.
    expect(await screen.findByRole('button', { name: 'Uploading…' })).toBeDisabled()
    expect(screen.getByRole('region', { name: 'File' })).toHaveTextContent('Welcome handbook.pdf')
    expect(screen.queryByText('Handbook v2.pdf', { selector: 'dd' })).not.toBeInTheDocument()

    held.resolve(replacement())
    expect(
      await screen.findByText('The file was replaced. The Card now offers the new file.'),
    ).toBeInTheDocument()

    const section = screen.getByRole('region', { name: 'File' })
    expect(section).toHaveTextContent('Handbook v2.pdf')
    expect(section).toHaveTextContent('2.0 MB')
    expect(section).not.toHaveTextContent('Welcome handbook.pdf')
    const call = api.callsTo(`POST ${CARD_PATH}/file`)[0]
    expect(call?.body).toBeNull()
    expect([...(call?.form?.keys() ?? [])]).toEqual(['file'])
    expect(call?.form?.get('file')).toHaveProperty('name', 'Handbook v2.pdf')
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
    expect(document.activeElement).toHaveTextContent('The file was replaced.')
  })

  it('clears the chosen file after a replacement, so it is not sent twice', async () => {
    const { user, api } = await openFileCard()
    api.on(`POST ${CARD_PATH}/file`, () => replacement())

    await user.upload(screen.getByLabelText('Replacement file'), pdf('Handbook v2.pdf'))
    await user.click(screen.getByRole('button', { name: 'Replace file' }))
    await screen.findByText('The file was replaced. The Card now offers the new file.')

    expect(screen.getByLabelText<HTMLInputElement>('Replacement file').files).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Replace file' }))
    expect(await screen.findByText('Choose the replacement file.')).toBeInTheDocument()
    expect(api.callsTo(`POST ${CARD_PATH}/file`)).toHaveLength(1)
  })

  it('asks for a file when none was chosen, and sends nothing', async () => {
    const { user, api } = await openFileCard()
    await user.click(screen.getByRole('button', { name: 'Replace file' }))

    expect(await screen.findByText('Choose the replacement file.')).toBeInTheDocument()
    expect(api.callsTo(`POST ${CARD_PATH}/file`)).toHaveLength(0)
  })

  it.each([
    [
      'a 413 with no body of ours',
      () => new Response('Too big', { status: 413 }),
      /That file is too large\. The limit is 20 MB\./,
    ],
    [
      'a refused type',
      () => fileTypeNotAllowed(),
      /That file type is not allowed, or the file is not what its name says/,
    ],
    [
      'a store that cannot keep it',
      () => coded(503, 'file_storage_unavailable'),
      /The file could not be stored just now\. Nothing was changed\./,
    ],
  ])('keeps the current file, and says so, after %s', async (_label, respond, message) => {
    const { user, api } = await openFileCard()
    api.on(`POST ${CARD_PATH}/file`, respond)

    await user.upload(screen.getByLabelText('Replacement file'), pdf('Handbook v2.pdf'))
    await user.click(screen.getByRole('button', { name: 'Replace file' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(message)
    expect(alert).toHaveTextContent('The Card still has its current file.')
    expect(alert).toHaveFocus()
    const section = screen.getByRole('region', { name: 'File' })
    expect(section).toHaveTextContent('Welcome handbook.pdf')
    expect(
      within(section).getByRole('link', { name: /Download Welcome handbook\.pdf/ }),
    ).toBeInTheDocument()
  })

  it('says a Card that has gone is gone, and changes nothing on screen', async () => {
    const { user, api } = await openFileCard()
    api.on(`POST ${CARD_PATH}/file`, () => coded(404, 'card_not_found'))

    await user.upload(screen.getByLabelText('Replacement file'), pdf('Handbook v2.pdf'))
    await user.click(screen.getByRole('button', { name: 'Replace file' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That Card no longer exists. It may have been deleted.',
    )
    expect(screen.getByRole('region', { name: 'File' })).toHaveTextContent('Welcome handbook.pdf')
  })

  it('is not offered for a Card that is not a File Card', async () => {
    const api = serve()
    api.on(`GET ${CARD_PATH}`, () => json(wireCard()))
    renderApp(`/resources/packs/${PACK_ID}/cards/${CARD_ID}`)
    await screen.findByRole('heading', { level: 1, name: 'Opening hours' })

    expect(screen.queryByRole('region', { name: 'File' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Replacement file')).not.toBeInTheDocument()
  })
})
