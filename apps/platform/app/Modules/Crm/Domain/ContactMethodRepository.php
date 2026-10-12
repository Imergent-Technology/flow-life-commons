<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

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

    /**
     * A Person's methods like {@see forPerson}, read with a lock (SELECT ... FOR UPDATE). For a caller that has taken the
     * Person's profile lock and must decide on what is current: under MariaDB's REPEATABLE READ a plain read could still
     * serve an older snapshot than the lock just waited for (ADR 0038, T1).
     *
     * @return list<ContactMethod>
     */
    public function forPersonLocked(PersonId $personId): array;

    /**
     * The stored `value` of each Person's PRIMARY email and PRIMARY phone, and nothing else, in ONE query. A Person with
     * no primary of a kind has null for it. Every id asked about is present in the answer.
     *
     * @param  list<PersonId>  $personIds
     * @return array<string, array{email: ?string, phone: ?string}> keyed by PersonId value
     */
    public function primaryValues(array $personIds): array;

    public function add(ContactMethod $method): void;

    /** Writes the changed fields (value, search value, label, primary flag, update time) of an existing method. */
    public function save(ContactMethod $method): void;

    public function remove(ContactMethodId $id): void;

    /**
     * Clears the primary flag of whichever method of this kind is the Person's primary (there is at most one) and stamps
     * that demoted row with the acting Account and the time: a row whose primary flag changes always carries who changed
     * it (ADR 0038, P9). There is no way to demote without an attribution.
     */
    public function clearPrimary(PersonId $personId, ContactMethodKind $kind, AccountId $by, DateTimeImmutable $now): void;

    /**
     * Persons that have a method of this kind with exactly this search value, primary or not. Used to find a possible
     * duplicate, and by the exact-match candidate lookup. At most `$limit` when given.
     *
     * @return list<PersonId>
     */
    public function personIdsWithSearchValue(ContactMethodKind $kind, string $searchValue, ?int $limit = null): array;

    /**
     * Distinct Persons with an email or phone method matching this text, as a fragment ("contains"), at most `$limit`
     * + 1 of them so a caller can tell the set was too large to use.
     *
     * @return list<PersonId>
     */
    public function personIdsMatching(string $text, int $limit): array;

    /**
     * Among `$within`, the Persons whose PRIMARY email contains the text, or whose PRIMARY phone contains its digits
     * (3 or more). A non-primary method never matches, so a search cannot probe the methods a caller is not shown.
     * At most `$limit` + 1, ordered by id.
     *
     * @param  list<PersonId>  $within
     * @return list<PersonId>
     */
    public function personIdsMatchingPrimary(string $text, array $within, int $limit): array;
}
