<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\PersonId;

/**
 * Which of these people have an Account that can still authenticate: the one fact Access
 * needs to decide who counts as an "active administrator", offered so it never reads
 * Identity's tables (ADR 0020). "Can still authenticate" is Identity's rule
 * (Account::canAuthenticate); an invited, disabled or missing Account does not count.
 */
interface ActiveAccountQuery
{
    /**
     * @param  list<PersonId>  $personIds
     * @return list<PersonId> those with an Account that can authenticate
     */
    public function activePersonIds(array $personIds): array;

    /**
     * As activePersonIds, but locks those Accounts' rows (SELECT ... FOR UPDATE, ordered by id,
     * held until the caller's transaction ends) and reads the latest COMMITTED state. The answer
     * therefore cannot change under the caller until it commits, and a disable that committed
     * while the caller waited is seen. Must be called inside a transaction.
     *
     * @param  list<PersonId>  $personIds
     * @return list<PersonId>
     */
    public function lockActivePersonIds(array $personIds): array;
}
