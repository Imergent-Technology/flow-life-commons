<?php

declare(strict_types=1);

use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * POST /admin/membership-grants/{grant}/revoke: one-way revocation (ADR 0028, Work Package 5).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('revokes the grant and returns no content', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();

    $stored = app(MembershipGrantRepository::class)->find($grant->id);
    expect($stored?->isRevoked())->toBeTrue();
});

it('answers a stable 404 problem for an unknown grant', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/membership-grants/01jzzzzzzzzzzzzzzzzzzzzzzz/revoke')
        ->assertNotFound()->assertJson(['code' => 'grant_not_found']);
    $console->post('/api/v1/admin/membership-grants/not-an-id/revoke')->assertNotFound();
});

it('answers a distinct 409 for a grant that was already revoked, and does not overwrite its provenance', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();
    $firstRevokedAt = DB::table('membership_grants')->where('id', $grant->id->value)->value('revoked_at');

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')
        ->assertStatus(409)->assertJson(['code' => 'grant_already_revoked']);

    expect(DB::table('membership_grants')->where('id', $grant->id->value)->value('revoked_at'))->toBe($firstRevokedAt)
        ->and(DB::table('membership_grants')->where('id', $grant->id->value)->value('revoked_by_account_id'))->toBe($admin->id->value);
});

it('derives provenance from the Actor, never from a caller-supplied field', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke', [
        'revoked_by_account_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
    ])->assertNoContent();

    expect(DB::table('membership_grants')->where('id', $grant->id->value)->value('revoked_by_account_id'))
        ->toBe($admin->id->value);
});

it('is reflected immediately in membership derivation: an actively covering grant stops covering', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id, Identity::now(), null);

    expect($console->get('/api/v1/admin/members/'.$person->id->value)->json('active'))->toBeTrue();

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();

    expect($console->get('/api/v1/admin/members/'.$person->id->value)->json('active'))->toBeFalse();
});
