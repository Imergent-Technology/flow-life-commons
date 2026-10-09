<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Domain\SourcedGrantAlreadyRecorded;
use App\Modules\Access\Domain\SourcedRoleGrant;
use App\Modules\Access\Domain\SourcedRoleGrantId;
use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use stdClass;

/**
 * Query builder rather than an Eloquent model, as for role assignments: nothing outside
 * this class can save or delete a sourced grant except through the methods below.
 */
final readonly class DatabaseSourcedRoleGrantRepository implements SourcedRoleGrantRepository
{
    private const string UNIQUE = 'sourced_role_grants_source_role_unique';

    public function __construct(private ConnectionInterface $database) {}

    public function forPerson(PersonId $personId): array
    {
        return $this->map($this->database->table('sourced_role_grants')
            ->where('person_id', $personId->value)
            ->orderBy('granted_at')
            ->orderBy('id')
            ->get());
    }

    public function forPeople(array $personIds): array
    {
        $byPerson = [];
        foreach ($personIds as $personId) {
            $byPerson[$personId->value] = [];
        }
        if ($personIds === []) {
            return $byPerson;
        }

        $rows = $this->database->table('sourced_role_grants')
            ->whereIn('person_id', array_map(static fn (PersonId $person): string => $person->value, $personIds))
            ->orderBy('granted_at')
            ->orderBy('id')
            ->get();

        foreach ($this->map($rows) as $grant) {
            $byPerson[$grant->personId->value][] = $grant;
        }

        return $byPerson;
    }

    public function forSourceType(string $sourceType): array
    {
        return array_values(array_filter(
            $this->map($this->database->table('sourced_role_grants')->where('source_type', $sourceType)->orderBy('id')->get()),
            static fn (SourcedRoleGrant $grant): bool => $grant->sourceType === $sourceType,
        ));
    }

    public function forSources(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $query = $this->database->table('sourced_role_grants');
        $query->where(function (Builder $outer) use ($sources): void {
            foreach ($sources as [$type, $id]) {
                $outer->orWhere(function (Builder $inner) use ($type, $id): void {
                    $inner->where('source_type', $type)->where('source_id', $id);
                });
            }
        });

        $wanted = [];
        foreach ($sources as [$type, $id]) {
            $wanted[$type."\0".$id] = true;
        }

        return array_values(array_filter(
            $this->map($query->orderBy('id')->get()),
            static fn (SourcedRoleGrant $grant): bool => isset($wanted[$grant->sourceType."\0".$grant->sourceId]),
        ));
    }

    public function findBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
    {
        return $this->one($this->matching($sourceType, $sourceId, $roleKey)->orderBy('id')->get(), $sourceType, $sourceId, $roleKey);
    }

    public function lockBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
    {
        $this->lockSource($sourceType, $sourceId);

        return $this->one(
            $this->matching($sourceType, $sourceId, $roleKey)->orderBy('id')->lockForUpdate()->get(),
            $sourceType,
            $sourceId,
            $roleKey,
        );
    }

    public function lockForSource(string $sourceType, string $sourceId): array
    {
        $this->lockSource($sourceType, $sourceId);

        $rows = $this->database->table('sourced_role_grants')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return array_values(array_filter(
            $this->map($rows),
            static fn (SourcedRoleGrant $grant): bool => $grant->sourceType === $sourceType && $grant->sourceId === $sourceId,
        ));
    }

    public function add(SourcedRoleGrant $grant): void
    {
        try {
            $this->database->table('sourced_role_grants')->insert([
                'id' => $grant->id->value,
                'person_id' => $grant->personId->value,
                'role_key' => $grant->roleKey,
                'source_type' => $grant->sourceType,
                'source_id' => $grant->sourceId,
                'granted_by_account_id' => $grant->grantedByAccountId?->value,
                'granted_at' => $grant->grantedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw str_contains($e->getMessage(), self::UNIQUE) ? new SourcedGrantAlreadyRecorded($e) : $e;
        }
    }

    public function delete(SourcedRoleGrantId $id): void
    {
        $this->database->table('sourced_role_grants')->where('id', $id->value)->delete();
    }

    public function personIdsHoldingRoles(array $roleKeys): array
    {
        if ($roleKeys === []) {
            return [];
        }

        $holders = [];
        $seen = [];
        foreach ($this->database->table('sourced_role_grants')->whereIn('role_key', $roleKeys)->orderBy('id')->get(['person_id', 'role_key']) as $row) {
            assert(is_string($row->person_id) && is_string($row->role_key));
            if (! in_array($row->role_key, $roleKeys, true) || isset($seen[$row->person_id])) {
                continue;
            }
            $seen[$row->person_id] = true;
            $holders[] = PersonId::fromString($row->person_id);
        }

        return $holders;
    }

    public function lockSource(string $sourceType, string $sourceId): void
    {
        $default = config('database.default');
        assert(is_string($default));
        if (config("database.connections.{$default}.driver") !== 'pgsql') {
            return;
        }

        $digest = hash('sha256', $sourceType."\0".$sourceId, true);
        $pair = unpack('Nhigh/Nlow', substr($digest, 0, 8));
        assert(is_array($pair));
        $high = $pair['high'];
        $low = $pair['low'];
        assert(is_int($high) && is_int($low));
        if ($high > 0x7FFFFFFF) {
            $high -= 0x100000000;
        }
        if ($low > 0x7FFFFFFF) {
            $low -= 0x100000000;
        }
        $this->database->select('select pg_advisory_xact_lock(?, ?)', [$high, $low]);
    }

    private function matching(string $sourceType, string $sourceId, string $roleKey): Builder
    {
        return $this->database->table('sourced_role_grants')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('role_key', $roleKey);
    }

    /**
     * @param  iterable<stdClass>  $rows
     */
    private function one(iterable $rows, string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
    {
        foreach ($this->map($rows) as $grant) {
            if ($grant->sourceType === $sourceType && $grant->sourceId === $sourceId && $grant->roleKey === $roleKey) {
                return $grant;
            }
        }

        return null;
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return list<SourcedRoleGrant>
     */
    private function map(iterable $rows): array
    {
        $grants = [];
        foreach ($rows as $row) {
            assert(is_string($row->id) && is_string($row->person_id) && is_string($row->role_key));
            assert(is_string($row->source_type) && is_string($row->source_id) && is_string($row->granted_at));
            assert($row->granted_by_account_id === null || is_string($row->granted_by_account_id));

            $grants[] = SourcedRoleGrant::reconstitute(
                SourcedRoleGrantId::fromString($row->id),
                PersonId::fromString($row->person_id),
                $row->role_key,
                $row->source_type,
                $row->source_id,
                $row->granted_by_account_id === null ? null : AccountId::fromString($row->granted_by_account_id),
                new DateTimeImmutable($row->granted_at, new DateTimeZone('UTC')),
            );
        }

        return $grants;
    }
}
