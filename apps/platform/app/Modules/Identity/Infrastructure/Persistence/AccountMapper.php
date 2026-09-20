<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;

/** The one place an AccountRecord becomes an Account, shared by the repository and the auth provider. */
final class AccountMapper
{
    public function toDomain(AccountRecord $record): Account
    {
        return Account::reconstitute(
            AccountId::fromString($record->id),
            PersonId::fromString($record->person_id),
            EmailAddress::fromString($record->email),
            $record->status,
            $record->password_hash,
            Utc::fromColumnOrNull($record->password_updated_at),
            Utc::fromColumnOrNull($record->email_verified_at),
            Utc::fromColumnOrNull($record->disabled_at),
            Utc::fromColumnOrNull($record->last_login_at),
            Utc::fromColumn($record->created_at),
            Utc::fromColumn($record->updated_at),
        );
    }
}
