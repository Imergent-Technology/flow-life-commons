<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterMemberRequest extends FormRequest
{
    use DeclaresMembershipTerm;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // 255, matching Identity's own Person rule (its Domain constant is not reachable from here: a
            // module may not depend on another module's Domain). Identity re-validates and is authoritative.
            'display_name' => ['required', 'string', 'max:255'],
            ...$this->termRules(),
        ];
    }

    public function displayName(): string
    {
        return $this->string('display_name')->toString();
    }
}
