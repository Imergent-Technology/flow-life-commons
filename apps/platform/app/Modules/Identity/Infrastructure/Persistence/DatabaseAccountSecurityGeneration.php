<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\AccountSecurityGeneration;
use App\Shared\Domain\AccountId;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The security generation as a column on `accounts` (ADR 0025).
 *
 * `advance()` is one `UPDATE ... SET security_generation = security_generation + 1`, which MariaDB and
 * PostgreSQL both apply to the current row value rather than to one PHP read a moment earlier, so two
 * advances can never land on the same number even without a lock. It then re-reads the row it just
 * wrote, inside the caller's transaction, to report the value it committed.
 */
final readonly class DatabaseAccountSecurityGeneration implements AccountSecurityGeneration
{
    public function __construct(private ConnectionInterface $database) {}

    public function current(AccountId $account): ?int
    {
        $value = $this->database->table('accounts')->where('id', $account->value)->value('security_generation');

        return is_numeric($value) ? (int) $value : null;
    }

    public function advance(AccountId $account): int
    {
        $this->database->table('accounts')->where('id', $account->value)
            ->update(['security_generation' => $this->database->raw('security_generation + 1')]);

        return $this->current($account)
            ?? throw new RuntimeException('The account whose security generation was advanced no longer exists.');
    }
}
