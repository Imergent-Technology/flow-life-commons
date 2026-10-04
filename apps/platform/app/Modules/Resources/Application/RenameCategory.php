<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/** Renames a Category. Needs `resources.manage`. Renaming to the name it already has is allowed; to another's is `duplicate_category`. */
final readonly class RenameCategory
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws CategoryNotFound
     * @throws InvalidResourceInput
     * @throws DuplicateCategory
     */
    public function __invoke(Actor $actor, CategoryId $id, string $name): CategoryView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        try {
            $renamed = $this->database->transaction(function () use ($actor, $id, $name) {
                $category = $this->categories->lock($id) ?? throw new CategoryNotFound;
                $renamed = $category->renamed($name, $actor->personId, DateTimeImmutable::createFromInterface(now()));
                $same = $this->categories->findByCanonicalName($renamed->nameCanonical);
                if ($same !== null && ! $same->id->equals($id)) {
                    throw new DuplicateCategory;
                }
                $this->categories->saveName($renamed);

                return $renamed;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateCategory;
        }

        return $this->views->categories([$renamed])[0];
    }
}
