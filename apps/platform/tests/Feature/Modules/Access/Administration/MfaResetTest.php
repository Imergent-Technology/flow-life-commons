<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Resetting ANOTHER Account's second factor through the administration surface (ADR 0024).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
});

it('resets another Account: factor and codes gone, sessions ended, everything else kept, audited', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target, Totp::RFC_SECRET);
    $session = new Console;
    $session->loginWithMfa('target@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $before = DB::table('accounts')->where('id', $target->id->value)->first(['password_hash', 'status']);

    $response = $console->post("/api/v1/admin/accounts/{$target->id->value}/mfa/reset")->assertOk();

    $events = Identity::events('mfa.administratively_reset');
    expect($response->json('mfa'))->toBe(['enrolled' => false, 'recovery_codes_remaining' => 0])
        ->and($response->json('status'))->toBe('active')
        ->and($response->json('assignments.0.key'))->toBe('guardian')
        ->and(DB::table('accounts')->where('id', $target->id->value)->first(['password_hash', 'status']))->toEqual($before)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and(Mfa::isEnrolled($target))->toBeFalse();
    $session->me()->assertUnauthorized();
    Mfa::assertAbsent(Mfa::auditText().$response->getContent(), $factor['secret'], ...$factor['codes']);
});

it('sends the target to enrolment, and no old code or recovery code gets them in', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target, Totp::RFC_SECRET);
    $console->post("/api/v1/admin/accounts/{$target->id->value}/mfa/reset")->assertOk();

    $person = new Console;
    $person->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
    $person->post('/api/v1/mfa/challenge', ['code' => Totp::next($factor['secret'])])->assertUnauthorized();
    $person->post('/api/v1/mfa/challenge', ['recovery_code' => $factor['codes'][0]])->assertUnauthorized();
    $person->me()->assertUnauthorized();

    $fresh = new Console;
    $fresh->login('target@example.org', Identity::PASSWORD)->assertStatus(202);
    $secret = Mfa::text($fresh->post('/api/v1/mfa/enrollment')->assertOk()->json('secret'));
    $fresh->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk();
    $fresh->me()->assertOk();
});

it('REFUSES to reset the acting operator\'s own second factor, with a stable code, and changes nothing', function () {
    [$console, $admin] = Mfa::signedInAdmin();

    $console->post("/api/v1/admin/accounts/{$admin->id->value}/mfa/reset")->assertStatus(422)->assertJson(['code' => 'self_mfa_reset_prohibited']);

    expect(Mfa::isEnrolled($admin))->toBeTrue()->and(Mfa::remainingCodes($admin))->toBe(10)->and(Identity::events('mfa.administratively_reset'))->toBe([]);
    $console->me()->assertOk();
});

it('says so when there is nothing to reset, and records nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');

    $console->post("/api/v1/admin/accounts/{$target->id->value}/mfa/reset")->assertStatus(409)->assertJson(['code' => 'mfa_not_enrolled']);

    expect(Identity::events('mfa.administratively_reset'))->toBe([]);
});

it('needs a fresh proof: a stale one is refused, and the target keeps their factor', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    Mfa::enroll($target, Totp::RFC_SECRET);
    Console::advance(16 * 60);

    $console->post("/api/v1/admin/accounts/{$target->id->value}/mfa/reset")->assertForbidden()->assertJson(['verification_required' => true]);

    expect(Mfa::isEnrolled($target))->toBeTrue();
});

it('recovers the SOLE administrator when another administrator does it, and leaves the role alone', function () {
    // Two administrators, one has lost their factor. The other resets it; nobody's administrator authority moves.
    [$console] = Mfa::signedInAdmin();
    $locked = Access::admin('locked@example.org');
    Mfa::enroll($locked, Totp::RFC_SECRET);

    $console->post("/api/v1/admin/accounts/{$locked->id->value}/mfa/reset")->assertOk();

    expect(Access::activeAdministrators())->toBe(2);
    (new Console)->login('locked@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
});
