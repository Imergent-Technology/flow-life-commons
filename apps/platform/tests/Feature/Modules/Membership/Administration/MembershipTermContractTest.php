<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * Open-ended access is an explicit operator decision (ADR 0028), and the HTTP contract says so with a required
 * `open_ended` boolean that must agree with `ends_at`. Laravel's global TrimStrings + ConvertEmptyStringsToNull turn
 * `""` and `"   "` into null before validation, so `ends_at` alone cannot tell a deliberate null from a blank form
 * field: without `open_ended` a blank end date silently granted indefinite access.
 *
 * Every case runs against BOTH endpoints that carry a term, so neither can drift from the other.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/** @return array{Console, string} a signed-in administrator, and the id of an existing Person for the grant endpoint */
function termSetup(): array
{
    [$console] = Mfa::signedInAdmin();

    return [$console, Identity::savedPerson('Existing')->id->value];
}

/** @return array<string, array{string, array<string, mixed>}> the two endpoints, as [label => [path-with-{p}, extra body]] */
function termEndpoints(): array
{
    return [
        'create member' => ['/api/v1/admin/members', ['display_name' => 'Term Test']],
        'add grant' => ['/api/v1/admin/members/{p}/grants', []],
    ];
}

/**
 * Posts a term to an endpoint. A key in $term whose value is the string `MISSING` is omitted from the body.
 *
 * @param  array<string, mixed>  $extra
 * @param  array<string, mixed>  $term
 * @return TestResponse<Response>
 */
function postTerm(Console $console, string $person, string $path, array $extra, array $term): TestResponse
{
    $body = [...$extra, 'starts_at' => '2026-09-23T12:00:00Z', 'source' => 'operator', ...$term];
    foreach ($term as $key => $value) {
        if ($value === 'MISSING') {
            unset($body[$key]);
        }
    }

    return $console->post(str_replace('{p}', $person, $path), $body);
}

function grantCount(): int
{
    return DB::table('membership_grants')->count();
}

foreach (termEndpoints() as $label => [$path, $extra]) {
    it("{$label}: creates an open-ended grant only from open_ended=true with ends_at=null", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        $response = postTerm($console, $person, $path, $extra, ['open_ended' => true, 'ends_at' => null]);

        $response->assertCreated();
        expect(grantCount())->toBe(1)
            ->and(DB::table('membership_grants')->value('ends_at'))->toBeNull();
    });

    it("{$label}: creates a bounded grant from open_ended=false with a later end", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        postTerm($console, $person, $path, $extra, ['open_ended' => false, 'ends_at' => '2027-09-23T12:00:00Z'])->assertCreated();

        expect(grantCount())->toBe(1)
            ->and(DB::table('membership_grants')->value('ends_at'))->not->toBeNull();
    });

    it("{$label}: refuses a missing open_ended", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        postTerm($console, $person, $path, $extra, ['open_ended' => 'MISSING', 'ends_at' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['open_ended']);
        expect(grantCount())->toBe(0);
    });

    it("{$label}: refuses a non-boolean open_ended", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        foreach (['yes', 'maybe', [], 'null-ish'] as $value) {
            postTerm($console, $person, $path, $extra, ['open_ended' => $value, 'ends_at' => null])
                ->assertStatus(422)->assertJsonValidationErrors(['open_ended']);
        }
        expect(grantCount())->toBe(0);
    });

    it("{$label}: refuses a missing ends_at", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        foreach ([true, false] as $openEnded) {
            postTerm($console, $person, $path, $extra, ['open_ended' => $openEnded, 'ends_at' => 'MISSING'])
                ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        }
        expect(grantCount())->toBe(0);
    });

    it("{$label}: refuses open_ended=false with no end date", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        postTerm($console, $person, $path, $extra, ['open_ended' => false, 'ends_at' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        expect(grantCount())->toBe(0);
    });

    it("{$label}: refuses open_ended=false with a blank end date, however it is blank", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        // The regression itself: TrimStrings + ConvertEmptyStringsToNull make each of these null before validation.
        foreach (['', '   ', "\t\n"] as $blank) {
            postTerm($console, $person, $path, $extra, ['open_ended' => false, 'ends_at' => $blank])
                ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        }
        expect(grantCount())->toBe(0);
    });

    it("{$label}: refuses open_ended=true together with an end date", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        postTerm($console, $person, $path, $extra, ['open_ended' => true, 'ends_at' => '2027-09-23T12:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        expect(grantCount())->toBe(0);
    });

    it("{$label}: still refuses a bounded end that is not after the start", function () use ($path, $extra) {
        [$console, $person] = termSetup();
        postTerm($console, $person, $path, $extra, ['open_ended' => false, 'ends_at' => '2026-09-23T12:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        expect(grantCount())->toBe(0);
    });
}
