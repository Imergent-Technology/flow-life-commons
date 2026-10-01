<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\SearchPeople;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/**
 * The People directory: EVERY Person Identity holds, whether or not CRM has anything about them, optionally narrowed
 * by a text search and a tag. Identity owns the registry's order and paging; CRM contributes the two sets it owns:
 *
 * - **text** matches the display name (Identity's) OR an email or phone number recorded in CRM. It never matches an
 *   Account's login email: that stays behind `identity.accounts.view` (ADR 0034). So a Person found by their login
 *   address alone is found only once that address has also been recorded as a CRM contact method.
 * - **tag** restricts the directory to People holding that tag. An unknown or unused tag yields an empty directory.
 *
 * CRM never reads `people` or any Account table. A page costs a constant number of queries, whatever its size.
 */
final readonly class PagePeopleDirectory
{
    public const int MAX_PER_PAGE = PeopleQuery::MAX_PER_PAGE;

    public function __construct(
        private AuthorizeAction $authorize,
        private SearchPeople $search,
        private ContactMethodRepository $methods,
        private ContactTagRepository $tags,
    ) {}

    /**
     * @throws AccessDenied
     * @throws SearchTooBroad the text or tag selects more People than a search can compose
     */
    public function __invoke(Actor $actor, ?string $text, ?ContactTagId $tag, int $page, int $perPage): PeopleDirectoryPage
    {
        ($this->authorize)($actor, Capability::ViewPeople);

        $text = $text === null ? '' : trim($text);

        $include = null;
        if ($text !== '') {
            $include = $this->methods->personIdsMatching($text, PeopleQuery::MAX_ID_SET);
            if (count($include) > PeopleQuery::MAX_ID_SET) {
                throw new SearchTooBroad;
            }
        }

        $restrict = null;
        if ($tag !== null) {
            $restrict = $this->tags->personIdsWithTag($tag, PeopleQuery::MAX_ID_SET);
            if (count($restrict) > PeopleQuery::MAX_ID_SET) {
                throw new SearchTooBroad;
            }
        }

        $result = ($this->search)(new PeopleQuery($text, $include, $restrict, $page, $perPage));

        $ids = array_map(static fn ($summary): PersonId => $summary->id, $result->people);
        $methodsByPerson = $this->methods->forPeople($ids);
        $tagsByPerson = $this->tags->forPeople($ids);

        $listings = [];
        foreach ($result->people as $person) {
            $primary = [];
            foreach ($methodsByPerson[$person->id->value] ?? [] as $method) {
                if ($method->isPrimary) {
                    $primary[$method->kind->value] = $method->value;
                }
            }
            $listings[] = new PersonListing(
                $person,
                $primary[ContactMethodKind::Email->value] ?? null,
                $primary[ContactMethodKind::Phone->value] ?? null,
                $tagsByPerson[$person->id->value] ?? [],
            );
        }

        return new PeopleDirectoryPage($listings, $result->page, $result->perPage, $result->total);
    }
}
