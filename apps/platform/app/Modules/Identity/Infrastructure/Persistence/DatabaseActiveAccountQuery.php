<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\ActiveAccountQuery;
use App\Shared\Domain\PersonId;

final readonly class DatabaseActiveAccountQuery implements ActiveAccountQuery
{
    public function __construct(private AccountMapper $mapper) {}

    public function activePersonIds(array $personIds): array
    {
        return $this->active($personIds, lock: false);
    }

    public function lockActivePersonIds(array $personIds): array
    {
        return $this->active($personIds, lock: true);
    }

    /**
     * @param  list<PersonId>  $personIds
     * @return list<PersonId>
     */
    private function active(array $personIds, bool $lock): array
    {
        if ($personIds === []) {
            return [];
        }

        // Ordered by id so that every transaction takes these locks in the same order.
        $query = AccountRecord::query()
            ->whereIn('person_id', array_map(fn (PersonId $p): string => $p->value, $personIds))
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $active = [];
        foreach ($query->get() as $record) {
            // Identity's own rule decides; an inconsistent row throws, and a caller that
            // cannot tell must not go on to remove an administrator.
            if ($this->mapper->toDomain($record)->canAuthenticate()) {
                $active[] = PersonId::fromString($record->person_id);
            }
        }

        return $active;
    }
}
