<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceProjection;
use App\Shared\Domain\Actor;

/**
 * What one audience would receive if the Pack were Published as it stands (ADR 0037, decision 49). Needs `resources.manage`:
 * it may show Drafts. It is the SAME projection delivery uses, with only the Pack's own publication set aside, so Card
 * publication, audiences, narrowing and non-disclosure are exactly as delivery would apply them. It changes nothing, publishes
 * nothing and is not an audience or a lifecycle state. The audience must be one the catalog knows: there is no free-form audience.
 * An audience that would see nothing is reported as such (`pack` null), not as a missing Pack, because the caller may see it all.
 */
final readonly class PreviewPack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private CategoryRepository $categories,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     */
    public function __invoke(Actor $actor, PackId $id, Audience $audience): PackPreview
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $pack = $this->packs->find($id) ?? throw new PackNotFound;
        $visible = ResourceProjection::visibleCards($pack, $this->cards->outlinesOf($id), AudienceSet::of($audience), asIfPublished: true);
        $category = $pack->categoryId === null ? null : $this->categories->find($pack->categoryId);

        $delivered = null;
        if ($visible !== [] && $category !== null) {
            $delivered = DeliveredPacks::of($pack, $category, $visible, $this->cards);
        }

        return new PackPreview($audience, $pack->state, $pack->audiences->contains($audience), $delivered);
    }
}
