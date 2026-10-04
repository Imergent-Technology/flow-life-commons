<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

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
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PublicationState;
use App\Modules\Resources\Domain\SummaryMode;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Query builder, no model. Every write names its columns. Reading an outline never selects `content_document`, so listing a
 * Pack's Cards does not load up to 256 KiB of document for each.
 */
final readonly class DatabaseCardRepository implements CardRepository
{
    private const string TABLE = 'resource_cards';

    private const string AUDIENCES = 'resource_card_audiences';

    /** Every column but the content: what an outline needs. */
    private const array OUTLINE_COLUMNS = [
        'id', 'pack_id', 'position', 'type', 'title', 'summary_mode', 'summary_text', 'external_uri', 'audience_mode', 'state',
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

        return $row === null ? null : $this->hydrateCards([$row])[0];
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

    public function delete(CardId $id): void
    {
        $this->database->table(self::AUDIENCES)->where('card_id', $id->value)->delete();
        $this->database->table(self::TABLE)->where('id', $id->value)->delete();
    }

    public function deleteAllOf(PackId $pack): int
    {
        // A locking, CURRENT read, on purpose. The caller has the Pack's lock, so no Card can be added or removed from here on, but
        // one may have committed while it waited for that lock. A plain read would use the transaction's snapshot, which on MariaDB
        // (REPEATABLE READ) was fixed by an earlier plain read (finding the Pack), and would miss that Card, while the DELETE below
        // (always a current read) removes it: the count would be short and its audience rows would be left to trip the foreign key.
        $ids = $this->database->table(self::TABLE)->where('pack_id', $pack->value)->lockForUpdate()->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }
        $this->database->table(self::AUDIENCES)->whereIn('card_id', $ids)->delete();
        $this->database->table(self::TABLE)->where('pack_id', $pack->value)->delete();

        return count($ids);
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
    private function hydrateCards(array $rows): array
    {
        $keys = $this->audienceKeys($rows);

        return array_map(static function (stdClass $row) use ($keys): Card {
            $id = Rows::string($row, 'id');

            return Card::reconstitute(
                CardId::fromString($id),
                PackId::fromString(Rows::string($row, 'pack_id')),
                Rows::int($row, 'position'),
                CardType::from(Rows::string($row, 'type')),
                Rows::string($row, 'title'),
                CardSummary::reconstitute(SummaryMode::from(Rows::string($row, 'summary_mode')), Rows::string($row, 'summary_text')),
                ContentDocument::fromStored(Rows::string($row, 'content_format'), Rows::int($row, 'content_version'), Rows::string($row, 'content_document')),
                Rows::nullableString($row, 'external_uri'),
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

        return array_map(static function (stdClass $row) use ($keys): CardOutline {
            $id = Rows::string($row, 'id');

            return new CardOutline(
                CardId::fromString($id),
                PackId::fromString(Rows::string($row, 'pack_id')),
                Rows::int($row, 'position'),
                CardType::from(Rows::string($row, 'type')),
                Rows::string($row, 'title'),
                CardSummary::reconstitute(SummaryMode::from(Rows::string($row, 'summary_mode')), Rows::string($row, 'summary_text')),
                Rows::nullableString($row, 'external_uri'),
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
