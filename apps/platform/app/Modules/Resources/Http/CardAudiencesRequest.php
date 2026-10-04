<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `mode` is `inherit` (the Pack's audiences) or `narrowed` with the subset to narrow to. The use case enforces that it is a subset. */
final class CardAudiencesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(AudienceMode::class)],
            'audiences' => ['sometimes', 'array', 'max:'.count(Audience::cases())],
            'audiences.*' => ['required', Rule::enum(Audience::class)],
        ];
    }

    public function mode(): AudienceMode
    {
        return AudienceMode::from($this->string('mode')->toString());
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
