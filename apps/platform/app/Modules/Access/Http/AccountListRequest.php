<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Identity\Application\AccountSearch;
use Illuminate\Foundation\Http\FormRequest;

final class AccountListRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AccountSearch::MAX_PER_PAGE],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:invited,active,disabled'],
        ];
    }

    public function search(): AccountSearch
    {
        $query = $this->input('q');
        $status = $this->input('status');

        return new AccountSearch(
            $this->integer('page', 1), $this->integer('per_page', 25),
            is_string($query) && $query !== '' ? $query : null,
            is_string($status) && $status !== '' ? $status : null,
        );
    }
}
