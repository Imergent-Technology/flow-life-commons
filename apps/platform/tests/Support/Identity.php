<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

/** Builders and helpers shared by the Identity tests. */
final class Identity
{
    public const string PASSWORD = 'correct horse battery staple';

    /** A fixed, whole-second UTC instant so round trips through DATETIME(0) compare exactly. */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-19 12:00:00', new DateTimeZone('UTC'));
    }

    public static function person(string $name = 'Ada Lovelace'): Person
    {
        return Person::create(PersonId::generate(), $name, self::now());
    }

    /** Persists a Person and returns it. */
    public static function savedPerson(string $name = 'Ada Lovelace'): Person
    {
        $person = self::person($name);
        app(PersonRepository::class)->save($person);

        return $person;
    }

    public static function invitedAccount(Person $person, string $email = 'ada@example.org'): Account
    {
        return Account::invite(AccountId::generate(), $person->id, EmailAddress::fromString($email), self::now());
    }

    /** Persists a Person plus an invited Account for them. */
    public static function savedInvitedAccount(string $email = 'ada@example.org'): Account
    {
        $account = self::invitedAccount(self::savedPerson(), $email);
        app(AccountRepository::class)->save($account);

        return $account;
    }

    /** Persists a Person and an ACTIVE Account that can sign in with the given password. */
    public static function savedActiveAccount(
        string $email = 'ada@example.org',
        string $password = self::PASSWORD,
        string $name = 'Ada Lovelace',
    ): Account {
        $person = self::savedPerson($name);
        $account = self::invitedAccount($person, $email)->activate(Hash::make($password), self::now());
        app(AccountRepository::class)->save($account);

        return $account;
    }

    /** Persists a DISABLED Account (it once had a password). */
    public static function savedDisabledAccount(string $email = 'ada@example.org', string $password = self::PASSWORD): Account
    {
        $account = self::savedActiveAccount($email, $password)->disable(self::now()->modify('+1 minute'));
        app(AccountRepository::class)->save($account);

        return $account;
    }

    public static function invitation(
        Account $account,
        ?InvitationToken $token = null,
        ?AccountId $invitedBy = null,
        string $ttl = 'P7D',
    ): AccountInvitation {
        return AccountInvitation::issue(
            AccountInvitationId::generate(),
            $account->id,
            $token ?? InvitationToken::generate(),
            self::now()->add(new DateInterval($ttl)),
            self::now(),
            $invitedBy,
        );
    }

    public static function savedInvitation(Account $account, ?InvitationToken $token = null): AccountInvitation
    {
        $invitation = self::invitation($account, $token);
        app(AccountInvitationRepository::class)->save($invitation);

        return $invitation;
    }

    /**
     * Recorded security events, oldest first, optionally of one type.
     *
     * @return list<object{type: string, outcome: string, actor_account_id: ?string, subject_account_id: ?string, subject_person_id: ?string, ip: ?string, user_agent: ?string, context: ?string}>
     */
    public static function events(?string $type = null): array
    {
        $query = DB::table('security_events')->orderBy('id');
        if ($type !== null) {
            $query->where('type', $type);
        }

        /** @var list<object{type: string, outcome: string, actor_account_id: ?string, subject_account_id: ?string, subject_person_id: ?string, ip: ?string, user_agent: ?string, context: ?string}> */
        return $query->get()->all();
    }

    /**
     * A recorded event's context, decoded.
     *
     * @param  object{context: ?string}  $event
     * @return array<string, mixed>
     */
    public static function context(object $event): array
    {
        $decoded = json_decode($event->context ?? 'null', true);
        /** @var array<string, mixed> $context */
        $context = is_array($decoded) ? $decoded : [];

        return $context;
    }

    /** One column of the first row of a table, as a string: for reading ids and hashes back out. */
    public static function scalar(string $table, string $column): string
    {
        $value = DB::table($table)->value($column);
        assert(is_string($value));

        return $value;
    }

    /** @return list<string> */
    public static function sessionIds(): array
    {
        $ids = [];
        foreach (DB::table('sessions')->pluck('id')->all() as $id) {
            assert(is_string($id));
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Runs $work expecting the database to refuse it, and returns what it threw.
     *
     * Runs inside a nested transaction (a savepoint under RefreshDatabase's transaction).
     * On PostgreSQL a failed statement aborts the surrounding transaction, so without the
     * savepoint every later query in the same test would fail too.
     */
    /**
     * @param  Closure(): mixed  $work
     */
    public static function violation(Closure $work): Throwable
    {
        try {
            DB::transaction($work);
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected the operation to be refused, but it succeeded.');
    }
}
