<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Pages the Person registry by name, so a module that enriches People (CRM, first) can list all of them without
 * reading Identity's tables. Returns `PersonSummary`: an id and a display name, nothing about Accounts (see
 * `PeopleDirectory`).
 *
 * Results are ordered by lower-cased display name, then by id, so equal names never trade places between pages.
 *
 * **This use case does not authorize its caller** (Identity cannot ask Access). It is an Application seam for a
 * later, authorized use case to compose; no HTTP route exposes it, and none may without first requiring a
 * capability defined by Access.
 */
final readonly class SearchPeople
{
    public function __construct(private PeopleDirectory $directory) {}

    public function __invoke(PeopleQuery $query): PeoplePage
    {
        return $this->directory->search($query->bounded());
    }
}
