<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // Shape only. Whether the address is acceptable is EmailAddress's decision, as at login.
        return ['email' => ['required', 'string', 'max:254']];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }
}
