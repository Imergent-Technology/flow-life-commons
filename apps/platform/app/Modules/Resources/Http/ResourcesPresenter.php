<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\CategoryView;
use App\Modules\Resources\Application\DeliveredCard;
use App\Modules\Resources\Application\DeliveredPack;
use App\Modules\Resources\Application\LibraryCategory;
use App\Modules\Resources\Application\ManagedCardOutlineView;
use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackPage;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PackPreview;
use App\Modules\Resources\Application\ResourcePerson;
use App\Modules\Resources\Domain\CardAudience;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\ResourceAsset;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the Resources API (openapi/openapi.yaml). Management and delivery differ DELIBERATELY: management shows state,
 * revision, audiences, stored position and provenance (a Person's id and display name, never an Account, a login email, a role,
 * a capability, MFA or Membership state); delivery shows only what a viewer may have, with a Card's place being its index among the
 * Cards that viewer can see. A document is emitted as the object it is, never as HTML.
 *
 * A File Card's file is described, never located: its name, media type and size, and the API path that serves it to THIS caller
 * (the management route for management, the library route for delivery). No storage key, disk, directory or asset id is ever in a
 * response; management alone also sees the digest, the uploader and whether the store holds the file now.
 */
final readonly class ResourcesPresenter
{
    /**
     * @param  list<CategoryView>  $views
     * @return array<string, mixed>
     */
    public function categories(array $views): array
    {
        return ['data' => array_map($this->category(...), $views)];
    }

    /** @return array<string, mixed> */
    public function category(CategoryView $view): array
    {
        $category = $view->category;

        return [
            'id' => $category->id->value,
            'name' => $category->name,
            'position' => $category->position,
            'pack_count' => $view->packCount,
            'created_by' => $this->person($view->createdBy),
            'updated_by' => $this->person($view->updatedBy),
            'created_at' => $this->instant($category->provenance->createdAt),
            'updated_at' => $this->instant($category->provenance->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public function packs(ManagedPackPage $page): array
    {
        return [
            'data' => array_map($this->pack(...), $page->packs),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
        ];
    }

    /**
     * @param  list<ManagedPackView>  $views
     * @return array<string, mixed>
     */
    public function packList(array $views): array
    {
        return ['data' => array_map($this->pack(...), $views)];
    }

    /** @return array<string, mixed> */
    public function pack(ManagedPackView $view): array
    {
        $pack = $view->pack;
        $out = [
            'id' => $pack->id->value,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'is_series' => $pack->isSeries,
            'category' => $this->categoryRef($view->category),
            'position' => $pack->position,
            'state' => $pack->state->value,
            'revision' => $pack->revision,
            'audiences' => $pack->audiences->values(),
            'card_count' => $view->cardCount,
            'published_card_count' => $view->publishedCardCount,
            'created_by' => $this->person($view->createdBy),
            'updated_by' => $this->person($view->updatedBy),
            'created_at' => $this->instant($pack->provenance->createdAt),
            'updated_at' => $this->instant($pack->provenance->updatedAt),
        ];
        if ($view->cards !== null) {
            $out['cards'] = array_map($this->outline(...), $view->cards);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function card(ManagedCardView $view): array
    {
        $card = $view->card;

        $out = [
            ...$this->outline(new ManagedCardOutlineView($card->outline(), $view->createdBy, $view->updatedBy)),
            'content' => ['format' => $card->content->format, 'version' => $card->content->version, 'document' => $card->content->decoded()],
        ];
        if ($card->asset !== null) {
            $out['file'] = [
                ...$this->fileSummary($card->asset),
                'sha256' => $card->asset->sha256,
                'uploaded_by' => $view->uploadedBy === null ? null : $this->person($view->uploadedBy),
                'uploaded_at' => $this->instant($card->asset->uploadedAt),
                'available' => $view->fileAvailable === true,
                'download_path' => self::path('api.v1.admin.resources.cards.file', $card->packId, $card->id),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function outline(ManagedCardOutlineView $view): array
    {
        $card = $view->card;

        return [
            'id' => $card->id->value,
            'pack_id' => $card->packId->value,
            'position' => $card->position,
            'type' => $card->type->value,
            'title' => $card->title,
            'summary_mode' => $card->summary->mode->value,
            'summary' => $card->summary->text,
            'uri' => $card->externalUri,
            'file' => $card->asset === null ? null : $this->fileSummary($card->asset),
            ...$this->audience($card->audience),
            'state' => $card->state->value,
            'revision' => $card->revision,
            'created_by' => $this->person($view->createdBy),
            'updated_by' => $this->person($view->updatedBy),
            'created_at' => $this->instant($card->provenance->createdAt),
            'updated_at' => $this->instant($card->provenance->updatedAt),
        ];
    }

    /**
     * @param  list<LibraryCategory>  $library
     * @return array<string, mixed>
     */
    public function library(array $library): array
    {
        return ['data' => array_map(fn (LibraryCategory $entry): array => [
            'category' => $this->categoryRef($entry->category),
            'packs' => array_map(static fn ($listed): array => [
                'id' => $listed->pack->id->value,
                'title' => $listed->pack->title,
                'summary' => $listed->pack->summary,
                'is_series' => $listed->pack->isSeries,
                'card_count' => $listed->visibleCardCount,
            ], $entry->packs),
        ], $library)];
    }

    /** @return array<string, mixed> */
    public function delivered(DeliveredPack $delivered): array
    {
        $pack = $delivered->pack;

        return [
            'id' => $pack->id->value,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'is_series' => $pack->isSeries,
            'category' => $this->categoryRef($delivered->category),
            'card_count' => count($delivered->cards),
            'cards' => array_map(fn (DeliveredCard $entry): array => [
                'id' => $entry->card->id->value,
                'index' => $entry->index,
                'type' => $entry->card->type->value,
                'title' => $entry->card->title,
                'summary' => $entry->card->summary->text,
                'uri' => $entry->card->externalUri,
                'file' => $entry->card->asset === null ? null : [
                    ...$this->fileSummary($entry->card->asset),
                    'download_path' => self::path('api.v1.admin.resource-library.cards.file', $entry->card->packId, $entry->card->id),
                ],
                'content' => ['format' => $entry->card->content->format, 'version' => $entry->card->content->version, 'document' => $entry->card->content->decoded()],
            ], $delivered->cards),
        ];
    }

    /** @return array<string, mixed> */
    public function preview(PackPreview $preview): array
    {
        return [
            'audience' => $preview->audience->value,
            'pack_state' => $preview->packState->value,
            'audience_targeted' => $preview->audienceTargeted,
            'visible' => $preview->visible(),
            'pack' => $preview->pack === null ? null : $this->delivered($preview->pack),
        ];
    }

    /** @return array{name: string, media_type: string, byte_size: int} */
    private function fileSummary(ResourceAsset $asset): array
    {
        return ['name' => $asset->originalFilename, 'media_type' => $asset->mediaType, 'byte_size' => $asset->byteSize];
    }

    /** The API path (no host) of a Card's file on one of the two file routes. */
    private static function path(string $route, PackId $pack, CardId $card): string
    {
        return route($route, ['pack' => $pack->value, 'card' => $card->value], false);
    }

    /** @return array{audience_mode: string, audiences: list<string>} */
    private function audience(CardAudience $audience): array
    {
        return ['audience_mode' => $audience->mode->value, 'audiences' => $audience->set->values()];
    }

    /** @return array{id: string, name: string}|null */
    private function categoryRef(?Category $category): ?array
    {
        return $category === null ? null : ['id' => $category->id->value, 'name' => $category->name];
    }

    /** @return array{id: string, display_name: string|null} */
    private function person(ResourcePerson $person): array
    {
        return ['id' => $person->id->value, 'display_name' => $person->displayName];
    }

    private function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
