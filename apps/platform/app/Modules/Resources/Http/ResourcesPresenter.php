<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\CategoryView;
use App\Modules\Resources\Application\ManagedCardOutlineView;
use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackPage;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PackPreview;
use App\Modules\Resources\Application\ResourcePerson;
use App\Modules\Resources\Domain\CardAudience;

/**
 * The JSON shapes of POLICY B, management (openapi/openapi.yaml): state, revision, audiences, stored position and provenance (a Person's
 * id and display name, never an Account, a login email, a role, a capability, MFA or Membership state), plus preview, which shows the
 * delivery shape (`DeliveryPresenter`) with file paths on the MANAGEMENT file route. The privileged viewer's shape is `ViewerPresenter`
 * and the reader's is `DeliveryPresenter`: three policies, three presenters, none of them able to answer for another. A document is
 * emitted as the object it is, never as HTML.
 *
 * A File Card's file is described, never located: its name, media type and size, and the API path that serves it to THIS caller. No
 * storage key, disk, directory or asset id is ever in a response; management alone also sees the digest, the uploader and whether the
 * store holds the file now.
 */
final readonly class ResourcesPresenter
{
    /** The route preview's File Cards are served from: management's, so a `resources.manage`-only previewer can open what preview shows, Drafts included (ADR 0038, R3). */
    private const string MANAGEMENT_FILE_ROUTE = 'api.v1.admin.resources.cards.file';

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
            'created_at' => ResourceShapes::instant($category->provenance->createdAt),
            'updated_at' => ResourceShapes::instant($category->provenance->updatedAt),
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
            'category' => ResourceShapes::categoryRef($view->category),
            'position' => $pack->position,
            'state' => $pack->state->value,
            'revision' => $pack->revision,
            'audiences' => $pack->audiences->values(),
            'card_count' => $view->cardCount,
            'published_card_count' => $view->publishedCardCount,
            'created_by' => $this->person($view->createdBy),
            'updated_by' => $this->person($view->updatedBy),
            'created_at' => ResourceShapes::instant($pack->provenance->createdAt),
            'updated_at' => ResourceShapes::instant($pack->provenance->updatedAt),
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
                ...ResourceShapes::fileSummary($card->asset),
                'sha256' => $card->asset->sha256,
                'uploaded_by' => $view->uploadedBy === null ? null : $this->person($view->uploadedBy),
                'uploaded_at' => ResourceShapes::instant($card->asset->uploadedAt),
                'available' => $view->fileAvailable === true,
                'download_path' => ResourceShapes::path(self::MANAGEMENT_FILE_ROUTE, $card->packId, $card->id),
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
            'file' => $card->asset === null ? null : ResourceShapes::fileSummary($card->asset),
            ...$this->audience($card->audience),
            'state' => $card->state->value,
            'revision' => $card->revision,
            'created_by' => $this->person($view->createdBy),
            'updated_by' => $this->person($view->updatedBy),
            'created_at' => ResourceShapes::instant($card->provenance->createdAt),
            'updated_at' => ResourceShapes::instant($card->provenance->updatedAt),
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
            'pack' => $preview->pack === null ? null : (new DeliveryPresenter)->pack($preview->pack, self::MANAGEMENT_FILE_ROUTE),
        ];
    }

    /** @return array{audience_mode: string, audiences: list<string>} */
    private function audience(CardAudience $audience): array
    {
        return ['audience_mode' => $audience->mode->value, 'audiences' => $audience->set->values()];
    }

    /** @return array{id: string, display_name: string|null} */
    private function person(ResourcePerson $person): array
    {
        return ['id' => $person->id->value, 'display_name' => $person->displayName];
    }
}
