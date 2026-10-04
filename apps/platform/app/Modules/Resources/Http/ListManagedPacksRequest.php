<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\PageManagedPacks;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\PublicationState;
use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListManagedPacksRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'ulid'],
            'audience' => ['sometimes', Rule::enum(Audience::class)],
            'state' => ['sometimes', Rule::enum(PublicationState::class)],
            'card_type' => ['sometimes', Rule::enum(CardType::class)],
            'q' => ['sometimes', 'string', 'max:'.ResourceText::TITLE_MAX],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PageManagedPacks::MAX_PER_PAGE],
        ];
    }

    public function filter(): ManagedPackFilter
    {
        $category = $this->input('category');
        $audience = $this->input('audience');
        $state = $this->input('state');
        $type = $this->input('card_type');
        $text = $this->input('q');

        return new ManagedPackFilter(
            is_string($category) ? CategoryId::fromString($category) : null,
            is_string($audience) ? Audience::from($audience) : null,
            is_string($state) ? PublicationState::from($state) : null,
            is_string($type) ? CardType::from($type) : null,
            is_string($text) && trim($text) !== '' ? $text : null,
        );
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
