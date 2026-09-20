<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

/** Fresh proof: the current password AND a second factor. */
final class SecurityProofRequest extends SecondFactorRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Required and bounded, nothing more: whether it is right is the use case's decision.
            'current_password' => ['required', 'string', 'max:1024'],
            ...$this->factorRules(),
        ];
    }

    public function currentPassword(): string
    {
        return $this->string('current_password')->toString();
    }
}
