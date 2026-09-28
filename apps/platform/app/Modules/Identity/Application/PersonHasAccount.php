<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * The Application-layer form of `Identity\Domain\PersonAlreadyHasAccount` (ADR 0015: at most one Account per
 * Person). A caller outside Identity must never see the Domain exception directly — an architecture test forbids
 * Access from referencing `Identity\Domain` at all — so this is what crosses the boundary instead, the same way
 * `EmailAlreadyInUse` stands in for `Identity\Domain\EmailAddressAlreadyInUse`.
 */
final class PersonHasAccount extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This person already has an account.');
    }
}
