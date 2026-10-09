<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Relationships\Domain\DeletionCounts;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Shared\Domain\PersonId;
use Closure;
use DateTimeImmutable;

/** Fires once, after a chosen repository method returns, with the caller's transaction still open. */
final class RelationshipsPauses
{
    public static function after(string $method, Closure $hook): void
    {
        $inner = app(RelationshipRepository::class);
        app()->instance(RelationshipRepository::class, new PausingRelationshipRepository($inner, $method, $hook));
    }
}

final class PausingRelationshipRepository implements RelationshipRepository
{
    private bool $fired = false;

    public function __construct(private RelationshipRepository $inner, private string $method, private Closure $hook) {}

    public function find(PersonId $person, string $typeKey): ?RelationshipRecord
    {
        return $this->inner->find($person, $typeKey);
    }

    public function lock(PersonId $person, string $typeKey): ?RelationshipRecord
    {
        $record = $this->inner->lock($person, $typeKey);
        $this->fire('lock');

        return $record;
    }

    public function add(RelationshipRecord $record): void
    {
        $this->inner->add($record);
        $this->fire('add');
    }

    public function saveStatus(RelationshipRecord $updated, int $expectedRevision): bool
    {
        return $this->inner->saveStatus($updated, $expectedRevision);
    }

    public function saveFields(RelationshipRecord $updated, int $expectedRevision, array $written, array $cleared, PersonId $by, DateTimeImmutable $at): bool
    {
        return $this->inner->saveFields($updated, $expectedRevision, $written, $cleared, $by, $at);
    }

    public function delete(RelationshipId $id): DeletionCounts
    {
        return $this->inner->delete($id);
    }

    public function personIds(string $typeKey, ?string $status): array
    {
        return $this->inner->personIds($typeKey, $status);
    }

    public function statusesFor(string $typeKey, array $personIds): array
    {
        return $this->inner->statusesFor($typeKey, $personIds);
    }

    public function currentOf(PersonId $person): array
    {
        return $this->inner->currentOf($person);
    }

    public function storedRelationships(): array
    {
        return $this->inner->storedRelationships();
    }

    public function storedFieldValues(): array
    {
        return $this->inner->storedFieldValues();
    }

    private function fire(string $method): void
    {
        if ($method === $this->method && ! $this->fired) {
            $this->fired = true;
            ($this->hook)();
        }
    }
}
