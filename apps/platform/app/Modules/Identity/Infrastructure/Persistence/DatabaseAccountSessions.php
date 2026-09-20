<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\AccountSessions;
use App\Shared\Domain\AccountId;
use Illuminate\Database\ConnectionInterface;

/**
 * Sessions live in the database (ADR 0016), so ending them is a delete: they are revocable
 * by construction, which is the reason for choosing that store. `sessions.user_id` holds the
 * Account's ULID.
 */
final readonly class DatabaseAccountSessions implements AccountSessions
{
    public function __construct(private ConnectionInterface $database) {}

    public function revokeAllFor(AccountId $account): int
    {
        return $this->database->table('sessions')->where('user_id', $account->value)->delete();
    }

    public function revokeAllExcept(AccountId $account, string $keepSessionId): int
    {
        return $this->database->table('sessions')->where('user_id', $account->value)->where('id', '!=', $keepSessionId)->delete();
    }
}
