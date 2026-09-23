<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantSource;
use DateTimeImmutable;
use Illuminate\Validation\Validator;

/**
 * The term of a membership grant as the HTTP contract states it, shared by the two requests that carry one.
 *
 * **Open-ended access is declared, never inferred (ADR 0028).** Laravel's global `TrimStrings` and
 * `ConvertEmptyStringsToNull` turn `""` and `"   "` into `null` before validation runs, so a blank end date is
 * indistinguishable from a deliberate `null` by `ends_at` alone. The intent therefore travels in its own required
 * boolean, and the two fields must agree:
 *
 * - `open_ended: true`  with `ends_at: null`                      — open-ended access
 * - `open_ended: false` with `ends_at` strictly after `starts_at` — a bounded term
 *
 * Anything else is a 422, including a blank or absent end date without `open_ended: true`.
 */
trait DeclaresMembershipTerm
{
    /** @return array<string, list<string>> */
    protected function termRules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'open_ended' => ['required', 'boolean'],
            'ends_at' => ['present', 'nullable', 'date', 'after:starts_at'],
            'source' => ['required', 'in:operator,luma_legacy'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:'.MembershipGrant::MAX_SOURCE_REFERENCE_LENGTH],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Only judge the pair once each half is individually well-formed.
            if ($validator->errors()->hasAny(['open_ended', 'ends_at'])) {
                return;
            }

            $openEnded = $this->boolean('open_ended');
            $endsAt = $this->input('ends_at');

            if ($openEnded && $endsAt !== null) {
                $validator->errors()->add('ends_at', 'An open-ended grant must not have an end date.');
            } elseif (! $openEnded && $endsAt === null) {
                $validator->errors()->add('ends_at', 'A bounded grant needs an end date; choose open-ended access to have none.');
            }
        }];
    }

    public function startsAt(): DateTimeImmutable
    {
        return MembershipRequestDates::parse($this->string('starts_at')->toString());
    }

    public function endsAt(): ?DateTimeImmutable
    {
        $value = $this->input('ends_at');

        return ! $this->boolean('open_ended') && is_string($value) ? MembershipRequestDates::parse($value) : null;
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
