<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\EmailAddressAlreadyInUse;
use App\Modules\Identity\Domain\PersonAlreadyHasAccount;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Uniqueness is enforced by the database (unique email_canonical, unique person_id), so
 * it holds under concurrency; this class only translates a violation into a domain
 * error. Note for callers: on PostgreSQL a violation inside an open transaction aborts
 * that transaction, so treat the exception as terminal for it.
 */
final class EloquentAccountRepository implements AccountRepository
{
    // Constraint names from the accounts migration. Both engines include the name in the error.
    private const string EMAIL_UNIQUE = 'accounts_email_canonical_unique';

    private const string PERSON_UNIQUE = 'accounts_person_id_unique';

    public function save(Account $account): void
    {
        $record = AccountRecord::query()->find($account->id->value) ?? new AccountRecord;

        $record->id = $account->id->value;
        $record->person_id = $account->personId->value;
        $record->email = $account->email->value;
        $record->email_canonical = $account->email->canonical;
        $record->status = $account->status;
        $record->password_hash = $account->passwordHash;
        $record->password_updated_at = Utc::toColumnOrNull($account->passwordUpdatedAt);
        $record->email_verified_at = Utc::toColumnOrNull($account->emailVerifiedAt);
        $record->disabled_at = Utc::toColumnOrNull($account->disabledAt);
        $record->last_login_at = Utc::toColumnOrNull($account->lastLoginAt);
        $record->created_at = Utc::toColumn($account->createdAt);
        $record->updated_at = Utc::toColumn($account->updatedAt);

        try {
            $record->save();
        } catch (UniqueConstraintViolationException $e) {
            throw match (true) {
                str_contains($e->getMessage(), self::EMAIL_UNIQUE) => new EmailAddressAlreadyInUse($e),
                str_contains($e->getMessage(), self::PERSON_UNIQUE) => new PersonAlreadyHasAccount($e),
                default => $e,
            };
        }
    }

    public function find(AccountId $id): ?Account
    {
        $record = AccountRecord::query()->find($id->value);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByPersonId(PersonId $personId): ?Account
    {
        $record = AccountRecord::query()->where('person_id', $personId->value)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByEmail(EmailAddress $email): ?Account
    {
        // The canonical column is the only lookup key (ADR 0015); never query `email`.
        $record = AccountRecord::query()->where('email_canonical', $email->canonical)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(AccountRecord $record): Account
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
