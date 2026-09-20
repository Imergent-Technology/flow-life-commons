<?php

declare(strict_types=1);

use App\Modules\Access\Application\ConsoleMultiFactorPolicy;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\MultiFactorPolicy;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Infrastructure\Mfa\AlwaysRequireMultiFactor;
use App\Shared\Domain\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * WHO must have a second factor (ADR 0023): anyone whose access reaches the Console, however they came to
 * hold it, and no one is asked by role name. Authentication strength, not authorization.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Mfa::registerProbeRoutes();
});

it('is answered by Access, through the port Identity owns', function () {
    expect(app(MultiFactorPolicy::class))->toBeInstanceOf(ConsoleMultiFactorPolicy::class);
});

it('requires a second factor of every Account whose access reaches the Console, guardian and administrator alike', function (Role $role) {
    $account = Identity::savedActiveAccount();
    Access::grant($account, $role);

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
})->with([Role::Guardian, Role::PlatformAdministrator]);

it('requires nothing of an Account whose access does not reach the Console', function () {
    Identity::savedActiveAccount();

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('is decided by what the Account may do NOW, not by what role it happens to hold', function () {
    $account = Identity::savedActiveAccount();
    $actor = Actor::user($account->id, $account->personId);
    $policy = app(MultiFactorPolicy::class);
    expect($policy->requiredFor($actor))->toBeFalse();

    Access::grant($account, Role::Guardian);
    expect($policy->requiredFor($actor))->toBeTrue();

    Access::revoke($account, Role::Guardian);
    expect($policy->requiredFor($actor))->toBeFalse();
    // A stored role key that no longer means anything grants nothing, so it requires nothing.
    Access::plant($account->personId, 'retired_role');
    expect($policy->requiredFor($actor))->toBeFalse();
});

it('fails CLOSED if nobody has registered a policy: everyone is then asked for a second factor', function () {
    $account = Identity::savedActiveAccount();

    expect((new AlwaysRequireMultiFactor)->requiredFor(Actor::user($account->id, $account->personId)))->toBeTrue();
});

it('ends a password-only session that is given Console access, so it never becomes privileged unproven', function () {
    $account = Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $console->me()->assertOk();

    Access::grant($account, Role::Guardian);   // access that needs a second factor, which this session never proved

    $console->get('/api/v1/zz/console')->assertUnauthorized();
    $console->me()->assertUnauthorized();
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    $ended = Identity::events('session.second_factor_required');
    expect($ended)->toHaveCount(1)->and($ended[0]->subject_account_id)->toBe($account->id->value);

    // Signing in again goes through enrolment.
    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
});

it('leaves a session that DID prove a second factor alone when access changes', function () {
    [$console, $account] = Mfa::signedIn();

    Access::revoke($account, Role::Guardian);
    $console->me()->assertOk();
    Access::grant($account, Role::PlatformAdministrator);
    $console->me()->assertOk()->assertJsonPath('capabilities', Access::everyCapabilityId());
    expect(Identity::events('session.second_factor_required'))->toBe([]);
});

it('ends a password-only session whose Account has enrolled an authenticator elsewhere', function () {
    $account = Identity::savedActiveAccount();   // no Console role: password-only is fine
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $console->me()->assertOk();

    Mfa::enroll($account);   // an authenticator now exists, so this password-only session is no longer enough

    $console->me()->assertUnauthorized();
});

it('never asks a second factor of a session that has none due, and costs it nothing', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->me()->assertOk();
    $console->me()->assertOk();
    expect(Identity::events('session.second_factor_required'))->toBe([]);
});

it('gives no session to an Account that is disabled, even with a second factor pending', function () {
    $account = Mfa::guardian();
    Mfa::enroll($account);
    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();
});

it('keeps authorization separate: a signed-in Account with a second factor still needs the capability', function () {
    $account = Identity::savedActiveAccount();
    Mfa::enroll($account);   // an authenticator, but no role
    $console = new Console;
    $console->loginWithMfa('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->me()->assertOk()->assertJsonPath('capabilities', []);
    $console->get('/api/v1/zz/console')->assertForbidden();
});
