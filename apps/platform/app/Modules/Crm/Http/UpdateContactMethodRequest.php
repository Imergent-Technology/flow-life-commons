<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateContactMethodRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // The kind of a method does not change: an email is not turned into a phone number. Remove and add instead.
            'value' => ['sometimes', 'required', 'string', 'max:255'],
            'label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(['value', 'label', 'is_primary'])) {
                $validator->errors()->add('value', 'Send at least one of value, label or is_primary.');
            }
        }];
    }

    /** @return array{value?: string, label?: ?string, is_primary?: bool} */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('value')) {
            $changes['value'] = $this->string('value')->toString();
        }
        if ($this->has('label')) {
            $label = $this->input('label');
            $changes['label'] = is_string($label) ? $label : null;
        }
        if ($this->has('is_primary')) {
            $changes['is_primary'] = $this->boolean('is_primary');
        }

        return $changes;
    }
}
