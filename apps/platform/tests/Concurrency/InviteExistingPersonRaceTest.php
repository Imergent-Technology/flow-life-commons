<?php

declare(strict_types=1);

use App\Modules\Identity\Application\InviteAccountForPerson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\Support\Race;

/*
 * Inviting an existing Person under REAL concurrency, across two PHP processes and two database connections
 * (method: Tests\Support\Race). A Person holds at most one Account (ADR 0015), enforced by the database's
 * unique constraint on accounts.person_id — the same mechanism InviteAccount already relies on for
 * accounts.email_canonical, proven the same way here.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

it('creates one Account when two operators invite the same existing Person at once, even with different addresses', function () {
    $person = Identity::savedPerson('Race Target');

    $race = Race::against(
        fn () => app(InviteAccountForPerson::class)($person->id, 'first@example.org'),
        'account.invited', 'invite_existing_person', ['person' => $person->id->value, 'email' => 'second@example.org'],
    );

    expect($race['blocked'])->toBeTrue('the second invitation did not wait on the Person')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe('App\\Modules\\Identity\\Application\\PersonHasAccount')
        ->and(DB::table('people')->count())->toBe(1) // no new Person on either side
        ->and(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('accounts')->value('email_canonical'))->toBe('first@example.org') // the winner's address
        ->and(DB::table('account_invitations')->count())->toBe(1);
});
