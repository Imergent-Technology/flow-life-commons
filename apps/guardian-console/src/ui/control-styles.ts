import { cn, focusRing } from './cn.ts'

/**
 * Shared look of the text-accepting controls. It sets NO font size on purpose: the size comes from the
 * `--text-control` floor in theme/tokens.css (14px on desktop, 16px where the pointer is coarse or the
 * viewport narrow), applied to `input`/`select`/`textarea` in the base layer. A size utility here would
 * beat that rule and bring back zoom-on-focus on phones.
 */
export const controlStyles = cn(
  'block h-(--control-md) w-full rounded-sm border border-input bg-field px-3 text-foreground',
  'placeholder:text-subtle-foreground',
  'disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground',
  'aria-invalid:border-danger aria-invalid:ring-1 aria-invalid:ring-danger',
  focusRing,
)
