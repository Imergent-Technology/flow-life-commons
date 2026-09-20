<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;

/**
 * A sign-in whose password has been proved and which is waiting for the second factor: the minimum
 * needed to finish it. It is NOT authentication: it grants no identity, no capability and no access.
 *
 * It carries the Account, whether the next step is a challenge or an enrolment, and a digest bound to
 * the password that was proved (CredentialMarker). It holds no password, hash, secret, code or
 * capability. The transport keeps it in the browser session for a few minutes, and everything that
 * completes it re-reads the Account under a lock and checks the digest, so a password that was replaced
 * or an Account that was disabled in the meantime ends it.
 */
final readonly class PendingLogin
{
    public function __construct(
        public AccountId $accountId,
        public string $credentialMarker,
        public SecondFactorNeed $need,
    ) {}
}
