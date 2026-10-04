<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * Deletes a Category that no Pack holds, in any state (ADR 0037, decision 10). Needs `resources.manage` and nothing more: it
 * loses a name, not content, so it asks for no recent verification. It never reassigns Packs: a Category in use is refused
 * `category_not_empty`, and the foreign key is the backstop. The Category's row lock is what serialises this against a Pack
 * being created into, or moved into, the Category.
 */
final readonly class DeleteCategory
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private PackRepository $packs,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws CategoryNotFound
     * @throws CategoryNotEmpty
     */
    public function __invoke(Actor $actor, CategoryId $id): void
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->database->transaction(function () use ($id): void {
            $this->categories->lock($id) ?? throw new CategoryNotFound;
            if ($this->packs->countIn($id) > 0) {
                throw new CategoryNotEmpty;
            }
            $this->categories->delete($id);
        }, 3);
    }
}
