import { buildProblems, servedProblems } from './buildFreshness.ts'

// Runs once before any journey. The functional journeys test the Console's production build, so they do not start on a missing,
// incomplete or out-of-date one, or against an origin that is serving a different one.
export default async function globalSetup(): Promise<void> {
  const root = process.cwd()
  const problems = buildProblems(root)
  if (problems.length === 0) {
    const developmentURL = new URL(
      process.env.E2E_BASE_URL ?? 'http://commons.flowlife.localhost:18080',
    )
    problems.push(
      ...(await servedProblems(root, Number(developmentURL.port), 'prod.flowlife.localhost')),
    )
  }
  if (problems.length > 0) {
    throw new Error(
      `The browser suite will not run against this build:\n${problems.map((p) => `  - ${p}`).join('\n')}`,
    )
  }
}
