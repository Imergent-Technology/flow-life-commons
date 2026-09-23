<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * POST /admin/members/{person}/grants: an additional membership term for an EXISTING Person
 * (ADR 0028, Work Package 5).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('grants an additional term to an existing Person, and returns the grant', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Mia Member');

    $response = $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    expect(DB::table('membership_grants')->where('person_id', $person->id->value)->count())->toBe(1)
        ->and($response->json('person_id'))->toBe($person->id->value)
        ->and($response->json('source'))->toBe('operator')
        ->and($response->json('ends_at'))->toBeNull();
});

it('works for a Person who has no Account', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson(); // no Account

    $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    expect(DB::table('membership_grants')->where('person_id', $person->id->value)->count())->toBe(1);
});

it('rejects a missing ends_at key: open-ended access must be an explicit choice', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'source' => 'operator',
    ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);

    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('answers a stable 404 problem for an unknown Person', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/members/01jzzzzzzzzzzzzzzzzzzzzzzz/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertNotFound()->assertJson(['code' => 'person_not_found']);

    $console->post('/api/v1/admin/members/not-an-id/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertNotFound();

    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('allows an overlapping grant: the derivation merges them, it does not forbid them', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now(), Identity::now()->modify('+6 months'));

    $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => Identity::now()->modify('+3 months')->format('c'), 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    expect(DB::table('membership_grants')->where('person_id', $person->id->value)->count())->toBe(2);
});

it('allows a grant that exactly touches another', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $firstEnd = Identity::now()->modify('+6 months');
    Membership::savedGrant($person->id, Identity::now(), $firstEnd);

    $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => $firstEnd->format('c'), 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    expect(DB::table('membership_grants')->where('person_id', $person->id->value)->count())->toBe(2);
});

it('cannot be made to impersonate the caller through a person or account field in the body', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $other = Identity::savedPerson();

    $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
        'person_id' => $other->id->value, // the route parameter is the only subject that counts
        'granted_by_account_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
    ])->assertCreated();

    expect(DB::table('membership_grants')->where('person_id', $person->id->value)->count())->toBe(1)
        ->and(DB::table('membership_grants')->where('person_id', $other->id->value)->count())->toBe(0)
        ->and(DB::table('membership_grants')->where('person_id', $person->id->value)->value('granted_by_account_id'))
        ->toBe($admin->id->value);
});

it('discloses exactly the approved fields for the created grant, and nothing else', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $response = $console->post('/api/v1/admin/members/'.$person->id->value.'/grants', [
        'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator', 'source_reference' => 'ref-1',
    ])->assertCreated();

    expect(array_keys(Api::map($response->json())))->toBe(['id', 'person_id', 'starts_at', 'ends_at', 'source', 'source_reference', 'revoked_at']);
});
