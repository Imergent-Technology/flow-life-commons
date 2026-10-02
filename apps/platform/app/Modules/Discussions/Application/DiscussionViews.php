<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Discussions\Domain\Discussion;
use App\Modules\Discussions\Domain\DiscussionMessage;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Identity\Application\FindPeople;
use App\Shared\Domain\PersonId;

/**
 * Names the people in discussions, for every use case that returns them. Internal to Discussions. Identity owns Person
 * names, so this asks Identity's batched `FindPeople` (one query for a whole page) and never reads `people`, which also
 * means a rename shows on messages already written.
 */
final readonly class DiscussionViews
{
    public function __construct(private FindPeople $findPeople, private DiscussionMessageRepository $messages) {}

    /**
     * @param  list<Discussion>  $discussions
     * @return list<DiscussionView>
     */
    public function ofDiscussions(array $discussions): array
    {
        $creators = $this->messages->openingAuthors(array_map(static fn (Discussion $d) => $d->id, $discussions));

        $ids = [];
        foreach ($discussions as $discussion) {
            $creator = $creators[$discussion->id->value] ?? null;
            foreach ([$creator, $discussion->resolvedByPersonId] as $person) {
                if ($person !== null) {
                    $ids[$person->value] = $person;
                }
            }
        }
        $names = $this->names($ids);

        return array_map(static fn (Discussion $discussion): DiscussionView => new DiscussionView(
            $discussion,
            isset($creators[$discussion->id->value]) ? self::person($creators[$discussion->id->value], $names) : null,
            $discussion->resolvedByPersonId === null ? null : self::person($discussion->resolvedByPersonId, $names),
        ), $discussions);
    }

    /**
     * @param  list<DiscussionMessage>  $messages
     * @return list<MessageView>
     */
    public function ofMessages(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            $ids[$message->authorPersonId->value] = $message->authorPersonId;
            if ($message->editedByPersonId !== null) {
                $ids[$message->editedByPersonId->value] = $message->editedByPersonId;
            }
        }
        $names = $this->names($ids);

        return array_map(static fn (DiscussionMessage $message): MessageView => new MessageView(
            $message,
            self::person($message->authorPersonId, $names),
            $message->editedByPersonId === null ? null : self::person($message->editedByPersonId, $names),
        ), $messages);
    }

    /**
     * @param  array<string, PersonId>  $ids
     * @return array<string, string> display names keyed by PersonId value
     */
    private function names(array $ids): array
    {
        $names = [];
        foreach ($ids === [] ? [] : ($this->findPeople)(array_values($ids)) as $key => $summary) {
            $names[$key] = $summary->displayName;
        }

        return $names;
    }

    /** @param  array<string, string>  $names */
    private static function person(PersonId $id, array $names): DiscussionPerson
    {
        return new DiscussionPerson($id, $names[$id->value] ?? null);
    }
}
