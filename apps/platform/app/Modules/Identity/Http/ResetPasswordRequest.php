<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

final class ResetPasswordRequest extends NewPasswordRequest
{
    /** @return array<string, list<string|\Closure>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:254'],
            // Shape only: whether it is this address's live token is the use case's decision, and it
            // gives one answer for every way it can be wrong.
            'token' => ['required', 'string', 'max:256'],
            ...$this->newPasswordRules(),
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }
}
