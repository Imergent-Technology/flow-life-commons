<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mfa;

/*
 * A route id that is not a valid ULID must not reach the value object, whose constructor throws (an uncaught
 * InvalidArgumentException, a 500). The route constraint is exactly what Str::isUlid accepts, so a malformed id is a
 * route that does not match: the platform's ordinary 404, before any middleware, use case or query.
 *
 * The old constraint `[0-9a-z]{26}` accepted every string below.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/** Twenty-six lowercase alphanumerics that the loose constraint matched and Str::isUlid rejects. */
const MALFORMED_IDS = [
    'all z' => 'zzzzzzzzzzzzzzzzzzzzzzzzzz',
    'first char above 7' => '8zzzzzzzzzzzzzzzzzzzzzzzzz',
    'letter u (not Crockford)' => '01uuuuuuuuuuuuuuuuuuuuuuuu',
    'letter i (not Crockford)' => '01iiiiiiiiiiiiiiiiiiiiiiii',
    'letter l (not Crockford)' => '01llllllllllllllllllllllll',
    'letter o (not Crockford)' => '01oooooooooooooooooooooooo',
    'wrong length' => '01jzzzzzzzzzzzzzzzzzzzzzz',
    'not an id at all' => 'not-an-id',
];

it('answers the ordinary route 404, never a 500, for a malformed id on every route that takes one', function () {
    [$console] = Mfa::signedInAdmin();
    $term = ['starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'];

    foreach (MALFORMED_IDS as $why => $id) {
        $responses = [
            'detail' => $console->get("/api/v1/admin/members/{$id}"),
            'grant' => $console->post("/api/v1/admin/members/{$id}/grants", $term),
            'revoke' => $console->post("/api/v1/admin/membership-grants/{$id}/revoke"),
        ];
        foreach ($responses as $route => $response) {
            expect($response->status())->toBe(404, "{$route} with {$why}")
                ->and($response->json('code'))->toBeNull("{$route} with {$why}: a route miss, not a coded domain 404");
        }
    }
    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('still reaches the use case for a well-formed id that names nothing (control: the 404 above was the route\'s)', function () {
    [$console] = Mfa::signedInAdmin();
    $unknown = '01jzzzzzzzzzzzzzzzzzzzzzzz';

    $console->get("/api/v1/admin/members/{$unknown}")->assertNotFound()->assertJson(['code' => 'person_not_found']);
    $console->post("/api/v1/admin/membership-grants/{$unknown}/revoke")->assertNotFound()->assertJson(['code' => 'grant_not_found']);
});
