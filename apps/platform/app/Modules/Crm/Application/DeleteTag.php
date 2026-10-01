<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Deletes a tag nobody holds. Needs `crm.people.manage`. A tag in use is REFUSED, not stripped from the People who hold
 * it: removing a label from every Person at once is not something a delete should do silently. The database refuses it
 * too (RESTRICT), so an assignment made while this runs still cannot be lost.
 */
final readonly class DeleteTag
{
    public function __construct(
        private AuthorizeAction $authorize,
        private ContactTagRepository $tags,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws TagNotFound
     * @throws TagInUse
     */
    public function __invoke(Actor $actor, ContactTagId $tagId): void
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        try {
            $this->database->transaction(function () use ($tagId): void {
                $this->tags->find($tagId) ?? throw new TagNotFound;
                if ($this->tags->assignmentCount($tagId) > 0) {
                    throw new TagInUse;
                }
                $this->tags->remove($tagId);
            }, 3);
        } catch (QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                throw new TagInUse; // an assignment landed between the count and the delete: RESTRICT held
            }
            throw $e;
        }
    }
}
