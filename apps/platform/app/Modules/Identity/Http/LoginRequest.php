<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Shape only. Whether the address is acceptable is EmailAddress's decision.
            'email' => ['required', 'string', 'max:254'],
            // Bounded so an enormous body cannot be used to burn hashing time.
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
