<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

final class ChangePasswordRequest extends NewPasswordRequest
{
    /** @return array<string, list<string|\Closure>> */
    public function rules(): array
    {
        return [
            // Required and bounded, nothing more: whether it is right is the use case's decision.
            'current_password' => ['required', 'string', 'max:1024'],
            ...$this->newPasswordRules(),
        ];
    }

    public function currentPassword(): string
    {
        return $this->string('current_password')->toString();
    }
}
