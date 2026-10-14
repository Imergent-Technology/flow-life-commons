<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Account;
use Database\Seeders\E2eAgedSessionSeeder;
use Database\Seeders\E2eSessionKeepAlive;
use Database\Seeders\E2eSessionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Mfa;
use Tests\Support\Resources;

/*
 * The browser suite mints its sessions before a run, and a journey first uses one whenever it is scheduled. On a slow run that is more
 * than the 30 minutes of inactivity the platform allows, and the journey finds a Sign-in page where its session was (the G10 WP7
 * remediation's `resources-stepup` failures). E2eSessionKeepAlive does for the minted sessions what a request would, and nothing more:
 * it moves `last_activity` to now. These tests pin what it does, and the three things it must not.
 */

/**
 * Runs $work with the minted-session id file holding exactly $ids, and puts back whatever file the developer had.
 *
 * @param  array<string, string>  $ids
 */
function withMintedIds(array $ids, Closure $work): void
{
    $path = storage_path(E2eSessionSeeder::IDS_FILE);
    $before = File::exists($path) ? File::get($path) : null;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($ids, JSON_THROW_ON_ERROR));

    try {
        $work();
    } finally {
        $before === null ? File::delete($path) : File::put($path, $before);
    }
}

function sessionRowOf(Account $account): stdClass
{
    $row = DB::table('sessions')->where('user_id', $account->id->value)->first();
    assert($row instanceof stdClass);

    return $row;
}

function sessionIdOf(Account $account): string
{
    return Resources::str(sessionRowOf($account)->id);
}

function idleFor(Account $account, int $minutes): void
{
    DB::table('sessions')->where('user_id', $account->id->value)->update(['last_activity' => time() - $minutes * 60]);
}

it('judges an idle session on its last_activity: 29 minutes idle is alive, 31 is gone', function () {
    [$alive, $aliveAccount] = Mfa::signedInAdmin('alive@example.org');
    [$gone, $goneAccount] = Mfa::signedInAdmin('gone@example.org');
    idleFor($aliveAccount, 29);
    idleFor($goneAccount, 31);

    expect($alive->me()->status())->toBe(200)
        ->and($gone->me()->status())->toBe(401);
});

it('keeps a minted session alive across a gap that ends one that was not kept', function () {
    [$kept, $keptAccount] = Mfa::signedInAdmin('kept@example.org');
    [$left, $leftAccount] = Mfa::signedInAdmin('left@example.org');
    idleFor($keptAccount, 29);
    idleFor($leftAccount, 29);

    withMintedIds(['kept' => sessionIdOf($keptAccount)], function (): void {
        expect((new E2eSessionKeepAlive)->setContainer(app())->keep())->toBe(1);
    });

    // Five minutes on: the kept session has been idle for five, the other for thirty-four.
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    try {
        expect($kept->me()->status())->toBe(200)
            ->and($left->me()->status())->toBe(401);
    } finally {
        Carbon::setTestNow();
    }
});

it('moves last_activity and nothing else: the payload, and so every proof in it, is byte for byte what it was', function () {
    [, $account] = Mfa::signedInAdmin('payload@example.org');
    idleFor($account, 20);
    $before = sessionRowOf($account);

    withMintedIds(['payload' => sessionIdOf($account)], function (): void {
        (new E2eSessionKeepAlive)->setContainer(app())->keep();
    });

    $after = sessionRowOf($account);
    expect($after->payload)->toBe($before->payload)
        ->and($after->user_id)->toBe($before->user_id)
        ->and($after->id)->toBe($before->id)
        ->and(Resources::int($after->last_activity))->toBeGreaterThan(Resources::int($before->last_activity))
        ->and(Resources::int($after->last_activity))->toBeGreaterThanOrEqual(time() - 5);
});

it('does not revive a session already past its lifetime, and does not touch one it did not mint', function () {
    [, $dead] = Mfa::signedInAdmin('dead@example.org');
    [, $stranger] = Mfa::signedInAdmin('stranger@example.org');
    idleFor($dead, 31);
    idleFor($stranger, 20);
    $deadActivity = Resources::int(sessionRowOf($dead)->last_activity);
    $strangerActivity = Resources::int(sessionRowOf($stranger)->last_activity);

    withMintedIds(['dead' => sessionIdOf($dead)], function (): void {
        expect((new E2eSessionKeepAlive)->setContainer(app())->keep())->toBe(0);
    });

    expect(Resources::int(sessionRowOf($dead)->last_activity))->toBe($deadActivity)
        ->and(Resources::int(sessionRowOf($stranger)->last_activity))->toBe($strangerActivity);
});

it('does nothing when nothing was minted', function () {
    $path = storage_path(E2eSessionSeeder::IDS_FILE);
    $before = File::exists($path) ? File::get($path) : null;
    File::delete($path);

    try {
        expect((new E2eSessionKeepAlive)->setContainer(app())->keep())->toBe(0);
    } finally {
        if ($before !== null) {
            File::put($path, $before);
        }
    }
});

it('refuses to run, and to mint aged sessions, outside local and testing', function () {
    $original = app()->environment();
    app()->instance('env', 'production');
    config()->set('app.env', 'production');

    try {
        expect(fn () => (new E2eSessionKeepAlive)->setContainer(app())->keep())
            ->toThrow(RuntimeException::class, 'only run in a local or testing environment')
            ->and(fn () => (new E2eAgedSessionSeeder)->setContainer(app())->run())
            ->toThrow(RuntimeException::class, 'only be minted in a local or testing environment')
            ->and(DB::table('sessions')->count())->toBe(0);
    } finally {
        app()->instance('env', $original);
        config()->set('app.env', $original);
    }
});
