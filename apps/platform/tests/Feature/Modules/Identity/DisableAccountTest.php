<?php

declare(strict_types=1);

use App\Modules\Access\Application\LastAdministratorDeactivationGuard;
use App\Modules\Access\Application\Role;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Application\AccountDeactivationGuard;
use App\Modules\Identity\Application\AccountDeactivationRefused;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\DeactivationOutcome;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

function disableAccount(): DisableAccount
{
    return app(DisableAccount::class);
}

function statusOf(AccountId $id): ?AccountStatus
{
    return app(AccountRepository::class)->find($id)?->status;
}

it('disables an active account', function () {
    $account = Identity::savedActiveAccount();

    $outcome = disableAccount()($account->id);

    expect($outcome)->toBe(DeactivationOutcome::Disabled)
        ->and(statusOf($account->id))->toBe(AccountStatus::Disabled)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled')
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('disabled_at'))->not->toBeNull();
});

it('can disable an account that never activated', function () {
    $invited = Identity::savedInvitedAccount();

    disableAccount()($invited->id);

    expect(statusOf($invited->id))->toBe(AccountStatus::Disabled);
});

it('keeps the person, the role assignments and the history: disabling is not deleting', function () {
    $admin = Access::admin('admin@example.org', 'Admin');
    $account = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::grant($account, Role::Guardian);
    Identity::savedInvitation($account);
    Mfa::enroll($account);
    (new Console)->loginWithMfa('target@example.org', Identity::PASSWORD)->assertOk();

    disableAccount()($account->id, Access::actorFor($admin));

    expect(app(PersonRepository::class)->find($account->personId))->not->toBeNull()
        ->and(DB::table('role_assignments')->where('person_id', $account->personId->value)->count())->toBe(1)
        ->and(DB::table('account_invitations')->where('account_id', $account->id->value)->count())->toBe(1)
        ->and(DB::table('security_events')->count())->toBeGreaterThan(0) // earlier history is intact
        ->and(app(AccountRepository::class)->find($account->id)?->lastLoginAt)->not->toBeNull();
});

it('ends every session of the account and only that account\'s', function () {
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    $bystander = Identity::savedActiveAccount('bystander@example.org', name: 'Bystander');
    $first = new Console;
    $second = new Console;
    $other = new Console;
    $first->login('target@example.org', Identity::PASSWORD)->assertOk();
    $second->login('target@example.org', Identity::PASSWORD)->assertOk();
    $other->login('bystander@example.org', Identity::PASSWORD)->assertOk();
    $anonymous = new Console;
    $anonymous->bootstrap();
    expect(DB::table('sessions')->where('user_id', $target->id->value)->count())->toBe(2);

    disableAccount()($target->id);

    expect(DB::table('sessions')->where('user_id', $target->id->value)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $bystander->id->value)->count())->toBe(1)
        ->and(DB::table('sessions')->whereNull('user_id')->count())->toBe(1);
    // ...and the cookies really are dead, not merely orphaned: neither browser is signed in.
    $first->me()->assertUnauthorized();
    $second->me()->assertUnauthorized();
    $other->me()->assertOk();
});

it('cannot sign in again once disabled', function () {
    $account = Identity::savedActiveAccount();
    disableAccount()($account->id);

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();
});

it('audits the disable with the actor, the subject and no secret', function () {
    $admin = Access::admin('admin@example.org', 'Admin');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    (new Console)->login('target@example.org', Identity::PASSWORD)->assertOk();

    disableAccount()($target->id, Access::actorFor($admin));

    $events = Identity::events('account.disabled');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_account_id)->toBe($target->id->value)
        ->and($events[0]->subject_person_id)->toBe($target->personId->value)
        ->and(Identity::context($events[0]))->toBe(['previous_status' => 'active', 'signed_out' => 1]);
});

it('records no actor when an operator with server access disables an account', function () {
    $target = Identity::savedActiveAccount();

    disableAccount()($target->id);

    expect(Identity::events('account.disabled')[0]->actor_account_id)->toBeNull();
});

it('rolls the whole change back when the audit write fails', function () {
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    $console = new Console;
    $console->login('target@example.org', Identity::PASSWORD)->assertOk();
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => disableAccount()($target->id))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(statusOf($target->id))->toBe(AccountStatus::Active)                             // not disabled
        ->and(DB::table('sessions')->where('user_id', $target->id->value)->count())->toBe(1); // sessions survive
    app()->forgetInstance(SecurityEventWriter::class);
    $console->me()->assertOk();
});

it('treats disabling an already-disabled account as a successful no-op that records nothing', function () {
    $target = Identity::savedActiveAccount();
    disableAccount()($target->id);

    expect(disableAccount()($target->id))->toBe(DeactivationOutcome::AlreadyDisabled)
        ->and(Identity::events('account.disabled'))->toHaveCount(1);
});

it('refuses an account that does not exist', function () {
    expect(fn () => disableAccount()(AccountId::generate()))->toThrow(AccountNotFound::class);
});

// --- The guard chain -------------------------------------------------------------------------------

it('registers Access\'s last-administrator guard in the chain, without Identity naming it', function () {
    $guards = iterator_to_array(app()->tagged(AccountDeactivationGuard::TAG), false);

    $access = array_filter($guards, fn (mixed $g): bool => $g instanceof LastAdministratorDeactivationGuard);

    expect($guards)->not->toBe([])->and($access)->not->toBe([]);
});

it('consults every guard BEFORE changing anything, and stops if one refuses', function () {
    $target = Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $seen = new ArrayObject;
    $observer = new class($seen, $target->id) implements AccountDeactivationGuard
    {
        /** @param  ArrayObject<int, string>  $seen */
        public function __construct(private ArrayObject $seen, private AccountId $id) {}

        public function assertMayDeactivate(AccountId $account, PersonId $person): void
        {
            // At this moment nothing has changed: the account is still active and its session lives.
            $status = DB::table('accounts')->where('id', $this->id->value)->value('status');
            $this->seen[] = (is_string($status) ? $status : '?').'/'.DB::table('sessions')->where('user_id', $this->id->value)->count();
            throw new AccountDeactivationRefused('a guard said no');
        }
    };
    app()->instance('test.observer', $observer);
    app()->tag(['test.observer'], AccountDeactivationGuard::TAG);

    expect(fn () => disableAccount()($target->id))->toThrow(AccountDeactivationRefused::class, 'a guard said no');

    expect($seen->getArrayCopy())->toBe(['active/1'])
        ->and(statusOf($target->id))->toBe(AccountStatus::Active)
        ->and(Identity::events('account.disabled'))->toBe([]);
    $console->me()->assertOk();
});

it('does not consult the chain at all for an account that is already disabled', function () {
    $target = Identity::savedActiveAccount();
    disableAccount()($target->id);
    $calls = 0;
    app()->instance('test.counter', new class($calls) implements AccountDeactivationGuard
    {
        public function __construct(private int &$calls) {}

        public function assertMayDeactivate(AccountId $account, PersonId $person): void
        {
            $this->calls++;
        }
    });
    app()->tag(['test.counter'], AccountDeactivationGuard::TAG);

    disableAccount()($target->id);

    expect($calls)->toBe(0);
});

it('re-reads the account WITH a lock before changing it, so a concurrent write cannot be overwritten', function () {
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target'); // not an administrator
    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    disableAccount()($target->id);

    $inside = array_slice($log, (int) array_search('BEGIN', $log, true) + 1);
    $locking = array_filter($inside, fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, 'accounts') && str_contains($s, 'for update'));

    expect($locking)->toHaveCount(1); // the target, locked, before the update
});
