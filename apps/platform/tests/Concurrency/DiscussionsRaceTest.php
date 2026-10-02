<?php

declare(strict_types=1);

use App\Modules\Discussions\Application\DiscussionResolved;
use App\Modules\Discussions\Application\DiscussionView;
use App\Modules\Discussions\Application\EditOwnMessage;
use App\Modules\Discussions\Application\MessageRemoved;
use App\Modules\Discussions\Application\RemoveOwnMessage;
use App\Modules\Discussions\Application\ReopenDiscussion;
use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Application\ResolveDiscussion;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Discussions;
use Tests\Support\Race;

/*
 * Discussions' write integrity under REAL concurrency, across two PHP processes and two database connections (method:
 * Tests\Support\Race, ADR 0035 decisions 22 and 23). The first process does its work inside an open transaction and stops
 * before committing; a second process then runs a competing operation, and the test checks that it waited and that the
 * committed state is right. Every scenario asserts BOTH halves (it blocked, and the state is right), since either alone can
 * pass for the wrong reason, and each has a control that waits and then succeeds.
 *
 * The invariant that needs a lock: no reply is committed into a discussion that was already resolved, and sequences are
 * gap-free in commit order. Reply, resolve and reopen take the discussion row's lock; edit and remove take none, and are
 * safe because their writes are conditional on the message not being removed.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

/** @return array<string, string> the worker's identification of the acting Guardian */
function discussionActorArgs(Actor $by): array
{
    return ['actor_account' => $by->accountId->value, 'actor_person' => $by->personId->value];
}

/** @return list<string> the sequences of a discussion's messages, in order */
function sequencesOf(DiscussionView $discussion): array
{
    $sequences = [];
    foreach (DB::table('discussion_messages')->where('discussion_id', $discussion->discussion->id->value)->orderBy('sequence')->pluck('sequence') as $sequence) {
        assert(is_int($sequence) || is_string($sequence));
        $sequences[] = (string) $sequence;
    }

    return $sequences;
}

it('serialises a reply behind a resolution: the reply waits, then is refused, and nothing is committed into the resolved discussion', function () {
    $by = Discussions::participant();
    $started = Discussions::start($by);
    $id = $started->discussion->id;

    // The resolution holds the discussion's lock and has not committed when the reply arrives.
    $race = Race::against(
        function (Closure $pause) use ($by, $id) {
            DB::transaction(function () use ($by, $id, $pause) {
                app(ResolveDiscussion::class)($by, $id);
                $pause();
            });
        },
        null, 'discussion_reply', [...discussionActorArgs($by), 'discussion' => $id->value, 'body' => 'Too late'],
    );

    expect($race['blocked'])->toBeTrue('the reply did not wait for the resolution to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(DiscussionResolved::class)
        ->and(sequencesOf($started))->toBe(['1'])
        ->and(DB::table('discussions')->where('id', $id->value)->value('state'))->toBe('resolved')
        ->and(DB::table('discussions')->where('id', $id->value)->value('message_count'))->toBe(1);
});

it('serialises a resolution behind a reply: the resolution waits, and the reply, which came first, stays committed', function () {
    $by = Discussions::participant();
    $started = Discussions::start($by);
    $id = $started->discussion->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $id) {
            DB::transaction(function () use ($by, $id, $pause) {
                app(ReplyToDiscussion::class)($by, $id, 'First in');
                $pause();
            });
        },
        null, 'discussion_resolve', [...discussionActorArgs($by), 'discussion' => $id->value],
    );

    expect($race['blocked'])->toBeTrue('the resolution did not wait for the reply to commit')
        ->and($race['exit'])->toBe(0)
        ->and(sequencesOf($started))->toBe(['1', '2'])
        ->and(DB::table('discussions')->where('id', $id->value)->value('state'))->toBe('resolved')
        ->and(DB::table('discussions')->where('id', $id->value)->value('message_count'))->toBe(2);
});

it('serialises a reply behind a reopening: the reply waits, then succeeds into the reopened discussion', function () {
    $by = Discussions::participant();
    $started = Discussions::start($by);
    $id = $started->discussion->id;
    app(ResolveDiscussion::class)($by, $id);

    $race = Race::against(
        function (Closure $pause) use ($by, $id) {
            DB::transaction(function () use ($by, $id, $pause) {
                app(ReopenDiscussion::class)($by, $id);
                $pause();
            });
        },
        null, 'discussion_reply', [...discussionActorArgs($by), 'discussion' => $id->value, 'body' => 'After the reopening'],
    );

    expect($race['blocked'])->toBeTrue('the reply did not wait for the reopening to commit')
        ->and($race['exit'])->toBe(0)
        ->and(sequencesOf($started))->toBe(['1', '2'])
        ->and(DB::table('discussions')->where('id', $id->value)->value('state'))->toBe('open');
});

it('gives two simultaneous replies different, consecutive sequences: the second waits, then takes the next number', function () {
    $by = Discussions::participant();
    $other = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($by);
    $id = $started->discussion->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $id) {
            DB::transaction(function () use ($by, $id, $pause) {
                app(ReplyToDiscussion::class)($by, $id, 'Dee, holding the lock');
                $pause();
            });
        },
        null, 'discussion_reply', [...discussionActorArgs($other), 'discussion' => $id->value, 'body' => 'Kai, waiting'],
    );

    expect($race['blocked'])->toBeTrue('the second reply did not wait for the first to commit')
        ->and($race['exit'])->toBe(0) // not a unique-key error: it waited, saw the committed count and took the next number
        ->and(sequencesOf($started))->toBe(['1', '2', '3'])
        ->and(DB::table('discussions')->where('id', $id->value)->value('message_count'))->toBe(3)
        ->and(DB::table('discussion_messages')->where('sequence', 2)->value('body'))->toBe('Dee, holding the lock')
        ->and(DB::table('discussion_messages')->where('sequence', 3)->value('body'))->toBe('Kai, waiting');
});

it('never lets an edit resurrect a removed message: the removal commits first, the edit waits, then changes nothing', function () {
    $by = Discussions::participant();
    $started = Discussions::start($by);
    $reply = Discussions::reply($by, $started, 'Words to withdraw');

    // The removal has set the tombstone and not committed when the edit's write arrives.
    $race = Race::against(
        function (Closure $pause) use ($by, $started, $reply) {
            DB::transaction(function () use ($by, $started, $reply, $pause) {
                app(RemoveOwnMessage::class)($by, $started->discussion->id, $reply->message->id);
                $pause();
            });
        },
        null, 'discussion_edit', [...discussionActorArgs($by), 'discussion' => $started->discussion->id->value, 'message' => $reply->message->id->value, 'body' => 'Resurrected'],
    );

    $row = DB::table('discussion_messages')->where('id', $reply->message->id->value)->first();
    assert($row instanceof stdClass);
    expect($race['blocked'])->toBeTrue('the edit did not wait for the removal to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(MessageRemoved::class)
        ->and($row->body)->toBeNull()->and($row->removed_at)->not->toBeNull()->and($row->edited_at)->toBeNull();
});

it('ends a message removed when an edit and its removal race the other way: the edit commits first, the removal follows', function () {
    $by = Discussions::participant();
    $started = Discussions::start($by);
    $reply = Discussions::reply($by, $started, 'Words to withdraw');

    $race = Race::against(
        function (Closure $pause) use ($by, $started, $reply) {
            DB::transaction(function () use ($by, $started, $reply, $pause) {
                app(EditOwnMessage::class)($by, $started->discussion->id, $reply->message->id, 'Edited just before');
                $pause();
            });
        },
        null, 'discussion_remove', [...discussionActorArgs($by), 'discussion' => $started->discussion->id->value, 'message' => $reply->message->id->value],
    );

    $row = DB::table('discussion_messages')->where('id', $reply->message->id->value)->first();
    assert($row instanceof stdClass);
    expect($race['blocked'])->toBeTrue('the removal did not wait for the edit to commit')
        ->and($race['exit'])->toBe(0)
        ->and($row->removed_at)->not->toBeNull()
        ->and($row->body)->toBeNull(); // whichever order, no text survives a removal
    expect($row->id)->toBe($reply->message->id->value);
});
