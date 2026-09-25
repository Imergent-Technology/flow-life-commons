import { buttonVariants } from './button-variants.ts'

// Legacy: kept until the pages migrate to <Button> / buttonVariants (WP5), and deleted in WP6.
// They now resolve to the same semantic look as the new primitive rather than a second definition.
export const secondaryButton = buttonVariants({ variant: 'secondary' })
export const dangerButton = buttonVariants({ variant: 'danger' })
