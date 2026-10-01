<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

interface ContactProfileRepository
{
    public function find(PersonId $personId): ?ContactProfile;

    /**
     * The Person's profile row, created empty if there is none, LOCKED (SELECT ... FOR UPDATE) until the caller's
     * transaction ends. This is CRM's per-Person write lock: a use case takes it before it decides anything about
     * the Person's contact methods or tags, so two concurrent writers are applied one after the other, identically
     * on MariaDB and PostgreSQL. Inside a transaction only.
     */
    public function lock(PersonId $personId, DateTimeImmutable $now): ContactProfile;

    public function save(ContactProfile $profile): void;
}
