<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * Sets the order of ALL Categories from the complete ordered list of their ids. Needs `resources.manage`. The set is locked,
 * the list must be exactly the set that is there now (`order_mismatch` otherwise), and positions are rewritten 1..n in one
 * transaction (ADR 0037, decisions 8-9). Ordering is not editing: provenance is untouched.
 */
final readonly class ReorderCategories
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<CategoryId>  $order
     * @return list<CategoryView>
     *
     * @throws AccessDenied
     * @throws OrderMismatch
     */
    public function __invoke(Actor $actor, array $order): array
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->database->transaction(function () use ($order): void {
            $current = $this->categories->lockAll();
            SiblingOrder::assertSameSet(
                array_map(static fn (Category $c): string => $c->id->value, $current),
                array_map(static fn (CategoryId $id): string => $id->value, $order),
            );
            foreach ($order as $i => $id) {
                $this->categories->savePosition($id, $i + 1);
            }
        }, 3);

        return $this->views->categories($this->categories->all());
    }
}
