<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;

/** The library's only inputs: one Category, and search text. Audience is not an input: a viewer receives their own projection. */
final class LibraryRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['category' => ['sometimes', 'ulid'], 'q' => ['sometimes', 'string', 'max:'.ResourceText::TITLE_MAX]];
    }

    public function category(): ?string
    {
        $category = $this->input('category');

        return is_string($category) ? $category : null;
    }

    public function text(): ?string
    {
        $q = $this->input('q');

        return is_string($q) && trim($q) !== '' ? $q : null;
    }
}
