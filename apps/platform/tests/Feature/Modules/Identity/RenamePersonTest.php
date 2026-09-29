<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Application\PersonNotFound;
use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Identity\Application\RenamePerson;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

/*
 * RenamePerson: Identity's only way to correct a display name, called by a later, authorized use case. Internal
 * seam: no route, no capability check, no step-up (ADR 0034).
 */

function renamePerson(PersonId $id, string $name, ?Actor $by = null): PersonSummary
{
    return app(RenamePerson::class)($id, $name, $by);
}

function nameOf(PersonId $id): ?string
{
    $value = DB::table('people')->where('id', $id->value)->value('display_name');

    return is_string($value) ? $value : null;
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:30:00');
});

it('renames a Person and says so', function () {
    $person = Identity::savedPerson('Ada Lovelase');

    $result = renamePerson($person->id, '  Ada Lovelace ');

    expect($result)->toBeInstanceOf(PersonSummary::class)
        ->and($result->displayName)->toBe('Ada Lovelace')
        ->and($result->id->equals($person->id))->toBeTrue()
        ->and(nameOf($person->id))->toBe('Ada Lovelace');
});

it('keeps the id and the creation time, and moves the update time', function () {
    $person = Identity::savedPerson('Ada Lovelase');

    renamePerson($person->id, 'Ada Lovelace');

    $stored = app(PersonRepository::class)->find($person->id);
    expect($stored?->id->equals($person->id))->toBeTrue()
        ->and($stored?->createdAt)->toEqual(Identity::now())
        ->and($stored?->updatedAt)->toEqual(new DateTimeImmutable('2026-09-29 09:30:00', new DateTimeZone('UTC')))
        ->and(DB::table('people')->count())->toBe(1);
});

it('gives a stable not-found for a Person that does not exist, and records nothing', function () {
    expect(fn () => renamePerson(PersonId::generate(), 'Nobody'))->toThrow(PersonNotFound::class, 'There is no such person.');

    expect(Identity::events())->toBe([]);
});

it('reuses Person\'s own validation', function (string $name) {
    $person = Identity::savedPerson('Ada Lovelace');

    expect(fn () => renamePerson($person->id, $name))->toThrow(InvalidArgumentException::class);

    expect(nameOf($person->id))->toBe('Ada Lovelace')
        ->and(Identity::events())->toBe([]);
})->with(['empty' => '', 'blank' => "  \t ", 'too long' => str_repeat('x', Person::MAX_DISPLAY_NAME_LENGTH + 1)]);

it('accepts a multibyte name at the limit', function () {
    $person = Identity::savedPerson('Ada');

    renamePerson($person->id, str_repeat('é', Person::MAX_DISPLAY_NAME_LENGTH));

    expect(mb_strlen((string) nameOf($person->id)))->toBe(Person::MAX_DISPLAY_NAME_LENGTH);
});

it('changes nothing and records nothing when the name is already the current one', function () {
    $person = Identity::savedPerson('Ada Lovelace');

    $result = renamePerson($person->id, '  Ada Lovelace  ');

    expect($result->displayName)->toBe('Ada Lovelace')
        ->and(Identity::events())->toBe([])
        ->and(DB::table('people')->where('id', $person->id->value)->value('updated_at'))->toBe('2026-09-19 12:00:00');
});

it('is a change of case when only the case differs', function () {
    $person = Identity::savedPerson('ada lovelace');

    renamePerson($person->id, 'Ada Lovelace');

    expect(nameOf($person->id))->toBe('Ada Lovelace')->and(Identity::events('person.renamed'))->toHaveCount(1);
});

// --- Audit -----------------------------------------------------------------------------------------------------

it('records person.renamed with the actor, the subject and no names', function () {
    $operator = Access::admin('operator@example.org', 'Operator');
    $person = Identity::savedPerson('Ada Lovelase');

    renamePerson($person->id, 'Ada Lovelace', Access::actorFor($operator));

    $events = Identity::events('person.renamed');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($operator->id->value)
        ->and($events[0]->subject_person_id)->toBe($person->id->value)
        ->and($events[0]->subject_account_id)->toBeNull()
        ->and(Identity::context($events[0]))->toBe(['changed' => 'display_name']);

    // Append-only and permanent: neither the old nor the new name may be copied into it.
    $stored = json_encode(DB::table('security_events')->where('type', 'person.renamed')->first());
    expect($stored)->not->toContain('Lovelase')->and($stored)->not->toContain('Lovelace');
});

it('records a rename made without an actor (the platform or a server operator)', function () {
    $person = Identity::savedPerson('Ada Lovelase');

    renamePerson($person->id, 'Ada Lovelace');

    $event = Identity::events('person.renamed')[0];
    expect($event->actor_account_id)->toBeNull()->and($event->subject_person_id)->toBe($person->id->value);
});

it('rolls the rename back with an audit write that fails', function () {
    $person = Identity::savedPerson('Ada Lovelase');
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => renamePerson($person->id, 'Ada Lovelace'))->toThrow(RuntimeException::class, 'audit store unavailable');
    app()->forgetInstance(SecurityEventWriter::class);

    expect(nameOf($person->id))->toBe('Ada Lovelase');
});

it('participates in an outer transaction and rolls back with it', function () {
    $person = Identity::savedPerson('Ada Lovelase');

    try {
        DB::transaction(function () use ($person) {
            renamePerson($person->id, 'Ada Lovelace');

            throw new RuntimeException('outer failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(nameOf($person->id))->toBe('Ada Lovelase')->and(Identity::events())->toBe([]);
});

// --- What hangs off the Person does not move -----------------------------------------------------------------------

it('leaves the Account linked and untouched', function () {
    $account = Identity::savedActiveAccount('ada@example.org', name: 'Ada Lovelase');
    $before = (array) DB::table('accounts')->where('id', $account->id->value)->first();

    renamePerson($account->personId, 'Ada Lovelace');

    $after = (array) DB::table('accounts')->where('id', $account->id->value)->first();
    $found = app(AccountRepository::class)->findByPersonId($account->personId);
    expect($after)->toBe($before)
        ->and($found?->id->equals($account->id))->toBeTrue();
});

it('leaves role assignments and membership grants attached to the same person_id', function () {
    $account = Identity::savedActiveAccount('ada@example.org', name: 'Ada Lovelase');
    Access::grant($account, Role::Guardian);
    $grant = Membership::savedGrant($account->personId);

    renamePerson($account->personId, 'Ada Lovelace');

    expect(DB::table('role_assignments')->where('person_id', $account->personId->value)->count())->toBe(1)
        ->and(DB::table('membership_grants')->where('person_id', $account->personId->value)->value('id'))->toBe($grant->id->value);
});

it('adds no route: renaming is reachable from application code only', function () {
    $actions = implode(' ', array_map(fn ($route): string => $route->getActionName(), Route::getRoutes()->getRoutes()));

    expect($actions)->not->toContain('RenamePerson')
        ->and($actions)->not->toContain('SearchPeople');
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'people')) {
            expect($route->uri())->toBeIn(['api/v1/admin/people/{person}/invitation', 'api/v1/admin/people/{person}/commons-access']);
        }
    }
});
