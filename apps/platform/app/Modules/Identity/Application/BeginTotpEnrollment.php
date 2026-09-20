<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * A person who has proved their password, and must enrol an authenticator before they can use the
 * Console, asks for a secret to add to their app.
 *
 * It generates a secret and stores it ENCRYPTED as PENDING. Nothing is enrolled: a pending secret changes
 * nothing about how anyone signs in, and it cannot be used to sign in. Asking again replaces the earlier
 * pending secret, so an abandoned attempt strands no one. The plain secret is returned once, for display,
 * and is stored nowhere in plain text.
 */
final readonly class BeginTotpEnrollment
{
    public function __construct(
        private AccountRepository $accounts,
        private TotpFactorRepository $factors,
        private TotpAuthenticator $totp,
        private TotpSecretCipher $cipher,
        private CredentialMarker $marker,
        private ConnectionInterface $database,
    ) {}

    public function __invoke(PendingLogin $pending): TotpSetup|SecondFactorFailure
    {
        return $this->database->transaction(function () use ($pending): TotpSetup|SecondFactorFailure {
            $now = DateTimeImmutable::createFromInterface(now());
            $account = $this->accounts->findForUpdate($pending->accountId);
            $refusal = $this->refusal($account, $pending);
            if ($refusal !== null) {
                return $refusal;
            }
            assert($account instanceof Account);

            $factor = $this->factors->findByAccount($account->id);
            if ($factor?->isActive() === true) {
                return SecondFactorFailure::AlreadyEnrolled; // enrolled elsewhere since: sign in again and be challenged
            }

            $secret = $this->totp->generateSecret();
            $ciphertext = $this->cipher->encrypt($secret);
            $this->factors->save($factor === null
                ? TotpFactor::begin(TotpFactorId::generate(), $account->id, $ciphertext, $now)
                : $factor->withPending($ciphertext, $now));

            return new TotpSetup($secret, $this->totp->provisioningUri($secret, $account->email->value));
        });
    }

    private function refusal(?Account $account, PendingLogin $pending): ?SecondFactorFailure
    {
        return match (true) {
            $account === null, ! $account->canAuthenticate() => SecondFactorFailure::AccountNotActive,
            ! hash_equals($pending->credentialMarker, $this->marker->for($account)) => SecondFactorFailure::CredentialChanged,
            $pending->need !== SecondFactorNeed::Enrollment => SecondFactorFailure::WrongStep,
            default => null,
        };
    }
}
