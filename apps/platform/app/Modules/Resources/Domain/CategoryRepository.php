<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Persistence for Categories. Each write names the columns it changes and no others.
 */
interface CategoryRepository
{
    public function find(CategoryId $id): ?Category;

    /** Reads the row and holds it until the surrounding transaction ends (`SELECT ... FOR UPDATE`). Only meaningful inside one. */
    public function lock(CategoryId $id): ?Category;

    /**
     * Locks EVERY Category, in id order, and returns them in display order. The lock the set needs to be created into, reordered
     * or deleted from consistently (ADR 0037, decision 57); a Category can only be added while it is not held.
     *
     * @return list<Category>
     */
    public function lockAll(): array;

    /**
     * In display order: position, then id.
     *
     * @return list<Category>
     */
    public function all(): array;

    public function findByCanonicalName(string $canonical): ?Category;

    /** One after the highest position, or 1. */
    public function nextPosition(): int;

    public function add(Category $category): void;

    /** Writes the name, its canonical form and the editing provenance. */
    public function saveName(Category $category): void;

    public function savePosition(CategoryId $id, int $position): void;

    public function delete(CategoryId $id): void;
}
