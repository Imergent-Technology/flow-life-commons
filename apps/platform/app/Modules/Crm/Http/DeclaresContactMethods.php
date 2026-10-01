<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Domain\ContactMethodKind;

/** The request shape of ONE contact method, shared by "add a method" and the list inside "register a contact". */
trait DeclaresContactMethods
{
    /**
     * @param  string  $prefix  '' for a method at the top level, 'contact_methods.*.' inside a list
     * @return array<string, list<string>>
     */
    protected function methodRules(string $prefix = ''): array
    {
        return [
            $prefix.'kind' => ['required', 'string', 'in:email,phone'],
            $prefix.'value' => ['required', 'string', 'max:255'],
            $prefix.'label' => ['sometimes', 'nullable', 'string', 'max:64'],
            $prefix.'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<mixed>  $data */
    protected function methodFrom(array $data): NewContactMethod
    {
        $kind = $data['kind'] ?? null;
        $value = $data['value'] ?? null;
        $label = $data['label'] ?? null;

        return new NewContactMethod(
            ContactMethodKind::from(is_string($kind) ? $kind : ''),
            is_string($value) ? $value : '',
            is_string($label) ? $label : null,
            ($data['is_primary'] ?? false) === true,
        );
    }
}
