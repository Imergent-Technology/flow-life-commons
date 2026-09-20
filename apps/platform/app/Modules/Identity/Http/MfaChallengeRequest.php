<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

final class MfaChallengeRequest extends SecondFactorRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->factorRules();
    }
}
