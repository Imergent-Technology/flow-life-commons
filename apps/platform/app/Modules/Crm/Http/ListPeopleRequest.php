<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\PagePeopleDirectory;
use App\Modules\Crm\Domain\ContactTagId;
use Illuminate\Foundation\Http\FormRequest;

final class ListPeopleRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tag' => ['sometimes', 'nullable', 'string', 'ulid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PagePeopleDirectory::MAX_PER_PAGE],
        ];
    }

    public function text(): ?string
    {
        $value = $this->input('q');

        return is_string($value) ? $value : null;
    }

    public function tag(): ?ContactTagId
    {
        $value = $this->input('tag');

        return is_string($value) && $value !== '' ? ContactTagId::fromString($value) : null;
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
