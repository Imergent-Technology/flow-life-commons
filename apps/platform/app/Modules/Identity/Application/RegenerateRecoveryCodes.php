<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\Actor;

/**
 * A signed-in person replaces their recovery codes with a new set. Fresh proof first (SecurityProof: the
 * current password AND a second factor). Every earlier code, used or not, stops working in the same
 * transaction as the new set is stored; the new raw codes are returned ONCE and stored nowhere.
 */
final readonly class RegenerateRecoveryCodes
{
    public function __construct(
        private SecurityProof $proof,
        private IssueRecoveryCodes $issue,
        private MfaAudit $audit,
    ) {}

    /**
     * @return list<string> the new codes, to be shown once
     *
     * @throws TooManyAttempts
     * @throws NoLongerAuthenticated
     * @throws CurrentPasswordIncorrect
     * @throws SecondFactorRejected
     */
    public function __invoke(Actor $actor, #[\SensitiveParameter] string $currentPassword, SecondFactorProof $proof, ClientContext $client): array
    {
        return $this->proof->run($actor, $currentPassword, $proof, $client, function ($account, $verified, $now) use ($actor, $client): array {
            $codes = ($this->issue)($account->id, $now);
            $this->audit->recoveryCodesRegenerated($actor, count($codes), $client);

            return $codes;
        });
    }
}
