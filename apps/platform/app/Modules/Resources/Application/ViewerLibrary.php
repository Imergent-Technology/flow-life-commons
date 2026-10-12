<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Identity\Application\FindPeople;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\Provenance;
use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Domain\TextSearch;

/**
 * POLICY A, the privileged viewer's read model (ADR 0038, R4-R9): every Category that holds a Pack, every Pack and Card at its latest
 * saved content, Drafts and every audience included. Internal to Resources; `BrowseLibrary`, `ReadLibraryPack` and
 * `DownloadLibraryFile` have already asked for `resources.view`, and that is the ONLY thing that opens this class.
 *
 * It is deliberately not delivery. It never calls `ResourceProjection`, receives no audience set, asks neither `AudienceEligibility` nor
 * any relationship or Membership, and infers nothing from a role. Publication and audiences are DATA it reports, never a rule it
 * applies, so a Draft, an unpublished Pack or a Card for an audience nobody belongs to is simply read. With policy C it shares only
 * pure pieces: ordering (`position`, then id, from the repositories), outlines, Card loading, text matching and the file opener.
 *
 * It offers nothing that changes anything: no revision, stored position or creator is read out of it.
 */
final readonly class ViewerLibrary
{
    public function __construct(
        private PackRepository $packs,
        private CardRepository $cards,
        private CategoryRepository $categories,
        private FindPeople $findPeople,
    ) {}

    /**
     * Categories in order, each with the Packs the filters kept, then the uncategorized Packs as a final group. A Category with no Pack
     * (or none the filters kept) is not listed.
     *
     * @return list<ViewerCategory>
     */
    public function browse(LibraryFilter $filter): array
    {
        $packs = $this->packs->every($filter->category);
        $outlines = $this->cards->outlinesOfPacks(array_map(static fn (Pack $p): PackId => $p->id, $packs));
        $needle = TextSearch::needle($filter->text);

        $kept = [];
        foreach ($packs as $pack) {
            $cards = $outlines[$pack->id->value] ?? [];
            $drafts = count(array_filter($cards, static fn (CardOutline $c): bool => ! $c->isPublished()));
            if ($filter->publication !== null && ! $filter->publication->admits($pack, $drafts)) {
                continue;
            }
            if ($filter->audience !== null && ! $pack->audiences->contains($filter->audience)) {
                continue;
            }
            if ($needle !== null && ! TextSearch::matches($needle, self::texts($pack, $cards))) {
                continue;
            }
            $kept[] = [$pack, count($cards), $drafts];
        }

        $names = $this->names(array_map(static fn (array $k): Provenance => $k[0]->provenance, $kept));
        $byCategory = [];
        foreach ($kept as [$pack, $count, $drafts]) {
            $byCategory[$pack->categoryId->value ?? ''][] = new ViewerPackSummary($pack, $count, $drafts, $names[$pack->provenance->updatedBy->value] ?? null);
        }

        $library = [];
        foreach ($this->categories->all() as $category) {
            if (isset($byCategory[$category->id->value])) {
                $library[] = new ViewerCategory($category, $byCategory[$category->id->value]);
            }
        }
        // A Pack whose Category row is gone cannot be told from an uncategorized one in the listing; the foreign key keeps that from happening.
        if (isset($byCategory[''])) {
            $library[] = new ViewerCategory(null, $byCategory['']);
        }

        return $library;
    }

    /** @throws ResourcePackNotFound */
    public function pack(PackId $id): ViewerPack
    {
        $pack = $this->packs->find($id) ?? throw new ResourcePackNotFound;
        $outlines = $this->cards->outlinesOf($id);
        $cards = $this->cards->findMany(array_map(static fn (CardOutline $o): CardId => $o->id, $outlines));
        $names = $this->names([$pack->provenance, ...array_map(static fn (Card $c): Provenance => $c->provenance, $cards)]);

        $viewed = [];
        foreach ($cards as $i => $card) {
            $viewed[] = new ViewerCard($card, $i + 1, $card->audience->effective($pack->audiences), $names[$card->provenance->updatedBy->value] ?? null);
        }
        $category = $pack->categoryId === null ? null : $this->categories->find($pack->categoryId);
        $drafts = count(array_filter($outlines, static fn (CardOutline $c): bool => ! $c->isPublished()));

        return new ViewerPack($pack, $category, $drafts, $names[$pack->provenance->updatedBy->value] ?? null, $viewed);
    }

    /**
     * The file of any File Card of an existing Pack. A Pack that does not exist, or a Card that is not in it, is `ResourcePackNotFound`
     * (a viewer may know a Card exists elsewhere, but not through this Pack); a Card in the Pack without a file is `AssetUnavailable`.
     *
     * @throws ResourcePackNotFound
     * @throws AssetUnavailable
     */
    public function file(PackId $pack, CardId $card): ResourceAsset
    {
        $this->packs->find($pack) ?? throw new ResourcePackNotFound;
        $found = $this->cards->find($card);
        if ($found === null || ! $found->packId->equals($pack)) {
            throw new ResourcePackNotFound;
        }

        return $found->asset ?? throw new AssetUnavailable;
    }

    /**
     * Every text a search may match for a Pack: its own title and summary and EVERY Card's, Drafts included (R8).
     *
     * @param  list<CardOutline>  $cards
     * @return list<string>
     */
    private static function texts(Pack $pack, array $cards): array
    {
        $texts = [$pack->title, $pack->summary ?? ''];
        foreach ($cards as $card) {
            $texts[] = $card->title;
            $texts[] = $card->summary->text;
        }

        return $texts;
    }

    /**
     * Last editors' display names. Identity owns names, so this asks its batched lookup.
     *
     * @param  list<Provenance>  $provenance
     * @return array<string, string> keyed by PersonId value
     */
    private function names(array $provenance): array
    {
        $ids = [];
        foreach ($provenance as $p) {
            $ids[$p->updatedBy->value] = $p->updatedBy;
        }
        $names = [];
        foreach ($ids === [] ? [] : ($this->findPeople)(array_values($ids)) as $key => $summary) {
            $names[$key] = $summary->displayName;
        }

        return $names;
    }
}
