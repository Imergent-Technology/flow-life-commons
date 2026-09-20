<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorRepository;
use DateTimeImmutable;

/**
 * The one place a second factor is checked, for sign-in, step-up and every management operation.
 *
 * Call it INSIDE the caller's transaction with the Account row already locked. A code is accepted at
 * most once: an authenticator code by recording the time step it was accepted at (a later step is
 * required next time), and a recovery code by one atomic conditional update. It changes nothing when it
 * refuses. It audits nothing: what a refusal or a use means differs by caller, so callers record it.
 */
final readonly class SecondFactorVerifier
{
    public function __construct(
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
        private TotpAuthenticator $totp,
        private TotpSecretCipher $cipher,
    ) {}

    /** Checks against the ACTIVE factor. Null when there is none, or the proof is wrong. */
    public function verify(Account $account, SecondFactorProof $proof, DateTimeImmutable $now): ?VerifiedSecondFactor
    {
        $factor = $this->factors->findByAccount($account->id);
        if ($factor === null || ! $factor->isActive() || $factor->secretCiphertext === null) {
            return null;
        }

        $digits = $proof->totpDigits();
        if ($digits !== null) {
            $step = $this->totp->matchingStep($this->cipher->decrypt($factor->secretCiphertext), $digits, $now, $factor->lastUsedStep);
            if ($step === null) {
                return null;
            }
            $this->factors->save($factor->withStepUsed($step, $now));

            return new VerifiedSecondFactor(SecondFactorMethod::Totp);
        }

        $code = $proof->asRecoveryCode();
        if ($code === null || ! $this->recoveryCodes->consume($account->id, $code->digest($account->id), $now)) {
            return null;
        }

        return new VerifiedSecondFactor(SecondFactorMethod::RecoveryCode, $this->recoveryCodes->remaining($account->id));
    }

    /** The step at which `$code` proves the PENDING secret, or null. */
    public function matchPending(TotpFactor $factor, SecondFactorProof $proof, DateTimeImmutable $now): ?int
    {
        $digits = $proof->totpDigits();
        if ($digits === null || $factor->pendingCiphertext === null) {
            return null;
        }

        return $this->totp->matchingStep($this->cipher->decrypt($factor->pendingCiphertext), $digits, $now, null);
    }
}
