<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The only inputs audience delivery reads (ADR 0038, R14): one Category and search text. NOTHING here selects a Person, an audience, a
 * mode or a publication state, so no client value can widen what the signed-in Person is served: that comes from their own current
 * relationships alone. (Any other query parameter is simply never read.)
 */
final class MyResourcesRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['category' => ['sometimes', 'ulid'], 'q' => ['sometimes', 'string', 'max:'.ResourceText::TITLE_MAX]];
    }

    public function category(): ?CategoryId
    {
        $category = $this->input('category');

        return is_string($category) ? CategoryId::fromString($category) : null;
    }

    public function text(): ?string
    {
        $q = $this->input('q');

        return is_string($q) && trim($q) !== '' ? $q : null;
    }
}
