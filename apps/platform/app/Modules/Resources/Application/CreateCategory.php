<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Adds a Category at the end of the order. Needs `resources.manage`. "Training guides" and "training  Guides" are one name.
 *
 * Takes the lock on the whole set first, so a creation cannot slip into a reorder that is deciding what the set is (ADR 0037,
 * decision 57); `unique(name_canonical)` is the backstop for two creations of one name at once.
 */
final readonly class CreateCategory
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws InvalidResourceInput
     * @throws DuplicateCategory
     */
    public function __invoke(Actor $actor, string $name): CategoryView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        try {
            $category = $this->database->transaction(function () use ($actor, $name): Category {
                $this->categories->lockAll();
                $category = Category::create(CategoryId::generate(), $name, $this->categories->nextPosition(), $actor->personId, DateTimeImmutable::createFromInterface(now()));
                if ($this->categories->findByCanonicalName($category->nameCanonical) !== null) {
                    throw new DuplicateCategory;
                }
                $this->categories->add($category);

                return $category;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateCategory; // two creations of one name at once: the unique index decides, and the loser gets the same answer
        }

        return $this->views->categories([$category])[0];
    }
}
