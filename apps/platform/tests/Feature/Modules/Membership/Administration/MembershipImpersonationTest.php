<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Caller is not subject (Work Package 7). The caller of a Membership request is whoever the Commons session
 * authenticated, and nothing a request carries can change that: not a body field, a query parameter, a header naming a
 * person or a WordPress user, nor the Person or grant id in the URL, which only ever names WHAT is acted on.
 *
 * Extra body fields follow the platform's existing request convention, which is to ignore what a FormRequest does not
 * declare (it neither rejects nor reads them); so these tests assert they have no effect, rather than that they are
 * refused. Provenance is checked in the table, where a forged value would have to land to matter.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/**
 * Everything a client could send to claim to be someone else.
 *
 * @return array<string, string>
 */
function forgedIdentityHeaders(Account $as): array
{
    return [
        'X-User-Id' => $as->id->value,
        'X-Account-Id' => $as->id->value,
        'X-Person-Id' => $as->personId->value,
        'X-Actor-Id' => $as->id->value,
        'X-WordPress-User' => '42',
        'X-Forwarded-User' => $as->email->value,
        'Authorization' => 'Bearer '.$as->id->value,
    ];
}

/** @return array<string, mixed> */
function forgedIdentityBody(Account $as): array
{
    return [
        'actor_id' => $as->id->value,
        'actor_account_id' => $as->id->value,
        'account_id' => $as->id->value,
        'person_id' => $as->personId->value,
        'user_id' => $as->id->value,
        'wordpress_user_id' => 42,
        'email' => $as->email->value,
        'granted_by_account_id' => $as->id->value,
        'revoked_by_account_id' => $as->id->value,
        'role' => Role::PlatformAdministrator->value,
        'capability' => 'membership.records.manage',
    ];
}

/** @return array{starts_at: string, open_ended: true, ends_at: null, source: string} */
function openEndedTerm(): array
{
    return ['starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'];
}

function grantedBy(string $grantId): mixed
{
    return DB::table('membership_grants')->where('id', $grantId)->value('granted_by_account_id');
}

it('A: keeps operator A as the caller of a grant for C, whatever the body and headers say about B', function () {
    [$console, $a] = Mfa::signedInAdmin();
    $b = Access::admin('b@example.org', 'Operator B'); // a real, privileged Account: the most tempting one to borrow
    $c = Identity::savedPerson('Subject C');

    $response = $console->post(
        '/api/v1/admin/members/'.$c->id->value.'/grants',
        [...openEndedTerm(), ...forgedIdentityBody($b)],
        forgedIdentityHeaders($b),
    )->assertCreated();

    $grant = Api::string($response->json('id'));
    expect($response->json('person_id'))->toBe($c->id->value)
        ->and(DB::table('membership_grants')->where('id', $grant)->value('person_id'))->toBe($c->id->value)
        ->and(grantedBy($grant))->toBe($a->id->value)
        ->and(DB::table('membership_grants')->where('person_id', $b->personId->value)->count())->toBe(0);
});

it('A: keeps operator A as the caller when registering a new Person, and the new Person is nobody who was named', function () {
    [$console, $a] = Mfa::signedInAdmin();
    $b = Access::admin('b@example.org', 'Operator B');
    $accounts = DB::table('accounts')->count();

    $response = $console->post(
        '/api/v1/admin/members',
        ['display_name' => 'Newly Registered', ...openEndedTerm(), ...forgedIdentityBody($b)],
        forgedIdentityHeaders($b),
    )->assertCreated();

    $person = Api::string($response->json('person.id'));
    expect($person)->not->toBe($a->personId->value)
        ->and($person)->not->toBe($b->personId->value)
        ->and(DB::table('membership_grants')->where('person_id', $person)->value('granted_by_account_id'))->toBe($a->id->value)
        ->and(DB::table('accounts')->count())->toBe($accounts); // and nobody was given a way to sign in
});

it('A: keeps operator A as the revoker of a grant B made, whatever the request claims', function () {
    [$console, $a] = Mfa::signedInAdmin();
    $b = Access::admin('b@example.org', 'Operator B');
    $grant = Membership::savedGrant(Identity::savedPerson('Subject C')->id, grantedBy: $b->id);

    $console->post(
        '/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke',
        forgedIdentityBody($b),
        forgedIdentityHeaders($b),
    )->assertNoContent();

    $row = DB::table('membership_grants')->where('id', $grant->id->value)->first(['granted_by_account_id', 'revoked_by_account_id']);
    expect($row?->revoked_by_account_id)->toBe($a->id->value)
        ->and($row?->granted_by_account_id)->toBe($b->id->value); // history is not rewritten either
});

it('B: answers a read the same with and without forged identity headers', function () {
    [$console] = Mfa::signedInAdmin();
    $b = Access::admin('b@example.org', 'Operator B');
    $c = Identity::savedPerson('Subject C');
    Membership::savedGrant($c->id);

    foreach (['/api/v1/admin/members/'.$c->id->value, '/api/v1/admin/members'] as $path) {
        $plain = $console->get($path)->assertOk()->json();
        $forged = $console->get($path.'?'.http_build_query(forgedIdentityBody($b)), forgedIdentityHeaders($b))->assertOk()->json();
        expect($forged)->toBe($plain, $path);
    }
});

it('C: refuses every Membership route without a Commons session, however convincing the claimed identity', function () {
    $b = Access::admin('b@example.org', 'Operator B');
    $c = Identity::savedPerson('Subject C');
    $grant = Membership::savedGrant($c->id);
    $before = ['grants' => DB::table('membership_grants')->count(), 'people' => DB::table('people')->count()];
    $console = new Console;
    $console->bootstrap(); // a real CSRF token, so the refusal is authentication's, not the forgery check's
    $query = '?'.http_build_query(forgedIdentityBody($b));

    $responses = [
        $console->get('/api/v1/admin/members'.$query, forgedIdentityHeaders($b)),
        $console->get('/api/v1/admin/members/'.$c->id->value.$query, forgedIdentityHeaders($b)),
        $console->post('/api/v1/admin/members', ['display_name' => 'X', ...openEndedTerm(), ...forgedIdentityBody($b)], forgedIdentityHeaders($b)),
        $console->post('/api/v1/admin/members/'.$c->id->value.'/grants', [...openEndedTerm(), ...forgedIdentityBody($b)], forgedIdentityHeaders($b)),
        $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke', forgedIdentityBody($b), forgedIdentityHeaders($b)),
    ];

    foreach ($responses as $response) {
        $response->assertUnauthorized();
    }
    expect(DB::table('membership_grants')->count())->toBe($before['grants'])
        ->and(DB::table('people')->count())->toBe($before['people'])
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(0);
});

it('D: does not authorize a signed-in Person without the capability, even when the subject is themselves', function () {
    [$console, $guardian] = Mfa::signedIn('guardian@example.org', Totp::RFC_SECRET);
    $own = Membership::savedGrant($guardian->personId); // their OWN record: owning the subject grants nothing

    $responses = [
        $console->get('/api/v1/admin/members/'.$guardian->personId->value),
        $console->post('/api/v1/admin/members/'.$guardian->personId->value.'/grants', [...openEndedTerm(), ...forgedIdentityBody($guardian)]),
        $console->post('/api/v1/admin/membership-grants/'.$own->id->value.'/revoke'),
    ];

    foreach ($responses as $response) {
        expect($response->status())->toBe(403)
            ->and($response->json('verification_required'))->toBeNull(); // refused on capability, not asked to prove more
    }
    expect(DB::table('membership_grants')->where('person_id', $guardian->personId->value)->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(0);
});

it('E: grants to a Person with no Account without making them a caller: no Account, no session, no way in', function () {
    [$console] = Mfa::signedInAdmin();
    $subject = Identity::savedPerson('No Account');
    $before = ['accounts' => DB::table('accounts')->count(), 'sessions' => DB::table('sessions')->count()];

    $console->post('/api/v1/admin/members/'.$subject->id->value.'/grants', openEndedTerm())->assertCreated();

    expect(app(AccountRepository::class)->findByPersonId($subject->id))->toBeNull()
        ->and(DB::table('accounts')->count())->toBe($before['accounts'])
        ->and(DB::table('sessions')->count())->toBe($before['sessions']);

    // And their Person id is no credential: a fresh browser naming it is still nobody, while the operator is signed in.
    $stranger = new Console;
    $stranger->get('/api/v1/admin/members/'.$subject->id->value, ['X-Person-Id' => $subject->id->value])->assertUnauthorized();
    $stranger->me()->assertUnauthorized();
    $console->me()->assertOk();
});

it('F: changing the target Person changes only the subject; the recorded caller stays the same', function () {
    [$console, $a] = Mfa::signedInAdmin();
    $b = Identity::savedPerson('Subject B');
    $c = Identity::savedPerson('Subject C');

    $forB = Api::string($console->post('/api/v1/admin/members/'.$b->id->value.'/grants', openEndedTerm())->assertCreated()->json('id'));
    $forC = Api::string($console->post('/api/v1/admin/members/'.$c->id->value.'/grants', openEndedTerm())->assertCreated()->json('id'));

    expect(DB::table('membership_grants')->where('id', $forB)->value('person_id'))->toBe($b->id->value)
        ->and(DB::table('membership_grants')->where('id', $forC)->value('person_id'))->toBe($c->id->value)
        ->and(grantedBy($forB))->toBe($a->id->value)
        ->and(grantedBy($forC))->toBe($a->id->value);
});

it('treats the grant id in a revoke URL as a subject only: revoking another operator\'s grant records the caller', function () {
    [$console, $a] = Mfa::signedInAdmin();
    $b = Access::admin('b@example.org', 'Operator B');
    // B's OWN membership, granted by B: every id in the request points at B except the session's.
    $bs = Membership::savedGrant($b->personId, grantedBy: $b->id);

    $console->post('/api/v1/admin/membership-grants/'.$bs->id->value.'/revoke')->assertNoContent();

    expect(DB::table('membership_grants')->where('id', $bs->id->value)->value('revoked_by_account_id'))->toBe($a->id->value)
        ->and(DB::table('role_assignments')->where('person_id', $b->personId->value)->count())->toBe(1); // B's access is untouched
});
