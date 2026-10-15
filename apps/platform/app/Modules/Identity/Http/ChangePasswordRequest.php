<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SecondFactorProof;

/**
 * Changing the signed-in Account's password: the current password, the new one, and, **when the Account has an
 * authenticator**, a second factor (an authenticator `code` OR a `recovery_code`, never both). Whether the Account
 * has one is the server's to decide, so the factor is optional HERE and required by the use case when it must
 * be; an Account without one may send neither.
 */
final class ChangePasswordRequest extends NewPasswordRequest
{
    /** @return array<string, list<string|\Closure>> */
    public function rules(): array
    {
        return [
            // Required and bounded, nothing more: whether it is right is the use case's decision.
            'current_password' => ['required', 'string', 'max:1024'],
            'code' => ['nullable', 'string', 'max:32', 'prohibits:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64', 'prohibits:code'],
            ...$this->newPasswordRules(),
        ];
    }

    public function currentPassword(): string
    {
        return $this->string('current_password')->toString();
    }

    /** The second factor the caller presented, if any. Shape only; the use case says whether one is needed. */
    public function proof(): ?SecondFactorProof
    {
        $recovery = $this->string('recovery_code')->toString();
        $code = $this->string('code')->toString();

        return match (true) {
            $recovery !== '' => SecondFactorProof::recoveryCode($recovery),
            $code !== '' => SecondFactorProof::totp($code),
            default => null,
        };
    }
}
