<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;
use Tests\Support\Mfa;

/*
 * POST /admin/members: registers a new Person and their initial membership grant, atomically,
 * through the real RegisterPersonWithMembershipAccess use case (ADR 0028, Work Package 5).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('creates a Person and an initial grant, atomically, and returns the membership record', function () {
    [$console] = Mfa::signedInAdmin();
    $peopleBefore = DB::table('people')->count();

    $response = $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member',
        'starts_at' => '2026-09-23T12:00:00Z',
        'ends_at' => null,
        'source' => 'operator',
    ])->assertCreated();

    expect(DB::table('people')->count())->toBe($peopleBefore + 1)
        ->and(DB::table('membership_grants')->count())->toBe(1)
        ->and($response->json('person.display_name'))->toBe('Mia Member')
        ->and($response->json('active'))->toBeTrue()
        ->and($response->json('open_ended'))->toBeTrue()
        ->and($response->json('current_access_ends_at'))->toBeNull()
        ->and($response->json('grants'))->toHaveCount(1)
        ->and($response->json('grants.0.source'))->toBe('operator');
});

it('creates no Account, no invitation, no role assignment and no security event', function () {
    [$console] = Mfa::signedInAdmin();
    $before = [
        'accounts' => DB::table('accounts')->count(),
        'account_invitations' => DB::table('account_invitations')->count(),
        'role_assignments' => DB::table('role_assignments')->count(),
        'security_events' => DB::table('security_events')->count(),
    ];

    $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    expect(DB::table('accounts')->count())->toBe($before['accounts'])
        ->and(DB::table('account_invitations')->count())->toBe($before['account_invitations'])
        ->and(DB::table('role_assignments')->count())->toBe($before['role_assignments'])
        ->and(DB::table('security_events')->count())->toBe($before['security_events']);
});

it('cannot be made to accept a caller-supplied provenance or identity', function () {
    [$console] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
        // None of these are accepted fields; if any silently worked, provenance would not come from the Actor.
        'person_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
        'account_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
        'granted_by_account_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
        'email' => 'attacker@example.org',
    ])->assertCreated();

    $personId = Api::string($response->json('person.id'));
    expect($personId)->not->toBe('01jzzzzzzzzzzzzzzzzzzzzzzz')
        ->and(DB::table('membership_grants')->where('person_id', $personId)->value('granted_by_account_id'))
        ->not->toBe('01jzzzzzzzzzzzzzzzzzzzzzzz');
});

it('rejects a missing ends_at key: open-ended access must be an explicit choice', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'source' => 'operator',
    ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);

    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('accepts an explicit ends_at of null as open-ended access', function () {
    [$console] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    expect($response->json('open_ended'))->toBeTrue()
        ->and($response->json('grants.0.ends_at'))->toBeNull();
});

it('rejects a bounded term where ends_at is not strictly after starts_at', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => '2026-09-23T12:00:00Z', 'source' => 'operator',
    ])->assertStatus(422);

    expect(DB::table('membership_grants')->count())->toBe(0);
    // The HTTP-level `after:` rule catches this before Domain ever sees it (defence in depth).
});

it('validates the source enum', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'zeffy',
    ])->assertStatus(422)->assertJsonValidationErrors(['source']);
});

it('bounds source_reference to 191 characters', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
        'source_reference' => str_repeat('x', 192),
    ])->assertStatus(422)->assertJsonValidationErrors(['source_reference']);

    $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
        'source_reference' => str_repeat('x', 191),
    ])->assertCreated();
});

it('discloses exactly the approved fields, and nothing else', function () {
    [$console] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/members', [
        'display_name' => 'Mia Member', 'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
        'source_reference' => 'ref-1',
    ])->assertCreated();

    expect(array_keys(Api::map($response->json())))->toBe(['person', 'active', 'current_access_ends_at', 'open_ended', 'grants'])
        ->and(array_keys(Api::map($response->json('person'))))->toBe(['id', 'display_name'])
        ->and(array_keys(Api::map($response->json('grants.0'))))->toBe(['id', 'starts_at', 'ends_at', 'source', 'source_reference', 'revoked_at']);
});

it('rejects a missing display_name', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/members', [
        'starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator',
    ])->assertStatus(422)->assertJsonValidationErrors(['display_name']);
});
