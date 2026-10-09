<?php

declare(strict_types=1);

use App\Modules\Access\Application\GrantSourcedRole;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Access\Application\SourcedGrantBoundToAnotherPerson;
use App\Modules\Access\Application\WithdrawSourcedRoles;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Race;
use Tests\Support\SourcedRoleGrantPauses;

/*
 * Sourced grants under two processes. The pause is the audit write, inside the open
 * transaction and after the row lock, so a missing lock lets the second process finish.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

function sourcedActor(): Actor
{
    $account = Identity::savedActiveAccount('race-admin-'.bin2hex(random_bytes(3)).'@example.org', name: 'Race Admin');
    Access::grant($account, Role::PlatformAdministrator);

    return Access::actorFor($account);
}

it('lets one of two grants of the same source commit and treats the other as the same grant', function () {
    $actor = sourcedActor();
    $person = Identity::savedPerson('Same Source');
    $source = strtolower((string) Str::ulid());

    $race = Race::against(function () use ($actor, $person, $source): void {
        app(GrantSourcedRole::class)($actor, $person->id, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship($source));
    }, 'role.granted', 'grant_sourced_role', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'role' => 'guardian-initiate',
        'source' => $source,
    ]);

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'role.granted')->count())->toBe(1);
});

it('does not let a concurrent grant move the source onto another person', function () {
    $actor = sourcedActor();
    $first = Identity::savedPerson('First');
    $second = Identity::savedPerson('Second');
    $source = strtolower((string) Str::ulid());

    $race = Race::against(function () use ($actor, $first, $source): void {
        app(GrantSourcedRole::class)($actor, $first->id, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship($source));
    }, 'role.granted', 'grant_sourced_role', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $second->id->value,
        'role' => 'guardian-initiate',
        'source' => $source,
    ]);

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(SourcedGrantBoundToAnotherPerson::class)
        ->and(DB::table('sourced_role_grants')->where('person_id', $first->id->value)->count())->toBe(1)
        ->and(DB::table('sourced_role_grants')->where('person_id', $second->id->value)->count())->toBe(0);
});

it('lets a withdrawal wait for the grant and then remove only that source', function () {
    $actor = sourcedActor();
    $person = Identity::savedPerson('Withdrawn');
    $source = strtolower((string) Str::ulid());
    $other = strtolower((string) Str::ulid());
    app(GrantSourcedRole::class)($actor, $person->id, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship($other));

    $race = Race::against(function () use ($actor, $person, $source): void {
        app(GrantSourcedRole::class)($actor, $person->id, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship($source));
    }, 'role.granted', 'withdraw_sourced_roles', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'source' => $source,
    ]);

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('source_id', $source)->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('source_id', $other)->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'role.revoked')->count())->toBe(1);
});

it('lets two sources of the same role commit without waiting on each other', function () {
    $actor = sourcedActor();
    $person = Identity::savedPerson('Two Sources');
    $first = strtolower((string) Str::ulid());
    $second = strtolower((string) Str::ulid());

    $race = Race::against(function () use ($actor, $person, $first): void {
        app(GrantSourcedRole::class)($actor, $person->id, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship($first));
    }, 'role.granted', 'grant_sourced_role', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'role' => 'guardian-initiate',
        'source' => $second,
    ]);

    expect($race['blocked'])->toBeFalse()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(2);
});

it('does not let a grant of an empty source commit while withdrawal still holds it', function () {
    // Source-level exclusion only. WP2B must still lock the relationship instance around status
    // change and withdrawal; a grant that starts after this withdrawal commits is a new grant.
    $actor = sourcedActor();
    $person = Identity::savedPerson('Absent Then Granted');
    $source = strtolower((string) Str::ulid());

    expect(DB::table('sourced_role_grants')->where('source_id', $source)->count())->toBe(0);

    $race = Race::against(function (Closure $pause) use ($actor, $source): void {
        SourcedRoleGrantPauses::after('lockForSource', $pause);
        app(WithdrawSourcedRoles::class)($actor, RoleGrantSource::relationship($source));
    }, null, 'grant_sourced_role', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'role' => 'guardian-initiate',
        'source' => $source,
    ]);

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('source_id', $source)->count())->toBe(1)
        ->and(DB::table('sourced_role_grants')->where('person_id', $person->id->value)->value('role_key'))->toBe('guardian-initiate')
        ->and(DB::table('security_events')->where('type', 'role.granted')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'role.revoked')->count())->toBe(0);
});
