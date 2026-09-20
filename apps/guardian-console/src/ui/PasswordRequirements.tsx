/**
 * What a password must be, in the words of the current policy (ADR 0022). The server judges every
 * password; this is guidance only, and the browser does not try to duplicate the breach list. The byte
 * limit is a bcrypt consequence, and "72 characters" would be false for anything but plain ASCII, so it
 * is stated as bytes.
 */
export function PasswordRequirements() {
  return (
    <>
      <span className="block">Passwords must be at least 15 characters.</span>
      <span className="mt-1 block">
        Spaces are allowed, so a passphrase of a few unrelated words works well. There is no
        required mix of capitals, numbers or symbols. Passwords that appear in known data breaches
        are refused. Very long Unicode passwords may exceed the current 72-byte limit.
      </span>
    </>
  )
}
