<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

interface ContactTagRepository
{
    public function find(ContactTagId $id): ?ContactTag;

    public function findByCanonical(string $canonical): ?ContactTag;

    /**
     * Every tag by canonical name, with how many People hold it.
     *
     * @return list<array{tag: ContactTag, people: int}>
     */
    public function allWithCounts(): array;

    /**
     * The tags among these ids that exist.
     *
     * @param  list<ContactTagId>  $ids
     * @return list<ContactTag>
     */
    public function findMany(array $ids): array;

    public function add(ContactTag $tag): void;

    public function save(ContactTag $tag): void;

    public function remove(ContactTagId $id): void;

    public function assignmentCount(ContactTagId $id): int;

    /**
     * A Person's tag ids.
     *
     * @return list<ContactTagId>
     */
    public function tagIdsOf(PersonId $personId): array;

    /**
     * Tags of many Persons in ONE query, for a page of a list.
     *
     * @param  list<PersonId>  $personIds
     * @return array<string, list<ContactTag>> keyed by PersonId value, each list by canonical name
     */
    public function forPeople(array $personIds): array;

    public function assign(PersonId $personId, ContactTagId $tagId, ?AccountId $by, DateTimeImmutable $at): void;

    public function unassign(PersonId $personId, ContactTagId $tagId): void;

    /**
     * Persons holding this tag, at most `$limit` + 1 of them so a caller can tell the set was too large to use.
     *
     * @return list<PersonId>
     */
    public function personIdsWithTag(ContactTagId $tagId, int $limit): array;
}
