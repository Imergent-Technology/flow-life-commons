<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Resources\Application\CategoryView;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\CreateCategory;
use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Application\ManagedCardOutlineView;
use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use stdClass;

/** Builders shared by the Resources tests: everything goes through the real use cases, as an Actor who may manage Resources. */
final class Resources
{
    /**
     * An Actor who may view and manage Resources: a Guardian, since Resources access is an accepted owner decision (ADR 0037).
     * Idempotent within a test: the same address returns the same Person.
     */
    public static function editor(string $email = 'ed.editor@example.org', string $name = 'Ed Editor'): Actor
    {
        $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString($email));
        if ($account === null) {
            $account = Identity::savedActiveAccount($email, name: $name);
            Access::grant($account, Role::Guardian);
        }

        return Access::actorFor($account);
    }

    /** An ACTIVE Account that holds no role at all: no capability, however much of anything else it is (a Member, say). */
    public static function outsider(string $email = 'olive.outsider@example.org', string $name = 'Olive Outsider'): Actor
    {
        $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString($email));
        if ($account === null) {
            $account = Identity::savedActiveAccount($email, name: $name);
        }

        return Access::actorFor($account);
    }

    /**
     * A Guardian signed in through the real two steps.
     *
     * @return array{Console, Account, array{secret: string, codes: list<string>}}
     */
    public static function signedInGuardian(string $email = 'gina.guardian@example.org', string $name = 'Gina Guardian'): array
    {
        $account = Identity::savedActiveAccount($email, name: $name);
        Access::grant($account, Role::Guardian);
        $factor = Mfa::enroll($account);
        $console = new Console;
        $console->loginWithMfa($email, Identity::PASSWORD)->assertOk();

        return [$console, $account, $factor];
    }

    /** @return array<string, mixed> a document of one paragraph per argument */
    public static function doc(string ...$paragraphs): array
    {
        return ['type' => 'doc', 'content' => array_map(
            static fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            array_values($paragraphs),
        )];
    }

    public static function category(Actor $by, string $name = 'Guides'): CategoryView
    {
        return app(CreateCategory::class)($by, $name);
    }

    public static function pack(Actor $by, string $title = 'A pack', ?CategoryId $category = null, ?string $summary = null, bool $series = false): ManagedPackView
    {
        return app(CreatePack::class)($by, $title, $summary, $series, $category);
    }

    /** A Draft Card with one paragraph of text (or an external link), through the real use case. */
    public static function card(
        Actor $by,
        PackId|ManagedPackView $pack,
        string $title = 'A card',
        string $text = 'Some words',
        CardType $type = CardType::Basic,
        ?string $uri = null,
        ?string $summary = null,
    ): ManagedCardView {
        return app(CreateCard::class)($by, self::id($pack), $type, $title, self::doc($text), $uri, $summary);
    }

    public static function publishedCard(Actor $by, PackId|ManagedPackView $pack, string $title = 'A card', string $text = 'Some words'): ManagedCardView
    {
        $card = self::card($by, $pack, $title, $text);

        return app(PublishCard::class)($by, self::id($pack), $card->card->id);
    }

    /**
     * A Published Pack in a Category, targeted at `$audiences`, holding `$cards` Published Cards titled "<title> card 1".
     *
     * @param  list<Audience>  $audiences
     */
    public static function published(Actor $by, array $audiences = [Audience::Guardian], int $cards = 1, string $title = 'A pack', ?CategoryId $category = null): ManagedPackView
    {
        $category ??= self::category($by, 'Category for '.$title)->category->id;
        $pack = self::pack($by, $title, $category);
        for ($i = 1; $i <= $cards; $i++) {
            self::publishedCard($by, $pack, "{$title} card {$i}", "Words of {$title} card {$i}");
        }
        app(SetPackAudiences::class)($by, $pack->pack->id, $audiences);

        return app(PublishPack::class)($by, $pack->pack->id);
    }

    public static function id(PackId|ManagedPackView $pack): PackId
    {
        return $pack instanceof PackId ? $pack : $pack->pack->id;
    }

    /** @return list<stdClass> every recorded security event of a Resources type, oldest first */
    public static function events(): array
    {
        return array_values(DB::table('security_events')->where('type', 'like', 'resource.%')->orderBy('occurred_at')->orderBy('id')->get()->all());
    }

    /** The Nth Card of a Pack as management lists it (Drafts included, no content); fails the test if there is no such Card. */
    public static function outline(ManagedPackView $pack, int $index): ManagedCardOutlineView
    {
        return ($pack->cards ?? [])[$index] ?? throw new LogicException("the Pack has no Card #{$index}");
    }

    /** @return list<ManagedCardOutlineView> */
    public static function outlines(ManagedPackView $pack): array
    {
        return $pack->cards ?? [];
    }

    /** What the database handed back for a number, as a number. */
    public static function int(mixed $value): int
    {
        assert(is_int($value) || is_string($value) || is_float($value), 'a number');

        return (int) $value;
    }

    /** What the database handed back for text, as text. */
    public static function str(mixed $value): string
    {
        assert(is_string($value), 'a string');

        return $value;
    }

    /** One row by id, which must exist. */
    public static function row(string $table, string $id): stdClass
    {
        return DB::table($table)->where('id', $id)->first() ?? throw new LogicException("no {$table} row {$id}");
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     * @return list<string>
     */
    public static function strings(Collection $values): array
    {
        return array_values(array_map(self::str(...), $values->all()));
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     * @return list<int>
     */
    public static function ints(Collection $values): array
    {
        return array_values(array_map(self::int(...), $values->all()));
    }

    /**
     * @param  array<mixed>  $values
     * @return list<Audience>
     */
    public static function audiences(array $values): array
    {
        $audiences = [];
        foreach ($values as $value) {
            assert($value instanceof Audience);
            $audiences[] = $value;
        }

        return $audiences;
    }

    /**
     * Every table in the database, unqualified.
     *
     * @return list<string>
     */
    public static function allTables(): array
    {
        $names = [];
        foreach (Schema::getTables() as $table) {
            assert(is_array($table));
            $names[] = self::str($table['name'] ?? null);
        }

        // On MariaDB `Schema::getTables()` lists every database the connection can see, and the development database holds these tables
        // too once the browser suite has migrated it, so each name can appear once per database: the NAMES are what is asked about.
        return array_values(array_unique($names));
    }

    public static function eventCount(): int
    {
        return DB::table('security_events')->count();
    }

    /** @return list<string> the table names Resources owns */
    public static function tables(): array
    {
        return ['resource_categories', 'resource_packs', 'resource_pack_audiences', 'resource_cards', 'resource_card_audiences'];
    }
}
