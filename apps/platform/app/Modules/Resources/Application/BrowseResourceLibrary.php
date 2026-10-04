<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceProjection;
use App\Shared\Domain\Actor;

/**
 * The Guardian Console's library: Categories in order, each with the Packs this viewer may see in it, found through the ONE
 * projection rule (ADR 0037, decisions 45-48). Needs `resources.view`. A Category with no visible Pack is not listed, a Pack with
 * no visible Card is omitted, and the count of Cards shown is the count THIS viewer can see.
 *
 * Optionally one Category, and `$search`: a case-insensitive "contains" over a Pack's title and summary and the titles and
 * summaries of its VISIBLE Cards only, so an unpublished or unauthorized Card can never make a Pack match, and rich content is
 * never searched. It runs over the projection in PHP with Unicode case folding, so it cannot disagree between the two database
 * engines and cannot find what the projection hid. Audience is not a filter: the viewer simply receives their own projection.
 */
final readonly class BrowseResourceLibrary
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private PackRepository $packs,
        private CardRepository $cards,
    ) {}

    /**
     * @return list<LibraryCategory>
     *
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor, ?CategoryId $category, ?string $search): array
    {
        ($this->authorize)($actor, Capability::ViewResources);

        $viewer = DeliverySurface::guardianConsole();
        $needle = $search === null || trim($search) === '' ? null : trim($search);

        $packs = $this->packs->published($category);
        $outlines = $this->cards->outlinesOfPacks(array_map(static fn (Pack $p) => $p->id, $packs));

        $byCategory = [];
        foreach ($packs as $pack) {
            $visible = ResourceProjection::visibleCards($pack, $outlines[$pack->id->value] ?? [], $viewer);
            if ($visible === [] || ($needle !== null && ! self::matches($pack, $visible, $needle))) {
                continue;
            }
            if ($pack->categoryId !== null) {
                $byCategory[$pack->categoryId->value][] = new LibraryPack($pack, count($visible));
            }
        }

        $library = [];
        foreach ($this->categories->all() as $cat) {
            if (isset($byCategory[$cat->id->value])) {
                $library[] = new LibraryCategory($cat, $byCategory[$cat->id->value]);
            }
        }

        return $library;
    }

    /** @param  list<CardOutline>  $visible */
    private static function matches(Pack $pack, array $visible, string $needle): bool
    {
        $haystacks = [$pack->title, $pack->summary ?? ''];
        foreach ($visible as $card) {
            $haystacks[] = $card->title;
            $haystacks[] = $card->summary->text;
        }
        foreach ($haystacks as $text) {
            if ($text !== '' && mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
