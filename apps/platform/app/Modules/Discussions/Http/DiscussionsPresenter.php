<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\DiscussionPage;
use App\Modules\Discussions\Application\DiscussionPerson;
use App\Modules\Discussions\Application\DiscussionView;
use App\Modules\Discussions\Application\MessagePage;
use App\Modules\Discussions\Application\MessageView;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the Discussions API (openapi/openapi.yaml). Only what Discussions owns plus a Person's id and display
 * name: never an Account id, a login email, a role, a capability, MFA or Membership state (ADR 0035). A removed message
 * has NO `body` key at all, so there is nothing for a client to render or a test to find.
 */
final readonly class DiscussionsPresenter
{
    /** @return array<string, mixed> */
    public function discussions(DiscussionPage $page): array
    {
        return [
            'data' => array_map($this->discussion(...), $page->discussions),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function discussion(DiscussionView $view): array
    {
        $discussion = $view->discussion;

        return [
            'id' => $discussion->id->value,
            'title' => $discussion->title,
            'state' => $discussion->state->value,
            'creator' => $view->creator === null ? null : $this->person($view->creator),
            'message_count' => $discussion->messageCount,
            'last_activity_at' => $this->instant($discussion->lastActivityAt),
            'created_at' => $this->instant($discussion->createdAt),
            'resolved_at' => $this->instant($discussion->resolvedAt),
            'resolved_by' => $view->resolvedBy === null ? null : $this->person($view->resolvedBy),
        ];
    }

    /** @return array<string, mixed> */
    public function messages(MessagePage $page): array
    {
        return [
            'data' => array_map($this->message(...), $page->messages),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function message(MessageView $view): array
    {
        $message = $view->message;
        $common = [
            'id' => $message->id->value,
            'sequence' => $message->sequence,
            'author' => $this->person($view->author),
            'created_at' => $this->instant($message->createdAt),
        ];

        // A tombstone: its place, its author and when it was removed, and not one word of what it said or whether it was edited.
        if ($message->isRemoved()) {
            return [...$common, 'removed' => true, 'removed_at' => $this->instant($message->removedAt)];
        }

        return [
            ...$common,
            'removed' => false,
            'body' => $message->body,
            'edited_at' => $this->instant($message->editedAt),
            'edited_by' => $view->editedBy === null ? null : $this->person($view->editedBy),
        ];
    }

    /** @return array{id: string, display_name: string|null} */
    private function person(DiscussionPerson $person): array
    {
        return ['id' => $person->id->value, 'display_name' => $person->displayName];
    }

    private function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
