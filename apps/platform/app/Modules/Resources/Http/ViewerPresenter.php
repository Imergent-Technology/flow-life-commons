<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ViewerCard;
use App\Modules\Resources\Application\ViewerCategory;
use App\Modules\Resources\Application\ViewerPack;
use App\Modules\Resources\Application\ViewerPackSummary;

/**
 * The privileged viewer's shape (ADR 0038, R6-R7): delivery's fields, plus what `resources.view` is allowed to know. Publication state
 * and audiences are DATA here (a Draft is marked as one, a Card says whom it effectively targets and whether it narrows), together
 * with when it was last edited and by whom (a display name). It carries no revision, stored position or creator, and offers no action:
 * it is a read model, not the management API. Its file paths are the library's own file route, so it cannot be mistaken for delivery.
 */
final readonly class ViewerPresenter
{
    private const string FILE_ROUTE = 'api.v1.admin.resource-library.cards.file';

    /**
     * @param  list<ViewerCategory>  $library
     * @return array<string, mixed>
     */
    public function library(array $library): array
    {
        return ['data' => array_map(fn (ViewerCategory $group): array => [
            'category' => ResourceShapes::categoryRef($group->category),
            'packs' => array_map($this->listed(...), $group->packs),
        ], $library)];
    }

    /** @return array<string, mixed> */
    public function pack(ViewerPack $viewed): array
    {
        $pack = $viewed->pack;

        return [
            'id' => $pack->id->value,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'is_series' => $pack->isSeries,
            'category' => ResourceShapes::categoryRef($viewed->category),
            'state' => $pack->state->value,
            'audiences' => $pack->audiences->values(),
            'card_count' => count($viewed->cards),
            'draft_card_count' => $viewed->draftCardCount,
            'updated_at' => ResourceShapes::instant($pack->provenance->updatedAt),
            'updated_by' => ['display_name' => $viewed->editor],
            'cards' => array_map($this->card(...), $viewed->cards),
        ];
    }

    /** @return array<string, mixed> */
    private function listed(ViewerPackSummary $listed): array
    {
        $pack = $listed->pack;

        return [
            'id' => $pack->id->value,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'is_series' => $pack->isSeries,
            'state' => $pack->state->value,
            'audiences' => $pack->audiences->values(),
            'card_count' => $listed->cardCount,
            'draft_card_count' => $listed->draftCardCount,
            'updated_at' => ResourceShapes::instant($pack->provenance->updatedAt),
            'updated_by' => ['display_name' => $listed->editor],
        ];
    }

    /** @return array<string, mixed> */
    private function card(ViewerCard $viewed): array
    {
        $card = $viewed->card;

        return [
            'id' => $card->id->value,
            'index' => $viewed->index,
            'type' => $card->type->value,
            'title' => $card->title,
            'summary' => $card->summary->text,
            'uri' => $card->externalUri,
            'file' => $card->asset === null ? null : [
                ...ResourceShapes::fileSummary($card->asset),
                'download_path' => ResourceShapes::path(self::FILE_ROUTE, $card->packId, $card->id),
            ],
            'content' => ResourceShapes::content($card),
            'state' => $card->state->value,
            'audience_mode' => $card->audience->mode->value,
            'audiences' => $viewed->audiences->values(),
            'updated_at' => ResourceShapes::instant($card->provenance->updatedAt),
            'updated_by' => ['display_name' => $viewed->editor],
        ];
    }
}
