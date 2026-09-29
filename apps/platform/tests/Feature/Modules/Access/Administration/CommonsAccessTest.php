<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * GET /admin/people/{person}/commons-access (ADR 0032, Work Package 5): whether a Person has a Commons Account
 * yet, and whether the existing-Person invitation (POST /admin/people/{person}/invitation) may still be issued
 * for them. Access's own read, entirely apart from Membership's own responses — MembershipDisclosureTest (Work
 * Package 7) keeps those free of anything account-shaped, so this never touches them.
 */

it('reports not_invited and can_invite for a Person with no Account yet', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('No Account Yet');

    $response = $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertOk();

    expect($response->json('commons_access'))->toBe(['state' => 'not_invited', 'can_invite' => true]);
});

it('reports invited and can_invite: false once an invitation is outstanding', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Invited Person');
    app(AccountRepository::class)->save(Identity::invitedAccount($person, 'invited.person@example.org'));

    $response = $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertOk();

    expect($response->json('commons_access'))->toBe(['state' => 'invited', 'can_invite' => false]);
});

it('reports active and can_invite: false once the invitation is accepted', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Active Person');
    $account = Identity::invitedAccount($person, 'active.person@example.org')
        ->activate(Hash::make(Identity::PASSWORD), Identity::now());
    app(AccountRepository::class)->save($account);

    $response = $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertOk();

    expect($response->json('commons_access'))->toBe(['state' => 'active', 'can_invite' => false]);
});

it('reports disabled and can_invite: false for a disabled Account, and never its id or email', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Disabled Person');
    $account = Identity::invitedAccount($person, 'disabled.person@example.org')
        ->activate(Hash::make(Identity::PASSWORD), Identity::now())
        ->disable(Identity::now()->modify('+1 minute'));
    app(AccountRepository::class)->save($account);

    $response = $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertOk();

    expect($response->json('commons_access'))->toBe(['state' => 'disabled', 'can_invite' => false]);
    $body = strtolower((string) $response->getContent());
    foreach ([strtolower($account->id->value), 'disabled.person@example.org', 'capabilit', 'role'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('discloses exactly commons_access.state and commons_access.can_invite, nothing else', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $response = $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertOk();

    expect(array_keys((array) $response->json()))->toBe(['commons_access'])
        ->and(array_keys((array) $response->json('commons_access')))->toBe(['state', 'can_invite']);
});

it('fails closed: 404 for a Person that does not exist, never a false "not_invited"', function () {
    [$console] = Mfa::signedInAdmin();

    $console->get('/api/v1/admin/people/01jzzzzzzzzzzzzzzzzzzzzzzz/commons-access')
        ->assertNotFound()->assertJson(['code' => 'person_not_found']);
});

it('needs identity.invitations.issue, the same capability the invitation itself needs', function () {
    $person = Identity::savedPerson();
    // A Guardian holds console.access only, not identity.invitations.issue, and is refused.
    [$console] = Mfa::signedIn('guardian@example.org', Totp::RFC_SECRET);

    $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertForbidden();
});

it('needs no recent verification: a read, not a mutation', function () {
    [$console] = Mfa::signedInAdmin(); // freshly verified from signing in, but this proves the route itself asks for nothing more
    $person = Identity::savedPerson();

    $console->get("/api/v1/admin/people/{$person->id->value}/commons-access")->assertOk();
});
