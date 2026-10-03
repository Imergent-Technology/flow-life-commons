<?php

declare(strict_types=1);

use App\Modules\Discussions\Application\EditOwnMessage;
use App\Modules\Discussions\Application\NotAuthor;
use App\Modules\Discussions\Application\PageDiscussionMessages;
use App\Modules\Discussions\Application\PageDiscussions;
use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionState;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DiscussionsDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;
use Tests\Support\Discussions;
use Tests\Support\Identity;

/*
 * The Discussions demo dataset (G2 WP3): what it makes, that it can be run again, that it can only run where a demo belongs,
 * and that it is written through the use cases, so what it shows is what a Guardian could have written. Runs on MariaDB and
 * PostgreSQL.
 */

/** Runs the demo seeder as though the application were in the named environment (the default is `testing`). */
function seedDiscussionsDemo(?string $environment = null): void
{
    $original = app()->environment();
    if ($environment !== null) {
        app()->instance('env', $environment);
        config()->set('app.env', $environment);
    }

    try {
        (new DiscussionsDemoSeeder)->setContainer(app())->run();
    } finally {
        app()->instance('env', $original);
        config()->set('app.env', $original);
    }
}

/**
 * Two operators, as the seeder finds them (the first two by email that may take part in discussions).
 *
 * @return array{Actor, Actor}
 */
function discussionOperators(): array
{
    return [Discussions::participant('demo.a@example.org', 'Demo A'), Discussions::participant('demo.b@example.org', 'Demo B')];
}

function discussionIdNamed(string $title): string
{
    $id = DB::table('discussions')->where('title', $title)->value('id');
    assert(is_string($id));

    return $id;
}

/** @return list<stdClass> a discussion's messages in sequence order, straight from the table */
function demoMessages(string $title): array
{
    return array_values(DB::table('discussion_messages')->where('discussion_id', discussionIdNamed($title))->orderBy('sequence')->get()->all());
}

function demoDiscussionRow(string $title): stdClass
{
    $row = DB::table('discussions')->where('title', $title)->first();
    assert($row instanceof stdClass);

    return $row;
}

/** @return list<string> every demo title, oldest activity last, as the list shows them */
function demoListOrder(Actor $as): array
{
    $page = app(PageDiscussions::class)($as, null, null, 1, 100);

    return array_map(static fn ($v): string => $v->discussion->title, $page->discussions);
}

it('makes five discussions, written as real operators, with no Account, role, Membership or Person of its own', function () {
    [$a, $b] = discussionOperators();
    $people = DB::table('people')->count();
    seedDiscussionsDemo();

    expect(DB::table('discussions')->count())->toBe(5)
        ->and(DB::table('discussions')->pluck('title')->all())->toEqualCanonicalizing([
            DiscussionsDemoSeeder::OPEN_THREAD, DiscussionsDemoSeeder::RESOLVED_THREAD, DiscussionsDemoSeeder::UNKNOWN_AUTHOR_THREAD,
            DiscussionsDemoSeeder::PAGING_THREAD, DiscussionsDemoSeeder::SINGLE_THREAD,
        ])
        ->and(DB::table('discussion_messages')->count())->toBe(4 + 4 + 4 + DiscussionsDemoSeeder::PAGING_MESSAGES + 1)
        ->and(DB::table('accounts')->count())->toBe(2)
        ->and(DB::table('role_assignments')->count())->toBe(2)
        ->and(DB::table('membership_grants')->count())->toBe(0)
        ->and(DB::table('people')->count())->toBe($people); // no Person made for anyone

    $authors = DB::table('discussion_messages')->where('author_person_id', '!=', DiscussionsDemoSeeder::DEPARTED_PERSON)->distinct()->pluck('author_person_id')->all();
    expect($authors)->toEqualCanonicalizing([$a->personId->value, $b->personId->value]);
});

it('shows each lifecycle case: open, resolved, edited, removed, a different creator, an unknown author, and a thread longer than a page', function () {
    [$a, $b] = discussionOperators();
    seedDiscussionsDemo();

    $state = fn (string $title): string => Api::string(demoDiscussionRow($title)->state);
    expect($state(DiscussionsDemoSeeder::OPEN_THREAD))->toBe('open')
        ->and($state(DiscussionsDemoSeeder::RESOLVED_THREAD))->toBe('resolved')
        ->and($state(DiscussionsDemoSeeder::UNKNOWN_AUTHOR_THREAD))->toBe('open')
        ->and($state(DiscussionsDemoSeeder::PAGING_THREAD))->toBe('open')
        ->and($state(DiscussionsDemoSeeder::SINGLE_THREAD))->toBe('open');

    // Edited: marked, by its own author, and the discussion's activity is the last POST, not the edit.
    $open = demoMessages(DiscussionsDemoSeeder::OPEN_THREAD);
    $edited = array_values(array_filter($open, static fn (stdClass $m): bool => $m->edited_at !== null));
    expect($edited)->toHaveCount(1);
    $theEdit = $edited[0];
    expect($theEdit->sequence)->toBe(3)
        ->and($theEdit->edited_by_person_id)->toBe($theEdit->author_person_id)
        ->and(Api::string($theEdit->body))->toContain('on Thursday');

    // Removed: a tombstone in its place, with no text, in a resolved thread.
    $resolved = demoMessages(DiscussionsDemoSeeder::RESOLVED_THREAD);
    expect($resolved)->toHaveCount(4)
        ->and($resolved[2]->body)->toBeNull()
        ->and($resolved[2]->removed_at)->not->toBeNull()
        ->and(count(array_filter($resolved, static fn (stdClass $m): bool => $m->body === null)))->toBe(1);

    // Two creators: the open thread was opened by one, the resolved one by the other.
    expect($open[0]->author_person_id)->toBe($a->personId->value)
        ->and($resolved[0]->author_person_id)->toBe($b->personId->value)
        ->and(DB::table('discussions')->where('title', DiscussionsDemoSeeder::RESOLVED_THREAD)->value('resolved_by_person_id'))->toBe($a->personId->value);

    // More than one page of messages, to read in sequence order.
    $paging = app(PageDiscussionMessages::class)($a, DiscussionId::fromString(discussionIdNamed(DiscussionsDemoSeeder::PAGING_THREAD)), 2, 25);
    expect($paging->total)->toBe(DiscussionsDemoSeeder::PAGING_MESSAGES)
        ->and($paging->lastPage())->toBe(2)
        ->and(array_map(static fn ($v): int => $v->message->sequence, $paging->messages))->toBe([26, 27]);
});

it('orders the list by last activity, and a resolution that is newer than any message does not move a thread', function () {
    [$a] = discussionOperators();
    seedDiscussionsDemo();

    expect(demoListOrder($a))->toBe([
        DiscussionsDemoSeeder::OPEN_THREAD,           // 2026-09-28
        DiscussionsDemoSeeder::UNKNOWN_AUTHOR_THREAD, // 2026-09-17
        DiscussionsDemoSeeder::RESOLVED_THREAD,       // last message 2026-09-03, resolved 2026-09-20: not activity
        DiscussionsDemoSeeder::PAGING_THREAD,         // 2026-08-20
        DiscussionsDemoSeeder::SINGLE_THREAD,         // 2026-08-01
    ]);

    $resolved = demoDiscussionRow(DiscussionsDemoSeeder::RESOLVED_THREAD);
    expect(Api::string($resolved->resolved_at))->toBeGreaterThan(Api::string($resolved->last_activity_at));
});

it('stamps fixed past times, so the data does not depend on the day it is made, and leaves the clock alone', function () {
    discussionOperators();
    Carbon::setTestNow(Carbon::parse('2031-01-01 00:00:00 UTC'));
    try {
        seedDiscussionsDemo();
        $held = Carbon::hasTestNow() ? Carbon::now()->toDateString() : null;
    } finally {
        Carbon::setTestNow();
    }

    expect(DB::table('discussions')->where('title', DiscussionsDemoSeeder::OPEN_THREAD)->value('last_activity_at'))->toStartWith('2026-09-28 17:05:00')
        ->and(DB::table('discussions')->where('title', DiscussionsDemoSeeder::SINGLE_THREAD)->value('created_at'))->toStartWith('2026-08-01 12:00:00')
        ->and($held)->toBeNull(); // the seeder cleared the pinned clock; it does not leave "now" frozen in the past
});

it('keeps a message word out of titles: a title search finds the thread by its title and not by what is said in it', function () {
    [$a] = discussionOperators();
    seedDiscussionsDemo();
    $search = fn (string $q): array => array_map(static fn ($v): string => $v->discussion->title, app(PageDiscussions::class)($a, null, $q, 1, 25)->discussions);

    expect($search('signage'))->toBe([DiscussionsDemoSeeder::SINGLE_THREAD])
        ->and($search(DiscussionsDemoSeeder::MESSAGE_ONLY_WORD))->toBe([])
        ->and(DB::table('discussion_messages')->where('body', 'like', '%'.DiscussionsDemoSeeder::MESSAGE_ONLY_WORD.'%')->count())->toBe(1) // it IS in a message
        ->and(array_map(static fn ($v): string => $v->discussion->title, app(PageDiscussions::class)($a, DiscussionState::Resolved, null, 1, 25)->discussions))->toBe([DiscussionsDemoSeeder::RESOLVED_THREAD]);
});

it('has one message by an author Identity no longer holds, shown unknown, and nobody can edit it', function () {
    [$a, $b] = discussionOperators();
    seedDiscussionsDemo();

    $id = DiscussionId::fromString(discussionIdNamed(DiscussionsDemoSeeder::UNKNOWN_AUTHOR_THREAD));
    $messages = app(PageDiscussionMessages::class)($a, $id, 1, 25)->messages;
    $unknown = array_values(array_filter($messages, static fn ($v): bool => $v->author->displayName === null));

    expect($unknown)->toHaveCount(1)
        ->and($unknown[0]->message->sequence)->toBe(3)
        ->and($unknown[0]->author->id->value)->toBe(DiscussionsDemoSeeder::DEPARTED_PERSON)
        ->and(DB::table('people')->where('id', DiscussionsDemoSeeder::DEPARTED_PERSON)->exists())->toBeFalse()
        ->and($unknown[0]->message->body)->toBe('Add the tidy-up rota; it is the thing people forget.'); // the words stay

    foreach ([$a, $b] as $operator) {
        expect(fn () => app(EditOwnMessage::class)($operator, $id, $unknown[0]->message->id, 'Mine now'))->toThrow(NotAuthor::class);
    }
});

it('uses the one operator for every author when there is only one, and does not fail', function () {
    $only = Discussions::participant('only.operator@example.org', 'Only Operator');
    seedDiscussionsDemo();

    $authors = DB::table('discussion_messages')->where('author_person_id', '!=', DiscussionsDemoSeeder::DEPARTED_PERSON)->distinct()->pluck('author_person_id')->all();
    expect($authors)->toBe([$only->personId->value])
        ->and(DB::table('discussions')->count())->toBe(5);
});

it('can be run again without adding anything or touching what a Guardian has since done', function () {
    [, $b] = discussionOperators();
    seedDiscussionsDemo();
    $tables = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), ['discussions', 'discussion_messages']);

    // A Guardian changes things in the meantime: a reply, a changed title.
    $open = DiscussionId::fromString(discussionIdNamed(DiscussionsDemoSeeder::OPEN_THREAD));
    app(ReplyToDiscussion::class)($b, $open, 'A reply added by hand');
    DB::table('discussions')->where('title', DiscussionsDemoSeeder::SINGLE_THREAD)->update(['title' => 'Renamed since']);
    $before = $tables();
    $snapshot = DB::table('discussion_messages')->orderBy('id')->get(['id', 'sequence', 'body', 'edited_at', 'removed_at', 'author_person_id'])->all();

    seedDiscussionsDemo();

    // The renamed thread is "not there" by title, so it is made again under its demo title (one more discussion, one more message); everything else is untouched.
    expect($tables())->toBe([$before[0] + 1, $before[1] + 1])
        ->and(DB::table('discussion_messages')->whereIn('id', array_map(static fn (stdClass $m): string => Api::string($m->id), $snapshot))->orderBy('id')->get(['id', 'sequence', 'body', 'edited_at', 'removed_at', 'author_person_id'])->all())->toEqual($snapshot)
        ->and(DB::table('discussion_messages')->where('discussion_id', $open->value)->count())->toBe(5);

    $again = $tables();
    seedDiscussionsDemo();
    expect($tables())->toBe($again); // and now nothing at all
});

it('points rows whose operators were replaced at the current operators, and touches nothing else', function () {
    [, $b] = discussionOperators();
    seedDiscussionsDemo();
    $rows = fn (string $title) => collect(demoMessages($title))->map(fn (stdClass $m): array => [$m->id, $m->body, $m->author_person_id, $m->edited_by_person_id])->all();
    $beforeOpen = $rows(DiscussionsDemoSeeder::OPEN_THREAD);
    $beforeUnknown = $rows(DiscussionsDemoSeeder::UNKNOWN_AUTHOR_THREAD);
    $beforeResolved = DB::table('discussions')->where('title', DiscussionsDemoSeeder::RESOLVED_THREAD)->first();
    $counts = fn (): array => [DB::table('discussions')->count(), DB::table('discussion_messages')->count()];
    $count = $counts();

    // The operators are recreated, as the browser suite recreates its fixtures: their Persons are gone, new ones stand in.
    // (Here: everything the demo wrote points at a Person that no longer exists, except one row that still resolves.)
    $gone = PersonId::generate()->value;
    DB::table('discussion_messages')->where('author_person_id', '!=', DiscussionsDemoSeeder::DEPARTED_PERSON)->update(['author_person_id' => $gone]);
    DB::table('discussion_messages')->whereNotNull('edited_by_person_id')->update(['edited_by_person_id' => $gone]);
    DB::table('discussions')->whereNotNull('resolved_by_person_id')->update(['resolved_by_person_id' => $gone]);
    $stillResolves = DB::table('discussion_messages')->where('discussion_id', discussionIdNamed(DiscussionsDemoSeeder::SINGLE_THREAD))->first();
    DB::table('discussion_messages')->where('id', $stillResolves?->id)->update(['author_person_id' => $b->personId->value]); // a Guardian's own now

    seedDiscussionsDemo();

    expect($counts())->toBe($count)                                                                       // nothing added
        ->and($rows(DiscussionsDemoSeeder::OPEN_THREAD))->toBe($beforeOpen)                              // as first chosen, edit provenance too
        ->and($rows(DiscussionsDemoSeeder::UNKNOWN_AUTHOR_THREAD))->toBe($beforeUnknown)                 // the unknown author is still unknown
        ->and(DB::table('discussions')->where('title', DiscussionsDemoSeeder::RESOLVED_THREAD)->value('resolved_by_person_id'))->toBe($beforeResolved->resolved_by_person_id ?? null)
        ->and(DB::table('discussion_messages')->where('id', $stillResolves?->id)->value('author_person_id'))->toBe($b->personId->value); // a row that resolves is left alone
});

it('leaves a discussion that exists under a demo title alone, and writes nothing in it', function () {
    [$a] = discussionOperators();
    Discussions::start($a, DiscussionsDemoSeeder::OPEN_THREAD, 'Someone already started this one');
    seedDiscussionsDemo();

    expect(DB::table('discussions')->where('title', DiscussionsDemoSeeder::OPEN_THREAD)->count())->toBe(1)
        ->and(DB::table('discussion_messages')->where('discussion_id', discussionIdNamed(DiscussionsDemoSeeder::OPEN_THREAD))->count())->toBe(1)
        ->and(DB::table('discussions')->where('title', DiscussionsDemoSeeder::RESOLVED_THREAD)->count())->toBe(1); // the rest still arrive
});

it('refuses to run outside local and testing, and writes nothing', function () {
    discussionOperators();

    foreach (['production', 'staging'] as $environment) {
        expect(fn () => seedDiscussionsDemo($environment))->toThrow(RuntimeException::class, 'only be seeded in a local or testing environment');
    }
    expect(DB::table('discussions')->count())->toBe(0)
        ->and(DB::table('discussion_messages')->count())->toBe(0);
});

it('needs an operator to write as, and says so, rather than inventing one', function () {
    expect(fn () => seedDiscussionsDemo())->toThrow(RuntimeException::class, 'no active Account that may take part in discussions')
        ->and(DB::table('discussions')->count())->toBe(0);
});

it('ignores an Account that may not take part, however early its email sorts', function () {
    Identity::savedActiveAccount('a.plain@example.org'); // sorts first, holds nothing
    $operator = Discussions::participant('z.operator@example.org', 'Zed Operator');
    seedDiscussionsDemo();

    $authors = DB::table('discussion_messages')->where('author_person_id', '!=', DiscussionsDemoSeeder::DEPARTED_PERSON)->distinct()->pluck('author_person_id')->all();
    expect($authors)->toBe([$operator->personId->value]);
});

it('records no Account login or other identity detail in what it writes', function () {
    discussionOperators();
    seedDiscussionsDemo();

    $logins = DB::table('accounts')->pluck('email')->map(fn ($e) => strtolower(Api::string($e)))->all();
    $text = strtolower(implode(' ', [...Api::strings(DB::table('discussions')->pluck('title')->all()), ...Api::strings(DB::table('discussion_messages')->whereNotNull('body')->pluck('body')->all())]));

    foreach ($logins as $login) {
        expect($text)->not->toContain($login);
    }
});

it('is not part of the default seeder, and nothing in the application refers to it', function () {
    $root = dirname(__DIR__, 4);
    expect((string) file_get_contents("{$root}/database/seeders/DatabaseSeeder.php"))->not->toContain('DiscussionsDemo');

    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'DiscussionsDemoSeeder')) {
                $offenders[] = str_replace("{$root}/", '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(class_exists(DatabaseSeeder::class))->toBeTrue()
        ->and(str_contains((string) file_get_contents("{$root}/database/seeders/DiscussionsDemoSeeder.php"), 'DiscussionsDemoSeeder'))->toBeTrue(); // positive control
});

it('is the only place that writes the unknown author, and the only direct writes are the two it documents', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/database/seeders/DiscussionsDemoSeeder.php');
    preg_match_all("/DB::table\\('(\\w+)'\\)[^;]*->(insert|update|delete|upsert|truncate)\\(/s", $source, $writes, PREG_SET_ORDER);

    // Direct writes name only Discussions' own two tables, and only to update provenance columns: never an insert or a delete.
    expect(array_values(array_unique(array_map(static fn (array $w): string => $w[1], $writes))))->toEqualCanonicalizing(['discussion_messages', 'discussions'])
        ->and(array_values(array_unique(array_map(static fn (array $w): string => $w[2], $writes))))->toBe(['update'])
        ->and($source)->not->toContain("table('people')->update");
});
