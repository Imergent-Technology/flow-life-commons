import type { ReactNode } from 'react'

import { Alert } from '../ui/Alert.tsx'
import { cn } from '../ui/cn.ts'
import {
  normaliseLinkHref,
  parseContentDocument,
  type ContentMark,
  type ContentNode,
} from './contract.ts'
import './richtext.css'

/**
 * Draws a Resources document (ADR 0037, decision 29) as React elements, from the validated document
 * and nothing else. No HTML string is produced or interpreted in the browser, so there is nothing to
 * sanitise, and no raw-HTML escape hatch is used; the Console's source rules keep it that way.
 *
 * The document is checked against the profile first (`contract.ts`). Ordinary data was validated by
 * the server before it was stored, so a refusal here means stored data that is out of date or damaged,
 * or a defect, and the response is the same for all three: this content is not drawn, a short message
 * says so, and the rest of the page is untouched. The document itself is never shown to the reader.
 */
export function RichContentRenderer({
  document,
  className,
}: {
  /** A canonical document: the `content_document` of a Card, decoded. Typed `unknown` because it is data. */
  document: unknown
  className?: string
}) {
  const parsed = parseContentDocument(document)
  if (!parsed.ok) {
    return <Alert tone="warning">This content can&rsquo;t be displayed.</Alert>
  }

  return <div className={cn('rich-content', className)}>{renderNodes(parsed.document.content)}</div>
}

function renderNodes(nodes: ContentNode[] | undefined): ReactNode[] {
  return (nodes ?? []).map((node, index) => renderNode(node, index))
}

function renderNode(node: ContentNode, key: number): ReactNode {
  switch (node.type) {
    case 'paragraph':
      return <p key={key}>{renderNodes(node.content)}</p>
    case 'heading':
      return renderHeading(node, key)
    case 'bulletList':
      return <ul key={key}>{renderNodes(node.content)}</ul>
    case 'orderedList':
      return (
        <ol key={key} start={node.attrs?.start}>
          {renderNodes(node.content)}
        </ol>
      )
    case 'listItem':
      return <li key={key}>{renderNodes(node.content)}</li>
    case 'blockquote':
      return <blockquote key={key}>{renderNodes(node.content)}</blockquote>
    case 'horizontalRule':
      return <hr key={key} />
    case 'codeBlock':
      return (
        <pre key={key}>
          <code>{(node.content ?? []).map((child) => child.text ?? '').join('')}</code>
        </pre>
      )
    case 'table':
      return renderTable(node, key)
    case 'hardBreak':
      return <br key={key} />
    case 'text':
      return renderText(node, key)
    default:
      // Unreachable for a document that passed the profile; refused rather than drawn if that ever changes.
      return null
  }
}

function renderHeading(node: ContentNode, key: number): ReactNode {
  const children = renderNodes(node.content)
  switch (node.attrs?.level) {
    case 2:
      return <h2 key={key}>{children}</h2>
    case 3:
      return <h3 key={key}>{children}</h3>
    default:
      return <h4 key={key}>{children}</h4>
  }
}

function renderTable(node: ContentNode, key: number): ReactNode {
  const rows = node.content ?? []
  const headed = rows[0]?.content?.every((cell) => cell.type === 'tableHeader') === true

  return (
    <table key={key}>
      {headed && rows[0] !== undefined ? <thead>{renderRow(rows[0], 0, true)}</thead> : null}
      <tbody>{rows.slice(headed ? 1 : 0).map((row, i) => renderRow(row, i, false))}</tbody>
    </table>
  )
}

function renderRow(row: ContentNode, key: number, inHead: boolean): ReactNode {
  return (
    <tr key={key}>
      {(row.content ?? []).map((cell, i) => {
        const span = { colSpan: cell.attrs?.colspan, rowSpan: cell.attrs?.rowspan }
        return cell.type === 'tableHeader' ? (
          <th key={i} scope={inHead ? 'col' : 'row'} {...span}>
            {renderNodes(cell.content)}
          </th>
        ) : (
          <td key={i} {...span}>
            {renderNodes(cell.content)}
          </td>
        )
      })}
    </tr>
  )
}

function renderText(node: ContentNode, key: number): ReactNode {
  // The marks are stored in a fixed order (bold, italic, underline, code, link): the first is innermost.
  const wrapped = (node.marks ?? []).reduce<ReactNode>(
    (inner, mark) => wrapMark(mark, inner),
    node.text ?? '',
  )

  return <span key={key}>{wrapped}</span>
}

function wrapMark(mark: ContentMark, inner: ReactNode): ReactNode {
  switch (mark.type) {
    case 'bold':
      return <strong>{inner}</strong>
    case 'italic':
      return <em>{inner}</em>
    case 'underline':
      return <u>{inner}</u>
    case 'code':
      return <code>{inner}</code>
    case 'link':
      return renderLink(mark.attrs?.href, inner)
  }
}

/**
 * A link, re-checked here as defence in depth (decision 29): an address that is not http, https or
 * mailto is not made a link at all, and its text is shown as text. A web address opens in a new browsing
 * context and cannot reach back to this one (`noopener`) or tell the destination where it came from
 * (`noreferrer`); a mailto link is an ordinary link.
 */
function renderLink(raw: string | undefined, inner: ReactNode): ReactNode {
  const href = raw === undefined ? null : normaliseLinkHref(raw)
  if (href === null) return inner
  if (href.startsWith('mailto:')) return <a href={href}>{inner}</a>

  return (
    <a href={href} target="_blank" rel="noopener noreferrer">
      {inner}
    </a>
  )
}
