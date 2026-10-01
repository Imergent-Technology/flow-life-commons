<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Modules\Identity\Application\FindPeople;
use App\Shared\Domain\PersonId;

/**
 * Assembles what CRM holds about a Person. Internal to Crm and authorizes nothing: `GetPersonRecord` is the checked
 * entry point, and `RegisterContact` reads back the Person it has just created with it.
 */
final readonly class ReadPersonRecord
{
    public function __construct(
        private FindPeople $findPeople,
        private ContactProfileRepository $profiles,
        private ContactMethodRepository $methods,
        private ContactTagRepository $tags,
    ) {}

    /** @throws UnknownPerson */
    public function __invoke(PersonId $personId): PersonRecord
    {
        $person = ($this->findPeople)([$personId])[$personId->value] ?? throw new UnknownPerson;

        $tagsByName = $this->tags->forPeople([$personId])[$personId->value] ?? [];

        return new PersonRecord($person, $this->profiles->find($personId), $this->methods->forPerson($personId), $tagsByName);
    }
}
