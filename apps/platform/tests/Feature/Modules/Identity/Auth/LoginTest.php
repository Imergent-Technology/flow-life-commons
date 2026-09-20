<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
});

it('signs in an active account and returns identity and session information only', function () {
    $account = Identity::savedActiveAccount('ada@example.org');
    $console = new Console;

    $response = $console->login('ada@example.org', Identity::PASSWORD);

    $response->assertOk()
        ->assertJsonPath('account.id', $account->id->value)
        ->assertJsonPath('account.email', 'ada@example.org')
        ->assertJsonPath('person.id', $account->personId->value)
        ->assertJsonPath('person.display_name', 'Ada Lovelace')
        ->assertJsonPath('session.authenticated_at', '2026-09-19T12:00:00Z')
        ->assertJsonPath('session.absolute_expires_at', '2026-09-20T00:00:00Z');

    // What the account may do is reported as capability identifiers (none held here); role
    // names are never exposed.
    $body = $response->json();
    assert(is_array($body));
    expect(array_keys($body))->toBe(['account', 'person', 'capabilities', 'session'])
        ->and($body['capabilities'])->toBe([])
        ->and($body)->not->toHaveKeys(['roles', 'permissions']);
});

it('resolves the account by canonical email, whatever case or padding is typed', function (string $typed) {
    Identity::savedActiveAccount('Ada.Lovelace@Example.org');

    (new Console)->login($typed, Identity::PASSWORD)->assertOk();
})->with(['ada.lovelace@example.org', 'ADA.LOVELACE@EXAMPLE.ORG', 'Ada.Lovelace@Example.org', '  ada.lovelace@example.org ']);

it('records the sign-in time on the account', function () {
    $account = Identity::savedActiveAccount();

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertOk();

    expect(app(AccountRepository::class)->find($account->id)?->lastLoginAt)->toEqual(new DateTimeImmutable('2026-09-19 12:00:00'))
        ->and(DB::table('accounts')->value('last_login_at'))->toBe('2026-09-19 12:00:00');
});

it('rejects a wrong password', function () {
    Identity::savedActiveAccount();

    (new Console)->login('ada@example.org', 'not the password')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'The provided credentials are incorrect.']);
});

it('rejects a malformed email as a validation error, not a credentials error', function (string $email) {
    (new Console)->login($email, 'whatever')->assertUnprocessable()->assertJsonValidationErrors(['email']);
})->with(['not an email', 'josé@example.org', 'a@b@c.org']);

it('requires both fields', function () {
    $console = new Console;
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org'])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $console->post('/api/v1/login', ['password' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('gives an indistinguishable answer for unknown, wrong-password, invited and disabled accounts', function () {
    Identity::savedActiveAccount('active@example.org');
    Identity::savedInvitedAccount('invited@example.org');
    Identity::savedDisabledAccount('disabled@example.org');

    $attempts = [
        'unknown address' => ['nobody@example.org', Identity::PASSWORD],
        'wrong password' => ['active@example.org', 'wrong'],
        'invited account' => ['invited@example.org', Identity::PASSWORD],
        'invited account, any password' => ['invited@example.org', ''.'x'],
        'disabled account, right password' => ['disabled@example.org', Identity::PASSWORD],
    ];

    $seen = [];
    foreach ($attempts as [$email, $password]) {
        $response = (new Console)->login($email, $password);
        $seen[] = [$response->getStatusCode(), $response->getContent(), $response->headers->get('Content-Type')];
    }

    expect(array_unique($seen, SORT_REGULAR))->toHaveCount(1)
        ->and($seen[0][0])->toBe(401);
});

it('never lets an invited or disabled account establish a session', function (string $email) {
    Identity::savedInvitedAccount('invited@example.org');
    Identity::savedDisabledAccount('disabled@example.org');
    $console = new Console;

    $console->login($email, Identity::PASSWORD)->assertUnauthorized();

    expect($console->me()->status())->toBe(401)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
})->with(['invited@example.org', 'disabled@example.org']);

it('performs one password check on every path, so response time does not reveal whether an address exists', function () {
    Identity::savedActiveAccount('active@example.org');
    Identity::savedInvitedAccount('invited@example.org');
    Identity::savedDisabledAccount('disabled@example.org');

    $real = app(Hasher::class);
    $checks = 0;
    app()->instance(Hasher::class, new class($real, $checks) implements Hasher
    {
        public function __construct(private Hasher $inner, private int &$checks) {}

        /** @return array<mixed> */
        public function info($hashedValue): array
        {
            return $this->inner->info($hashedValue);
        }

        /** @param  array<string, mixed>  $options */
        public function make($value, array $options = []): string
        {
            return $this->inner->make($value, $options);
        }

        /** @param  array<string, mixed>  $options */
        public function check($value, $hashedValue, array $options = []): bool
        {
            $this->checks++;

            return $this->inner->check($value, $hashedValue, $options);
        }

        /** @param  array<string, mixed>  $options */
        public function needsRehash($hashedValue, array $options = []): bool
        {
            return $this->inner->needsRehash($hashedValue, $options);
        }
    });

    $perPath = [];
    foreach (['nobody@example.org', 'active@example.org', 'invited@example.org', 'disabled@example.org'] as $email) {
        $checks = 0;
        (new Console)->login($email, Identity::PASSWORD);
        $perPath[$email] = $checks;
    }

    expect($perPath)->toBe([
        'nobody@example.org' => 1,
        'active@example.org' => 1,
        'invited@example.org' => 1,
        'disabled@example.org' => 1,
    ]);
});

it('never persists the plain password anywhere, only the approved hash', function () {
    $password = 'Zx9!plaintext-marker-password';
    Identity::savedActiveAccount('ada@example.org', $password);
    $console = new Console;

    $console->login('ada@example.org', $password)->assertOk();
    $console->login('ada@example.org', $password.'-wrong')->assertUnauthorized();
    $console->logout();

    $everything = '';
    foreach (['people', 'accounts', 'account_invitations', 'sessions', 'security_events'] as $table) {
        $everything .= json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
    }

    $hash = Identity::scalar('accounts', 'password_hash');
    expect($everything)->not->toContain($password)
        ->and($everything)->not->toContain('marker-password')
        ->and($hash)->toStartWith('$2y$')
        ->and(app(Hasher::class)->check($password, $hash))->toBeTrue();
});

it('fails the whole sign-in, and establishes no session, when the audit write fails', function () {
    // ADR 0019: the event and the state change share a transaction, so an unrecorded login is a failed one.
    $account = Identity::savedActiveAccount();
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });
    $console = new Console;
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD])->assertServerError();

    expect(DB::table('accounts')->where('id', $account->id->value)->value('last_login_at'))->toBeNull()
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});

it('does not let a sign-in undo a disable that committed after the account was read', function () {
    // The interleaving, made deterministic: login reads the Account (active) and verifies the
    // password; then a disable commits; then login writes. Saving the stale copy back would
    // silently re-enable the account.
    $account = Identity::savedActiveAccount();
    $real = app(AccountRepository::class);
    app()->instance(AccountRepository::class, new class($real) implements AccountRepository
    {
        public function __construct(private AccountRepository $inner) {}

        public function findByEmail(EmailAddress $email): ?Account
        {
            $account = $this->inner->findByEmail($email);
            if ($account !== null) {
                // A disable commits right after login read the account.
                $this->inner->save($account->disable(new DateTimeImmutable('2026-09-20 00:00:00', new DateTimeZone('UTC'))));
            }

            return $account; // ...and login carries on with its stale, still-active copy
        }

        public function save(Account $account): void
        {
            $this->inner->save($account);
        }

        public function find(AccountId $id): ?Account
        {
            return $this->inner->find($id);
        }

        public function findForUpdate(AccountId $id): ?Account
        {
            return $this->inner->findForUpdate($id);
        }

        public function findByPersonId(PersonId $personId): ?Account
        {
            return $this->inner->findByPersonId($personId);
        }
    });

    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();

    expect(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled')
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('last_login_at'))->toBeNull()
        ->and(Identity::events('authentication.succeeded'))->toBe([])
        ->and(Identity::context(Identity::events('authentication.failed')[0])['reason'])->toBe('account_not_active');
    app()->instance(AccountRepository::class, $real);
    $console->me()->assertUnauthorized();
});
