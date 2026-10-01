<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/** Records an email or phone number for a Person. Needs `crm.people.manage`. */
final readonly class AddContactMethod
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private ContactProfileRepository $profiles,
        private ContactMethodWriter $writer,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws InvalidContactInput
     * @throws DuplicateContactMethod
     */
    public function __invoke(Actor $actor, PersonId $personId, NewContactMethod $new): ContactMethod
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        return $this->database->transaction(function () use ($personId, $new): ContactMethod {
            $now = DateTimeImmutable::createFromInterface(now());
            $this->profiles->lock($personId, $now); // CRM's per-Person write lock, then decide

            return $this->writer->add($personId, $new, $now);
        }, 3);
    }
}
