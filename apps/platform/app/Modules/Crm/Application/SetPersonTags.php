<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Makes a Person's tags EXACTLY the given set: tags not in it are removed, new ones added. Needs `crm.people.manage`.
 * An empty set clears them. Tags are labels; this changes what a Person is called in the directory and nothing about
 * what they may do.
 */
final readonly class SetPersonTags
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private ContactProfileRepository $profiles,
        private ContactTagRepository $tags,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<ContactTagId>  $tagIds
     * @return list<ContactTag> the Person's tags afterwards, by name
     *
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws UnknownTags
     */
    public function __invoke(Actor $actor, PersonId $personId, array $tagIds): array
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        $wanted = [];
        foreach ($tagIds as $id) {
            $wanted[$id->value] = $id;
        }
        $existing = $this->tags->findMany(array_values($wanted));
        if (count($existing) !== count($wanted)) {
            throw new UnknownTags;
        }

        try {
            $this->database->transaction(function () use ($actor, $personId, $wanted): void {
                $now = DateTimeImmutable::createFromInterface(now());
                $this->profiles->lock($personId, $now);

                $held = [];
                foreach ($this->tags->tagIdsOf($personId) as $id) {
                    $held[$id->value] = $id;
                }
                foreach (array_diff_key($held, $wanted) as $remove) {
                    $this->tags->unassign($personId, $remove);
                }
                foreach (array_diff_key($wanted, $held) as $add) {
                    $this->tags->assign($personId, $add, $actor->accountId, $now);
                }
            }, 3);
        } catch (QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                throw new UnknownTags; // a tag was deleted between the check and the write: RESTRICT refused, nothing changed
            }
            throw $e;
        }

        return $this->tags->forPeople([$personId])[$personId->value] ?? [];
    }
}
