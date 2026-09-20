<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\Actor;

/**
 * Step-up: a signed-in person re-proves the current password AND a second factor, so the transport can
 * record "recently verified" on their session (ADR 0023). The window and what needs it are the
 * transport's and the future routes' business; this only decides whether the proof is good, and records
 * that it was.
 */
final readonly class VerifySecurityAccess
{
    public function __construct(
        private SecurityProof $proof,
        private MfaAudit $audit,
    ) {}

    /**
     * @throws TooManyAttempts
     * @throws NoLongerAuthenticated
     * @throws CurrentPasswordIncorrect
     * @throws SecondFactorRejected
     */
    public function __invoke(Actor $actor, #[\SensitiveParameter] string $currentPassword, SecondFactorProof $proof, ClientContext $client): SecondFactorMethod
    {
        return $this->proof->run($actor, $currentPassword, $proof, $client, function ($account, VerifiedSecondFactor $verified) use ($actor, $client): SecondFactorMethod {
            $this->audit->reverified($actor, $verified->method, $client);

            return $verified->method;
        });
    }
}
