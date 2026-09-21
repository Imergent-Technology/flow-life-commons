<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\AccountId;
use DateTimeImmutable;

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

    public function forgetStalePending(DateTimeImmutable $startedBefore): int
    {
        $cutoff = Utc::toColumn($startedBefore);

        // Two statements, because the domain forbids a factor with neither secret: an enrolment that
        // was only ever pending ceases to exist, and one with a proved secret merely loses the pending
        // one. Both are plain conditional writes, so they are idempotent and portable.
        $abandoned = TotpFactorRecord::query()
            ->whereNull('secret_ciphertext')
            ->whereNotNull('pending_started_at')
            ->where('pending_started_at', '<', $cutoff)
            ->toBase()->delete();

        $cleared = TotpFactorRecord::query()
            ->whereNotNull('secret_ciphertext')
            ->whereNotNull('pending_started_at')
            ->where('pending_started_at', '<', $cutoff)
            ->toBase()->update(['pending_secret_ciphertext' => null, 'pending_started_at' => null]);

        return $abandoned + $cleared;
    }
}
