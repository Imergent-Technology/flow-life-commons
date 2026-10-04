<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\PublicationState;
use Closure;

/**
 * Pauses a Resources use case BETWEEN a read and the write it will make from it, in the first process of a race (tests/Concurrency).
 *
 * The race harness (Race::against) normally pauses after the use case has written, and a write takes its own row lock, which would hide a
 * missing EXPLICIT lock: the contended row is locked by the write anyway. The window an explicit lock really protects is the one
 * between "I looked at the state" and "I changed it", so these decorators fire a hook once, right after a chosen repository read returns,
 * with the use case's transaction open, its locks (if it took any) held and nothing yet written. A second process that then runs a
 * competing operation either BLOCKS (the lock is there) or sails through on the stale state (it is not).
 */
final class ResourcesPauses
{
    /** After `CardRepository::$method` returns once. */
    public static function afterCard(string $method, Closure $hook): void
    {
        $inner = app(CardRepository::class);
        app()->bind(CardRepository::class, fn () => new PausingCardRepository($inner, $method, $hook));
    }

    /** After `PackRepository::$method` returns once. */
    public static function afterPack(string $method, Closure $hook): void
    {
        $inner = app(PackRepository::class);
        app()->bind(PackRepository::class, fn () => new PausingPackRepository($inner, $method, $hook));
    }

    /** After `CategoryRepository::$method` returns once. */
    public static function afterCategory(string $method, Closure $hook): void
    {
        $inner = app(CategoryRepository::class);
        app()->bind(CategoryRepository::class, fn () => new PausingCategoryRepository($inner, $method, $hook));
    }
}

/** Fires its hook the first time the named method returns. Shared by the three decorators. */
trait FiresOnce
{
    private bool $fired = false;

    private function fire(string $method): void
    {
        if ($method === $this->method && ! $this->fired) {
            $this->fired = true;
            ($this->hook)();
        }
    }
}

final class PausingCardRepository implements CardRepository
{
    use FiresOnce;

    public function __construct(private CardRepository $inner, private string $method, private Closure $hook) {}

    public function find(CardId $id): ?Card
    {
        $r = $this->inner->find($id);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function lock(CardId $id): ?Card
    {
        $r = $this->inner->lock($id);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function findMany(array $ids): array
    {
        return $this->inner->findMany($ids);
    }

    public function outlinesOf(PackId $pack): array
    {
        $r = $this->inner->outlinesOf($pack);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function outlinesOfPacks(array $packs): array
    {
        return $this->inner->outlinesOfPacks($packs);
    }

    public function add(Card $card): void
    {
        $this->inner->add($card);
    }

    public function saveAuthored(Card $card, int $expectedRevision, PublicationState $observedState): bool
    {
        return $this->inner->saveAuthored($card, $expectedRevision, $observedState);
    }

    public function saveState(Card $card): void
    {
        $this->inner->saveState($card);
    }

    public function saveAudience(Card $card): void
    {
        $this->inner->saveAudience($card);
    }

    public function savePosition(CardId $id, int $position): void
    {
        $this->inner->savePosition($id, $position);
    }

    public function delete(CardId $id): void
    {
        $this->inner->delete($id);
    }

    public function deleteAllOf(PackId $pack): int
    {
        $r = $this->inner->deleteAllOf($pack);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function countIn(PackId $pack): int
    {
        $r = $this->inner->countIn($pack);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function nextPositionIn(PackId $pack): int
    {
        $r = $this->inner->nextPositionIn($pack);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function countsByPack(array $packs): array
    {
        return $this->inner->countsByPack($packs);
    }
}

final class PausingPackRepository implements PackRepository
{
    use FiresOnce;

    public function __construct(private PackRepository $inner, private string $method, private Closure $hook) {}

    public function find(PackId $id): ?Pack
    {
        $r = $this->inner->find($id);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function lock(PackId $id): ?Pack
    {
        $r = $this->inner->lock($id);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function add(Pack $pack): void
    {
        $this->inner->add($pack);
    }

    public function saveAuthored(Pack $pack, int $expectedRevision): bool
    {
        return $this->inner->saveAuthored($pack, $expectedRevision);
    }

    public function saveState(Pack $pack): void
    {
        $this->inner->saveState($pack);
    }

    public function saveAudiences(Pack $pack): void
    {
        $this->inner->saveAudiences($pack);
    }

    public function savePosition(PackId $id, int $position): void
    {
        $this->inner->savePosition($id, $position);
    }

    public function delete(PackId $id): void
    {
        $this->inner->delete($id);
    }

    public function idsIn(?CategoryId $category): array
    {
        $r = $this->inner->idsIn($category);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function countIn(CategoryId $category): int
    {
        $r = $this->inner->countIn($category);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function nextPositionIn(?CategoryId $category): int
    {
        $r = $this->inner->nextPositionIn($category);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function page(ManagedPackFilter $filter, int $page, int $perPage): array
    {
        return $this->inner->page($filter, $page, $perPage);
    }

    public function count(ManagedPackFilter $filter): int
    {
        return $this->inner->count($filter);
    }

    public function published(?CategoryId $category): array
    {
        return $this->inner->published($category);
    }

    public function countsByCategory(array $categories): array
    {
        return $this->inner->countsByCategory($categories);
    }
}

final class PausingCategoryRepository implements CategoryRepository
{
    use FiresOnce;

    public function __construct(private CategoryRepository $inner, private string $method, private Closure $hook) {}

    public function find(CategoryId $id): ?Category
    {
        return $this->inner->find($id);
    }

    public function lock(CategoryId $id): ?Category
    {
        $r = $this->inner->lock($id);
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function lockAll(): array
    {
        $r = $this->inner->lockAll();
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function findByCanonicalName(string $canonical): ?Category
    {
        return $this->inner->findByCanonicalName($canonical);
    }

    public function nextPosition(): int
    {
        $r = $this->inner->nextPosition();
        $this->fire(__FUNCTION__);

        return $r;
    }

    public function add(Category $category): void
    {
        $this->inner->add($category);
    }

    public function saveName(Category $category): void
    {
        $this->inner->saveName($category);
    }

    public function savePosition(CategoryId $id, int $position): void
    {
        $this->inner->savePosition($id, $position);
    }

    public function delete(CategoryId $id): void
    {
        $this->inner->delete($id);
    }
}
