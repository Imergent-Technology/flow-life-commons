<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Persistence for Cards and their narrowing rows. Each write names the columns it changes and no others.
 */
interface CardRepository
{
    public function find(CardId $id): ?Card;

    /**
     * Reads the Card and holds its row until the surrounding transaction ends. Publishing takes it, so an authored edit that
     * arrives meanwhile waits and then meets the new state (its write is conditional on the state it saw). Only meaningful
     * inside a transaction, after the Pack's lock.
     */
    public function lock(CardId $id): ?Card;

    /**
     * Cards with their content, in the given order, silently omitting any that no longer exist.
     *
     * @param  list<CardId>  $ids
     * @return list<Card>
     */
    public function findMany(array $ids): array;

    /**
     * Every Card of a Pack WITHOUT content, in order (position, then id), Drafts included.
     *
     * @return list<CardOutline>
     */
    public function outlinesOf(PackId $pack): array;

    /**
     * Every Card of each Pack without content, keyed by Pack id, each list in order.
     *
     * @param  list<PackId>  $packs
     * @return array<string, list<CardOutline>>
     */
    public function outlinesOfPacks(array $packs): array;

    /** Inserts the Card and its audience rows. */
    public function add(Card $card): void;

    /**
     * Writes the authored fields, the address and the new revision and editing provenance, ONLY if the row still holds
     * `$expectedRevision` AND is still in `$observedState`. The state condition is what keeps an edit, validated against a Draft,
     * from landing in a Card that was Published in between (decision 56). Returns whether it did.
     */
    public function saveAuthored(Card $card, int $expectedRevision, PublicationState $observedState): bool;

    /** Writes the state and the editing provenance. */
    public function saveState(Card $card): void;

    /** Writes the audience mode, replaces the narrowing rows with the Card's set, and writes the editing provenance. */
    public function saveAudience(Card $card): void;

    public function savePosition(CardId $id, int $position): void;

    /** Deletes the narrowing rows and the Card. */
    public function delete(CardId $id): void;

    /**
     * Deletes every Card of a Pack with their narrowing rows, and returns how many there were.
     */
    public function deleteAllOf(PackId $pack): int;

    public function countIn(PackId $pack): int;

    /** One after the highest position in the Pack, or 1. */
    public function nextPositionIn(PackId $pack): int;

    /**
     * How many Cards each Pack holds in total and how many are Published, keyed by Pack id.
     *
     * @param  list<PackId>  $packs
     * @return array<string, array{total: int, published: int}>
     */
    public function countsByPack(array $packs): array;
}
