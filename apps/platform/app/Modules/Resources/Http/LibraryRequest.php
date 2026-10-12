<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\LibraryFilter;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PublicationFilter;
use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The privileged library's inputs (ADR 0038, R8): Category, search text, publication and audience. All four only NARROW what
 * `resources.view` already reads; an unknown value is a 422, and none of them is an authority. The audience here is a FILTER over
 * the Packs targeted at it, never the set a reader is served as: that is policy C's, taken from the signed-in Person alone.
 */
final class LibraryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'ulid'],
            'q' => ['sometimes', 'string', 'max:'.ResourceText::TITLE_MAX],
            'publication' => ['sometimes', Rule::enum(PublicationFilter::class)],
            'audience' => ['sometimes', Rule::enum(Audience::class)],
        ];
    }

    public function filter(): LibraryFilter
    {
        $category = $this->input('category');
        $text = $this->input('q');
        $publication = $this->input('publication');
        $audience = $this->input('audience');

        return new LibraryFilter(
            is_string($category) ? CategoryId::fromString($category) : null,
            is_string($text) && trim($text) !== '' ? $text : null,
            is_string($publication) ? PublicationFilter::from($publication) : null,
            is_string($audience) ? Audience::from($audience) : null,
        );
    }
}
