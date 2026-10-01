<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * The People whose display name IS this name, ignoring case: an exact lookup, not a search. A module that wants to warn
 * about a possible duplicate (CRM, first) asks this instead of paging `SearchPeople`'s contains-matches and comparing,
 * which could never be sure it had looked far enough.
 *
 * "Exactly" has the same meaning as `SearchPeople`'s name match: `lower()` on both sides, so ASCII case behaves identically
 * on MariaDB and PostgreSQL, and accent sensitivity follows each engine's collation. No fuzzy matching, no Account data:
 * `PersonSummary` only. Ordered by id, so the answer is deterministic; at most `MAX_RESULTS` are returned, which is
 * far more than any advice needs (one match is already a duplicate).
 *
 * **This use case does not authorize its caller** (Identity cannot ask Access); a later, authorized use case composes it.
 */
final readonly class FindPeopleNamed
{
    public const int MAX_RESULTS = 100;

    public function __construct(private PeopleDirectory $directory) {}

    /** @return list<PersonSummary> blank (empty or whitespace-only) matches nobody */
    public function __invoke(string $displayName): array
    {
        $name = trim($displayName);

        return $name === '' ? [] : $this->directory->named($name, self::MAX_RESULTS);
    }
}
