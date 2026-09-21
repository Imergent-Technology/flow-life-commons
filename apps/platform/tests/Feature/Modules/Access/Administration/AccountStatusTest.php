<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Disabling and re-enabling an Account through the administration surface (ADR 0024): the existing use cases, with a
 * capability and a fresh proof in front of them.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
});

it('disables through the existing safe path: sessions end, and the Person, roles and history stay', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target, Totp::RFC_SECRET);
    $session = new Console;
    $session->loginWithMfa('target@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();

    $response = $console->post("/api/v1/admin/accounts/{$target->id->value}/disable")->assertOk();

    $events = Identity::events('account.disabled');
    expect($response->json('status'))->toBe('disabled')
        ->and($response->json('disabled_at'))->not->toBeNull()
        ->and($response->json('assignments.0.key'))->toBe(Role::Guardian->value) // roles preserved
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and(Identity::context($events[0]))->toBe(['previous_status' => 'active', 'signed_out' => 1])
        ->and(DB::table('people')->where('id', $target->personId->value)->exists())->toBeTrue();
    $session->me()->assertUnauthorized();
    (new Console)->login('target@example.org', Identity::PASSWORD)->assertUnauthorized();
});

it('refuses to disable the LAST active administrator, with a stable code, and changes nothing', function () {
    [$console, $admin] = Mfa::signedInAdmin();

    $console->post("/api/v1/admin/accounts/{$admin->id->value}/disable")->assertStatus(409)->assertJson(['code' => 'last_administrator_required']);

    expect(DB::table('accounts')->where('id', $admin->id->value)->value('status'))->toBe('active');
    $console->me()->assertOk();
    expect(Identity::events('account.disabled'))->toBe([]);
});

it('lets one administrator disable another while a third remains', function () {
    [$console] = Mfa::signedInAdmin();
    $second = Access::admin('second@example.org');

    $console->post("/api/v1/admin/accounts/{$second->id->value}/disable")->assertOk()->assertJson(['status' => 'disabled']);
});

it('disabling twice succeeds and records one event: it is idempotent', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');

    $console->post("/api/v1/admin/accounts/{$target->id->value}/disable")->assertOk();
    $console->post("/api/v1/admin/accounts/{$target->id->value}/disable")->assertOk()->assertJson(['status' => 'disabled']);

    expect(Identity::events('account.disabled'))->toHaveCount(1);
});

it('re-enables a disabled Account, audited, with NO session, and no change to its password or roles', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    Mfa::enroll($target, Totp::RFC_SECRET);
    $console->post("/api/v1/admin/accounts/{$target->id->value}/disable")->assertOk();
    $hash = DB::table('accounts')->where('id', $target->id->value)->value('password_hash');

    $response = $console->post("/api/v1/admin/accounts/{$target->id->value}/enable")->assertOk();

    $events = Identity::events('account.reenabled');
    expect($response->json('status'))->toBe('active')
        ->and($response->json('disabled_at'))->toBeNull()
        ->and($response->json('assignments.0.key'))->toBe(Role::Guardian->value)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and(DB::table('accounts')->where('id', $target->id->value)->value('password_hash'))->toBe($hash)
        ->and(DB::table('sessions')->where('user_id', $target->id->value)->count())->toBe(0);
});

it('does NOT bypass MFA: a re-enabled Console user is still challenged', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target, Totp::RFC_SECRET);
    $console->post("/api/v1/admin/accounts/{$target->id->value}/disable")->assertOk();
    $console->post("/api/v1/admin/accounts/{$target->id->value}/enable")->assertOk();

    $person = new Console;
    $person->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'challenge']);
    $person->me()->assertUnauthorized();
    $person->challenge($factor['secret'])->assertOk();
});

it('sends a re-enabled Account whose second factor was reset to enrolment', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    Mfa::enroll($target, Totp::RFC_SECRET);
    $console->post("/api/v1/admin/accounts/{$target->id->value}/disable")->assertOk();
    $console->post("/api/v1/admin/accounts/{$target->id->value}/mfa/reset")->assertOk();
    $console->post("/api/v1/admin/accounts/{$target->id->value}/enable")->assertOk();

    (new Console)->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
});

it('refuses to enable an Account that is not disabled, with a stable code', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');

    $console->post("/api/v1/admin/accounts/{$target->id->value}/enable")->assertStatus(409)->assertJson(['code' => 'account_not_disabled']);

    expect(Identity::events('account.reenabled'))->toBe([]);
});

it('returns an Account that never chose a password to INVITED when it is re-enabled, so it still needs its invitation', function () {
    [$console] = Mfa::signedInAdmin();
    $invited = Identity::savedInvitedAccount('invited@example.org');
    $console->post("/api/v1/admin/accounts/{$invited->id->value}/disable")->assertOk();

    $console->post("/api/v1/admin/accounts/{$invited->id->value}/enable")->assertOk()->assertJson(['status' => 'invited']);
});

it('answers 404 for an Account that does not exist', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/accounts/01jzzzzzzzzzzzzzzzzzzzzzzz/disable')->assertNotFound();
    $console->post('/api/v1/admin/accounts/01jzzzzzzzzzzzzzzzzzzzzzzz/enable')->assertNotFound();
});
