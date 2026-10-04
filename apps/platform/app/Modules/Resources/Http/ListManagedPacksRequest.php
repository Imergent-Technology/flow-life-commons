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
        // A blank filter is no filter (see BlankInput).
        $category = BlankInput::optional($this->input('category'));
        $audience = BlankInput::optional($this->input('audience'));
        $state = BlankInput::optional($this->input('state'));
        $type = BlankInput::optional($this->input('card_type'));
        $text = BlankInput::optional($this->input('q'));

        return new ManagedPackFilter(
            $category === null ? null : CategoryId::fromString($category),
            $audience === null ? null : Audience::from($audience),
            $state === null ? null : PublicationState::from($state),
            $type === null ? null : CardType::from($type),
            $text,
        );
    }

    public function page(): int
    {
        return BlankInput::isBlank($this->input('page')) ? 1 : $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return BlankInput::isBlank($this->input('per_page')) ? 25 : $this->integer('per_page', 25);
    }
}
