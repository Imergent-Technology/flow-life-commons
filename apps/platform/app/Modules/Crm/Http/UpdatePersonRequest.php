<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\ProfileChanges;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Any subset of the Person's name and the two profile fields; a field that is sent is changed, one that is not is left alone. */
final class UpdatePersonRequest extends FormRequest
{
    private const array FIELDS = ['display_name', 'how_we_know', 'affiliation'];

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // A name is never cleared; the profile fields can be, by sending null or blank.
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'how_we_know' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'affiliation' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(self::FIELDS)) {
                $validator->errors()->add('display_name', 'Send at least one of display_name, how_we_know or affiliation.');
            }
        }];
    }

    public function displayName(): ?string
    {
        return $this->has('display_name') ? $this->string('display_name')->toString() : null;
    }

    public function changes(): ProfileChanges
    {
        $fields = [];
        foreach (['how_we_know', 'affiliation'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $fields[$field] = is_string($value) ? $value : null;
            }
        }

        return new ProfileChanges($fields);
    }
}
