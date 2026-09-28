<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * GET /my/membership (ADR 0032, Work Package 2): an authenticated Account's own membership, over the wire.
 * The temporal derivation itself is proven in GetCurrentMembershipTest; this proves what is specific to the
 * HTTP boundary: authentication is the whole requirement, the subject can never be forged, and the response
 * excludes every administrative fact a Member has no claim to.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
});

it('answers 200 for ANY signed-in Account, with no console.access and no membership capability required', function () {
    Identity::savedActiveAccount('member@example.org', name: 'Mia Member');
    $console = new Console;
    $console->login('member@example.org', Identity::PASSWORD)->assertOk(); // password-only session: not a Guardian

    $console->get('/api/v1/my/membership')->assertOk();
});

it('reports no membership at all as a normal, successful answer — reaching /my/ is not evidence of membership', function () {
    Identity::savedActiveAccount('never-a-member@example.org');
    $console = new Console;
    $console->login('never-a-member@example.org', Identity::PASSWORD)->assertOk();

    $response = $console->get('/api/v1/my/membership')->assertOk();

    expect($response->json())->toBe(['active' => false, 'current_access_ends_at' => null, 'open_ended' => false, 'grants' => []]);
});

it('reports the CALLER\'S own active membership, with its current access-through', function () {
    $account = Identity::savedActiveAccount('member@example.org', name: 'Mia Member');
    Membership::savedGrant($account->personId, Identity::now()->modify('-1 month'), Identity::now()->modify('+11 months'));
    $console = new Console;
    $console->login('member@example.org', Identity::PASSWORD)->assertOk();

    $response = $console->get('/api/v1/my/membership')->assertOk();

    expect($response->json('active'))->toBeTrue()
        ->and($response->json('current_access_ends_at'))->toBe(Identity::now()->modify('+11 months')->format('Y-m-d\TH:i:s\Z'))
        ->and($response->json('open_ended'))->toBeFalse()
        ->and($response->json('grants'))->toHaveCount(1)
        ->and($response->json('grants.0.revoked'))->toBeFalse();
});

it('exposes exactly the documented keys: no grant id, no source, no source_reference, no granting or revoking account', function () {
    $account = Identity::savedActiveAccount('member@example.org');
    $admin = Access::admin('admin@example.org');
    $grant = Membership::savedGrant($account->personId, Identity::now(), Identity::now()->modify('+1 year'), grantedBy: $admin->id, sourceReference: 'ref-12345');
    $console = new Console;
    $console->login('member@example.org', Identity::PASSWORD)->assertOk();

    $response = $console->get('/api/v1/my/membership')->assertOk();
    $body = $response->json();
    assert(is_array($body));

    expect(array_keys($body))->toEqualCanonicalizing(['active', 'current_access_ends_at', 'open_ended', 'grants']);
    $grantJson = $response->json('grants.0');
    assert(is_array($grantJson));
    expect(array_keys($grantJson))->toEqualCanonicalizing(['starts_at', 'ends_at', 'revoked']);
    $raw = $response->getContent();
    expect($raw)->not->toContain($grant->id->value)
        ->and($raw)->not->toContain($admin->id->value)
        ->and($raw)->not->toContain('ref-12345')
        ->and($raw)->not->toContain('source')
        ->and($raw)->not->toContain('granted_by')
        ->and($raw)->not->toContain('revoked_by');
});

it('never lets a caller ask about anyone else: there is no person id parameter, path or otherwise', function () {
    Identity::savedActiveAccount('member@example.org');
    $other = Identity::savedPerson('Someone Else');
    Membership::savedGrant($other->id, Identity::now(), Identity::now()->modify('+1 year')); // the other Person IS a member
    $console = new Console;
    $console->login('member@example.org', Identity::PASSWORD)->assertOk();

    // Forged query and body parameters naming another Person are silently ignored: the route takes no input at all.
    $response = $console->get('/api/v1/my/membership?person_id='.$other->id->value)->assertOk();

    expect($response->json('active'))->toBeFalse() // the CALLER has no grant, whatever the query string claims
        ->and($response->json('grants'))->toBe([]);
});

it('refuses an unauthenticated caller', function () {
    (new Console)->get('/api/v1/my/membership')->assertUnauthorized();
});

it('reports a lapsed member\'s history, not just their current (false) state', function () {
    $account = Identity::savedActiveAccount('lapsed@example.org');
    Membership::savedGrant($account->personId, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    $console = new Console;
    $console->login('lapsed@example.org', Identity::PASSWORD)->assertOk();

    $response = $console->get('/api/v1/my/membership')->assertOk();

    expect($response->json('active'))->toBeFalse()
        ->and($response->json('grants'))->toHaveCount(1);
});

it('works the same for a Guardian, who is also just an authenticated Account', function () {
    [$console] = Mfa::signedInAdmin();

    $console->get('/api/v1/my/membership')->assertOk()->assertJson(['active' => false, 'grants' => []]);
});
