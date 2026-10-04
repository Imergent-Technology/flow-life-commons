<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A Pack's authored fields, and the revision the edit is based on, which is REQUIRED (ADR 0037, decision 56): there is no
 * last-write-wins. Only the keys sent change; `summary` and `category_id` may be null to clear them.
 */
final class UpdatePackRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:'.(ResourceText::TITLE_MAX * 4)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.(ResourceText::SUMMARY_MAX * 4)],
            'is_series' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }

    public function revision(): int
    {
        return $this->integer('revision');
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $changes = array_intersect_key($this->validated(), array_flip(['title', 'summary', 'is_series', 'category_id']));
        if (array_key_exists('category_id', $changes)) {
            $changes['category_id'] = BlankInput::optional($changes['category_id']); // "" clears the Category, exactly as null does
        }

        return $changes;
    }
}
