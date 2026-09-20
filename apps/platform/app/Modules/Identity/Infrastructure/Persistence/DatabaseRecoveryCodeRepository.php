<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\RecoveryCodeId;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Shared\Domain\AccountId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Plain query-builder access to `account_recovery_codes`: every operation here is one statement, so
 * there is no model to hold stale state.
 *
 * `consume` is a single `UPDATE ... WHERE account_id AND code_hash AND used_at IS NULL`. On both engines
 * a competing update to the same row waits for the first to commit and then re-evaluates the WHERE
 * against the committed row, finds it used, and changes nothing: exactly one caller sees an affected
 * row. That holds whatever else is or is not locked.
 */
final readonly class DatabaseRecoveryCodeRepository implements RecoveryCodeRepository
{
    private const string TABLE = 'account_recovery_codes';

    public function __construct(private ConnectionInterface $database) {}

    public function replaceAll(AccountId $account, array $digests, DateTimeImmutable $now): void
    {
        $this->database->table(self::TABLE)->where('account_id', $account->value)->delete();

        $created = Utc::toColumn($now);
        $this->database->table(self::TABLE)->insert(array_map(
            static fn (string $digest): array => [
                'id' => RecoveryCodeId::generate()->value,
                'account_id' => $account->value,
                'code_hash' => $digest,
                'used_at' => null,
                'created_at' => $created,
            ],
            $digests,
        ));
    }

    public function consume(AccountId $account, string $digest, DateTimeImmutable $now): bool
    {
        return $this->database->table(self::TABLE)
            ->where('account_id', $account->value)
            ->where('code_hash', $digest)
            ->whereNull('used_at')
            ->update(['used_at' => Utc::toColumn($now)]) === 1;
    }

    public function remaining(AccountId $account): int
    {
        return $this->database->table(self::TABLE)
            ->where('account_id', $account->value)
            ->whereNull('used_at')
            ->count();
    }

    public function deleteAll(AccountId $account): int
    {
        return $this->database->table(self::TABLE)->where('account_id', $account->value)->delete();
    }
}
