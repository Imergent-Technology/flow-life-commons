<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

final class AcceptInvitationRequest extends NewPasswordRequest
{
    /** @return array<string, list<string|\Closure>> */
    public function rules(): array
    {
        return [
            // Shape only. Whether it is a usable invitation is the use case's decision, and it gives
            // one answer for every way it can be unusable.
            'token' => ['required', 'string', 'max:256'],
            ...$this->newPasswordRules(),
        ];
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }
}
