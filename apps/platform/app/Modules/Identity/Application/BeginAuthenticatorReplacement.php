<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\Actor;

/**
 * A signed-in person with an authenticator asks to replace it (a new phone, say). Fresh proof first:
 * the current password AND a current second factor (SecurityProof), never the session alone.
 *
 * It only generates a PENDING secret. The authenticator that works today keeps working until the new one
 * is proved (ConfirmAuthenticatorReplacement), so a replacement that is abandoned, or fails, cannot strand
 * the Account.
 */
final readonly class BeginAuthenticatorReplacement
{
    public function __construct(
        private SecurityProof $proof,
        private TotpFactorRepository $factors,
        private TotpAuthenticator $totp,
        private TotpSecretCipher $cipher,
    ) {}

    /**
     * @throws TooManyAttempts
     * @throws NoLongerAuthenticated
     * @throws CurrentPasswordIncorrect
     * @throws SecondFactorRejected
     */
    public function __invoke(Actor $actor, #[\SensitiveParameter] string $currentPassword, SecondFactorProof $proof, ClientContext $client): TotpSetup
    {
        return $this->proof->run($actor, $currentPassword, $proof, $client, function ($account, $verified, $now): TotpSetup {
            $factor = $this->factors->findByAccount($account->id);
            // Cannot happen after a second factor was just verified; refuse rather than assume.
            if ($factor === null || ! $factor->isActive()) {
                throw new SecondFactorRejected(SecondFactorFailure::NotEnrolled, $account);
            }

            $secret = $this->totp->generateSecret();
            $this->factors->save($factor->withPending($this->cipher->encrypt($secret), $now));

            return new TotpSetup($secret, $this->totp->provisioningUri($secret, $account->email->value));
        });
    }
}
