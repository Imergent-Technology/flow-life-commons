<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SecondFactorProof;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request that carries a second factor: an authenticator code OR a recovery code, exactly one. Shape
 * only: whether it is right is the use case's decision. Neither is ever logged or echoed back.
 */
abstract class SecondFactorRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    protected function factorRules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:32', 'required_without:recovery_code', 'prohibits:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64', 'required_without:code', 'prohibits:code'],
        ];
    }

    public function proof(): SecondFactorProof
    {
        $recovery = $this->string('recovery_code')->toString();

        return $recovery !== '' ? SecondFactorProof::recoveryCode($recovery) : SecondFactorProof::totp($this->string('code')->toString());
    }
}
