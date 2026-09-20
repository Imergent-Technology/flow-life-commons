<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\InvalidRecoveryCode;
use App\Modules\Identity\Domain\RecoveryCode;

/**
 * What a person presents as their second factor: a code from their authenticator app, or one of their
 * recovery codes. Held only for the duration of one check; never stored, logged or audited.
 *
 * Text that is not the shape of a code is not an error to report: it is simply a code that cannot be
 * right, and is answered exactly as a wrong one is.
 */
final readonly class SecondFactorProof
{
    private function __construct(
        public SecondFactorMethod $method,
        private string $value,
    ) {}

    /** Spaces are welcome ("123 456"); anything but six digits afterwards cannot be a code. */
    public static function totp(#[\SensitiveParameter] string $code): self
    {
        return new self(SecondFactorMethod::Totp, (string) preg_replace('/\s+/', '', $code));
    }

    public static function recoveryCode(#[\SensitiveParameter] string $code): self
    {
        return new self(SecondFactorMethod::RecoveryCode, $code);
    }

    /** The digits, if this is well-formed as a TOTP code. */
    public function totpDigits(): ?string
    {
        return $this->method === SecondFactorMethod::Totp && preg_match('/^\d{6}$/D', $this->value) === 1 ? $this->value : null;
    }

    /** The recovery code, if this is well-formed as one. */
    public function asRecoveryCode(): ?RecoveryCode
    {
        if ($this->method !== SecondFactorMethod::RecoveryCode) {
            return null;
        }

        try {
            return RecoveryCode::fromPresented($this->value);
        } catch (InvalidRecoveryCode) {
            return null;
        }
    }
}
