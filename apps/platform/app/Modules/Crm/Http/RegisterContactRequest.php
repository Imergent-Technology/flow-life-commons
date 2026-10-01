<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\NewContactMethod;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterContactRequest extends FormRequest
{
    use DeclaresContactMethods;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // 255, matching Identity's own Person rule; Identity re-validates and is authoritative.
            'display_name' => ['required', 'string', 'max:255'],
            'how_we_know' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'affiliation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_methods' => ['sometimes', 'array', 'max:20'],
            ...$this->methodRules('contact_methods.*.'),
            'confirm_distinct' => ['sometimes', 'boolean'],
        ];
    }

    public function displayName(): string
    {
        return $this->string('display_name')->toString();
    }

    public function howWeKnow(): ?string
    {
        $value = $this->input('how_we_know');

        return is_string($value) ? $value : null;
    }

    public function affiliation(): ?string
    {
        $value = $this->input('affiliation');

        return is_string($value) ? $value : null;
    }

    /** @return list<NewContactMethod> */
    public function contactMethods(): array
    {
        $list = $this->input('contact_methods', []);
        $methods = [];
        foreach (is_array($list) ? $list : [] as $entry) {
            if (is_array($entry)) {
                $methods[] = $this->methodFrom($entry);
            }
        }

        return $methods;
    }

    public function confirmDistinct(): bool
    {
        return $this->boolean('confirm_distinct');
    }
}
