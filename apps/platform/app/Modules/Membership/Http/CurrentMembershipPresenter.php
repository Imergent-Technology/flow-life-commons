<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Domain\MembershipGrant;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shape of `GET /my/membership` (openapi/openapi.yaml, CurrentMembership) — deliberately
 * NOT the admin `MembershipPresenter`'s shape, kept as its own small class so a field never reaches
 * a Member merely because the admin presenter already had it.
 *
 * Excluded on purpose, none of it administrative provenance a Member has any claim to: the grant
 * id (an internal reference, not something a Member acts on), `source`/`source_reference` (how or
 * why an operator granted it), and `granted_by_account_id`/`revoked_by_account_id` (which operator).
 * A grant's revocation is exposed as the boolean fact `revoked`, not the instant it happened —
 * enough to explain the history, nothing that reads as an operational timestamp.
 */
final readonly class CurrentMembershipPresenter
{
    /** @return array<string, mixed> */
    public function present(MembershipRecord $record): array
    {
        return [
            'active' => $record->active,
            'current_access_ends_at' => $this->instant($record->currentAccessEndsAt),
            'open_ended' => $record->openEnded,
            'grants' => array_map($this->grant(...), $record->grants),
        ];
    }

    /** @return array<string, mixed> */
    private function grant(MembershipGrant $grant): array
    {
        return [
            'starts_at' => $this->instant($grant->startsAt),
            'ends_at' => $this->instant($grant->endsAt),
            'revoked' => $grant->isRevoked(),
        ];
    }

    private function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
