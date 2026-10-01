<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/** One Person as CRM knows them. Needs `crm.people.view`. */
final readonly class GetPersonRecord
{
    public function __construct(private AuthorizeAction $authorize, private ReadPersonRecord $read) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     */
    public function __invoke(Actor $actor, PersonId $personId): PersonRecord
    {
        ($this->authorize)($actor, Capability::ViewPeople);

        return ($this->read)($personId);
    }
}
