<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use Illuminate\Foundation\Http\FormRequest;

/** The whole-sibling-list contract of every reorder: the COMPLETE ordered list of ids. The use case decides whether it is the set that is there. */
final class OrderRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['ids' => ['present', 'array', 'max:1000'], 'ids.*' => ['required', 'ulid']];
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = [];
        foreach ((array) $this->input('ids', []) as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
