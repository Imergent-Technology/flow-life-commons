import { useState } from 'react'

import { Alert } from './Alert.tsx'
import { Button } from './Button.tsx'
import { Checkbox } from './Checkbox.tsx'

/**
 * Recovery codes, shown ONCE. They are single-use, the server keeps only digests and cannot show them
 * again, and this page is the only place they exist.
 *
 * They live in this component's memory (a prop from the page's state) and nowhere else: not in the URL,
 * history, browser storage or any log. Copying and downloading each happen ONLY on a click: the download
 * is a file built in the browser from these codes and handed straight to the person; nothing is uploaded
 * or kept. "Continue" stays disabled until the person says they have saved them.
 */
export function RecoveryCodes({
  codes,
  onDone,
  doneLabel,
}: {
  codes: readonly string[]
  onDone: () => void
  doneLabel: string
}) {
  const [saved, setSaved] = useState(false)
  const [note, setNote] = useState<string | null>(null)

  function copy() {
    void navigator.clipboard.writeText(codes.join('\n')).then(
      () => {
        setNote('Copied to the clipboard. Paste them somewhere safe.')
      },
      () => {
        setNote('Could not copy. Select the codes and copy them by hand.')
      },
    )
  }

  function download() {
    const file = new Blob(
      [`Flow Life Commons recovery codes\nEach code works once.\n\n${codes.join('\n')}\n`],
      { type: 'text/plain' },
    )
    const url = URL.createObjectURL(file)
    const link = document.createElement('a')
    link.href = url
    link.download = 'flow-life-recovery-codes.txt'
    link.click()
    URL.revokeObjectURL(url)
    setNote('Downloaded. Keep the file somewhere safe and private.')
  }

  return (
    <div className="flex flex-col gap-4">
      <Alert tone="info">
        Save these recovery codes now. <strong>They will not be shown again.</strong> Each one works
        once, in place of a code from your authenticator app, if you lose it.
      </Alert>
      <ol
        aria-label="Recovery codes"
        className="grid grid-cols-1 gap-1 rounded-md border border-border bg-muted p-3 font-mono text-meta text-foreground sm:grid-cols-2"
      >
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ol>
      <div className="flex flex-wrap gap-2">
        <Button onClick={copy}>Copy codes</Button>
        <Button onClick={download}>Download as a file</Button>
      </div>
      {note ? (
        <p role="status" className="text-label text-muted-foreground">
          {note}
        </p>
      ) : null}
      <Checkbox
        label="I have saved these recovery codes somewhere safe."
        checked={saved}
        onChange={(event) => {
          setSaved(event.target.checked)
        }}
      />
      <Button variant="primary" disabled={!saved} onClick={onDone} className="self-start">
        {doneLabel}
      </Button>
    </div>
  )
}
