<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * The result of finishing a sign-in with a second factor. Failure carries only a reason class; the
 * transport decides what the caller is told (a wrong code is a validation error, everything else ends the
 * pending sign-in).
 */
final readonly class SecondFactorOutcome
{
    /**
     * @param  list<string>  $recoveryCodes  the new codes, set only when enrolment just completed: shown ONCE
     */
    private function __construct(
        public ?CurrentAccount $account,
        public ?SecondFactorMethod $method,
        public ?SecondFactorFailure $failure,
        public array $recoveryCodes,
    ) {}

    public static function signedIn(CurrentAccount $account, SecondFactorMethod $method): self
    {
        return new self($account, $method, null, []);
    }

    /** @param  list<string>  $recoveryCodes */
    public static function enrolled(CurrentAccount $account, array $recoveryCodes): self
    {
        return new self($account, SecondFactorMethod::Enrollment, null, $recoveryCodes);
    }

    public static function refused(SecondFactorFailure $failure): self
    {
        return new self(null, null, $failure, []);
    }

    public function succeeded(): bool
    {
        return $this->account !== null;
    }
}
