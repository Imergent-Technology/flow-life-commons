<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\Audience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A Pack's complete audience set. May be empty for a Draft; the use case refuses an empty set for a Published Pack. */
final class AudiencesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['audiences' => ['present', 'array', 'max:'.count(Audience::cases())], 'audiences.*' => ['required', Rule::enum(Audience::class)]];
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        $audiences = [];
        foreach ((array) $this->input('audiences', []) as $value) {
            if (is_string($value)) {
                $audiences[] = Audience::from($value);
            }
        }

        return $audiences;
    }
}
