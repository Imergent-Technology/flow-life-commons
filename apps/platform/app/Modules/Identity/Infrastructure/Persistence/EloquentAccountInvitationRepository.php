<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;

/**
 * Persists only the token hash. Marking an invitation accepted is a plain update here;
 * making that claim atomic under concurrent acceptance (lock, then check-and-set) belongs
 * to the acceptance use case, which owns the transaction and reads through findByTokenForUpdate.
 */
final class EloquentAccountInvitationRepository implements AccountInvitationRepository
{
    public function save(AccountInvitation $invitation): void
    {
        $record = AccountInvitationRecord::query()->find($invitation->id->value) ?? new AccountInvitationRecord;

        $record->id = $invitation->id->value;
        $record->account_id = $invitation->accountId->value;
        $record->token_hash = $invitation->tokenHash;
        $record->expires_at = Utc::toColumn($invitation->expiresAt);
        $record->accepted_at = Utc::toColumnOrNull($invitation->acceptedAt);
        $record->invited_by_account_id = $invitation->invitedByAccountId?->value;
        $record->channel = $invitation->channel->value;
        $record->save();
    }

    public function find(AccountInvitationId $id): ?AccountInvitation
    {
        $record = AccountInvitationRecord::query()->find($id->value);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByToken(InvitationToken $token): ?AccountInvitation
    {
        $record = AccountInvitationRecord::query()->where('token_hash', $token->hash())->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByTokenForUpdate(InvitationToken $token): ?AccountInvitation
    {
        $record = AccountInvitationRecord::query()->where('token_hash', $token->hash())->lockForUpdate()->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function lockAllFor(AccountId $account): void
    {
        AccountInvitationRecord::query()->where('account_id', $account->value)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public function deleteUnacceptedFor(AccountId $account): int
    {
        return AccountInvitationRecord::query()->where('account_id', $account->value)->whereNull('accepted_at')->toBase()->delete();
    }

    private function toDomain(AccountInvitationRecord $record): AccountInvitation
    {
        return AccountInvitation::reconstitute(
            AccountInvitationId::fromString($record->id),
            AccountId::fromString($record->account_id),
            $record->token_hash,
            Utc::fromColumn($record->expires_at),
            Utc::fromColumnOrNull($record->accepted_at),
            $record->invited_by_account_id === null ? null : AccountId::fromString($record->invited_by_account_id),
            InvitationChannel::from($record->channel),
        );
    }
}
