<?php

declare(strict_types=1);

/*
 * A second PHP process for the concurrency tests: boots the application, runs ONE use case,
 * and reports what happened. It is launched by AdministratorRemovalRaceTest while the test
 * process is holding an open transaction, and is expected to BLOCK on the administrator lock
 * until that transaction commits.
 *
 *   php worker.php revoke  '{"actor_account":"...","actor_person":"...","person":"..."}'
 *   php worker.php disable '{"account":"..."}'
 *   php worker.php login   '{"email":"...","password":"..."}'
 *
 * It prints READY just before it starts the use case, then one JSON line, and exits 0 when
 * the operation succeeded, 2 when it was refused or failed. It refuses to run against any
 * database whose name does not end in "_test".
 */

use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AuthenticateAccount;
use App\Modules\Identity\Application\AuthenticationStatus;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.'.config()->string('database.default').'.database');
if (! is_string($database) || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "worker refuses to run against a database that is not a _test database\n");
    exit(64);
}

$operation = $argv[1] ?? '';
$decoded = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
assert(is_array($decoded));
$arg = static function (string $name) use ($decoded): string {
    $value = $decoded[$name] ?? null;

    return is_string($value) ? $value : throw new InvalidArgumentException("missing argument {$name}");
};

echo "READY\n";
flush();

try {
    if ($operation === 'revoke') {
        $app->make(RevokeRole::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            PersonId::fromString($arg('person')),
            Role::PlatformAdministrator,
        );
    } elseif ($operation === 'disable') {
        $app->make(DisableAccount::class)(AccountId::fromString($arg('account')));
    } elseif ($operation === 'login') {
        $result = $app->make(AuthenticateAccount::class)(
            EmailAddress::fromString($arg('email')), $arg('password'), new ClientContext('127.0.0.1', 'worker'),
        );
        if ($result->status !== AuthenticationStatus::Authenticated) {
            throw new RuntimeException('login was not accepted');
        }
    } else {
        throw new InvalidArgumentException("unknown operation {$operation}");
    }
    echo json_encode(['result' => 'done'])."\n";
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['result' => 'refused', 'class' => $e::class])."\n";
    exit(2);
}
