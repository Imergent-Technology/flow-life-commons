<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Password;

use App\Modules\Identity\Application\CompromisedPasswords;
use App\Modules\Identity\Domain\PlainPassword;

/**
 * Finds nothing compromised, because it looks at nothing. It exists so development and automated
 * tests need no outbound network, and it is the ONE way to switch the breach check off: the
 * container refuses to build it outside the `local` and `testing` environments (see
 * IdentityServiceProvider), so production can never run without a real checker, whatever its
 * configuration says.
 */
final readonly class NoCompromisedPasswordCheck implements CompromisedPasswords
{
    public function contains(PlainPassword $password): bool
    {
        return false;
    }
}
