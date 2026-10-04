<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\ExternalUri;
use App\Modules\Resources\Domain\ResourceText;
use App\Modules\Resources\Domain\SummaryMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Card's authored fields, and the revision the edit is based on, which is REQUIRED (ADR 0037, decision 56). Only the keys sent
 * change. `summary` writes a custom summary; `summary_mode: derived` returns to the automatic one. `uri` may be null to clear it
 * (an external link refuses that). There is no `type` field: a Card's Type never changes.
 */
final class UpdateCardRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:'.(ResourceText::TITLE_MAX * 4)],
            'content' => ['sometimes', 'array'],
            'uri' => ['sometimes', 'nullable', 'string', 'max:'.(ExternalUri::MAX_LENGTH * 2)],
            'summary' => ['sometimes', 'string', 'max:'.(ResourceText::SUMMARY_MAX * 4)],
            'summary_mode' => ['sometimes', Rule::enum(SummaryMode::class)],
        ];
    }

    public function revision(): int
    {
        return $this->integer('revision');
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['title', 'content', 'uri', 'summary', 'summary_mode']));
    }
}
