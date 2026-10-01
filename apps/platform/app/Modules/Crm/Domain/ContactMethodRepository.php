<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\PersonId;

/**
 * Contact-method persistence. Every mutating call is made by a use case that already holds the Person's profile-row
 * lock (ContactProfileRepository::lock), which is what makes these checks race-free; the unique indexes are the
 * backstop, not the mechanism.
 */
interface ContactMethodRepository
{
    public function find(ContactMethodId $id): ?ContactMethod;

    /**
     * A Person's methods in a stable order: kind, then creation, then id.
     *
     * @return list<ContactMethod>
     */
    public function forPerson(PersonId $personId): array;

    /**
     * The same for many Persons in ONE query, for a page of a list.
     *
     * @param  list<PersonId>  $personIds
     * @return array<string, list<ContactMethod>> keyed by PersonId value; a Person with none is absent
     */
    public function forPeople(array $personIds): array;

    public function add(ContactMethod $method): void;

    /** Writes the changed fields (value, search value, label, primary flag, update time) of an existing method. */
    public function save(ContactMethod $method): void;

    public function remove(ContactMethodId $id): void;

    /** Clears the primary flag of whichever method of this kind is the Person's primary (there is at most one). */
    public function clearPrimary(PersonId $personId, ContactMethodKind $kind): void;

    /**
     * Persons that have a method of this kind with exactly this search value. Used to find a possible duplicate.
     *
     * @return list<PersonId>
     */
    public function personIdsWithSearchValue(ContactMethodKind $kind, string $searchValue): array;

    /**
     * Distinct Persons with an email or phone method matching this text, as a fragment ("contains"), at most `$limit`
     * + 1 of them so a caller can tell the set was too large to use.
     *
     * @return list<PersonId>
     */
    public function personIdsMatching(string $text, int $limit): array;
}
