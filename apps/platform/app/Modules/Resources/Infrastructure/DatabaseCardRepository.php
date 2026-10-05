<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\AssetId;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardAudience;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CardSummary;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\DeletedCards;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PublicationState;
use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Domain\StorageKey;
use App\Modules\Resources\Domain\SummaryMode;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use stdClass;

/**
 * Query builder, no model. Every write names its columns. Reading an outline never selects `content_document`, so listing a
 * Pack's Cards does not load up to 256 KiB of document for each. A File Card's asset row is read in one batch per list of Cards,
 * as the narrowing rows are, and is written and deleted only with its Card.
 */
final readonly class DatabaseCardRepository implements CardRepository
{
    private const string TABLE = 'resource_cards';

    private const string AUDIENCES = 'resource_card_audiences';

    private const string ASSETS = 'resource_assets';

    /** Every column but the content: what an outline needs. */
    private const array OUTLINE_COLUMNS = [
        'id', 'pack_id', 'position', 'type', 'title', 'summary_mode', 'summary_text', 'external_uri', 'asset_id', 'audience_mode', 'state',
        'revision', 'created_by_person_id', 'updated_by_person_id', 'created_at', 'updated_at',
    ];

    public function __construct(private ConnectionInterface $database) {}

    public function find(CardId $id): ?Card
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrateCards([$row])[0];
    }

    public function lock(CardId $id): ?Card
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->lockForUpdate()->first();

        // The asset by a locking (current) read too: the Card row just read is current, and the asset it names may have been
        // committed while this caller waited for that row. On MariaDB a plain read could still use a REPEATABLE READ snapshot fixed
        // earlier in the transaction, miss that asset, and leave the caller believing the Card had none (measured, WP3).
        return $row === null ? null : $this->hydrateCards([$row], current: true)[0];
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->database->table(self::TABLE)->whereIn('id', array_map(static fn (CardId $id): string => $id->value, $ids))->get()->all();
        $byId = [];
        foreach ($this->hydrateCards(array_values($rows)) as $card) {
            $byId[$card->id->value] = $card;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id->value])) {
                $ordered[] = $byId[$id->value];
            }
        }

        return $ordered;
    }

    public function outlinesOf(PackId $pack): array
    {
        return $this->outlinesOfPacks([$pack])[$pack->value] ?? [];
    }

    public function outlinesOfPacks(array $packs): array
    {
        if ($packs === []) {
            return [];
        }
        $rows = $this->database->table(self::TABLE)
            ->select(self::OUTLINE_COLUMNS)
            ->whereIn('pack_id', array_map(static fn (PackId $p): string => $p->value, $packs))
            ->orderBy('position')->orderBy('id')
            ->get()->all();

        $byPack = [];
        foreach ($this->hydrateOutlines(array_values($rows)) as $outline) {
            $byPack[$outline->packId->value][] = $outline;
        }

        return $byPack;
    }

    public function add(Card $card): void
    {
        if ($card->asset !== null) {
            $this->insertAsset($card->asset);
        }
        $this->database->table(self::TABLE)->insert([
            'id' => $card->id->value,
            'pack_id' => $card->packId->value,
            'position' => $card->position,
            'type' => $card->type->value,
            'title' => $card->title,
            'summary_mode' => $card->summary->mode->value,
            'summary_text' => $card->summary->text,
            'content_format' => $card->content->format,
            'content_version' => $card->content->version,
            'content_document' => $card->content->json,
            'external_uri' => $card->externalUri,
            'asset_id' => $card->asset?->id->value,
            'audience_mode' => $card->audience->mode->value,
            'state' => $card->state->value,
            'revision' => $card->revision,
            'created_by_person_id' => $card->provenance->createdBy->value,
            'updated_by_person_id' => $card->provenance->updatedBy->value,
            'created_at' => SqlTime::to($card->provenance->createdAt),
            'updated_at' => SqlTime::to($card->provenance->updatedAt),
        ]);
        $this->insertAudiences($card);
    }

    public function replaceAsset(Card $card, ?AssetId $previous): void
    {
        $asset = $card->asset ?? throw new LogicException('a replaced file is a file');
        $this->insertAsset($asset);
        $this->database->table(self::TABLE)->where('id', $card->id->value)->update([
            'asset_id' => $asset->id->value,
            'updated_by_person_id' => $card->provenance->updatedBy->value,
            'updated_at' => SqlTime::to($card->provenance->updatedAt),
        ]);
        if ($previous !== null) {
            $this->database->table(self::ASSETS)->where('id', $previous->value)->delete();
        }
    }

    public function saveAuthored(Card $card, int $expectedRevision, PublicationState $observedState): bool
    {
        $affected = $this->database->table(self::TABLE)
            ->where('id', $card->id->value)
            ->where('revision', $expectedRevision)
            ->where('state', $observedState->value)
            ->update([
                'title' => $card->title,
                'summary_mode' => $card->summary->mode->value,
                'summary_text' => $card->summary->text,
                'content_format' => $card->content->format,
                'content_version' => $card->content->version,
                'content_document' => $card->content->json,
                'external_uri' => $card->externalUri,
                'revision' => $card->revision,
                'updated_by_person_id' => $card->provenance->updatedBy->value,
                'updated_at' => SqlTime::to($card->provenance->updatedAt),
            ]);

        return $affected === 1;
    }

    public function saveState(Card $card): void
    {
        $this->database->table(self::TABLE)->where('id', $card->id->value)->update([
            'state' => $card->state->value,
            'updated_by_person_id' => $card->provenance->updatedBy->value,
            'updated_at' => SqlTime::to($card->provenance->updatedAt),
        ]);
    }

    public function saveAudience(Card $card): void
    {
        $this->database->table(self::AUDIENCES)->where('card_id', $card->id->value)->delete();
        $this->insertAudiences($card);
        $this->database->table(self::TABLE)->where('id', $card->id->value)->update([
            'audience_mode' => $card->audience->mode->value,
            'updated_by_person_id' => $card->provenance->updatedBy->value,
            'updated_at' => SqlTime::to($card->provenance->updatedAt),
        ]);
    }

    public function savePosition(CardId $id, int $position): void
    {
        $this->database->table(self::TABLE)->where('id', $id->value)->update(['position' => $position]);
    }

    public function delete(CardId $id): ?StorageKey
    {
        // The asset the Card points at NOW, by a locking (current) read: a replacement that committed while this caller waited for the
        // Pack's lock has already moved it, and a snapshot read could name the file that replacement removed instead of the one it left.
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->lockForUpdate()->first(['asset_id']);
        $assetId = $row === null ? null : Rows::nullableString($row, 'asset_id');
        $key = $assetId === null ? null : $this->storageKeyOf($assetId);

        $this->database->table(self::AUDIENCES)->where('card_id', $id->value)->delete();
        $this->database->table(self::TABLE)->where('id', $id->value)->delete();
        if ($assetId !== null) {
            $this->database->table(self::ASSETS)->where('id', $assetId)->delete();
        }

        return $key;
    }

    public function deleteAllOf(PackId $pack): DeletedCards
    {
        // A locking, CURRENT read, on purpose. The caller has the Pack's lock, so no Card can be added or removed from here on, but
        // one may have committed while it waited for that lock. A plain read would use the transaction's snapshot, which on MariaDB
        // (REPEATABLE READ) was fixed by an earlier plain read (finding the Pack), and would miss that Card, while the DELETE below
        // (always a current read) removes it: the count would be short and its audience rows would be left to trip the foreign key.
        // The same holds for which asset each Card points at: a replacement may have committed during the wait.
        $rows = $this->database->table(self::TABLE)->where('pack_id', $pack->value)->lockForUpdate()->get(['id', 'asset_id'])->all();
        if ($rows === []) {
            return new DeletedCards(0, []);
        }
        $ids = array_map(static fn (stdClass $row): string => Rows::string($row, 'id'), $rows);
        $assetIds = array_values(array_filter(array_map(static fn (stdClass $row): ?string => Rows::nullableString($row, 'asset_id'), $rows)));

        $keys = [];
        if ($assetIds !== []) {
            foreach ($this->database->table(self::ASSETS)->whereIn('id', $assetIds)->orderBy('storage_key')->lockForUpdate()->pluck('storage_key') as $key) {
                $keys[] = StorageKey::fromStored(is_string($key) ? $key : '');
            }
        }

        $this->database->table(self::AUDIENCES)->whereIn('card_id', $ids)->delete();
        $this->database->table(self::TABLE)->where('pack_id', $pack->value)->delete();
        if ($assetIds !== []) {
            $this->database->table(self::ASSETS)->whereIn('id', $assetIds)->delete();
        }

        return new DeletedCards(count($ids), $keys);
    }

    public function assetStorageKeys(): array
    {
        $keys = [];
        foreach ($this->database->table(self::ASSETS)->orderBy('storage_key')->pluck('storage_key') as $key) {
            $keys[] = StorageKey::fromStored(is_string($key) ? $key : '');
        }

        return $keys;
    }

    public function countIn(PackId $pack): int
    {
        return $this->database->table(self::TABLE)->where('pack_id', $pack->value)->count();
    }

    public function nextPositionIn(PackId $pack): int
    {
        $max = $this->database->table(self::TABLE)->where('pack_id', $pack->value)->max('position');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    public function countsByPack(array $packs): array
    {
        if ($packs === []) {
            return [];
        }
        $counts = [];
        $rows = $this->database->table(self::TABLE)
            ->selectRaw('pack_id, count(*) as total, sum(case when state = ? then 1 else 0 end) as published', [PublicationState::Published->value])
            ->whereIn('pack_id', array_map(static fn (PackId $p): string => $p->value, $packs))
            ->groupBy('pack_id')
            ->get();
        foreach ($rows as $row) {
            $counts[Rows::string($row, 'pack_id')] = ['total' => Rows::int($row, 'total'), 'published' => Rows::int($row, 'published')];
        }

        return $counts;
    }

    private function insertAsset(ResourceAsset $asset): void
    {
        $this->database->table(self::ASSETS)->insert([
            'id' => $asset->id->value,
            'storage_key' => $asset->storageKey->value,
            'original_filename' => $asset->originalFilename,
            'media_type' => $asset->mediaType,
            'byte_size' => $asset->byteSize,
            'sha256' => $asset->sha256,
            'uploaded_by_person_id' => $asset->uploadedBy->value,
            'created_at' => SqlTime::to($asset->uploadedAt),
        ]);
    }

    private function storageKeyOf(string $assetId): ?StorageKey
    {
        $key = $this->database->table(self::ASSETS)->where('id', $assetId)->lockForUpdate()->value('storage_key');

        return is_string($key) ? StorageKey::fromStored($key) : null;
    }

    /**
     * The asset rows the given Card rows point at, keyed by asset id: one query for the whole list. `$current` makes it a locking,
     * current read, for a caller that holds the Card's row.
     *
     * @param  list<stdClass>  $rows
     * @return array<string, ResourceAsset>
     */
    private function assets(array $rows, bool $current = false): array
    {
        $ids = array_values(array_filter(array_map(static fn (stdClass $row): ?string => Rows::nullableString($row, 'asset_id'), $rows)));
        $assets = [];
        if ($ids !== []) {
            $query = $this->database->table(self::ASSETS)->whereIn('id', $ids);
            foreach (($current ? $query->lockForUpdate() : $query)->get() as $asset) {
                $id = Rows::string($asset, 'id');
                $assets[$id] = new ResourceAsset(
                    AssetId::fromString($id),
                    StorageKey::fromStored(Rows::string($asset, 'storage_key')),
                    Rows::string($asset, 'original_filename'),
                    Rows::string($asset, 'media_type'),
                    Rows::int($asset, 'byte_size'),
                    Rows::string($asset, 'sha256'),
                    PersonId::fromString(Rows::string($asset, 'uploaded_by_person_id')),
                    SqlTime::from(Rows::string($asset, 'created_at')),
                );
            }
        }

        return $assets;
    }

    private function insertAudiences(Card $card): void
    {
        $rows = array_map(static fn (string $audience): array => ['card_id' => $card->id->value, 'audience' => $audience], $card->audience->set->values());
        if ($rows !== []) {
            $this->database->table(self::AUDIENCES)->insert($rows);
        }
    }

    /**
     * Narrowing keys by Card id.
     *
     * @param  list<stdClass>  $rows
     * @return array<string, list<string>>
     */
    private function audienceKeys(array $rows): array
    {
        $ids = array_map(static fn (stdClass $row): string => Rows::string($row, 'id'), $rows);
        $keys = [];
        if ($ids !== []) {
            foreach ($this->database->table(self::AUDIENCES)->whereIn('card_id', $ids)->get() as $audience) {
                $keys[Rows::string($audience, 'card_id')][] = Rows::string($audience, 'audience');
            }
        }

        return $keys;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<Card>
     */
    private function hydrateCards(array $rows, bool $current = false): array
    {
        $keys = $this->audienceKeys($rows);
        $assets = $this->assets($rows, $current);

        return array_map(static function (stdClass $row) use ($keys, $assets): Card {
            $id = Rows::string($row, 'id');
            $assetId = Rows::nullableString($row, 'asset_id');

            return Card::reconstitute(
                CardId::fromString($id),
                PackId::fromString(Rows::string($row, 'pack_id')),
                Rows::int($row, 'position'),
                CardType::from(Rows::string($row, 'type')),
                Rows::string($row, 'title'),
                CardSummary::reconstitute(SummaryMode::from(Rows::string($row, 'summary_mode')), Rows::string($row, 'summary_text')),
                ContentDocument::fromStored(Rows::string($row, 'content_format'), Rows::int($row, 'content_version'), Rows::string($row, 'content_document')),
                Rows::nullableString($row, 'external_uri'),
                $assetId === null ? null : ($assets[$assetId] ?? null),
                self::audience($row, $keys[$id] ?? []),
                PublicationState::from(Rows::string($row, 'state')),
                Rows::int($row, 'revision'),
                Rows::provenance($row),
            );
        }, $rows);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<CardOutline>
     */
    private function hydrateOutlines(array $rows): array
    {
        $keys = $this->audienceKeys($rows);
        $assets = $this->assets($rows);

        return array_map(static function (stdClass $row) use ($keys, $assets): CardOutline {
            $id = Rows::string($row, 'id');
            $assetId = Rows::nullableString($row, 'asset_id');

            return new CardOutline(
                CardId::fromString($id),
                PackId::fromString(Rows::string($row, 'pack_id')),
                Rows::int($row, 'position'),
                CardType::from(Rows::string($row, 'type')),
                Rows::string($row, 'title'),
                CardSummary::reconstitute(SummaryMode::from(Rows::string($row, 'summary_mode')), Rows::string($row, 'summary_text')),
                Rows::nullableString($row, 'external_uri'),
                $assetId === null ? null : ($assets[$assetId] ?? null),
                self::audience($row, $keys[$id] ?? []),
                PublicationState::from(Rows::string($row, 'state')),
                Rows::int($row, 'revision'),
                Rows::provenance($row),
            );
        }, $rows);
    }

    /** @param  list<string>  $keys */
    private static function audience(stdClass $row, array $keys): CardAudience
    {
        $mode = AudienceMode::from(Rows::string($row, 'audience_mode'));

        return CardAudience::reconstitute($mode, $mode === AudienceMode::Narrowed ? Rows::audiences($keys) : AudienceSet::none());
    }
}
