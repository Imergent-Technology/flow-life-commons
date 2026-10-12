import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react'

import { cn, focusRing } from './cn.ts'

export interface TabItem {
  id: string
  label: string
}

/**
 * A set of tabs over one panel, to the ARIA tabs pattern: arrow keys, Home and End move between the tabs (and show the
 * one they land on), only the selected tab is in the tab order, and the panel is labelled by its tab and can itself be
 * reached by Tab. Controlled: the caller holds the selection and renders the selected content as `children`.
 */
export function Tabs({
  label,
  tabs,
  selected,
  onSelect,
  children,
}: {
  label: string
  tabs: readonly TabItem[]
  selected: string
  onSelect: (id: string) => void
  children: ReactNode
}) {
  const base = useId()
  const refs = useRef(new Map<string, HTMLButtonElement>())
  const tabId = (id: string) => `${base}-tab-${id}`
  const panelId = `${base}-panel`

  function onKeyDown(event: KeyboardEvent<HTMLButtonElement>, index: number) {
    const last = tabs.length - 1
    const next =
      event.key === 'ArrowRight'
        ? (index + 1) % tabs.length
        : event.key === 'ArrowLeft'
          ? (index - 1 + tabs.length) % tabs.length
          : event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? last
              : null
    if (next === null) return
    event.preventDefault()
    const target = tabs[next]
    if (target === undefined) return
    onSelect(target.id)
    refs.current.get(target.id)?.focus()
  }

  return (
    <div className="flex flex-col gap-4">
      <div
        role="tablist"
        aria-label={label}
        className="flex flex-wrap gap-1 border-b border-border"
      >
        {tabs.map((tab, index) => {
          const active = tab.id === selected
          return (
            <button
              key={tab.id}
              ref={(element) => {
                if (element === null) refs.current.delete(tab.id)
                else refs.current.set(tab.id, element)
              }}
              id={tabId(tab.id)}
              type="button"
              role="tab"
              aria-selected={active}
              aria-controls={panelId}
              tabIndex={active ? 0 : -1}
              onClick={() => {
                onSelect(tab.id)
              }}
              onKeyDown={(event) => {
                onKeyDown(event, index)
              }}
              className={cn(
                '-mb-px rounded-t-sm border-b-2 px-3.5 py-2 text-label font-medium',
                active
                  ? 'border-primary text-foreground'
                  : 'border-transparent text-muted-foreground hover:text-foreground',
                focusRing,
              )}
            >
              {tab.label}
            </button>
          )
        })}
      </div>
      <div
        id={panelId}
        role="tabpanel"
        aria-labelledby={tabId(selected)}
        tabIndex={0}
        className={cn('rounded-sm outline-none', focusRing)}
      >
        {children}
      </div>
    </div>
  )
}
