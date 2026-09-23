<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use Illuminate\Foundation\Http\FormRequest;

final class GrantMembershipRequest extends FormRequest
{
    use DeclaresMembershipTerm;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->termRules();
    }
}
