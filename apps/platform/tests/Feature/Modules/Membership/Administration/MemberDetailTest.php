<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Tests\Support\Api;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * GET /admin/members/{person}: a Person's derived membership state and complete grant history
 * (ADR 0028, Work Package 5).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('shows an active record with its current access-through', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Mia Member');
    Membership::savedGrant($person->id, Identity::now()->modify('-1 month'), Identity::now()->modify('+11 months'));

    $response = $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk();

    expect($response->json('person'))->toBe(['id' => $person->id->value, 'display_name' => 'Mia Member'])
        ->and($response->json('active'))->toBeTrue()
        ->and($response->json('open_ended'))->toBeFalse()
        ->and($response->json('current_access_ends_at'))->toBe(Identity::now()->modify('+11 months')->format('Y-m-d\TH:i:s\Z'));
});

it('keeps an expired record retrievable, reported as inactive', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));

    $response = $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk();

    expect($response->json('active'))->toBeFalse()
        ->and($response->json('grants'))->toHaveCount(1);
});

it('keeps a fully revoked record retrievable', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();
    $response = $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk();

    expect($response->json('active'))->toBeFalse()
        ->and($response->json('grants.0.revoked_at'))->not->toBeNull();
});

it('keeps a future-only record retrievable, reported as inactive today', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('+1 month'), null);

    $response = $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk();

    expect($response->json('active'))->toBeFalse()
        ->and($response->json('grants'))->toHaveCount(1);
});

it('answers 404 for a bare Person who has never held a grant: not yet a membership record', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $console->get('/api/v1/admin/members/'.$person->id->value)
        ->assertNotFound()->assertJson(['code' => 'membership_record_not_found']);
});

it('answers a distinct 404 for a Person that does not exist at all', function () {
    [$console] = Mfa::signedInAdmin();

    $console->get('/api/v1/admin/members/01jzzzzzzzzzzzzzzzzzzzzzzz')
        ->assertNotFound()->assertJson(['code' => 'person_not_found']);
    $console->get('/api/v1/admin/members/not-an-id')->assertNotFound();
});

it('includes revoked, expired and future grants in the full history, oldest first', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $expired = Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    $revoked = Membership::savedGrant($person->id, Identity::now()->modify('-11 months'), Identity::now()->modify('-10 months'));
    Membership::savedGrant($person->id, Identity::now()->modify('+1 year'), null);
    $console->post('/api/v1/admin/membership-grants/'.$revoked->id->value.'/revoke')->assertNoContent();

    $response = $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk();

    expect($response->json('grants'))->toHaveCount(3)
        ->and($response->json('grants.0.id'))->toBe($expired->id->value)
        ->and(Api::rows($response->json('grants'))[1]['revoked_at'])->not->toBeNull();
});

it('never leaks a private, account or security field', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id);

    $body = strtolower((string) $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk()->getContent());

    foreach (['account_id', 'granted_by_account_id', 'revoked_by_account_id', 'email', 'password', 'role', 'capabilit', 'session'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('discloses exactly the approved fields, and nothing else', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id);

    $response = $console->get('/api/v1/admin/members/'.$person->id->value)->assertOk();

    expect(array_keys(Api::map($response->json())))->toBe(['person', 'active', 'current_access_ends_at', 'open_ended', 'grants'])
        ->and(array_keys(Api::map($response->json('person'))))->toBe(['id', 'display_name'])
        ->and(array_keys(Api::map($response->json('grants.0'))))->toBe(['id', 'starts_at', 'ends_at', 'source', 'source_reference', 'revoked_at']);
});
