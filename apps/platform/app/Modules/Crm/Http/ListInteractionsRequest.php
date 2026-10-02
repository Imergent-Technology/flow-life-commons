<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\ListInteractions;
use Illuminate\Foundation\Http\FormRequest;

final class ListInteractionsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListInteractions::MAX_PER_PAGE],
        ];
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
