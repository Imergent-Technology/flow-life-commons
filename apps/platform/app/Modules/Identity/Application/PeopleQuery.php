<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\PersonId;
use InvalidArgumentException;

/**
 * What a caller may ask of the Person registry, and no more: a name fragment, two optional sets of Person ids that
 * let a caller compose the registry with data it owns, and a page. There is no query language.
 *
 * - **`text`** matches the display name, case-insensitively, as a plain fragment (`%` and `_` are ordinary
 *   characters). Null, empty and whitespace-only all mean "no text": every Person.
 * - **`includeIds`** are Persons that count as a text match even though their name does not (a caller that found
 *   them in data it owns, such as a contact method). They widen a NON-EMPTY text match; with no text every Person
 *   is already selected, so they change nothing. Null and `[]` both add nothing.
 * - **`restrictToIds`** narrows the selection to those Persons (a caller's tag filter, say). Null means "no
 *   restriction". An explicitly supplied empty list means "restrict to nobody" and selects no one: an empty set
 *   is an answer, never a wildcard.
 *
 * Ids that name no Person, and ids repeated within or across the sets, are harmless. The size of an id set is
 * bounded, because a database bounds the parameters of one statement; a caller with more is asking for something
 * a search is not for.
 */
final readonly class PeopleQuery
{
    public const int MAX_PER_PAGE = 100;

    /** Well inside the parameter limit of either engine, and far above Flow Life's scale. */
    public const int MAX_ID_SET = 10_000;

    /**
     * @param  list<PersonId>|null  $includeIds
     * @param  list<PersonId>|null  $restrictToIds
     */
    public function __construct(
        public ?string $text = null,
        public ?array $includeIds = null,
        public ?array $restrictToIds = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
        foreach ([$includeIds, $restrictToIds] as $ids) {
            if ($ids !== null && count($ids) > self::MAX_ID_SET) {
                throw new InvalidArgumentException('An id set holds at most '.self::MAX_ID_SET.' Person ids.');
            }
        }
    }

    /** The same query with a positive page and a page size within bounds. */
    public function bounded(): self
    {
        return new self(
            $this->text, $this->includeIds, $this->restrictToIds,
            max(1, $this->page), min(self::MAX_PER_PAGE, max(1, $this->perPage)),
        );
    }
}
