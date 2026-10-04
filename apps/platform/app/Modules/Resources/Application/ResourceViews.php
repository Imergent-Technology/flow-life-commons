<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Identity\Application\FindPeople;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\Provenance;
use App\Shared\Domain\PersonId;

/**
 * Composes the views management returns, for every management use case. Internal to Resources. Identity owns Person names, so
 * this asks Identity's batched `FindPeople` (one call for a whole page, never a read of `people`), which also means a rename
 * shows on everything already written.
 */
final readonly class ResourceViews
{
    public function __construct(
        private FindPeople $findPeople,
        private CategoryRepository $categories,
        private PackRepository $packs,
        private CardRepository $cards,
    ) {}

    /**
     * @param  list<Category>  $categories
     * @return list<CategoryView>
     */
    public function categories(array $categories): array
    {
        $counts = $this->packs->countsByCategory(array_map(static fn (Category $c) => $c->id, $categories));
        $names = $this->names(array_map(static fn (Category $c): Provenance => $c->provenance, $categories));

        return array_map(fn (Category $c): CategoryView => new CategoryView(
            $c, $counts[$c->id->value] ?? 0, self::person($c->provenance->createdBy, $names), self::person($c->provenance->updatedBy, $names),
        ), $categories);
    }

    /**
     * @param  list<Pack>  $packs
     * @param  bool  $withCards  the contents list too (for one Pack), or just the counts (for a list)
     * @return list<ManagedPackView>
     */
    public function packs(array $packs, bool $withCards): array
    {
        $ids = array_map(static fn (Pack $p) => $p->id, $packs);
        $counts = $this->cards->countsByPack($ids);
        $outlines = $withCards ? $this->cards->outlinesOfPacks($ids) : [];

        $provenance = array_map(static fn (Pack $p): Provenance => $p->provenance, $packs);
        foreach ($outlines as $list) {
            foreach ($list as $outline) {
                $provenance[] = $outline->provenance;
            }
        }
        $names = $this->names($provenance);

        $categories = [];
        foreach ($packs as $pack) {
            if ($pack->categoryId !== null && ! isset($categories[$pack->categoryId->value])) {
                $categories[$pack->categoryId->value] = $this->categories->find($pack->categoryId);
            }
        }

        return array_map(function (Pack $pack) use ($counts, $outlines, $categories, $names, $withCards): ManagedPackView {
            $count = $counts[$pack->id->value] ?? ['total' => 0, 'published' => 0];

            return new ManagedPackView(
                $pack,
                $pack->categoryId === null ? null : ($categories[$pack->categoryId->value] ?? null),
                $count['total'],
                $count['published'],
                $withCards ? array_map(fn (CardOutline $o): ManagedCardOutlineView => $this->outline($o, $names), $outlines[$pack->id->value] ?? []) : null,
                self::person($pack->provenance->createdBy, $names),
                self::person($pack->provenance->updatedBy, $names),
            );
        }, $packs);
    }

    public function pack(Pack $pack): ManagedPackView
    {
        return $this->packs([$pack], true)[0];
    }

    public function card(Card $card): ManagedCardView
    {
        $names = $this->names([$card->provenance]);

        return new ManagedCardView($card, self::person($card->provenance->createdBy, $names), self::person($card->provenance->updatedBy, $names));
    }

    /**
     * @param  list<Provenance>  $provenance
     * @return array<string, string> display names keyed by PersonId value
     */
    private function names(array $provenance): array
    {
        $ids = [];
        foreach ($provenance as $p) {
            $ids[$p->createdBy->value] = $p->createdBy;
            $ids[$p->updatedBy->value] = $p->updatedBy;
        }
        $names = [];
        foreach ($ids === [] ? [] : ($this->findPeople)(array_values($ids)) as $key => $summary) {
            $names[$key] = $summary->displayName;
        }

        return $names;
    }

    /** @param  array<string, string>  $names */
    private function outline(CardOutline $outline, array $names): ManagedCardOutlineView
    {
        return new ManagedCardOutlineView($outline, self::person($outline->provenance->createdBy, $names), self::person($outline->provenance->updatedBy, $names));
    }

    /** @param  array<string, string>  $names */
    private static function person(PersonId $id, array $names): ResourcePerson
    {
        return new ResourcePerson($id, $names[$id->value] ?? null);
    }
}
