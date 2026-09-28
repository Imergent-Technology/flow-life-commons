<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use Illuminate\Foundation\Http\FormRequest;

final class InviteExistingPersonRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Shape only. Whether the address is acceptable is Identity's decision.
            'email' => ['required', 'string', 'max:254'],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }
}
