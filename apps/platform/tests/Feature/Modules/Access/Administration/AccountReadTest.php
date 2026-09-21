<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * The administration read model (ADR 0024): only what an operator needs to administer an Account, in a bounded page,
 * and never a secret.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
});

it('discloses exactly the approved fields for an Account, and nothing else', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    Mfa::enroll($target, 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U');

    $response = $console->get('/api/v1/admin/accounts/'.$target->id->value)->assertOk();

    expect(array_keys(Api::map($response->json())))->toBe(['id', 'person_id', 'display_name', 'email', 'email_verified_at', 'status', 'created_at', 'last_login_at', 'disabled_at', 'mfa', 'invitation', 'assignments'])
        ->and($response->json('id'))->toBe($target->id->value)
        ->and($response->json('person_id'))->toBe($target->personId->value)
        ->and($response->json('display_name'))->toBe('Ada Lovelace')
        ->and($response->json('email'))->toBe('target@example.org')
        ->and($response->json('email_verified_at'))->toBeNull()
        ->and($response->json('status'))->toBe('active')
        ->and($response->json('mfa'))->toBe(['enrolled' => true, 'recovery_codes_remaining' => 10])
        ->and($response->json('invitation'))->toBeNull()
        ->and(array_keys(Api::map($response->json('assignments.0'))))->toBe(['key', 'name', 'description', 'granted_at']);
});

it('never discloses a password hash, a factor secret, a recovery code, a token, a session id or an audit detail', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target, 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    $invitation = Identity::savedInvitation($invited);
    $secrets = [
        Api::string(DB::table('accounts')->where('id', $target->id->value)->value('password_hash')),
        Api::string(DB::table('account_totp_factors')->value('secret_ciphertext')),
        Api::string(DB::table('account_recovery_codes')->value('code_hash')),
        $invitation->tokenHash, $factor['secret'], ...$factor['codes'],
        ...Api::strings(DB::table('sessions')->pluck('id')->all()),
    ];

    $everything = (string) $console->get('/api/v1/admin/accounts')->assertOk()->getContent()
        .(string) $console->get('/api/v1/admin/accounts/'.$invited->id->value)->assertOk()->getContent();

    foreach ($secrets as $secret) {
        expect($secret)->not->toBe('');
        expect($everything)->not->toContain($secret);
    }
    foreach (['password', 'token', 'ciphertext', 'digest'] as $word) {
        expect(strtolower($everything))->not->toContain($word);
    }
});

it('shows an invited Account\'s outstanding invitation, and whether it has expired', function () {
    [$console] = Mfa::signedInAdmin();
    $invited = Identity::savedInvitedAccount('invited@example.org');
    Identity::savedInvitation($invited); // issued at Identity::now(), lasting seven days

    $response = $console->get('/api/v1/admin/accounts/'.$invited->id->value)->assertOk();

    expect($response->json('status'))->toBe('invited')
        ->and($response->json('invitation'))->toBe(['expires_at' => '2026-09-26T12:00:00Z', 'expired' => false, 'delivery' => 'operator'])
        ->and($response->json('mfa'))->toBe(['enrolled' => false, 'recovery_codes_remaining' => 0]);

    // An invitation whose lifetime has run out is reported as expired, so the Console can offer a fresh one. (Issued at a fixed
    // instant with a one-hour life, so it is over by the test clock; the clock itself is not moved.)
    $lapsed = Identity::savedInvitedAccount('lapsed@example.org');
    Identity::savedInvitation($lapsed, ttl: 'PT1H');
    expect($console->get('/api/v1/admin/accounts/'.$lapsed->id->value)->json('invitation.expired'))->toBeTrue();
});

it('lists what an Account HOLDS as catalog data, with words for a screen, under a neutral name', function () {
    [$console] = Mfa::signedInAdmin();

    $response = $console->get('/api/v1/admin/accounts?q=admin@example.org')->assertOk();

    expect(Api::map($response->json('data.0.assignments.0')))->toMatchArray(['key' => Role::PlatformAdministrator->value, 'name' => 'Platform administrator'])
        ->and($response->json('data.0.assignments.0.description'))->not->toBe('')
        ->and(array_keys(Api::map($response->json('data.0'))))->not->toContain('roles');
});

it('pages the list modestly, in a stable order, and reports the totals', function () {
    [$console] = Mfa::signedInAdmin();
    foreach (range(1, 7) as $n) {
        Identity::savedActiveAccount("person{$n}@example.org", name: 'Person '.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
    }

    $first = $console->get('/api/v1/admin/accounts?per_page=3&page=1')->assertOk();
    $last = $console->get('/api/v1/admin/accounts?per_page=3&page=3')->assertOk();
    $everyone = [];
    foreach ([1, 2, 3] as $page) {
        foreach (Api::rows($console->get("/api/v1/admin/accounts?per_page=3&page={$page}")->json('data')) as $row) {
            $everyone[] = $row['display_name'];
        }
    }

    expect($first->json('meta'))->toBe(['page' => 1, 'per_page' => 3, 'total' => 8, 'last_page' => 3])
        ->and($first->json('data'))->toHaveCount(3)
        ->and($last->json('data'))->toHaveCount(2)
        // Alphabetical by name (case-insensitively), each account once, whichever engine is underneath.
        ->and($everyone)->toBe(['Administrator', 'Person 01', 'Person 02', 'Person 03', 'Person 04', 'Person 05', 'Person 06', 'Person 07']);
});

it('bounds the page size: it is never an unlimited table', function () {
    [$console] = Mfa::signedInAdmin();

    $console->get('/api/v1/admin/accounts?per_page=101')->assertStatus(422);
    $console->get('/api/v1/admin/accounts?per_page=0')->assertStatus(422);
    $console->get('/api/v1/admin/accounts?page=0')->assertStatus(422);
    expect($console->get('/api/v1/admin/accounts')->json('meta.per_page'))->toBe(25);
});

it('searches by a fragment of the email or the name, and filters by status, and nothing more', function () {
    [$console] = Mfa::signedInAdmin();
    Identity::savedActiveAccount('grace@example.org', name: 'Grace Hopper');
    Identity::savedInvitedAccount('katherine@example.org');
    Identity::savedDisabledAccount('dorothy@example.org');
    $names = function (string $query) use ($console): array {
        $emails = [];
        foreach (Api::rows($console->get('/api/v1/admin/accounts?'.$query)->assertOk()->json('data')) as $row) {
            $emails[] = $row['email'];
        }

        return $emails;
    };

    expect($names('q=GRACE'))->toBe(['grace@example.org'])            // a name, in any case
        ->and($names('q=hopper'))->toBe(['grace@example.org'])
        ->and($names('q=katherine@'))->toBe(['katherine@example.org']) // an email fragment
        ->and($names('status=disabled'))->toBe(['dorothy@example.org'])
        ->and($names('status=invited'))->toBe(['katherine@example.org'])
        ->and($names('q=nobody-matches'))->toBe([])
        // A percent sign or an underscore is a character, not a wildcard.
        ->and($names('q=%25'))->toBe([])
        ->and($names('q=_'))->toBe([]);
    $console->get('/api/v1/admin/accounts?status=whatever')->assertStatus(422);
});

it('answers 404 for an Account that does not exist and for an id that is not one', function () {
    [$console] = Mfa::signedInAdmin();

    $console->get('/api/v1/admin/accounts/01jzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound()->assertJson(['code' => 'account_not_found']);
    $console->get('/api/v1/admin/accounts/not-an-id')->assertNotFound();
});

it('serves a page in a fixed number of queries, however many Accounts it holds', function () {
    [$console] = Mfa::signedInAdmin();
    foreach (range(1, 20) as $n) {
        Access::grant(Identity::savedActiveAccount("bulk{$n}@example.org"), Role::Guardian);
    }
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    $console->get('/api/v1/admin/accounts?per_page=25')->assertOk();

    // Session, authentication, three authorization checks, and the page: a handful, not one per row.
    expect($queries)->toBeLessThan(25);
});
