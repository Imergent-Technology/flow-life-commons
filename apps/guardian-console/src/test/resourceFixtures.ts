/// <reference types="node" />
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

// The shared Resources content corpus (ADR 0037, decision 28). It has ONE home, the platform's
// tests/Fixtures/resource-content, because the server's validation is the contract and the Console
// follows it. There is no second copy to drift: this reads that directory, and a missing directory is
// a hard failure (a suite that found nothing would pass by testing nothing).
//
// From the repository the directory is a sibling app. In the Guardian Console's container, which
// mounts only apps/guardian-console, compose.yaml mounts the same directory read-only at
// /platform/tests/Fixtures/resource-content, which is the same relative path from /app.

const root = join(import.meta.dirname, '../../../platform/tests/Fixtures/resource-content')

export interface ValidFixture {
  name: string
  description: string
  /** What an editor sends. */
  document: unknown
  /** What the server stores, when that differs from `document`. */
  canonical?: unknown
  /** The plain text the server derives. */
  text: string
}

export interface InvalidFixture {
  name: string
  description: string
  /** Where the server says the first offence is. */
  path: string
  document: unknown
}

function load<T>(kind: 'valid' | 'invalid'): T[] {
  const dir = join(root, kind)
  if (!existsSync(dir)) {
    throw new Error(
      `The shared Resources fixtures are not visible at ${dir}. Run the suite from the repository, ` +
        'or through ./flow (compose mounts apps/platform/tests/Fixtures/resource-content).',
    )
  }

  return readdirSync(dir)
    .filter((file) => file.endsWith('.json'))
    .sort()
    .map((file) => ({
      name: file.replace(/\.json$/, ''),
      ...(JSON.parse(readFileSync(join(dir, file), 'utf8')) as object),
    })) as T[]
}

export const validFixtures = (): ValidFixture[] => load<ValidFixture>('valid')
export const invalidFixtures = (): InvalidFixture[] => load<InvalidFixture>('invalid')
