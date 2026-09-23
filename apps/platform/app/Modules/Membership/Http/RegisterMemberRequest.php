<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantSource;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterMemberRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // 255, matching Identity's own Person rule (its Domain constant is not reachable from here: a
            // module may not depend on another module's Domain). Identity re-validates and is authoritative.
            'display_name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            // `present`: the key must exist even to say null. ADR 0028 requires an open-ended grant to be an
            // explicit operator choice, never what happens when the caller simply omits an end date.
            'ends_at' => ['present', 'nullable', 'date', 'after:starts_at'],
            'source' => ['required', 'in:operator,luma_legacy'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:'.MembershipGrant::MAX_SOURCE_REFERENCE_LENGTH],
        ];
    }

    public function displayName(): string
    {
        return $this->string('display_name')->toString();
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
