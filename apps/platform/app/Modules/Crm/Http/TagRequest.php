<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use Illuminate\Foundation\Http\FormRequest;

final class TagRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:64']];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }
}
