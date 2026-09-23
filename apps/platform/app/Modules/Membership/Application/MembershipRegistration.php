<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Shared\Domain\PersonId;

/**
 * What a committed "register and grant" produced. Holds only what Membership itself owns: the
 * new Person's id and display name (not the Identity\Domain\Person that created them, which
 * stays inside Identity), and the grant just created for them.
 */
final readonly class MembershipRegistration
{
    public function __construct(
        public PersonId $personId,
        public string $displayName,
        public MembershipGrant $grant,
    ) {}
}
