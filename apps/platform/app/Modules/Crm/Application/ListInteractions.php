<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\InteractionRepository;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/** A Person's notes and interactions, newest first (by when they happened). Needs `crm.people.view`. */
final readonly class ListInteractions
{
    public const int MAX_PER_PAGE = PeopleQuery::MAX_PER_PAGE;

    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private InteractionRepository $interactions,
        private InteractionViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     */
    public function __invoke(Actor $actor, PersonId $personId, int $page, int $perPage): InteractionPage
    {
        ($this->authorize)($actor, Capability::ViewPeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        return new InteractionPage(
            $this->views->of($this->interactions->page($personId, $page, $perPage)),
            $page,
            $perPage,
            $this->interactions->count($personId),
        );
    }
}
