<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Domain\ContactTagId;
use Illuminate\Foundation\Http\FormRequest;

final class SetPersonTagsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // `present`, not `required`: an empty list is a real answer ("no tags").
            'tag_ids' => ['present', 'array', 'max:200'],
            'tag_ids.*' => ['string', 'ulid'],
        ];
    }

    /** @return list<ContactTagId> */
    public function tagIds(): array
    {
        $ids = [];
        $list = $this->input('tag_ids', []);
        foreach (is_array($list) ? $list : [] as $value) {
            if (is_string($value)) {
                $ids[] = ContactTagId::fromString($value);
            }
        }

        return $ids;
    }
}
