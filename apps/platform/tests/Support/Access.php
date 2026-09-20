<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentId;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\ActiveAccountQuery;
use App\Modules\Identity\Domain\Account;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for authorization tests. Role grant and revoke use cases do not exist yet (the
 * revoke must wait for the last-administrator invariant), so tests set state up through the
 * persistence port and directly in the table, exactly as a future use case's effect would
 * look.
 */
final class Access
{
    /** Grants a role (or an arbitrary stored key, to model corrupt or obsolete rows). */
    public static function grant(PersonId|Account $to, Role|string $role, ?AccountId $by = null): RoleAssignment
    {
        $person = $to instanceof Account ? $to->personId : $to;
        $assignment = RoleAssignment::grant($person, $role instanceof Role ? $role->value : $role, $by, Identity::now());
        app(RoleAssignmentRepository::class)->add($assignment);

        return $assignment;
    }

    /** Writes a row exactly as given, bypassing the domain's key-shape check: a corrupt or obsolete row. */
    public static function plant(PersonId $person, string $roleKey): void
    {
        DB::table('role_assignments')->insert([
            'id' => RoleAssignmentId::generate()->value,
            'person_id' => $person->value,
            'role_key' => $roleKey,
            'granted_by_account_id' => null,
            'granted_at' => '2026-09-19 12:00:00',
        ]);
    }

    /** What revocation will do: delete the row. The port deliberately has no such method yet. */
    public static function revoke(PersonId|Account $from, Role|string $role): void
    {
        $person = $from instanceof Account ? $from->personId : $from;
        DB::table('role_assignments')->where('person_id', $person->value)->where('role_key', $role instanceof Role ? $role->value : $role)->delete();
    }

    /**
     * Every capability identifier, alphabetically: what the platform administrator holds. Derived
     * from the catalog on purpose, so a test about the administrator never has to be edited when a
     * capability is added (only the "exact catalog" test is meant to change then).
     *
     * @return list<string>
     */
    public static function everyCapabilityId(): array
    {
        $ids = array_map(fn (Capability $c): string => $c->value, Capability::cases());
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** An ACTIVE Account whose Person holds platform_administrator. */
    public static function admin(string $email, string $name = 'Administrator'): Account
    {
        $account = Identity::savedActiveAccount($email, name: $name);
        self::grant($account, Role::PlatformAdministrator);

        return $account;
    }

    /** How many administrators are ACTIVE right now (Identity's rule), not how many rows exist. */
    public static function activeAdministrators(): int
    {
        $holders = app(RoleAssignmentRepository::class)->holdersOf(Role::PlatformAdministrator->value);

        return count(app(ActiveAccountQuery::class)->activePersonIds($holders));
    }

    public static function actorFor(Account $account): Actor
    {
        return Actor::user($account->id, $account->personId);
    }
}
