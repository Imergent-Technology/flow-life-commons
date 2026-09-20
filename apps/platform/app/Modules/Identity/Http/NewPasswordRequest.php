<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Domain\PlainPassword;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape shared by every request that sets a new password: `password` and its confirmation.
 *
 * Only the SHAPE is checked here (present, bounded). Whether the password is acceptable is the
 * password policy's decision, made by the use case, once, for every path.
 *
 * The two fields are compared after normalisation, as they will be hashed: a confirmation typed with
 * a precomposed "é" matches a password typed with "e" and a combining accent, because they are the
 * same password.
 *
 * A password must reach the policy exactly as typed, with spaces at either end intact. Laravel's
 * TrimStrings middleware leaves `password`, `password_confirmation` and `current_password` alone by
 * default; that is what this relies on, and AcceptInvitationTest pins it so an upgrade that changed
 * it would be noticed rather than silently altering passwords.
 */
abstract class NewPasswordRequest extends FormRequest
{
    /** @return array<string, list<string|Closure>> */
    protected function newPasswordRules(): array
    {
        return [
            // Bounded so an enormous body cannot be used to burn hashing or network time.
            'password' => ['required', 'string', 'max:1024'],
            'password_confirmation' => [
                'required', 'string', 'max:1024',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! PlainPassword::fromInput($this->password())->equals(PlainPassword::fromInput($value))) {
                        $fail('The password confirmation does not match.');
                    }
                },
            ],
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
