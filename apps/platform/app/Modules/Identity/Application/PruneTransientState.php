<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorRepository;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Removes Identity's **expired transient state**: rows that are already unusable and would otherwise
 * accumulate for the life of the installation.
 *
 * Three things, and deliberately only three:
 *
 * - **Idle database sessions.** Every visitor gets a row, signed in or not. The cutoff is the configured
 *   inactivity lifetime, which is exactly the point at which the framework stops honouring a session,
 *   so this can never end one that still works.
 * - **Expired password-reset tokens.** `isValid` already refuses them; only the rows linger.
 * - **Stale pending authenticator secrets.** A generated secret that was never proved within
 *   TotpFactor::PENDING_LIFETIME_SECONDS can no longer be confirmed. Forgetting it takes unprovable
 *   ciphertext out of the database; an enrolment that was *only* ever pending was never an enrolment
 *   and goes with it.
 *
 * What it deliberately does NOT touch, so the retention decisions are explicit rather than implied:
 *
 * - **`security_events`.** The audit trail is append-only and is kept (ADR 0019). It grows; that is
 *   recorded as a production follow-up, not solved by deletion.
 * - **Invitations, including expired and unaccepted ones.** They are not transient: the Console shows
 *   an Account's invitation state, and "invited, never accepted, expired" is an answer an operator
 *   needs. One row per invited operator is bounded by the number of operators.
 * - **Accounts, People, role assignments, recovery codes, active factors.** None of these is transient.
 *
 * It is idempotent (a second run in the same minute removes nothing), takes no lock, needs no Redis,
 * and is safe to run while the platform is serving requests.
 */
final readonly class PruneTransientState
{
    public function __construct(
        private SessionMaintenance $sessions,
        private PasswordResetTokens $tokens,
        private TotpFactorRepository $factors,
        private Config $config,
    ) {}

    public function __invoke(): PrunedState
    {
        $now = DateTimeImmutable::createFromInterface(now());

        return new PrunedState(
            sessions: $this->sessions->pruneIdleBefore($now->modify('-'.$this->config->integer('session.lifetime').' minutes')),
            passwordResetTokens: $this->tokens->deleteExpired(),
            pendingAuthenticators: $this->factors->forgetStalePending($now->modify('-'.TotpFactor::PENDING_LIFETIME_SECONDS.' seconds')),
        );
    }
}
