<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\AccountId;

final class EloquentTotpFactorRepository implements TotpFactorRepository
{
    public function save(TotpFactor $factor): void
    {
        $record = TotpFactorRecord::query()->find($factor->id->value) ?? new TotpFactorRecord;

        $record->id = $factor->id->value;
        $record->account_id = $factor->accountId->value;
        $record->secret_ciphertext = $factor->secretCiphertext;
        $record->pending_secret_ciphertext = $factor->pendingCiphertext;
        $record->pending_started_at = Utc::toColumnOrNull($factor->pendingStartedAt);
        $record->enrolled_at = Utc::toColumnOrNull($factor->enrolledAt);
        $record->last_used_step = $factor->lastUsedStep;
        $record->created_at = Utc::toColumn($factor->createdAt);
        $record->updated_at = Utc::toColumn($factor->updatedAt);
        $record->save();
    }

    public function findByAccount(AccountId $account): ?TotpFactor
    {
        $record = TotpFactorRecord::query()->where('account_id', $account->value)->first();

        return $record === null ? null : TotpFactor::reconstitute(
            TotpFactorId::fromString($record->id),
            AccountId::fromString($record->account_id),
            $record->secret_ciphertext,
            $record->pending_secret_ciphertext,
            Utc::fromColumnOrNull($record->pending_started_at),
            Utc::fromColumnOrNull($record->enrolled_at),
            $record->last_used_step,
            Utc::fromColumn($record->created_at),
            Utc::fromColumn($record->updated_at),
        );
    }

    public function delete(AccountId $account): bool
    {
        return TotpFactorRecord::query()->where('account_id', $account->value)->toBase()->delete() > 0;
    }
}
