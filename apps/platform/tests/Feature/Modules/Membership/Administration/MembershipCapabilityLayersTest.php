<?php

declare(strict_types=1);

use App\Modules\Membership\Application\GrantMembershipAccess;
use App\Modules\Membership\Domain\MembershipGrantSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Capability enforcement is REAL at both layers, each on its own (Work Package 7).
 *
 * The role catalog is code-owned and has exactly two roles: the platform administrator (every capability) and the guardian
 * (Console access only). So no real Actor holds `membership.records.view` without `.manage`, or the reverse, and a test that
 * only ever uses those two cannot tell a correct check from a missing one at a single layer. These tests therefore move ONE
 * layer at a time through the platform's own seam — the Laravel Gate the route `can:` middleware asks — and keep the other
 * layer real:
 *
 * - the Gate refuses what the Authorizer would allow: the HTTP layer alone must stop the request;
 * - the Gate allows what the Authorizer refuses: the use case alone must stop the request.
 *
 * `manage` does not imply `view` anywhere: the Authorizer answers exact membership of a flat capability list, and nothing in
 * Access, the routes or the Console derives one from the other. These tests do not assume that implication either way.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/** Makes the Gate answer `$answer` for these abilities only; every other ability still asks the real Authorizer. */
function gateAnswers(bool $answer, string ...$abilities): void
{
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => in_array($ability, $abilities, true) ? $answer : null);
}

/** @return array<string, mixed> */
function newMemberBody(): array
{
    return ['display_name' => 'Layer Test', 'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'];
}

it('stops a mutation at the HTTP layer alone: capability refused at the route, although the use case would allow it', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $subject = Identity::savedPerson('Subject');
    $grant = Membership::savedGrant($subject->id);
    gateAnswers(false, 'membership.records.manage'); // view stays real (granted), manage is refused at the route only

    $responses = [
        $console->post('/api/v1/admin/members', newMemberBody()),
        $console->post('/api/v1/admin/members/'.$subject->id->value.'/grants', ['starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator']),
        $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke'),
    ];
    foreach ($responses as $response) {
        expect($response->status())->toBe(403)->and($response->json('verification_required'))->toBeNull();
    }
    // A view-only operator at the HTTP boundary: reads still succeed.
    $console->get('/api/v1/admin/members')->assertOk();
    $console->get('/api/v1/admin/members/'.$subject->id->value)->assertOk();

    expect(DB::table('membership_grants')->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('people')->where('display_name', 'Layer Test')->count())->toBe(0);

    // Control: the Application layer really would have allowed this Actor, so the refusal above was the route's.
    app(GrantMembershipAccess::class)(Access::actorFor($admin), $subject->id, Identity::now(), null, MembershipGrantSource::Operator);
    expect(DB::table('membership_grants')->count())->toBe(2);
});

it('stops a read at the HTTP layer alone, and does not make reading a precondition of writing', function () {
    [$console] = Mfa::signedInAdmin();
    $subject = Identity::savedPerson('Subject');
    Membership::savedGrant($subject->id);
    gateAnswers(false, 'membership.records.view'); // manage stays real (granted), view is refused at the route only

    $console->get('/api/v1/admin/members')->assertForbidden();
    $console->get('/api/v1/admin/members/'.$subject->id->value)->assertForbidden();

    // No hidden manage -> view dependency: every mutation, including the one whose response is a whole record, completes.
    $console->post('/api/v1/admin/members', newMemberBody())->assertCreated();
    $console->post('/api/v1/admin/members/'.$subject->id->value.'/grants', ['starts_at' => '2027-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'])->assertCreated();
});

it('stops every operation at the Application layer alone: the route lets a guardian through, the use case refuses', function () {
    [$console] = Mfa::signedIn('guardian@example.org', Totp::RFC_SECRET); // a fresh proof, so step-up is no obstacle
    $subject = Identity::savedPerson('Subject');
    $grant = Membership::savedGrant($subject->id);
    gateAnswers(true, 'membership.records.view', 'membership.records.manage');

    $responses = [
        'list' => $console->get('/api/v1/admin/members'),
        'show' => $console->get('/api/v1/admin/members/'.$subject->id->value),
        'register' => $console->post('/api/v1/admin/members', newMemberBody()),
        'grant' => $console->post('/api/v1/admin/members/'.$subject->id->value.'/grants', ['starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator']),
        'revoke' => $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke'),
    ];
    foreach ($responses as $name => $response) {
        // 403 from the use case's AccessDenied, not the step-up seam: the request reached the Application layer.
        expect($response->status())->toBe(403, $name)->and($response->json('verification_required'))->toBeNull($name);
    }

    expect(DB::table('membership_grants')->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('people')->where('display_name', 'Layer Test')->count())->toBe(0);
});

it('lets the request through only when both layers agree (control: the overrides above change nothing else)', function () {
    [$console] = Mfa::signedInAdmin();
    $subject = Identity::savedPerson('Subject');
    gateAnswers(true, 'membership.records.view', 'membership.records.manage'); // agreeing with the real Authorizer

    $console->get('/api/v1/admin/members')->assertOk();
    $console->post('/api/v1/admin/members/'.$subject->id->value.'/grants', ['starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'])->assertCreated();
});
