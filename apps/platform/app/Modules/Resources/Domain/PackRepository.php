<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Persistence for Packs and their audience rows. Each write names the columns it changes and no others, so a publication
 * change can never overwrite authored fields a concurrent editor moved, and the reverse.
 */
interface PackRepository
{
    public function find(PackId $id): ?Pack;

    /** Reads the row and holds it until the surrounding transaction ends. Only meaningful inside a transaction. */
    public function lock(PackId $id): ?Pack;

    /** Inserts the Pack and its audience rows. */
    public function add(Pack $pack): void;

    /**
     * Writes the authored fields, the Category and position, the new revision and the editing provenance, ONLY if the row still
     * holds `$expectedRevision`. Returns whether it did: false means someone else's edit won (or the row is gone).
     */
    public function saveAuthored(Pack $pack, int $expectedRevision): bool;

    /** Writes the state and the editing provenance. */
    public function saveState(Pack $pack): void;

    /** Replaces the audience rows with the Pack's set, and writes the editing provenance. */
    public function saveAudiences(Pack $pack): void;

    public function savePosition(PackId $id, int $position): void;

    /** Deletes the audience rows and the Pack. The caller has already removed its Cards. */
    public function delete(PackId $id): void;

    /**
     * The Packs in a Category (or, for null, with none), in order.
     *
     * @return list<PackId>
     */
    public function idsIn(?CategoryId $category): array;

    public function countIn(CategoryId $category): int;

    /** One after the highest position among the Packs in that Category (or with none), or 1. */
    public function nextPositionIn(?CategoryId $category): int;

    /**
     * The management list: by Category order, then position, then id; Packs with no Category last.
     *
     * @return list<Pack>
     */
    public function page(ManagedPackFilter $filter, int $page, int $perPage): array;

    public function count(ManagedPackFilter $filter): int;

    /**
     * Published Packs that have a Category, in library order (Category position, Pack position, id), optionally in one Category.
     * Whether any Card is visible to a viewer is the projection's question, not this one's.
     *
     * @return list<Pack>
     */
    public function published(?CategoryId $category): array;

    /**
     * How many Packs each Category holds, in any state, keyed by Category id.
     *
     * @param  list<CategoryId>  $categories
     * @return array<string, int>
     */
    public function countsByCategory(array $categories): array;
}
