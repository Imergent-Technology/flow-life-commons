<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\Audience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The audience to preview as. Only a supported audience: there is no free-form audience string (ADR 0037, decision 49). */
final class PreviewPackRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['audience' => ['required', Rule::enum(Audience::class)]];
    }

    public function audience(): Audience
    {
        return Audience::from($this->string('audience')->toString());
    }
}
