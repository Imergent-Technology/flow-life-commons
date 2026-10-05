<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Persistence for Cards, their narrowing rows and a File Card's asset row. Each write names the columns it changes and no others.
 * Asset rows are written and removed only with their Card: there is no way to reach one except through the Card that owns it.
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

    /** Inserts a File Card's asset row, then the Card, then its audience rows. */
    public function add(Card $card): void;

    /**
     * The File Card's file was replaced (decision 65): inserts the Card's new asset row, points the Card at it with the editing
     * provenance, and deletes the asset row it pointed at before (`$previous`; null only for a File Card whose row lost its asset,
     * which replacement repairs). The caller holds the Pack's lock and the Card's row.
     */
    public function replaceAsset(Card $card, ?AssetId $previous): void;

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

    /**
     * Deletes the narrowing rows, the Card and, for a File Card, its asset row. Returns the storage key of the asset removed, so the
     * caller can remove the file once the deletion has committed, or null when there was none.
     */
    public function delete(CardId $id): ?StorageKey;

    /**
     * Deletes every Card of a Pack with their narrowing rows and asset rows, and says how many Cards and asset rows there were and
     * which storage keys the removed assets used.
     */
    public function deleteAllOf(PackId $pack): DeletedCards;

    /**
     * Every storage key a committed asset row refers to: what the prune must never remove.
     *
     * @return list<StorageKey>
     */
    public function assetStorageKeys(): array;

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
