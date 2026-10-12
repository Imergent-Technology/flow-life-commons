<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\DeliveredCard;
use App\Modules\Resources\Application\DeliveredCategory;
use App\Modules\Resources\Application\DeliveredPack;

/**
 * The delivery shape (ADR 0037, decision 50; ADR 0038, R11, R14): what a reader receives under policy C, and what preview shows an
 * editor of the same. No state, publication, audience list or mode, revision, stored position, provenance or hidden Card id exists in it;
 * a Card's place is its index among the Cards THIS reader can see. A File Card's file is described, never located: the API path that
 * serves it to this caller, on the file route the caller names (`my-resources` for a reader, the management route for preview).
 */
final readonly class DeliveryPresenter
{
    /**
     * @param  list<DeliveredCategory>  $library
     * @return array<string, mixed>
     */
    public function library(array $library): array
    {
        return ['data' => array_map(static fn (DeliveredCategory $entry): array => [
            'category' => ResourceShapes::categoryRef($entry->category),
            'packs' => array_map(static fn ($listed): array => [
                'id' => $listed->pack->id->value,
                'title' => $listed->pack->title,
                'summary' => $listed->pack->summary,
                'is_series' => $listed->pack->isSeries,
                'card_count' => $listed->visibleCardCount,
            ], $entry->packs),
        ], $library)];
    }

    /**
     * @param  string  $fileRoute  the named route that serves a Card's file to this caller
     * @return array<string, mixed>
     */
    public function pack(DeliveredPack $delivered, string $fileRoute): array
    {
        $pack = $delivered->pack;

        return [
            'id' => $pack->id->value,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'is_series' => $pack->isSeries,
            'category' => ResourceShapes::categoryRef($delivered->category),
            'card_count' => count($delivered->cards),
            'cards' => array_map(static fn (DeliveredCard $entry): array => [
                'id' => $entry->card->id->value,
                'index' => $entry->index,
                'type' => $entry->card->type->value,
                'title' => $entry->card->title,
                'summary' => $entry->card->summary->text,
                'uri' => $entry->card->externalUri,
                'file' => $entry->card->asset === null ? null : [
                    ...ResourceShapes::fileSummary($entry->card->asset),
                    'download_path' => ResourceShapes::path($fileRoute, $entry->card->packId, $entry->card->id),
                ],
                'content' => ResourceShapes::content($entry->card),
            ], $delivered->cards),
        ];
    }
}
