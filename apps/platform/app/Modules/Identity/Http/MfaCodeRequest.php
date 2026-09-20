<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SecondFactorProof;
use Illuminate\Foundation\Http\FormRequest;

/** An authenticator code, and nothing else: confirming a new authenticator is proved with a code from it. */
final class MfaCodeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:32']];
    }

    public function proof(): SecondFactorProof
    {
        return SecondFactorProof::totp($this->string('code')->toString());
    }
}
