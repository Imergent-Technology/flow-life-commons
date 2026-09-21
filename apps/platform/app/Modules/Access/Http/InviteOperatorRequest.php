<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use Illuminate\Foundation\Http\FormRequest;

final class InviteOperatorRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Shape only. Whether the address and the name are acceptable is Identity's decision, and a key in the
            // catalog is Access's.
            'email' => ['required', 'string', 'max:254'],
            'display_name' => ['required', 'string', 'max:200'],
            'initial_assignments' => ['sometimes', 'array', 'max:10'],
            'initial_assignments.*' => ['string', 'max:64'],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function displayName(): string
    {
        return $this->string('display_name')->toString();
    }

    /** @return list<string> */
    public function assignments(): array
    {
        $given = $this->input('initial_assignments', []);

        return is_array($given) ? array_values(array_filter($given, is_string(...))) : [];
    }
}
