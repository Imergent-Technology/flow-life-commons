import { mkdirSync, mkdtempSync, rmSync, utimesSync, writeFileSync } from 'node:fs'
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'

import { expect, test } from '@playwright/test'

import { buildProblems, servedProblems } from './buildFreshness.ts'

// The functional journeys run against the Console's production build, and refuse to start on a missing, incomplete or out-of-date one
// (e2e/globalSetup.ts). That refusal is the guarantee that a passing run tested the checkpoint it claims to, so it is tested here: a build
// is made in a temporary directory with the mtimes set exactly, and each way it can be wrong must be named.

const INDEX =
  '<!doctype html><script type="module" src="/assets/index-abc.js"></script><link rel="stylesheet" href="/assets/index-abc.css">'
const BUILT = 1_700_000_000 // seconds since the epoch: when the build was written

function put(root: string, file: string, at: number, content = 'x'): void {
  const path = join(root, file)
  mkdirSync(dirname(path), { recursive: true })
  writeFileSync(path, content)
  utimesSync(path, at, at)
}

/** A Console directory whose build is complete and newer than every source file. */
function consoleDirectory(): string {
  const root = mkdtempSync(join(tmpdir(), 'console-'))
  put(root, 'dist/index.html', BUILT, INDEX)
  put(root, 'dist/assets/index-abc.js', BUILT)
  put(root, 'dist/assets/index-abc.css', BUILT)
  put(root, 'index.html', BUILT - 100)
  put(root, 'package.json', BUILT - 100)
  put(root, 'package-lock.json', BUILT - 100)
  put(root, 'vite.config.ts', BUILT - 100)
  put(root, 'src/main.tsx', BUILT - 100)
  put(root, 'src/pages/Home.tsx', BUILT - 100)
  return root
}

test.describe('the built Console the journeys run against', () => {
  let root = ''
  test.beforeEach(() => {
    root = consoleDirectory()
  })
  test.afterEach(() => {
    rmSync(root, { recursive: true, force: true })
  })

  test('a complete build newer than its source is accepted', () => {
    expect(buildProblems(root)).toEqual([])
  })

  test('a missing build is refused, and says how to make one', () => {
    rmSync(join(root, 'dist', 'index.html'))
    const problems = buildProblems(root)
    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('has not been built')
    expect(problems[0]).toContain('./flow build')
  })

  for (const file of [
    'src/pages/Home.tsx',
    'src/new/File.tsx',
    'index.html',
    'package.json',
    'package-lock.json',
    'vite.config.ts',
    'public/robots.txt',
  ]) {
    test(`a build older than ${file} is refused, naming it`, () => {
      put(root, file, BUILT + 5)
      const problems = buildProblems(root)
      expect(problems).toHaveLength(1)
      expect(problems[0]).toContain('older than its source')
      expect(problems[0]).toContain(file)
    })
  }

  for (const file of [
    'src/pages/Home.test.tsx',
    'src/test/setup.ts',
    'e2e/anything.spec.ts',
    'node_modules/x/index.js',
  ]) {
    test(`a change to ${file}, which is not in the bundle, does not make the build stale`, () => {
      put(root, file, BUILT + 5)
      expect(buildProblems(root)).toEqual([])
    })
  }

  test('a build whose shell names a file that is not there is refused as incomplete', () => {
    rmSync(join(root, 'dist', 'assets', 'index-abc.js'))
    const problems = buildProblems(root)
    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('/assets/index-abc.js')
    expect(problems[0]).toContain('incomplete')
  })

  test.describe('the origin that serves it', () => {
    let server: Server
    let port = 0
    let body = INDEX
    let status = 200
    test.beforeEach(async () => {
      body = INDEX
      status = 200
      server = createServer((_, response) => {
        response.statusCode = status
        response.end(body)
      })
      await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
      port = (server.address() as AddressInfo).port
    })
    test.afterEach(async () => {
      await new Promise<void>((resolve) =>
        server.close(() => {
          resolve()
        }),
      )
    })

    test('an origin that serves exactly dist/index.html is accepted', async () => {
      expect(await servedProblems(root, port, 'prod.flowlife.localhost')).toEqual([])
    })

    test('an origin serving a different build is refused', async () => {
      body = INDEX.replace('abc', 'old')
      const problems = await servedProblems(root, port, 'prod.flowlife.localhost')
      expect(problems).toHaveLength(1)
      expect(problems[0]).toContain('serving a different build')
    })

    test('an origin that does not answer 200 is refused', async () => {
      status = 503
      const problems = await servedProblems(root, port, 'prod.flowlife.localhost')
      expect(problems).toHaveLength(1)
      expect(problems[0]).toContain('could not be read')
    })

    test('an origin that cannot be reached is refused, and says to bring the stack up', async () => {
      const problems = await servedProblems(root, 1, 'prod.flowlife.localhost')
      expect(problems).toHaveLength(1)
      expect(problems[0]).toContain('./flow up')
    })
  })
})
