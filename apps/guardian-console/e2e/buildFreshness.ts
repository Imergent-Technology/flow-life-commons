import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { request } from 'node:http'
import { join, relative } from 'node:path'

// The browser suite's functional journeys run against the Console's production BUILD (`dist/`), served by the gateway's
// production-equivalent site (`prod.flowlife.localhost`). A build that is missing, or older than the source it should contain, would
// make those journeys test something that is not the checkpoint under test and could still pass, so the suite refuses to start on one
// (e2e/globalSetup.ts). `./flow test e2e` rebuilds first; this is what makes that guarantee hold for any other way of starting the suite.

/** Files and directories whose change changes the bundle. Tests are not part of it, so editing one does not make the build stale. */
const WATCHED_FILES = [
  'index.html',
  'vite.config.ts',
  'package.json',
  'package-lock.json',
  'tsconfig.json',
  'tsconfig.app.json',
  'tsconfig.node.json',
]
const WATCHED_DIRECTORIES = ['src', 'public']

const isTest = (path: string): boolean =>
  /\.test\.(ts|tsx)$/.test(path) || /[\\/]src[\\/]test[\\/]/.test(path)

function* filesUnder(directory: string): Generator<string> {
  if (!existsSync(directory)) return
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) yield* filesUnder(path)
    else yield path
  }
}

/** What is wrong with the build in `root` (the Console's directory), as sentences; empty when it is present, complete and current. */
export function buildProblems(root: string): string[] {
  const index = join(root, 'dist', 'index.html')
  if (!existsSync(index)) {
    return [
      'dist/index.html does not exist: the Console has not been built. Run ./flow build (./flow test e2e does it first).',
    ]
  }
  const problems: string[] = []
  const builtAt = statSync(index).mtimeMs

  const sources = [
    ...WATCHED_FILES.map((name) => join(root, name)).filter(existsSync),
    ...WATCHED_DIRECTORIES.flatMap((name) => [...filesUnder(join(root, name))]),
  ].filter((path) => !isTest(path))
  const newer = sources.filter((path) => statSync(path).mtimeMs > builtAt).sort()
  if (newer.length > 0) {
    problems.push(
      `The build is older than its source: ${String(newer.length)} file(s) changed after dist/index.html was written (first: ${relative(root, newer[0] ?? '')}). ` +
        'Rebuild with ./flow build (./flow test e2e does it first).',
    )
  }

  // Everything the shell loads must be there, or the journeys would be testing a page that cannot start.
  const html = readFileSync(index, 'utf8')
  for (const [, reference] of html.matchAll(/(?:src|href)="(\/assets\/[^"]+)"/g)) {
    if (reference !== undefined && !existsSync(join(root, 'dist', reference))) {
      problems.push(
        `dist/index.html names ${reference}, which is not in dist/. The build is incomplete.`,
      )
    }
  }
  return problems
}

/**
 * What is wrong with the origin that is meant to serve the build: it must answer `/` with exactly `dist/index.html`. Asked by address with
 * the host named (Node cannot resolve *.localhost; browsers can), which is also how a gateway that mounts some other directory is caught.
 */
export async function servedProblems(root: string, port: number, host: string): Promise<string[]> {
  const expected = readFileSync(join(root, 'dist', 'index.html'), 'utf8')
  const served = await new Promise<string | Error>((resolve) => {
    const call = request(
      {
        host: '127.0.0.1',
        port,
        path: '/',
        method: 'GET',
        headers: { Host: `${host}:${String(port)}` },
        timeout: 10_000,
      },
      (response) => {
        const chunks: Buffer[] = []
        response.on('data', (chunk: Buffer) => {
          chunks.push(chunk)
        })
        response.on('end', () => {
          resolve(
            response.statusCode === 200
              ? Buffer.concat(chunks).toString('utf8')
              : new Error(`answered ${String(response.statusCode)}`),
          )
        })
      },
    )
    call.on('timeout', () => {
      call.destroy(new Error('timed out'))
    })
    call.on('error', (error) => {
      resolve(error)
    })
    call.end()
  })
  if (served instanceof Error) {
    return [
      `The built origin ${host}:${String(port)} could not be read (${served.message}). Is the stack up? (./flow up)`,
    ]
  }
  return served === expected
    ? []
    : [
        `The built origin ${host}:${String(port)} does not serve dist/index.html as it is on disk: it is serving a different build.`,
      ]
}
