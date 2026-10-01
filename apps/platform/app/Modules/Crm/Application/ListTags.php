<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Shared\Domain\Actor;

/** Every tag, by name, with how many People hold it. Needs `crm.people.view`. */
final readonly class ListTags
{
    public function __construct(private AuthorizeAction $authorize, private ContactTagRepository $tags) {}

    /**
     * @return list<TagWithCount>
     *
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor): array
    {
        ($this->authorize)($actor, Capability::ViewPeople);

        return array_map(
            static fn (array $row): TagWithCount => new TagWithCount($row['tag'], $row['holders']),
            $this->tags->allWithCounts(),
        );
    }
}
