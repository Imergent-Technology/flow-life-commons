<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT AND TESTING ONLY. {@see E2eSessionSeeder}, with every session minted as if its last proof were
 * {@see self::PROOF_AGE_MINUTES} minutes old, which is past the platform's 15-minute window.
 *
 * A diagnostic, selected with `E2E_AGED_SESSIONS=1 ./flow test e2e`. A journey that needs a recent proof but borrows one from a minted
 * session passes only if it happens to run in the first 15 minutes after seeding, so how fast the host is decides whether the suite
 * passes. In this mode the minted proofs are already gone, so every such journey fails at its first protected step (`403`,
 * `verification_required`) however quickly the suite runs. A journey that signs in or proves for itself is unaffected, and so is one
 * that is stale by design. Sessions that were already older keep their age; nothing else differs.
 */
final class E2eAgedSessionSeeder extends Seeder
{
    /** Past the 15-minute window, and not so long that the session itself is near any other limit. */
    public const int PROOF_AGE_MINUTES = 20;

    public function run(): void
    {
        (new E2eSessionSeeder)->setContainer($this->container)->mintAll(self::PROOF_AGE_MINUTES);
    }
}
