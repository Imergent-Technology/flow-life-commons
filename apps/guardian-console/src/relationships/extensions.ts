import type { ComponentType } from 'react'

import type {
  RelationshipManagement,
  RelationshipRecord,
  RelationshipType,
} from '../api/relationships.ts'

/**
 * What a relationship type may add of its own to the generic record, without anyone editing the generic components (ADR 0038,
 * C2). A later type that needs a specialised section registers it here under its type key and the record renders it after the
 * generic panels. G10 registers none: both of its types are fully served by the generic screens.
 *
 * This is the one place a relationship type's key may be written in Console source (a source test pins it). It is a lookup, not
 * a plugin system: there is no loading, no ordering and no way to replace a generic panel.
 */
export interface RelationshipExtensionProps {
  type: RelationshipType
  personId: string
  /** What the operator may see of this relationship right now. Either may be absent, according to their capabilities. */
  view: RelationshipRecord | null
  management: RelationshipManagement | null
}

export interface RelationshipExtension {
  Section?: ComponentType<RelationshipExtensionProps>
}

export const relationshipExtensions: Readonly<Record<string, RelationshipExtension | undefined>> =
  {}
