import { useState } from 'react'

import type { AuthenticatorSetup } from '../api/auth.ts'
import { Button } from './Button.tsx'
import { QrCode } from './QrCode.tsx'

/** Groups the manual key in fours, the way authenticator apps show it. Purely for reading. */
function grouped(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim()
}

/** The server's expiry as a clock time in the person's own zone, or null if it is not a date. */
function clockTime(iso: string): string | null {
  const at = new Date(iso)
  return Number.isNaN(at.getTime())
    ? null
    : at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

/**
 * How to add an authenticator: the QR code (drawn here, from the provisioning URI) and the same secret as a
 * manual key, for people who cannot scan, and for accessibility. Shown once, held in memory only.
 *
 * The key is shown in groups of four for reading but COPIED as the canonical ungrouped secret, and only when the
 * person asks: the copy is a clipboard write and nothing else (no request, no storage, no log).
 */
export function AuthenticatorSetupDetails({
  setup,
  notice = '',
}: {
  setup: AuthenticatorSetup
  /** Said in the same live region as the copy note, for something the page just did (a new key replacing the old). */
  notice?: string
}) {
  // Remembers WHICH key was copied, so a "copied" note never outlives the key it was about (a restart makes a new one).
  const [copy, setCopy] = useState<{ secret: string; ok: boolean } | null>(null)
  const copyNote =
    copy?.secret !== setup.secret
      ? ''
      : copy.ok
        ? 'Setup key copied.'
        : 'Could not copy. Select the key and copy it by hand.'
  const note = copyNote === '' ? notice : copyNote
  const expires = clockTime(setup.expiresAt)

  async function copyKey() {
    const { secret } = setup
    try {
      await navigator.clipboard.writeText(secret)
      setCopy({ secret, ok: true })
    } catch {
      setCopy({ secret, ok: false }) // no clipboard here (or it was refused): the key is still on the page to select
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <ol className="list-decimal space-y-3 pl-5 text-body text-foreground marker:text-muted-foreground">
        <li>
          Open an authenticator app (any that supports the standard, such as 1Password, Authy or
          Google Authenticator).
        </li>
        <li>
          Scan this QR code, or choose &ldquo;enter a setup key&rdquo; and type the key below.
          <div className="mt-2 flex flex-col items-start gap-3">
            <QrCode value={setup.otpauthUri} label="QR code for your authenticator app" />
            <p>
              Can&rsquo;t scan it? Setup key:{' '}
              <code className="rounded-sm bg-muted px-2 py-1 font-mono text-body tracking-wide wrap-anywhere text-foreground select-all">
                {grouped(setup.secret)}
              </code>
            </p>
            <Button
              onClick={() => {
                void copyKey()
              }}
            >
              Copy setup key
            </Button>
            {/* Always in the page, so a screen reader is already watching it when the note appears. */}
            <p role="status" className="min-h-5 text-label text-muted-foreground">
              {note}
            </p>
          </div>
        </li>
        <li>Enter the 6-digit code the app shows.</li>
      </ol>
      <p className="text-meta text-muted-foreground">
        {expires === null ? '' : `This setup key expires at ${expires}. `}
        This key is shown once and cannot be retrieved later. If your app already lists this account
        from an earlier attempt, delete that older entry: only the key on this page will work.
      </p>
    </div>
  )
}
