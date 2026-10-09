<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Domain\SourcedRoleGrant;
use App\Modules\Access\Domain\SourcedRoleGrantId;
use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Modules\Access\Infrastructure\DatabaseSourcedRoleGrantRepository;
use App\Shared\Domain\PersonId;
use Closure;

/** Fires once, after a chosen sourced-grant repository method returns, with the caller's transaction still open. */
final class SourcedRoleGrantPauses
{
    public static function after(string $method, Closure $hook): void
    {
        $inner = app(DatabaseSourcedRoleGrantRepository::class);
        app()->instance(SourcedRoleGrantRepository::class, new PausingSourcedRoleGrantRepository($inner, $method, $hook));
    }
}

final class PausingSourcedRoleGrantRepository implements SourcedRoleGrantRepository
{
    private bool $fired = false;

    public function __construct(private SourcedRoleGrantRepository $inner, private string $method, private Closure $hook) {}

    public function forPerson(PersonId $personId): array
    {
        return $this->inner->forPerson($personId);
    }

    public function forPeople(array $personIds): array
    {
        return $this->inner->forPeople($personIds);
    }

    public function forSourceType(string $sourceType): array
    {
        return $this->inner->forSourceType($sourceType);
    }

    public function forSources(array $sources): array
    {
        return $this->inner->forSources($sources);
    }

    public function findBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
    {
        return $this->inner->findBySourceAndRole($sourceType, $sourceId, $roleKey);
    }

    public function lockBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
    {
        return $this->inner->lockBySourceAndRole($sourceType, $sourceId, $roleKey);
    }

    public function lockSource(string $sourceType, string $sourceId): void
    {
        $this->inner->lockSource($sourceType, $sourceId);
    }

    public function lockForSource(string $sourceType, string $sourceId): array
    {
        $grants = $this->inner->lockForSource($sourceType, $sourceId);
        $this->fire('lockForSource');

        return $grants;
    }

    public function add(SourcedRoleGrant $grant): void
    {
        $this->inner->add($grant);
    }

    public function delete(SourcedRoleGrantId $id): void
    {
        $this->inner->delete($id);
    }

    public function personIdsHoldingRoles(array $roleKeys): array
    {
        return $this->inner->personIdsHoldingRoles($roleKeys);
    }

    private function fire(string $method): void
    {
        if ($method === $this->method && ! $this->fired) {
            $this->fired = true;
            ($this->hook)();
        }
    }
}
