import type { AuthenticatorSetup } from '../api/auth.ts'
import { QrCode } from './QrCode.tsx'

/** Groups the manual key in fours, the way authenticator apps show it. Purely for reading. */
function grouped(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim()
}

/**
 * How to add an authenticator: the QR code (drawn here, from the provisioning URI) and the same secret as a
 * manual key, for people who cannot scan, and for accessibility. Shown once, held in memory only.
 */
export function AuthenticatorSetupDetails({ setup }: { setup: AuthenticatorSetup }) {
  return (
    <div className="flex flex-col gap-4">
      <ol className="list-decimal space-y-3 pl-5 text-sm text-slate-700">
        <li>
          Open an authenticator app (any that supports the standard, such as 1Password, Authy or
          Google Authenticator).
        </li>
        <li>
          Scan this QR code, or choose &ldquo;enter a setup key&rdquo; and type the key below.
          <div className="mt-2 flex flex-col items-start gap-3">
            <QrCode value={setup.otpauthUri} label="QR code for your authenticator app" />
            <p>
              Setup key:{' '}
              <code className="rounded bg-slate-100 px-2 py-1 font-mono text-base tracking-wide">
                {grouped(setup.secret)}
              </code>
            </p>
          </div>
        </li>
        <li>Enter the 6-digit code the app shows.</li>
      </ol>
      <p className="text-xs text-slate-500">
        This key is shown once and cannot be retrieved later.
      </p>
    </div>
  )
}
