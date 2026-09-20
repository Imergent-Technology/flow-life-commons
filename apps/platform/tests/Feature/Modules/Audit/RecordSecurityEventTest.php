<?php

declare(strict_types=1);

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Audit\Domain\InvalidSecurityEvent;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function record(): RecordSecurityEvent
{
    return app(RecordSecurityEvent::class);
}

/** @return object{id: string, occurred_at: string, type: string, actor_account_id: ?string, actor_client_id: ?string, subject_person_id: ?string, subject_account_id: ?string, ip: ?string, user_agent: ?string, outcome: string, context: ?string} */
function onlyEvent(): object
{
    expect(DB::table('security_events')->count())->toBe(1);
    $row = DB::table('security_events')->first();
    assert($row !== null);

    /** @var object{id: string, occurred_at: string, type: string, actor_account_id: ?string, actor_client_id: ?string, subject_person_id: ?string, subject_account_id: ?string, ip: ?string, user_agent: ?string, outcome: string, context: ?string} $row */
    return $row;
}

it('records an event with a UTC timestamp and a ULID', function () {
    Carbon::setTestNow('2026-09-19 12:00:00');

    record()('authentication.failed', SecurityEventOutcome::Failure);

    $row = onlyEvent();
    expect($row->occurred_at)->toBe('2026-09-19 12:00:00')
        ->and($row->id)->toHaveLength(26)
        ->and($row->type)->toBe('authentication.failed')
        ->and($row->outcome)->toBe('failure');
});

it('records the moment in UTC even when the application clock is in another zone', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-19 12:00:00', 'Australia/Brisbane')); // 02:00 UTC

    record()('authentication.failed', SecurityEventOutcome::Failure);

    expect(onlyEvent()->occurred_at)->toBe('2026-09-19 02:00:00');
});

it('leaves actor and subject empty when no identity exists, and never fabricates one', function () {
    record()('authentication.failed', SecurityEventOutcome::Failure, context: ['reason' => 'unknown_account']);

    $row = onlyEvent();
    expect($row->actor_account_id)->toBeNull()
        ->and($row->actor_client_id)->toBeNull()
        ->and($row->subject_person_id)->toBeNull()
        ->and($row->subject_account_id)->toBeNull()
        ->and($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull();
});

it('records the actor, subject, network details and context when they exist', function () {
    $account = AccountId::generate();
    $person = PersonId::generate();

    record()(
        'authentication.succeeded',
        SecurityEventOutcome::Success,
        Actor::user($account, $person),
        $person,
        $account,
        '203.0.113.7',
        "Mozilla/5.0\r\n(X11)",
        ['method' => 'password', 'attempts' => 1],
    );

    $row = onlyEvent();
    expect($row->actor_account_id)->toBe($account->value)
        ->and($row->subject_account_id)->toBe($account->value)
        ->and($row->subject_person_id)->toBe($person->value)
        ->and($row->ip)->toBe('203.0.113.7')
        ->and($row->user_agent)->toBe('Mozilla/5.0(X11)')
        ->and(json_decode((string) $row->context, true, 512, JSON_THROW_ON_ERROR))->toBe(['method' => 'password', 'attempts' => 1]);
});

it('stores no context at all as NULL rather than an empty object', function () {
    record()('authentication.logout', SecurityEventOutcome::Success);

    expect(onlyEvent()->context)->toBeNull();
});

it('bounds a client-supplied user agent', function () {
    record()('authentication.failed', SecurityEventOutcome::Failure, userAgent: str_repeat('a', 2000));

    expect(onlyEvent()->user_agent)->toHaveLength(512);
});

it('refuses to record secrets, and records nothing when it does', function (string $key, string $value) {
    expect(fn () => record()('authentication.failed', SecurityEventOutcome::Failure, context: [$key => $value]))
        ->toThrow(InvalidSecurityEvent::class);

    expect(DB::table('security_events')->count())->toBe(0);
})->with([
    'password' => ['password', 'hunter2'],
    'a session id' => ['detail', 'kJ3hd83Kd92LsmQp0xZ1vB7nC4yT6uR8eW5qA2gH'],
    'a token value' => ['detail', rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')],
]);

it('commits with the state change it describes, and rolls back with it', function () {
    // ADR 0019: recorded in the same transaction, so an event exists iff the change committed.
    try {
        DB::transaction(function () {
            record()('authentication.succeeded', SecurityEventOutcome::Success);
            throw new RuntimeException('the state change failed');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('security_events')->count())->toBe(0);

    DB::transaction(fn () => record()('authentication.succeeded', SecurityEventOutcome::Success));
    expect(DB::table('security_events')->count())->toBe(1);
});

it('fails the operation when the audit write fails, rather than losing the event silently', function () {
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('disk full');
        }
    });

    record()('authentication.failed', SecurityEventOutcome::Failure);
})->throws(RuntimeException::class, 'disk full');

it('exposes no way to change or remove an event', function () {
    $writerMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(SecurityEventWriter::class))->getMethods());
    $useCaseMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(RecordSecurityEvent::class))->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($writerMethods)->toBe(['append'])
        ->and($useCaseMethods)->toEqualCanonicalizing(['__construct', '__invoke']);
});

it('has no foreign keys, so audit outlives its subjects and never blocks an operation', function () {
    expect(Schema::getForeignKeys('security_events'))->toBe([]);

    // References may point at things that do not exist (or no longer do).
    record()('authentication.succeeded', SecurityEventOutcome::Success, Actor::user(AccountId::generate(), PersonId::generate()));

    expect(DB::table('security_events')->count())->toBe(1);
});

it('uses portable column types and no database ENUM', function () {
    $types = [];
    foreach (Schema::getColumns('security_events') as $column) {
        assert(is_array($column));
        assert(is_string($column['name']) && is_string($column['type']));
        $types[$column['name']] = $column['type'];
    }

    expect($types['type'])->toContain('64')
        ->and($types['outcome'])->not->toContain('enum')
        ->and($types['id'])->toContain('26');
});
