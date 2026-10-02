<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\InteractionPage;
use App\Modules\Crm\Application\InteractionView;
use App\Modules\Crm\Application\PeopleDirectoryPage;
use App\Modules\Crm\Application\PersonListing;
use App\Modules\Crm\Application\PersonRecord;
use App\Modules\Crm\Application\PersonUpdate;
use App\Modules\Crm\Application\TagWithCount;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactProfile;
use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Identity\Application\PersonSummary;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the People API (openapi/openapi.yaml). Only what CRM owns plus the Person's id and name: never an
 * Account id, a login email, a role, a capability, MFA or invitation state, or Membership provenance (ADR 0034).
 */
final readonly class CrmPresenter
{
    /** @return array<string, mixed> */
    public function directory(PeopleDirectoryPage $page): array
    {
        return [
            'data' => array_map($this->listing(...), $page->people),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function record(PersonRecord $record): array
    {
        return [
            'person' => $this->person($record->person),
            'profile' => $this->profile($record->profile),
            'contact_methods' => array_map($this->method(...), $record->contactMethods),
            'tags' => array_map($this->tagRef(...), $record->tags),
        ];
    }

    /** @return array<string, mixed> */
    public function update(PersonUpdate $update): array
    {
        return ['person' => $this->person($update->person), 'profile' => $this->profile($update->profile)];
    }

    /** @return array<string, mixed> */
    public function method(ContactMethod $method): array
    {
        return [
            'id' => $method->id->value,
            'kind' => $method->kind->value,
            'value' => $method->value,
            'label' => $method->label,
            'is_primary' => $method->isPrimary,
            'created_at' => $this->instant($method->createdAt),
            'updated_at' => $this->instant($method->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public function interactions(InteractionPage $page): array
    {
        return [
            'data' => array_map($this->interaction(...), $page->interactions),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function interaction(InteractionView $view): array
    {
        $interaction = $view->interaction;

        return [
            'id' => $interaction->id->value,
            'kind' => $interaction->kind->value,
            'body' => $interaction->body,
            'occurred_at' => $this->instant($interaction->occurredAt),
            'author' => $view->author === null ? null : $this->person($view->author),
            'updated_by' => $view->updatedBy === null ? null : $this->person($view->updatedBy),
            'created_at' => $this->instant($interaction->createdAt),
            'updated_at' => $this->instant($interaction->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public function tag(TagWithCount $tag): array
    {
        return ['id' => $tag->tag->id->value, 'name' => $tag->tag->name, 'person_count' => $tag->people];
    }

    /**
     * @param  list<TagWithCount>  $tags
     * @return array<string, mixed>
     */
    public function tags(array $tags): array
    {
        return ['data' => array_map($this->tag(...), $tags)];
    }

    /**
     * @param  list<ContactTag>  $tags
     * @return array<string, mixed>
     */
    public function personTags(array $tags): array
    {
        return ['data' => array_map($this->tagRef(...), $tags)];
    }

    /** @return array<string, mixed> */
    private function listing(PersonListing $listing): array
    {
        return [
            'id' => $listing->person->id->value,
            'display_name' => $listing->person->displayName,
            'primary_email' => $listing->primaryEmail,
            'primary_phone' => $listing->primaryPhone,
            'tags' => array_map($this->tagRef(...), $listing->tags),
        ];
    }

    /** @return array<string, mixed> */
    private function person(PersonSummary $person): array
    {
        return ['id' => $person->id->value, 'display_name' => $person->displayName];
    }

    /** @return array<string, mixed> */
    private function profile(?ContactProfile $profile): array
    {
        return [
            'how_we_know' => $profile?->howWeKnow,
            'affiliation' => $profile?->affiliation,
            'updated_at' => $this->instant($profile?->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    private function tagRef(ContactTag $tag): array
    {
        return ['id' => $tag->id->value, 'name' => $tag->name];
    }

    private function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
