<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\DeleteRelationship;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Personas for the Resources reading policies (ADR 0038, Part R), built the way production builds them: an Account with a real role (or
 * none), and relationships RECORDED through Relationships' own use cases by an administrator, never a stubbed eligibility and never a
 * `/me` interception. `Membership::savedGrant` supplies Membership.
 */
final class Audiences
{
    /** An administrator who may record, change and delete relationships (it holds every capability). */
    public static function operator(): Actor
    {
        $email = 'relationship.operator@example.org';
        $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString($email)) ?? Access::admin($email, 'Relationship Operator');

        return Access::actorFor($account);
    }

    /**
     * An ACTIVE Account holding `$role` (or none) whose Person has the relationships in `$relations`: `guardian`, `volunteer` or `member`,
     * each optionally with a status after a colon (`volunteer:pending`, `guardian:inactive`; the default is active). Idempotent per address: a second call returns the same Account untouched.
     *
     * @param  list<string>  $relations
     */
    public static function reader(array $relations = ['guardian'], ?Role $role = Role::ConsoleParticipant, ?string $email = null, string $name = 'Rae Reader'): Account
    {
        // One Person per role and set of relationships unless an address is given, so the same arguments are the same Person within a test.
        $kind = $role === null ? 'norole' : $role->value;
        $email ??= "reader.{$kind}.".str_replace(':', '_', implode('.', $relations === [] ? ['none'] : $relations)).'@example.org';
        $existing = app(AccountRepository::class)->findByEmail(EmailAddress::fromString($email));
        if ($existing !== null) {
            return $existing;
        }
        $account = Identity::savedActiveAccount($email, name: $name);
        if ($role !== null) {
            Access::grant($account, $role);
        }
        foreach ($relations as $relation) {
            [$type, $status] = array_pad(explode(':', $relation, 2), 2, 'active');
            if ($type === 'member') {
                Membership::savedGrant($account->personId);

                continue;
            }
            self::relate($account->personId, $type, $status);
        }

        return $account;
    }

    /**
     * Records the relationship through the real use case, as the operator, in `$status`: a state that is not an initial one (a Volunteer
     * cannot be recorded Inactive) is reached by recording it in its first initial state and moving it. `$grant` also provisions Guardian
     * Initiate access (WP2B). `$fields` are the relationship's descriptive fields.
     *
     * @param  array<string, string>  $fields
     */
    public static function relate(PersonId $person, string $type, string $status = 'active', bool $grant = false, array $fields = []): void
    {
        $definition = app(RelationshipCatalog::class)->definition(self::type($type));
        $initial = $definition->isInitial($status) ? $status : $definition->initialStates[0];
        $decides = $definition->defaultRole !== null && $definition->qualifies($initial);
        app(EstablishRelationship::class)(
            self::operator(), self::type($type), $person, true, $initial, $fields, $decides, $decides ? ($grant ? 'grant' : 'defer') : null,
        );
        if ($initial !== $status) {
            self::change($person, $type, $status);
        }
    }

    /** Moves the relationship to `$status` through the real use case. */
    public static function change(PersonId $person, string $type, string $status): void
    {
        $definition = app(RelationshipCatalog::class)->definition(self::type($type));
        $decides = $definition->defaultRole !== null && $definition->qualifies($status);
        [$id, $revision] = self::cursor($person, $type);
        app(ChangeRelationshipStatus::class)(
            self::operator(), self::type($type), $person, $id, $revision, $status, $decides, $decides ? 'defer' : null,
        );
    }

    /** Deletes the relationship through the real use case. */
    public static function remove(PersonId $person, string $type): void
    {
        [$id, $revision] = self::cursor($person, $type);
        app(DeleteRelationship::class)(self::operator(), self::type($type), $person, $id, $revision);
    }

    /** Signs the Account in through the real two steps, with a second factor, and returns the Console. */
    public static function signIn(Account $account): Console
    {
        if (! Mfa::isEnrolled($account)) {
            Mfa::enroll($account);
        }
        $console = new Console;
        $console->loginWithMfa($account->email->value, Identity::PASSWORD)->assertOk();

        return $console;
    }

    private static function type(string $key): RelationshipType
    {
        return app(RelationshipCatalog::class)->type($key) ?? throw new LogicException("no relationship type {$key}");
    }

    /** @return array{RelationshipId, int} */
    private static function cursor(PersonId $person, string $type): array
    {
        $row = DB::table('person_relationships')->where('person_id', $person->value)->where('relationship_type', $type)->first();
        if ($row === null || ! is_string($row->id) || ! is_numeric($row->revision)) {
            throw new LogicException("{$person->value} has no {$type} relationship");
        }

        return [RelationshipId::fromString($row->id), (int) $row->revision];
    }
}
