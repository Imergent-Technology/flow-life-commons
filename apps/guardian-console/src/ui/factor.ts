import type { SecondFactorProof } from '../api/auth.ts'

export type FactorMode = 'code' | 'recovery'

/** The typed value and which kind it is, as the proof the API wants. */
export function proofFrom(mode: FactorMode, value: string): SecondFactorProof {
  return mode === 'code' ? { code: value } : { recoveryCode: value }
}
