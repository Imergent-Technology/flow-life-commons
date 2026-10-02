<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Discussions\Application\DiscussionNotFound;
use App\Modules\Discussions\Application\DiscussionResolved;
use App\Modules\Discussions\Application\EditOwnMessage;
use App\Modules\Discussions\Application\GetDiscussion;
use App\Modules\Discussions\Application\MessageNotFound;
use App\Modules\Discussions\Application\MessageRemoved;
use App\Modules\Discussions\Application\NotAuthor;
use App\Modules\Discussions\Application\PageDiscussionMessages;
use App\Modules\Discussions\Application\PageDiscussions;
use App\Modules\Discussions\Application\RemoveOwnMessage;
use App\Modules\Discussions\Application\ReopenDiscussion;
use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Application\ResolveDiscussion;
use App\Modules\Discussions\Application\RetitleOwnDiscussion;
use App\Modules\Discussions\Application\StartDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Discussions\Domain\DiscussionState;
use App\Modules\Discussions\Domain\InvalidDiscussionInput;
use App\Shared\Domain\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Discussions;
use Tests\Support\Identity;

/*
 * Guardian Discussions at the use-case level (ADR 0035): the lifecycle, authorship, edit and removal rules, the meaning of
 * "activity" and the two orderings. HTTP shape, error codes and disclosure are DiscussionsApiTest; capability layers are
 * DiscussionsAccessControlTest; the races are tests/Concurrency/DiscussionsRaceTest.php. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function messageRow(string $id): stdClass
{
    $row = DB::table('discussion_messages')->where('id', $id)->first();
    assert($row instanceof stdClass);

    return $row;
}

/** The refusal a call raises, or null when it raised none. */
function refusalOf(Closure $call): ?InvalidDiscussionInput
{
    try {
        $call();
    } catch (InvalidDiscussionInput $e) {
        return $e;
    }

    return null;
}

function discussionRow(DiscussionId $id): stdClass
{
    $row = DB::table('discussions')->where('id', $id->value)->first();
    assert($row instanceof stdClass);

    return $row;
}

/** An Account that holds no capability at all: what a Member is to Discussions. */
function discussionOutsider(): Actor
{
    return Access::actorFor(Identity::savedActiveAccount('outsider@example.org', name: 'Out Sider'));
}

// --- Starting -------------------------------------------------------------------------------------------------------

it('starts a discussion with its opening message in one step: sequence 1, a count of 1, authored by the caller', function () {
    $dee = Discussions::participant();

    $started = Discussions::start($dee, '  Where do we meet?  ', "First line\r\nSecond line");

    $discussion = discussionRow($started->discussion->id);
    expect($discussion->title)->toBe('Where do we meet?')
        ->and($discussion->state)->toBe('open')
        ->and($discussion->message_count)->toBe(1)
        ->and($discussion->last_activity_at)->toBe('2026-10-01 12:00:00')
        ->and($discussion->resolved_at)->toBeNull()->and($discussion->resolved_by_person_id)->toBeNull()
        ->and($started->creator?->id->value)->toBe($dee->personId->value)
        ->and($started->creator?->displayName)->toBe('Dee Participant');

    $opening = DB::table('discussion_messages')->where('discussion_id', $discussion->id)->get();
    expect($opening)->toHaveCount(1);
    $message = $opening->first();
    assert($message instanceof stdClass);
    expect($message->sequence)->toBe(1)
        ->and($message->author_person_id)->toBe($dee->personId->value)
        ->and($message->body)->toBe("First line\nSecond line") // CRLF is LF
        ->and($message->created_at)->toBe('2026-10-01 12:00:00')
        ->and($message->edited_at)->toBeNull()->and($message->edited_by_person_id)->toBeNull()->and($message->removed_at)->toBeNull();
});

it('refuses a bad title or body and persists nothing, not even the header', function () {
    $dee = Discussions::participant();
    $start = app(StartDiscussion::class);

    foreach ([['', 'text'], ['   ', 'text'], [str_repeat('a', 201), 'text'], ["two\nlines", 'text'], ["tab\there", 'text'], ["bell\x07", 'text'], ["sep\u{2028}arator", 'text'],
        ['Fine', ''], ['Fine', "  \n "], ['Fine', str_repeat('b', 10001)], ['Fine', "nul\x00l"], ['Fine', "bell\x07"]] as [$title, $body]) {
        expect(fn () => $start($dee, $title, $body))->toThrow(InvalidDiscussionInput::class);
    }

    expect(DB::table('discussions')->count())->toBe(0)->and(DB::table('discussion_messages')->count())->toBe(0);
});

it('names the field that is wrong, and never echoes the value', function () {
    $dee = Discussions::participant();

    $body = refusalOf(fn () => app(StartDiscussion::class)($dee, 'Fine', "secret\x07text"));
    $title = refusalOf(fn () => app(StartDiscussion::class)($dee, str_repeat('x', 201), 'text'));

    assert($body !== null && $title !== null);
    expect($body->field)->toBe('body')->and($body->getMessage())->not->toContain('secret')
        ->and($title->field)->toBe('title');
});

it('accepts the limits exactly, and formatting characters as ordinary text', function () {
    $dee = Discussions::participant();

    $started = Discussions::start($dee, str_repeat('t', 200), str_repeat('b', 10000));
    expect(mb_strlen($started->discussion->title))->toBe(200);

    $zwj = "family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} and \u{200C}ZWNJ";
    $reply = Discussions::reply($dee, $started, $zwj);
    expect($reply->message->body)->toBe($zwj);
});

// --- Replying and sequence --------------------------------------------------------------------------------------------

it('gives each reply the next sequence, counts it, and moves the activity time to the reply', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee);

    Carbon::setTestNow('2026-10-01 12:05:00');
    $second = Discussions::reply($kai, $started, 'Second');
    Carbon::setTestNow('2026-10-01 12:09:00');
    $third = Discussions::reply($dee, $started, 'Third');

    expect($second->message->sequence)->toBe(2)->and($third->message->sequence)->toBe(3)
        ->and($second->author->id->value)->toBe($kai->personId->value)
        ->and($third->author->id->value)->toBe($dee->personId->value);

    $discussion = discussionRow($started->discussion->id);
    expect($discussion->message_count)->toBe(3)
        ->and($discussion->last_activity_at)->toBe('2026-10-01 12:09:00')
        ->and($discussion->updated_at)->toBe('2026-10-01 12:09:00')
        ->and($discussion->created_at)->toBe('2026-10-01 12:00:00')
        ->and(DB::table('discussion_messages')->where('discussion_id', $discussion->id)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('numbers each discussion on its own', function () {
    $dee = Discussions::participant();
    $one = Discussions::start($dee, 'One');
    $two = Discussions::start($dee, 'Two');

    Discussions::reply($dee, $one);
    Discussions::reply($dee, $one);
    $firstReplyInTwo = Discussions::reply($dee, $two);

    expect($firstReplyInTwo->message->sequence)->toBe(2);
});

it('refuses a reply to a resolved discussion without writing anything, and accepts it again once reopened', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    app(ResolveDiscussion::class)($dee, $started->discussion->id);

    expect(fn () => Discussions::reply($dee, $started))->toThrow(DiscussionResolved::class);
    expect(DB::table('discussion_messages')->count())->toBe(1)->and(discussionRow($started->discussion->id)->message_count)->toBe(1);

    app(ReopenDiscussion::class)($dee, $started->discussion->id);
    expect(Discussions::reply($dee, $started)->message->sequence)->toBe(2);
});

it('checks the discussion exists and is open BEFORE it judges the text', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    app(ResolveDiscussion::class)($dee, $started->discussion->id);

    expect(fn () => app(ReplyToDiscussion::class)($dee, $started->discussion->id, "bad\x07"))->toThrow(DiscussionResolved::class)
        ->and(fn () => app(ReplyToDiscussion::class)($dee, DiscussionId::generate(), "bad\x07"))->toThrow(DiscussionNotFound::class);
});

it('never lets the author be named by the caller: the author is always the Actor\'s Person', function () {
    // The use cases take no author argument at all; this pins it on the signature, as the HTTP layer pins it on the wire.
    foreach ([StartDiscussion::class, ReplyToDiscussion::class, EditOwnMessage::class, RemoveOwnMessage::class, RetitleOwnDiscussion::class, ResolveDiscussion::class, ReopenDiscussion::class] as $class) {
        $parameters = array_map(fn (ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod($class, '__invoke'))->getParameters());
        expect($parameters)->toContain('actor')->and(implode(',', $parameters))->not->toMatch('/author|editor|person|by/i');
    }
});

// --- What counts as activity ---------------------------------------------------------------------------------------------

it('counts only POSTING as activity: an edit, a removal, a new title, resolving and reopening leave the discussion where it was', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee, 'Original');
    $reply = Discussions::reply($dee, $started, 'A reply to edit');
    $second = Discussions::reply($dee, $started, 'A reply to remove');
    $id = $started->discussion->id;
    $posted = discussionRow($id)->last_activity_at;

    foreach ([
        fn () => app(EditOwnMessage::class)($dee, $id, $reply->message->id, 'Edited'),
        fn () => app(RemoveOwnMessage::class)($dee, $id, $second->message->id),
        fn () => app(RetitleOwnDiscussion::class)($dee, $id, 'Retitled'),
        fn () => app(ResolveDiscussion::class)($dee, $id),
        fn () => app(ReopenDiscussion::class)($dee, $id),
    ] as $change) {
        Carbon::setTestNow(Carbon::now()->addMinutes(7));
        $change();
        expect(discussionRow($id)->last_activity_at)->toBe($posted);
    }

    // Control: posting does move it, so the assertion above is not true of everything.
    Carbon::setTestNow(Carbon::now()->addMinutes(7));
    Discussions::reply($dee, $started);
    expect(discussionRow($id)->last_activity_at)->not->toBe($posted)
        ->and(discussionRow($id)->message_count)->toBe(4);
});

// --- Editing ------------------------------------------------------------------------------------------------------------

it('lets an author edit their own message, marking it edited and changing nothing else about it', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee, 'Topic', 'Original words');
    $openingId = Api::string(DB::table('discussion_messages')->where('discussion_id', $started->discussion->id->value)->value('id'));
    $before = messageRow($openingId);

    Carbon::setTestNow('2026-10-01 13:00:00');
    $edited = app(EditOwnMessage::class)($dee, $started->discussion->id, DiscussionMessageId::fromString($openingId), '  Better words ');

    $after = messageRow($openingId);
    expect($after->body)->toBe('Better words')
        ->and($after->edited_at)->toBe('2026-10-01 13:00:00')
        ->and($after->edited_by_person_id)->toBe($dee->personId->value)
        ->and($after->author_person_id)->toBe($before->author_person_id)
        ->and($after->sequence)->toBe(1)->and($after->created_at)->toBe($before->created_at)
        ->and($after->discussion_id)->toBe($before->discussion_id)->and($after->removed_at)->toBeNull()
        ->and($edited->message->body)->toBe('Better words')->and($edited->editedBy?->displayName)->toBe('Dee Participant');
});

it('refuses anyone but the author, even another participant, and leaves the words alone', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee, 'Topic', 'Dee wrote this');
    $reply = Discussions::reply($dee, $started, 'And this');

    expect(fn () => app(EditOwnMessage::class)($kai, $started->discussion->id, $reply->message->id, 'Kai rewrites it'))->toThrow(NotAuthor::class);

    $row = messageRow($reply->message->id->value);
    expect($row->body)->toBe('And this')->and($row->edited_at)->toBeNull()->and($row->edited_by_person_id)->toBeNull();
});

it('lets even a platform administrator edit only their own words: no capability means changing another Person\'s', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee, 'Topic', 'Dee wrote this');
    $reply = Discussions::reply($dee, $started);
    $admin = Access::actorFor(Access::admin('root@example.org'));

    expect(fn () => app(EditOwnMessage::class)($admin, $started->discussion->id, $reply->message->id, 'Rewritten'))->toThrow(NotAuthor::class)
        ->and(fn () => app(RemoveOwnMessage::class)($admin, $started->discussion->id, $reply->message->id))->toThrow(NotAuthor::class);

    expect(messageRow($reply->message->id->value)->body)->toBe('A reply');
});

it('still lets an author edit in a resolved discussion: resolved is not frozen', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee, 'Topic', 'Words');
    app(ResolveDiscussion::class)($dee, $started->discussion->id);
    $opening = Api::string(DB::table('discussion_messages')->where('discussion_id', $started->discussion->id->value)->value('id'));

    app(EditOwnMessage::class)($dee, $started->discussion->id, DiscussionMessageId::fromString($opening), 'Corrected after resolution');

    expect(DB::table('discussion_messages')->where('id', $opening)->value('body'))->toBe('Corrected after resolution')
        ->and(discussionRow($started->discussion->id)->state)->toBe('resolved');
});

it('writes nothing and marks nothing edited when the text does not change', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started, 'Same words');

    Carbon::setTestNow('2026-10-01 14:00:00');
    $result = app(EditOwnMessage::class)($dee, $started->discussion->id, $reply->message->id, "  Same words\r\n");

    expect($result->message->editedAt)->toBeNull()
        ->and(messageRow($reply->message->id->value)->edited_at)->toBeNull();
});

it('refuses to edit a removed message and never gives it text again', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started, 'Withdrawn words');
    app(RemoveOwnMessage::class)($dee, $started->discussion->id, $reply->message->id);

    expect(fn () => app(EditOwnMessage::class)($dee, $started->discussion->id, $reply->message->id, 'Back again'))->toThrow(MessageRemoved::class);

    $row = messageRow($reply->message->id->value);
    expect($row->body)->toBeNull()->and($row->removed_at)->not->toBeNull()->and($row->edited_at)->toBeNull();
});

it('judges an edit in the documented order: capability, existence, authorship, removal, then the text', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee);
    $id = $started->discussion->id;
    $mine = Discussions::reply($dee, $started);
    $removed = Discussions::reply($dee, $started);
    app(RemoveOwnMessage::class)($dee, $id, $removed->message->id);
    $edit = app(EditOwnMessage::class);

    expect(fn () => $edit(discussionOutsider(), $id, DiscussionMessageId::generate(), 'x'))->toThrow(AccessDenied::class) // capability before existence
        ->and(fn () => $edit($kai, $id, DiscussionMessageId::generate(), "bad\x07"))->toThrow(MessageNotFound::class) // existence before text
        ->and(fn () => $edit($kai, $id, $removed->message->id, 'x'))->toThrow(NotAuthor::class) // authorship before removal
        ->and(fn () => $edit($dee, $id, $removed->message->id, "bad\x07"))->toThrow(MessageRemoved::class) // removal before text
        ->and(fn () => $edit($dee, $id, $mine->message->id, "bad\x07"))->toThrow(InvalidDiscussionInput::class);
});

it('treats a message addressed through the wrong discussion as not found', function () {
    $dee = Discussions::participant();
    $one = Discussions::start($dee, 'One');
    $two = Discussions::start($dee, 'Two');
    $reply = Discussions::reply($dee, $one);

    expect(fn () => app(EditOwnMessage::class)($dee, $two->discussion->id, $reply->message->id, 'x'))->toThrow(MessageNotFound::class)
        ->and(fn () => app(RemoveOwnMessage::class)($dee, $two->discussion->id, $reply->message->id))->toThrow(MessageNotFound::class);
    expect(messageRow($reply->message->id->value)->body)->toBe('A reply');
});

// --- Title ----------------------------------------------------------------------------------------------------------------

it('lets only the creator correct the title, in an open or a resolved discussion, without moving it', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee, 'Typo tpic');
    $id = $started->discussion->id;
    $retitle = app(RetitleOwnDiscussion::class);

    expect(fn () => $retitle($kai, $id, 'Kai renames it'))->toThrow(NotAuthor::class);
    expect(discussionRow($id)->title)->toBe('Typo tpic');

    Carbon::setTestNow('2026-10-01 15:00:00');
    expect($retitle($dee, $id, ' Typo topic ')->discussion->title)->toBe('Typo topic');

    app(ResolveDiscussion::class)($kai, $id);
    $retitle($dee, $id, 'Typo topic (resolved)');

    $row = discussionRow($id);
    expect($row->title)->toBe('Typo topic (resolved)')->and($row->last_activity_at)->toBe('2026-10-01 12:00:00')->and($row->state)->toBe('resolved');
});

it('judges a title correction in order: capability, existence, creator, then the title', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $id = Discussions::start($dee)->discussion->id;
    $retitle = app(RetitleOwnDiscussion::class);

    expect(fn () => $retitle(discussionOutsider(), DiscussionId::generate(), 'x'))->toThrow(AccessDenied::class)
        ->and(fn () => $retitle($kai, DiscussionId::generate(), "bad\nline"))->toThrow(DiscussionNotFound::class)
        ->and(fn () => $retitle($kai, $id, "bad\nline"))->toThrow(NotAuthor::class)
        ->and(fn () => $retitle($dee, $id, "bad\nline"))->toThrow(InvalidDiscussionInput::class);
});

it('keeps the creator the creator even after they remove their opening message', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee, 'Topic');
    $openingId = Api::string(DB::table('discussion_messages')->where('discussion_id', $started->discussion->id->value)->value('id'));
    app(RemoveOwnMessage::class)($dee, $started->discussion->id, DiscussionMessageId::fromString($openingId));

    $view = app(GetDiscussion::class)($kai, $started->discussion->id);
    expect($view->creator?->id->value)->toBe($dee->personId->value);

    app(RetitleOwnDiscussion::class)($dee, $started->discussion->id, 'Retitled by its creator');
    expect(fn () => app(RetitleOwnDiscussion::class)($kai, $started->discussion->id, 'Not mine'))->toThrow(NotAuthor::class);
});

// --- Removal -----------------------------------------------------------------------------------------------------------------

it('removes a message by leaving a tombstone: the place, author and times stay and the text is gone from the row', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started, 'Something I should not have said');
    Discussions::reply($dee, $started, 'A later reply');
    $before = messageRow($reply->message->id->value);

    Carbon::setTestNow('2026-10-01 16:00:00');
    $tombstone = app(RemoveOwnMessage::class)($dee, $started->discussion->id, $reply->message->id);

    $row = messageRow($reply->message->id->value);
    expect($row->body)->toBeNull()
        ->and($row->removed_at)->toBe('2026-10-01 16:00:00')
        ->and($row->sequence)->toBe(2)
        ->and($row->author_person_id)->toBe($before->author_person_id)
        ->and($row->created_at)->toBe($before->created_at)
        ->and($row->discussion_id)->toBe($before->discussion_id)
        ->and($tombstone->message->isRemoved())->toBeTrue()->and($tombstone->message->body)->toBeNull();

    // The place is kept: the count includes it and the neighbours keep their numbers.
    expect(discussionRow($started->discussion->id)->message_count)->toBe(3)
        ->and(DB::table('discussion_messages')->where('discussion_id', $started->discussion->id->value)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('keeps the removed text nowhere: not in another column, and not in the security trail', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started, 'UNIQUE-REMOVED-PHRASE-7731');
    $events = DB::table('security_events')->count();

    app(RemoveOwnMessage::class)($dee, $started->discussion->id, $reply->message->id);

    foreach (['discussions', 'discussion_messages', 'security_events'] as $table) {
        expect(strtolower(json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR)))->not->toContain('unique-removed-phrase-7731', $table);
    }
    expect(DB::table('security_events')->count())->toBe($events);
});

it('refuses removal by anyone but the author and leaves the words in place', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started, 'Mine');

    expect(fn () => app(RemoveOwnMessage::class)($kai, $started->discussion->id, $reply->message->id))->toThrow(NotAuthor::class);

    $row = messageRow($reply->message->id->value);
    expect($row->body)->toBe('Mine')->and($row->removed_at)->toBeNull();
});

it('removes harmlessly twice, keeping the first removal time', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started);

    Carbon::setTestNow('2026-10-01 16:00:00');
    app(RemoveOwnMessage::class)($dee, $started->discussion->id, $reply->message->id);
    Carbon::setTestNow('2026-10-01 17:00:00');
    $again = app(RemoveOwnMessage::class)($dee, $started->discussion->id, $reply->message->id);

    expect($again->message->isRemoved())->toBeTrue()
        ->and(messageRow($reply->message->id->value)->removed_at)->toBe('2026-10-01 16:00:00');
});

it('lets an author remove in a resolved discussion: withdrawing is never blocked by the lifecycle', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started);
    app(ResolveDiscussion::class)($dee, $started->discussion->id);

    app(RemoveOwnMessage::class)($dee, $started->discussion->id, $reply->message->id);

    expect(messageRow($reply->message->id->value)->removed_at)->not->toBeNull();
});

it('does not delete anything when a Person\'s authorship can no longer be resolved, and lets nobody claim it', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($dee);
    $ghost = strtolower((string) Str::ulid()); // a Person Identity does not hold
    $id = strtolower((string) Str::ulid());
    DB::table('discussion_messages')->insert([
        'id' => $id, 'discussion_id' => $started->discussion->id->value, 'sequence' => 2, 'author_person_id' => $ghost, 'body' => 'Words that outlive their author',
        'created_at' => '2026-10-01 12:01:00', 'edited_at' => null, 'edited_by_person_id' => null, 'removed_at' => null,
    ]);
    DB::table('discussions')->where('id', $started->discussion->id->value)->update(['message_count' => 2]);

    $page = app(PageDiscussionMessages::class)($kai, $started->discussion->id, 1, 25);
    $view = $page->messages[1];
    expect($view->message->body)->toBe('Words that outlive their author')
        ->and($view->author->id->value)->toBe($ghost)->and($view->author->displayName)->toBeNull();

    foreach ([$dee, $kai, Access::actorFor(Access::admin('root@example.org'))] as $actor) {
        expect(fn () => app(EditOwnMessage::class)($actor, $started->discussion->id, DiscussionMessageId::fromString($id), 'x'))->toThrow(NotAuthor::class)
            ->and(fn () => app(RemoveOwnMessage::class)($actor, $started->discussion->id, DiscussionMessageId::fromString($id)))->toThrow(NotAuthor::class);
    }
    expect(messageRow($id)->body)->toBe('Words that outlive their author');
});

it('shows an author by their CURRENT name: a rename reaches messages already written', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    Discussions::reply($dee, $started);

    DB::table('people')->where('id', $dee->personId->value)->update(['display_name' => 'Dee Renamed']);

    $page = app(PageDiscussionMessages::class)($dee, $started->discussion->id, 1, 25);
    expect($page->messages[0]->author->displayName)->toBe('Dee Renamed')->and($page->messages[1]->author->displayName)->toBe('Dee Renamed')
        ->and(app(GetDiscussion::class)($dee, $started->discussion->id)->creator?->displayName)->toBe('Dee Renamed');
});

// --- Resolving and reopening -------------------------------------------------------------------------------------------------

it('records who resolved a discussion and when, and clears both on reopening', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $id = Discussions::start($dee)->discussion->id;

    Carbon::setTestNow('2026-10-01 18:00:00');
    $resolved = app(ResolveDiscussion::class)($kai, $id); // not the creator: any participant may

    expect($resolved->discussion->state)->toBe(DiscussionState::Resolved)->and($resolved->resolvedBy?->displayName)->toBe('Kai Participant');
    $row = discussionRow($id);
    expect($row->state)->toBe('resolved')->and($row->resolved_at)->toBe('2026-10-01 18:00:00')->and($row->resolved_by_person_id)->toBe($kai->personId->value);

    $reopened = app(ReopenDiscussion::class)($dee, $id);
    $row = discussionRow($id);
    expect($reopened->discussion->state)->toBe(DiscussionState::Open)->and($reopened->resolvedBy)->toBeNull()
        ->and($row->state)->toBe('open')->and($row->resolved_at)->toBeNull()->and($row->resolved_by_person_id)->toBeNull();
});

it('is idempotent both ways, and keeps the original resolver when a second person resolves', function () {
    $dee = Discussions::participant();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $id = Discussions::start($dee)->discussion->id;

    app(ReopenDiscussion::class)($dee, $id); // reopening an open discussion: harmless
    expect(discussionRow($id)->state)->toBe('open');

    Carbon::setTestNow('2026-10-01 18:00:00');
    app(ResolveDiscussion::class)($kai, $id);
    Carbon::setTestNow('2026-10-01 19:00:00');
    $again = app(ResolveDiscussion::class)($dee, $id);

    $row = discussionRow($id);
    expect($again->resolvedBy?->id->value)->toBe($kai->personId->value)
        ->and($row->resolved_by_person_id)->toBe($kai->personId->value)->and($row->resolved_at)->toBe('2026-10-01 18:00:00');
});

it('keeps a resolved discussion readable', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee, 'Settled');
    Discussions::reply($dee, $started, 'The answer');
    app(ResolveDiscussion::class)($dee, $started->discussion->id);

    expect(app(GetDiscussion::class)($dee, $started->discussion->id)->discussion->state)->toBe(DiscussionState::Resolved)
        ->and(app(PageDiscussionMessages::class)($dee, $started->discussion->id, 1, 25)->total)->toBe(2);
});

it('answers not found for a discussion that does not exist, on every use case that names one', function () {
    $dee = Discussions::participant();
    $ghost = DiscussionId::generate();

    foreach ([
        fn () => app(GetDiscussion::class)($dee, $ghost),
        fn () => app(PageDiscussionMessages::class)($dee, $ghost, 1, 25),
        fn () => app(ResolveDiscussion::class)($dee, $ghost),
        fn () => app(ReopenDiscussion::class)($dee, $ghost),
        fn () => app(RetitleOwnDiscussion::class)($dee, $ghost, 'x'),
        fn () => Discussions::reply($dee, $ghost),
    ] as $call) {
        expect($call)->toThrow(DiscussionNotFound::class);
    }
});

it('asks every use case for its capability BEFORE it looks at anything else', function () {
    $outsider = discussionOutsider();
    $id = DiscussionId::generate();
    $message = DiscussionMessageId::generate();

    foreach ([
        fn () => app(PageDiscussions::class)($outsider, null, null, 1, 25),
        fn () => app(GetDiscussion::class)($outsider, $id),
        fn () => app(PageDiscussionMessages::class)($outsider, $id, 1, 25),
        fn () => app(StartDiscussion::class)($outsider, 'Title', 'Body'),
        fn () => app(ReplyToDiscussion::class)($outsider, $id, 'Body'),
        fn () => app(EditOwnMessage::class)($outsider, $id, $message, 'Body'),
        fn () => app(RemoveOwnMessage::class)($outsider, $id, $message),
        fn () => app(RetitleOwnDiscussion::class)($outsider, $id, 'Title'),
        fn () => app(ResolveDiscussion::class)($outsider, $id),
        fn () => app(ReopenDiscussion::class)($outsider, $id),
    ] as $call) {
        expect($call)->toThrow(AccessDenied::class); // never "not found": an outsider learns nothing about what exists
    }
    expect(DB::table('discussions')->count())->toBe(0);
});

// --- Listing, ordering, filtering, paging --------------------------------------------------------------------------------------------

/** Inserts a discussion directly, so its activity time and id are exactly what a test says. */
function plantDiscussion(string $id, string $title, string $state, string $lastActivity, string $author): void
{
    DB::table('discussions')->insert([
        'id' => $id, 'title' => $title, 'state' => $state, 'message_count' => 1, 'last_activity_at' => $lastActivity,
        'resolved_at' => $state === 'resolved' ? $lastActivity : null, 'resolved_by_person_id' => $state === 'resolved' ? $author : null,
        'created_at' => '2026-09-01 00:00:00', 'updated_at' => $lastActivity,
    ]);
    DB::table('discussion_messages')->insert([
        'id' => strtolower((string) Str::ulid()), 'discussion_id' => $id, 'sequence' => 1, 'author_person_id' => $author, 'body' => 'Opening',
        'created_at' => '2026-09-01 00:00:00', 'edited_at' => null, 'edited_by_person_id' => null, 'removed_at' => null,
    ]);
}

it('lists the most recently active first, then by id descending: a total order, not creation order', function () {
    $dee = Discussions::participant();
    $a = '01jaaaaaaaaaaaaaaaaaaaaaa1'; // two with the same activity second: the larger id comes first
    $b = '01jaaaaaaaaaaaaaaaaaaaaaa2';
    $c = '01jaaaaaaaaaaaaaaaaaaaaaa0'; // the oldest id, but the most recently active
    plantDiscussion($a, 'A', 'open', '2026-10-01 10:00:00', $dee->personId->value);
    plantDiscussion($b, 'B', 'open', '2026-10-01 10:00:00', $dee->personId->value);
    plantDiscussion($c, 'C', 'open', '2026-10-01 11:00:00', $dee->personId->value);
    plantDiscussion('01jaaaaaaaaaaaaaaaaaaaaaa3', 'D', 'open', '2026-10-01 09:00:00', $dee->personId->value);

    $titles = array_map(fn ($v) => $v->discussion->title, app(PageDiscussions::class)($dee, null, null, 1, 25)->discussions);

    expect($titles)->toBe(['C', 'B', 'A', 'D']);
});

it('moves a discussion to the top when someone posts in it, and not when someone merely edits it', function () {
    $dee = Discussions::participant();
    Carbon::setTestNow('2026-10-01 12:00:00');
    $old = Discussions::start($dee, 'Old');
    Carbon::setTestNow('2026-10-01 12:10:00');
    $newer = Discussions::start($dee, 'Newer');

    Carbon::setTestNow('2026-10-01 12:20:00');
    $titles = fn () => array_map(fn ($v) => $v->discussion->title, app(PageDiscussions::class)($dee, null, null, 1, 25)->discussions);
    app(RetitleOwnDiscussion::class)($dee, $old->discussion->id, 'Old, retitled');
    app(ResolveDiscussion::class)($dee, $old->discussion->id);
    expect($titles())->toBe(['Newer', 'Old, retitled']);

    app(ReopenDiscussion::class)($dee, $old->discussion->id);
    Discussions::reply($dee, $old);
    expect($titles())->toBe(['Old, retitled', 'Newer']);
    expect($newer->discussion->id->value)->not->toBe($old->discussion->id->value);
});

it('filters by state, matches titles case-insensitively with wildcards taken literally, and never searches message text', function () {
    $dee = Discussions::participant();
    $open = Discussions::start($dee, 'Workshop venue', 'Body mentions the zebra');
    $resolved = Discussions::start($dee, '100% Budget_review', 'Plain');
    app(ResolveDiscussion::class)($dee, $resolved->discussion->id);
    $page = app(PageDiscussions::class);
    $titles = fn (?DiscussionState $state, ?string $q) => array_map(fn ($v) => $v->discussion->title, $page($dee, $state, $q, 1, 25)->discussions);

    expect($titles(DiscussionState::Open, null))->toBe(['Workshop venue'])
        ->and($titles(DiscussionState::Resolved, null))->toBe(['100% Budget_review'])
        ->and($titles(null, null))->toHaveCount(2)
        ->and($titles(null, 'WORKSHOP'))->toBe(['Workshop venue'])
        ->and($titles(null, 'shop ven'))->toBe(['Workshop venue'])
        ->and($titles(null, '100%'))->toBe(['100% Budget_review'])
        ->and($titles(null, 't_r'))->toBe(['100% Budget_review']) // `_` is a literal underscore, not "any character"
        ->and($titles(null, 't%r'))->toBe([])                       // and `%` is a literal percent sign
        ->and($titles(null, 'zebra'))->toBe([])                     // message text is not searched
        ->and($titles(DiscussionState::Open, 'budget'))->toBe([])
        ->and($titles(null, '   '))->toHaveCount(2);                 // a blank search is no search
});

it('pages with a stable total, and bounds the page size', function () {
    $dee = Discussions::participant();
    for ($i = 1; $i <= 5; $i++) {
        plantDiscussion(sprintf('01jbbbbbbbbbbbbbbbbbbbbbb%d', $i), "T{$i}", 'open', sprintf('2026-10-01 10:0%d:00', $i), $dee->personId->value);
    }
    $page = app(PageDiscussions::class);

    $one = $page($dee, null, null, 1, 2);
    $three = $page($dee, null, null, 3, 2);
    expect(array_map(fn ($v) => $v->discussion->title, $one->discussions))->toBe(['T5', 'T4'])
        ->and(array_map(fn ($v) => $v->discussion->title, $three->discussions))->toBe(['T1'])
        ->and($one->total)->toBe(5)->and($one->lastPage())->toBe(3)
        ->and($page($dee, null, null, 9, 2)->discussions)->toBe([])
        ->and($page($dee, null, null, 0, 1000)->perPage)->toBe(100)->and($page($dee, null, null, 0, 1000)->page)->toBe(1)
        ->and($page($dee, null, null, 1, 0)->perPage)->toBe(1);
});

it('lists messages by sequence, oldest first, whatever the clock says, with tombstones in place', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $id = $started->discussion->id->value;
    // Written out of time order: sequence 3 carries the earliest timestamp. Time is not the order.
    foreach ([[3, '2026-10-01 11:00:00'], [2, '2026-10-01 13:00:00']] as [$sequence, $at]) {
        DB::table('discussion_messages')->insert([
            'id' => strtolower((string) Str::ulid()), 'discussion_id' => $id, 'sequence' => $sequence, 'author_person_id' => $dee->personId->value,
            'body' => "Message {$sequence}", 'created_at' => $at, 'edited_at' => null, 'edited_by_person_id' => null, 'removed_at' => null,
        ]);
    }
    DB::table('discussions')->where('id', $id)->update(['message_count' => 3]);
    DB::table('discussion_messages')->where('discussion_id', $id)->where('sequence', 2)->update(['body' => null, 'removed_at' => '2026-10-01 14:00:00']);

    $page = app(PageDiscussionMessages::class);
    $all = $page($dee, $started->discussion->id, 1, 25);
    expect(array_map(fn ($m) => $m->message->sequence, $all->messages))->toBe([1, 2, 3])
        ->and($all->messages[1]->message->isRemoved())->toBeTrue()
        ->and($all->total)->toBe(3);

    $second = $page($dee, $started->discussion->id, 2, 2);
    expect(array_map(fn ($m) => $m->message->sequence, $second->messages))->toBe([3])->and($second->lastPage())->toBe(2);
});
