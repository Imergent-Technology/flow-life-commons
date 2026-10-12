<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\PersonId;

/**
 * Whether a Person already has an Account (ADR 0038, M4). Read-only. It returns no
 * Account fields: the Guardian adoption report must not print a login or a mailbox.
 */
final readonly class PersonAccountPresence
{
    public function __construct(private AccountDirectory $accounts) {}

    public function __invoke(PersonId $person): bool
    {
        return $this->accounts->findByPersonId($person) !== null;
    }
}
