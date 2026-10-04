<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * Sets the order of ALL the Packs in one Category from the complete ordered list of their ids. Needs `resources.manage`. The
 * Category's row is the lock, so a Pack cannot be created into or moved into the Category while the set is being decided; the
 * list must be exactly the set that is there now (`order_mismatch` otherwise). Ordering is not editing: provenance is untouched.
 */
final readonly class ReorderPacks
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private PackRepository $packs,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<PackId>  $order
     * @return list<ManagedPackView>
     *
     * @throws AccessDenied
     * @throws CategoryNotFound
     * @throws OrderMismatch
     */
    public function __invoke(Actor $actor, CategoryId $category, array $order): array
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->database->transaction(function () use ($category, $order): void {
            $this->categories->lock($category) ?? throw new CategoryNotFound;
            SiblingOrder::assertSameSet(
                array_map(static fn (PackId $id): string => $id->value, $this->packs->idsIn($category)),
                array_map(static fn (PackId $id): string => $id->value, $order),
            );
            foreach ($order as $i => $id) {
                $this->packs->savePosition($id, $i + 1);
            }
        }, 3);

        return $this->views->packs($this->packs->page(new ManagedPackFilter(category: $category), 1, 1000), false);
    }
}
