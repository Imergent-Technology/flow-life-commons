<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\NewContactMethod;
use Illuminate\Foundation\Http\FormRequest;

final class AddContactMethodRequest extends FormRequest
{
    use DeclaresContactMethods;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->methodRules();
    }

    /** Not `method()`: that is the HTTP verb on every Laravel request. */
    public function newMethod(): NewContactMethod
    {
        return $this->methodFrom($this->all());
    }
}
