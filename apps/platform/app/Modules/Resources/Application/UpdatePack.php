<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Changes a Pack's authored fields: `title`, `summary` (null clears it), `is_series` and `category_id` (null clears it). Only the
 * keys present in `$changes` change. Needs `resources.manage`; anyone who holds it may edit any Pack (ADR 0037, decision 54).
 *
 * The edit must name the revision it was based on and is refused `stale_revision`, with the current state, if someone else's
 * edit won (decision 56). The write itself is conditional on that revision, so two editors cannot both succeed whatever the
 * timing. Changing the Category is the one structural edit, so it locks the Categories involved (in id order) and then the Pack,
 * and re-reads the Pack under the lock before deciding; clearing the Category of a PUBLISHED Pack is refused
 * `published_pack_requirement`.
 */
final readonly class UpdatePack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CategoryRepository $categories,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  any of `title`, `summary`, `is_series`, `category_id`
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws StaleRevision
     * @throws InvalidResourceInput
     * @throws PublishedPackRequirement
     * @throws UnknownCategory
     */
    public function __invoke(Actor $actor, PackId $id, int $revision, array $changes): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $pack = $this->packs->find($id) ?? throw new PackNotFound;
        $target = array_key_exists('category_id', $changes) ? self::category($changes['category_id']) : $pack->categoryId;

        $edited = self::sameCategory($pack->categoryId, $target)
            ? $this->edit($actor, $pack, $revision, $changes, $target)
            : $this->database->transaction(fn (): Pack => $this->move($actor, $id, $revision, $changes, $target), 3);

        return $this->views->pack($edited);
    }

    /**
     * An edit that leaves the Category alone: no lock, the conditional write is the guard.
     *
     * @param  array<string, mixed>  $changes
     */
    private function edit(Actor $actor, Pack $pack, int $revision, array $changes, ?CategoryId $category): Pack
    {
        if ($pack->revision !== $revision) {
            throw new StaleRevision($this->views->pack($pack));
        }
        $edited = $this->apply($actor, $pack, $changes, $category, $pack->position);
        if (! $this->packs->saveAuthored($edited, $revision)) {
            throw new StaleRevision($this->views->pack($this->current($pack->id)));
        }

        return $edited;
    }

    /**
     * A Category change: Categories in id order, then the Pack, re-read under the lock.
     *
     * @param  array<string, mixed>  $changes
     */
    private function move(Actor $actor, PackId $id, int $revision, array $changes, ?CategoryId $target): Pack
    {
        $first = $this->packs->find($id) ?? throw new PackNotFound;
        $involved = [];
        foreach ([$first->categoryId, $target] as $category) {
            if ($category !== null) {
                $involved[$category->value] = $category;
            }
        }
        ksort($involved);
        foreach ($involved as $category) {
            if ($this->categories->lock($category) === null && $target !== null && $category->equals($target)) {
                throw new UnknownCategory;
            }
        }

        $pack = $this->packs->lock($id) ?? throw new PackNotFound;
        if ($pack->revision !== $revision) {
            throw new StaleRevision($this->views->pack($pack));
        }
        if ($target === null && $pack->isPublished()) {
            throw new PublishedPackRequirement(Pack::NEEDS_CATEGORY);
        }
        $edited = $this->apply($actor, $pack, $changes, $target, $this->packs->nextPositionIn($target));
        if (! $this->packs->saveAuthored($edited, $revision)) {
            throw new StaleRevision($this->views->pack($this->current($id)));
        }

        return $edited;
    }

    /** @throws PackNotFound */
    private function current(PackId $id): Pack
    {
        return $this->packs->find($id) ?? throw new PackNotFound;
    }

    /** @param  array<string, mixed>  $changes */
    private function apply(Actor $actor, Pack $pack, array $changes, ?CategoryId $category, int $position): Pack
    {
        return $pack->edited(
            self::string($changes, 'title', $pack->title),
            array_key_exists('summary', $changes) ? self::nullableString($changes['summary']) : $pack->summary,
            array_key_exists('is_series', $changes) ? (bool) $changes['is_series'] : $pack->isSeries,
            $category,
            $position,
            $actor->personId,
            DateTimeImmutable::createFromInterface(now()),
        );
    }

    private static function sameCategory(?CategoryId $a, ?CategoryId $b): bool
    {
        return $a === null ? $b === null : ($b !== null && $a->equals($b));
    }

    private static function category(mixed $value): ?CategoryId
    {
        return is_string($value) ? CategoryId::fromString($value) : null;
    }

    /** @param  array<string, mixed>  $changes */
    private static function string(array $changes, string $key, string $current): string
    {
        $value = $changes[$key] ?? null;

        return is_string($value) ? $value : $current;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
