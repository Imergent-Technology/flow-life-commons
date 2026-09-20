<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Application\CompromisedPasswordCheckUnavailable;
use App\Modules\Identity\Application\CompromisedPasswords;
use App\Modules\Identity\Domain\PlainPassword;
use Illuminate\Support\Facades\DB;

/**
 * A stand-in for the breach check that needs no network: it knows a list of "breached" passwords,
 * can be told it is down, and records how it was used so a test can prove WHEN the network would
 * have been called (never for a password that fails the offline rules, and never inside the
 * caller's transaction).
 */
final class FakeBreachCheck implements CompromisedPasswords
{
    /** @var list<string> */
    public array $checked = [];

    /** @var list<int> the database transaction depth at each call */
    public array $depths = [];

    /** @param  list<string>  $breached  passwords (NFC) this fake reports as compromised */
    public function __construct(private array $breached = [], private bool $down = false) {}

    public function contains(PlainPassword $password): bool
    {
        $this->checked[] = hash('sha256', $password->reveal()); // never keep the text itself
        $this->depths[] = DB::transactionLevel();

        if ($this->down) {
            throw new CompromisedPasswordCheckUnavailable('the fake breach service is down');
        }

        return in_array($password->reveal(), $this->breached, true);
    }
}
