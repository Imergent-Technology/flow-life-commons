<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Modules\Identity\Domain\TotpSecret;
use App\Shared\Domain\AccountId;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;

/**
 * DEVELOPMENT AND TESTING ONLY. Enrols an Account's authenticator with a KNOWN secret and known recovery
 * codes, so the browser end-to-end tests (which cannot see an authenticator app) can compute valid codes.
 *
 * It bypasses proof of possession and the audit trail on purpose, and so it refuses to run anywhere but
 * the local and testing environments, like Access's ConsoleUserFixture. It is not an enrolment path: real
 * enrolment is BeginTotpEnrollment and ConfirmTotpEnrollment, which prove the secret. Nothing in
 * production calls it.
 */
final readonly class EnrollTotpFixture
{
    public function __construct(
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
        private TotpSecretCipher $cipher,
        private Config $config,
    ) {}

    /** @param  list<string>  $recoveryCodes  raw codes, in any accepted spelling */
    public function __invoke(AccountId $account, string $base32Secret, array $recoveryCodes): void
    {
        if (! in_array($this->config->string('app.env'), ['local', 'testing'], true)) {
            throw new LogicException('The TOTP fixture may only run in a local or testing environment.');
        }

        $now = DateTimeImmutable::createFromInterface(now());
        $ciphertext = $this->cipher->encrypt(TotpSecret::fromBase32($base32Secret));
        $this->factors->save(TotpFactor::reconstitute(
            $this->factors->findByAccount($account)->id ?? TotpFactorId::generate(), $account,
            $ciphertext, null, null, $now, null, $now, $now,
        ));
        $this->recoveryCodes->replaceAll($account, array_map(
            static fn (string $code): string => RecoveryCode::fromPresented($code)->digest($account),
            $recoveryCodes,
        ), $now);
    }
}
