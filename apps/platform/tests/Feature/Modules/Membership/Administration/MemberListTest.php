<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * GET /admin/members: one record per distinct Person who has EVER held a grant (ADR 0028, Work
 * Package 5), preserving Package 4's history rule, now paged.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('includes active, inactive and revoked-history People alike', function () {
    [$console] = Mfa::signedInAdmin();
    $active = Identity::savedPerson('Active Member');
    $lapsed = Identity::savedPerson('Lapsed Member');
    $revoked = Identity::savedPerson('Revoked Member');
    Membership::savedGrant($active->id, Identity::now(), null);
    Membership::savedGrant($lapsed->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    $grant = Membership::savedGrant($revoked->id);
    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();

    $response = $console->get('/api/v1/admin/members?per_page=100')->assertOk();

    $names = array_map(fn (array $row): string => Api::string(Api::map($row['person'])['display_name']), Api::rows($response->json('data')));
    expect($names)->toContain('Active Member')->toContain('Lapsed Member')->toContain('Revoked Member');
});

it('does not list a Person who has never held a grant', function () {
    [$console] = Mfa::signedInAdmin();
    Identity::savedPerson('No Grants');

    $response = $console->get('/api/v1/admin/members?per_page=100')->assertOk();

    $names = array_map(fn (array $row): string => Api::string(Api::map($row['person'])['display_name']), Api::rows($response->json('data')));
    expect($names)->not->toContain('No Grants');
});

it('lists each Person exactly once, regardless of grant count', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Multi Grant');
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    Membership::savedGrant($person->id, Identity::now(), null);

    $response = $console->get('/api/v1/admin/members?per_page=100')->assertOk();

    $matches = array_values(array_filter(Api::rows($response->json('data')), fn (array $row): bool => Api::map($row['person'])['display_name'] === 'Multi Grant'));
    expect($matches)->toHaveCount(1)
        ->and($matches[0]['grants'])->toHaveCount(2)
        ->and($matches[0]['active'])->toBeTrue();
});

it('pages the list by distinct Person, not by raw grant row, and reports the totals', function () {
    [$console] = Mfa::signedInAdmin();
    foreach (range(1, 5) as $n) {
        $person = Identity::savedPerson('Grantee '.$n);
        // Two grants each: if pagination ran over rows, this would double the apparent count.
        Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
        Membership::savedGrant($person->id, Identity::now(), null);
    }

    $first = $console->get('/api/v1/admin/members?per_page=2&page=1')->assertOk();
    /** @var list<string> $everyone */
    $everyone = [];
    foreach ([1, 2, 3] as $page) {
        foreach (Api::rows($console->get("/api/v1/admin/members?per_page=2&page={$page}")->json('data')) as $row) {
            $everyone[] = Api::string(Api::map($row['person'])['id']);
        }
    }

    expect($first->json('meta'))->toBe(['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3])
        ->and($first->json('data'))->toHaveCount(2)
        ->and($everyone)->toHaveCount(5) // every Person exactly once across all pages
        ->and(array_unique($everyone))->toHaveCount(5)
        ->and(DB::table('membership_grants')->count())->toBe(10); // twice as many rows as People
});

it('bounds the page size: it is never an unlimited table', function () {
    [$console] = Mfa::signedInAdmin();

    $console->get('/api/v1/admin/members?per_page=101')->assertStatus(422);
    $console->get('/api/v1/admin/members?per_page=0')->assertStatus(422);
    $console->get('/api/v1/admin/members?page=0')->assertStatus(422);
    expect($console->get('/api/v1/admin/members')->json('meta.per_page'))->toBe(25);
});

it('discloses exactly the approved fields, and nothing else', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Mia Member');
    Membership::savedGrant($person->id);

    $response = $console->get('/api/v1/admin/members')->assertOk();

    expect(array_keys(Api::map($response->json())))->toBe(['data', 'meta'])
        ->and(array_keys(Api::map($response->json('meta'))))->toBe(['page', 'per_page', 'total', 'last_page'])
        ->and(array_keys(Api::map($response->json('data.0'))))->toBe(['person', 'active', 'current_access_ends_at', 'open_ended', 'grants']);
});

it('serves a page in a bounded number of queries, however many People it holds', function () {
    [$console] = Mfa::signedInAdmin();
    foreach (range(1, 20) as $n) {
        Membership::savedGrant(Identity::savedPerson('Bulk '.$n)->id);
    }
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    $console->get('/api/v1/admin/members?per_page=25')->assertOk();

    // Session, authentication, authorization, the id page, the total, the batched grant history and
    // the batched Person lookup: a handful, not one per row.
    expect($queries)->toBeLessThan(25);
});
