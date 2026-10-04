<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;

/** A new Draft Pack. Shape only; the domain owns the rules. There is no audience or state field: a Pack starts as an empty Draft. */
final class CreatePackRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(ResourceText::TITLE_MAX * 4)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.(ResourceText::SUMMARY_MAX * 4)],
            'is_series' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    public function summary(): ?string
    {
        $summary = $this->input('summary');

        return is_string($summary) ? $summary : null;
    }

    public function isSeries(): bool
    {
        return $this->boolean('is_series');
    }

    public function category(): ?string
    {
        $category = $this->input('category_id');

        return is_string($category) ? $category : null;
    }
}
