<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\PersonNotFound;
use App\Modules\Identity\Application\RenamePerson;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Corrects a Person's display name and/or CRM profile in one transaction. Needs `crm.people.manage`, and nothing
 * more: correcting a name or a note about how we know someone grants and removes no authority, so it does not ask for
 * recent verification (ADR 0034).
 *
 * The NAME is Identity's: this checks CRM's capability and then calls Identity's `RenamePerson`, which validates and
 * audits it as `person.renamed`. CRM never writes `people`, and Identity never learns which capability CRM checked.
 */
final readonly class UpdatePerson
{
    public function __construct(
        private AuthorizeAction $authorize,
        private FindPeople $findPeople,
        private RenamePerson $rename,
        private ContactProfileRepository $profiles,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  ?string  $displayName  null leaves the name as it is
     *
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws InvalidContactInput
     */
    public function __invoke(Actor $actor, PersonId $personId, ?string $displayName, ProfileChanges $changes): PersonUpdate
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (($this->findPeople)([$personId]) === []) {
            throw new UnknownPerson;
        }

        return $this->database->transaction(function () use ($actor, $personId, $displayName, $changes): PersonUpdate {
            $now = DateTimeImmutable::createFromInterface(now());

            try {
                $summary = $displayName === null ? null : ($this->rename)($personId, $displayName, $actor);
            } catch (PersonNotFound) {
                throw new UnknownPerson;
            } catch (InvalidArgumentException) {
                throw new InvalidContactInput('display_name', 'A name is 1 to 255 characters.');
            }

            if ($changes->isEmpty()) {
                $profile = $this->profiles->find($personId);
            } else {
                $profile = $this->profiles->lock($personId, $now)->with($changes->fields, $actor->accountId, $now);
                $this->profiles->save($profile);
            }

            $summary ??= ($this->findPeople)([$personId])[$personId->value] ?? throw new UnknownPerson;

            return new PersonUpdate($summary, $profile);
        }, 3);
    }
}
