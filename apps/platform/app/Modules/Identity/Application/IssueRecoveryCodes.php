<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Shared\Domain\AccountId;
use DateTimeImmutable;

/**
 * Generates a fresh set of recovery codes, stores their digests in place of any earlier set, and returns
 * the raw codes to be shown ONCE. Call inside the caller's transaction. The raw codes exist only in the
 * returned list: they are stored nowhere, so nothing can show them again.
 */
final readonly class IssueRecoveryCodes
{
    public const int COUNT = 10;

    public function __construct(private RecoveryCodeRepository $recoveryCodes) {}

    /** @return list<string> the codes, formatted for display */
    public function __invoke(AccountId $account, DateTimeImmutable $now): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = RecoveryCode::generate();
        }

        $this->recoveryCodes->replaceAll(
            $account,
            array_map(static fn (RecoveryCode $code): string => $code->digest($account), $codes),
            $now,
        );

        return array_map(static fn (RecoveryCode $code): string => $code->formatted(), $codes);
    }
}
