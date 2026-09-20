<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;

interface AccountRepository
{
    /**
     * Insert or update.
     *
     * @throws EmailAddressAlreadyInUse another Account holds the same canonical email
     * @throws PersonAlreadyHasAccount the Person already has a different Account
     */
    public function save(Account $account): void;

    public function find(AccountId $id): ?Account;

    public function findByPersonId(PersonId $personId): ?Account;

    /** Looks up by canonical email, so any case variant finds the same Account. */
    public function findByEmail(EmailAddress $email): ?Account;
}
