<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Tests\Support\Api;

/*
 * A5's verification table, hard-coded. A definition that dropped `intake` from Guardian's verified
 * operations would regenerate the route without `security.verified`, and this table would still fail.
 */

/** @var array<string, array{0: string, 1: bool}> method+uri suffix => [capability, verified] */
const RELATIONSHIP_ROUTE_TABLE = [
    'GET api/v1/admin/relationships/guardians' => ['guardians.view', false],
    'GET api/v1/admin/relationships/guardians/{person}' => ['guardians.view', false],
    'GET api/v1/admin/relationships/guardians/{person}/management' => ['guardians.manage', false],
    'GET api/v1/admin/relationships/guardians/candidates' => ['guardians.manage', false],
    'POST api/v1/admin/relationships/guardians' => ['guardians.manage', true],
    'PUT api/v1/admin/relationships/guardians/{person}/status' => ['guardians.manage', true],
    'PATCH api/v1/admin/relationships/guardians/{person}/fields' => ['guardians.manage', false],
    'DELETE api/v1/admin/relationships/guardians/{person}' => ['guardians.manage', true],
    'GET api/v1/admin/relationships/volunteers' => ['volunteers.view', false],
    'GET api/v1/admin/relationships/volunteers/{person}' => ['volunteers.view', false],
    'GET api/v1/admin/relationships/volunteers/{person}/management' => ['volunteers.manage', false],
    'GET api/v1/admin/relationships/volunteers/candidates' => ['volunteers.manage', false],
    'POST api/v1/admin/relationships/volunteers' => ['volunteers.manage', false],
    'PUT api/v1/admin/relationships/volunteers/{person}/status' => ['volunteers.manage', false],
    'PATCH api/v1/admin/relationships/volunteers/{person}/fields' => ['volunteers.manage', false],
    'DELETE api/v1/admin/relationships/volunteers/{person}' => ['volunteers.manage', true],
];

it('pins recent verification to A5 for the production catalog', function () {
    $found = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/admin/relationships/')) {
            continue;
        }
        foreach (array_diff(Api::strings($route->methods()), ['HEAD', 'OPTIONS']) as $method) {
            $found[$method.' '.$route->uri()] = Api::strings($route->gatherMiddleware());
        }
    }

    expect(array_keys($found))->toEqualCanonicalizing(array_keys(RELATIONSHIP_ROUTE_TABLE));
    foreach (RELATIONSHIP_ROUTE_TABLE as $key => [$capability, $verified]) {
        $middleware = $found[$key];
        expect($middleware)->toContain('can:'.$capability)
            ->and(in_array('security.verified', $middleware, true))->toBe($verified, $key);
        if ($verified) {
            expect(array_search('can:'.$capability, $middleware, true))
                ->toBeLessThan((int) array_search('security.verified', $middleware, true), $key);
        }
    }
});

it('serves relationship types with Console admission alone', function () {
    $route = app('router')->getRoutes()->getByName('api.v1.admin.relationship-types');
    assert($route instanceof Route);
    $middleware = Api::strings($route->gatherMiddleware());
    $capabilities = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));

    expect($middleware)->toContain('stateful', 'auth:web', 'can:console.access')
        ->and($middleware)->not->toContain('security.verified')
        ->and($capabilities)->toBe([]);
});
