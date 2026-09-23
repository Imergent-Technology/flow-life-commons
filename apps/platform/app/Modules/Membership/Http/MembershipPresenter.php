<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Application\MembershipRecordsPage;
use App\Modules\Membership\Domain\MembershipGrant;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the Membership administration API (openapi/openapi.yaml, Member and
 * friends). Only what the Guardian Console's Membership admin surface needs: never a role key, a
 * capability, an Account id kept merely because it exists in the row, or a payment fact (ADR
 * 0029 — Membership carries none to disclose). `granted_by_account_id`/`revoked_by_account_id`
 * are provenance for an operator investigating a grant, not something this Phase-1 surface shows.
 */
final readonly class MembershipPresenter
{
    /** @return array<string, mixed> */
    public function record(MembershipRecord $record, PersonSummary $person): array
    {
        return [
            'person' => ['id' => $person->id->value, 'display_name' => $person->displayName],
            'active' => $record->active,
            'current_access_ends_at' => $this->instant($record->currentAccessEndsAt),
            'open_ended' => $record->openEnded,
            'grants' => array_map($this->grantHistoryEntry(...), $record->grants),
        ];
    }

    /**
     * @param  array<string, PersonSummary>  $people  keyed by PersonId value; every record's Person must be present
     * @return array<string, mixed>
     */
    public function page(MembershipRecordsPage $page, array $people): array
    {
        return [
            'data' => array_map(function (MembershipRecord $record) use ($people): array {
                $summary = $people[$record->personId->value] ?? null;
                assert($summary instanceof PersonSummary);

                return $this->record($record, $summary);
            }, $page->records),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
        ];
    }

    /**
     * A single created grant, standing alone (not nested in a Person's history).
     *
     * @return array<string, mixed>
     */
    public function grant(MembershipGrant $grant): array
    {
        return [
            'id' => $grant->id->value,
            'person_id' => $grant->personId->value,
            'starts_at' => $this->instant($grant->startsAt),
            'ends_at' => $this->instant($grant->endsAt),
            'source' => $grant->source->value,
            'source_reference' => $grant->sourceReference,
            'revoked_at' => $this->instant($grant->revokedAt),
        ];
    }

    /** @return array<string, mixed> */
    private function grantHistoryEntry(MembershipGrant $grant): array
    {
        return [
            'id' => $grant->id->value,
            'starts_at' => $this->instant($grant->startsAt),
            'ends_at' => $this->instant($grant->endsAt),
            'source' => $grant->source->value,
            'source_reference' => $grant->sourceReference,
            'revoked_at' => $this->instant($grant->revokedAt),
        ];
    }

    private function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
