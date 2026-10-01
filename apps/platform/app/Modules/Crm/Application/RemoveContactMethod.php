<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Removes a contact method. Needs `crm.people.manage`. If it was the primary of its kind, the earliest remaining
 * method of that kind becomes the primary, so a Person who has an email always has a primary email.
 */
final readonly class RemoveContactMethod
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private ContactProfileRepository $profiles,
        private ContactMethodRepository $methods,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws ContactMethodNotFound
     */
    public function __invoke(Actor $actor, PersonId $personId, ContactMethodId $methodId): void
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        $this->database->transaction(function () use ($personId, $methodId): void {
            $now = DateTimeImmutable::createFromInterface(now());
            $this->profiles->lock($personId, $now);

            $method = $this->methods->find($methodId);
            if ($method === null || ! $method->personId->equals($personId)) {
                throw new ContactMethodNotFound;
            }

            $this->methods->remove($methodId);

            if ($method->isPrimary) {
                foreach ($this->methods->forPerson($personId) as $other) {
                    if ($other->kind === $method->kind) {
                        $this->methods->save($other->withPrimary(true, $now));
                        break; // forPerson is ordered by kind, then creation: the earliest remaining
                    }
                }
            }
        }, 3);
    }
}
