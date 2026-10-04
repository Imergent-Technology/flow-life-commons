<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;

/** A Category's name. Shape only (present, a string, not absurdly large); the domain owns the rules and answers `invalid_resource_input`. */
final class CategoryNameRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:'.(ResourceText::NAME_MAX * 4)]];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }
}
