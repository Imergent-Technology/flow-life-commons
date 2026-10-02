<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\PageDiscussions;
use App\Modules\Discussions\Domain\DiscussionState;
use App\Modules\Discussions\Domain\DiscussionTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListDiscussionsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::enum(DiscussionState::class)],
            'q' => ['sometimes', 'string', 'max:'.DiscussionTitle::MAX_LENGTH],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PageDiscussions::MAX_PER_PAGE],
        ];
    }

    public function state(): ?DiscussionState
    {
        $state = $this->input('state');

        return is_string($state) ? DiscussionState::from($state) : null;
    }

    public function text(): ?string
    {
        $q = $this->input('q');

        return is_string($q) && trim($q) !== '' ? $q : null;
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
