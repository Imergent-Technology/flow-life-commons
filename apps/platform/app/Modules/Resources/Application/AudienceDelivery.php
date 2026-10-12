<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceProjection;
use App\Modules\Resources\Domain\TextSearch;
use App\Shared\Domain\Actor;

/**
 * POLICY C, audience delivery (ADR 0038, R11-R13): what a signed-in Person may have of the Published Resources, as the ONE projection
 * rule decides it (ADR 0037, decisions 45-48), for the audiences `AudienceEligibility` says they are eligible for NOW. Internal to
 * Resources, and the one place (with preview) that calls `ResourceProjection::visibleCards`.
 *
 * Its audience set comes from `AudienceEligibility` and from nothing else: not a parameter, not a capability, not a role, not a
 * surface. It asks for no capability either, because the answer is about the Actor's OWN relationships. A Person eligible for nothing
 * is shown nothing, and everything not shown is the same `ResourcePackNotFound`, with nothing to tell which: a missing Pack, a Draft, an
 * unpublished or ineligible one, one with no Card this reader may see, a Draft or narrowed-away Card.
 *
 * It depends on no Console class, no `console.access` and no Guardian role, so a later surface (WordPress, `/my/`) reuses it through
 * its own authenticated adapter.
 */
final readonly class AudienceDelivery
{
    public function __construct(
        private AudienceEligibility $eligibility,
        private PackRepository $packs,
        private CardRepository $cards,
        private CategoryRepository $categories,
    ) {}

    /**
     * Categories in order, each with the Packs this reader may see in it, optionally one Category and `$search`: a case-insensitive
     * "contains" over a Pack's title and summary and the titles and summaries of its VISIBLE Cards only, so an unpublished or
     * unauthorized Card can never make a Pack match. Audience is not a filter: the reader receives their own projection.
     *
     * @return list<DeliveredCategory>
     */
    public function library(Actor $actor, ?CategoryId $category, ?string $search): array
    {
        $viewer = ($this->eligibility)($actor);
        if ($viewer->isEmpty()) {
            return [];
        }
        $needle = TextSearch::needle($search);

        $packs = $this->packs->published($category);
        $outlines = $this->cards->outlinesOfPacks(array_map(static fn (Pack $p): PackId => $p->id, $packs));

        $byCategory = [];
        foreach ($packs as $pack) {
            $visible = ResourceProjection::visibleCards($pack, $outlines[$pack->id->value] ?? [], $viewer);
            if ($visible === [] || ($needle !== null && ! TextSearch::matches($needle, self::texts($pack, $visible)))) {
                continue;
            }
            if ($pack->categoryId !== null) {
                $byCategory[$pack->categoryId->value][] = new DeliveredListedPack($pack, count($visible));
            }
        }

        $library = [];
        foreach ($this->categories->all() as $cat) {
            if (isset($byCategory[$cat->id->value])) {
                $library[] = new DeliveredCategory($cat, $byCategory[$cat->id->value]);
            }
        }

        return $library;
    }

    /**
     * @return array{Pack, Category, list<CardOutline>} the Pack, its Category and its visible Cards, in order
     *
     * @throws ResourcePackNotFound
     */
    public function visible(Actor $actor, PackId $id): array
    {
        $viewer = ($this->eligibility)($actor);
        $pack = $this->packs->find($id) ?? throw new ResourcePackNotFound;
        $visible = ResourceProjection::visibleCards($pack, $this->cards->outlinesOf($id), $viewer);
        $category = $pack->categoryId === null ? null : $this->categories->find($pack->categoryId);
        if ($visible === [] || $category === null) {
            throw new ResourcePackNotFound;
        }

        return [$pack, $category, $visible];
    }

    /**
     * @param  list<CardOutline>  $visible
     * @return list<string>
     */
    private static function texts(Pack $pack, array $visible): array
    {
        $texts = [$pack->title, $pack->summary ?? ''];
        foreach ($visible as $card) {
            $texts[] = $card->title;
            $texts[] = $card->summary->text;
        }

        return $texts;
    }
}
