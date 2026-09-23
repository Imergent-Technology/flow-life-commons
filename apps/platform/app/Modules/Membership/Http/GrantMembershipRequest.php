<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantSource;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class GrantMembershipRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            // `present`: the key must exist even to say null (ADR 0028 — open-ended access is an explicit choice).
            'ends_at' => ['present', 'nullable', 'date', 'after:starts_at'],
            'source' => ['required', 'in:operator,luma_legacy'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:'.MembershipGrant::MAX_SOURCE_REFERENCE_LENGTH],
        ];
    }

    public function startsAt(): DateTimeImmutable
    {
        return MembershipRequestDates::parse($this->string('starts_at')->toString());
    }

    public function endsAt(): ?DateTimeImmutable
    {
        $value = $this->input('ends_at');

        return is_string($value) ? MembershipRequestDates::parse($value) : null;
    }

    public function source(): MembershipGrantSource
    {
        return MembershipGrantSource::from($this->string('source')->toString());
    }

    public function sourceReference(): ?string
    {
        $value = $this->input('source_reference');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
