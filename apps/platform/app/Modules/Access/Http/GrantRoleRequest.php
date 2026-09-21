<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use Illuminate\Foundation\Http\FormRequest;

final class GrantRoleRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // A key, and nothing else: the client can never name a capability. Whether it is in the catalog is Access's call.
        return ['key' => ['required', 'string', 'max:64']];
    }

    public function key(): string
    {
        return $this->string('key')->toString();
    }
}
