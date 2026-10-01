<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Role;
use App\Modules\Crm\Application\AddContactMethod;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/** Builders shared by the CRM tests: everything goes through the real use cases, as an Actor who may manage People. */
final class Crm
{
    public const string MANAGER_NAME = 'Zz Manager';

    /** An Actor who may see and manage People: a Guardian, since CRM access is an accepted owner decision (ADR 0034). */
    public static function manager(string $email = 'manager@example.org'): Actor
    {
        // Named to sort last and collide with no fixture: the manager is a Person in the directory too.
        // Idempotent within a test: a second call returns the same manager rather than inviting the address again.
        $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString($email));
        if ($account === null) {
            $account = Identity::savedActiveAccount($email, name: self::MANAGER_NAME);
            Access::grant($account, Role::Guardian);
        }

        return Access::actorFor($account);
    }

    /**
     * A Guardian signed in through the real two steps, named so they collide with no fixture Person.
     *
     * @return array{Console, Account}
     */
    public static function signedInGuardian(string $email = 'gina.guardian@example.org', string $name = 'Gina Guardian'): array
    {
        $account = Identity::savedActiveAccount($email, name: $name);
        Access::grant($account, Role::Guardian);
        Mfa::enroll($account);
        $console = new Console;
        $console->loginWithMfa($email, Identity::PASSWORD)->assertOk();

        return [$console, $account];
    }

    public static function method(Actor $by, PersonId $person, ContactMethodKind $kind, string $value, bool $primary = false, ?string $label = null): ContactMethod
    {
        return app(AddContactMethod::class)($by, $person, new NewContactMethod($kind, $value, $label, $primary));
    }

    public static function email(Actor $by, PersonId $person, string $value, bool $primary = false): ContactMethod
    {
        return self::method($by, $person, ContactMethodKind::Email, $value, $primary);
    }

    public static function phone(Actor $by, PersonId $person, string $value, bool $primary = false): ContactMethod
    {
        return self::method($by, $person, ContactMethodKind::Phone, $value, $primary);
    }

    public static function tag(Actor $by, string $name): ContactTagId
    {
        return app(CreateTag::class)($by, $name)->tag->id;
    }

    /** @param  list<ContactTagId>  $tags */
    public static function tagPerson(Actor $by, PersonId $person, array $tags): void
    {
        app(SetPersonTags::class)($by, $person, $tags);
    }
}
