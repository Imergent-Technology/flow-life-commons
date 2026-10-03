<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Application\EditOwnMessage;
use App\Modules\Discussions\Application\PageDiscussionMessages;
use App\Modules\Discussions\Application\RemoveOwnMessage;
use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Application\ResolveDiscussion;
use App\Modules\Discussions\Application\StartDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEVELOPMENT AND TESTING ONLY, and opt-in: a few believable Guardian discussions so a Guardian can review Discussions
 * (ADR 0035) without writing a thread first, and so the browser suite has something real to read.
 *
 *     ./flow artisan db:seed --class=DiscussionsDemoSeeder
 *
 * It is not part of DatabaseSeeder, refuses to run outside the local and testing environments, and is referenced by nothing
 * in the application. It writes through Discussions' own use cases, as real operators, so everything it makes obeys the rules
 * a Guardian's own entries do: authorship comes from the Actor, a reply needs an open discussion, an edit and a removal need
 * authorship. The operators are the first (by email) one or two ACTIVE Accounts that may take part in discussions: it needs
 * an administrator or Guardian to exist (`identity:create-administrator`, or the browser suite's fixtures), and it makes a
 * second author out of a second operator when there is one. It creates no Account, role, Membership or Person.
 *
 * Each use case stamps its own time with `now()`, so every step is run with the clock held at a fixed past instant: the
 * data (and so the order of the list) never depends on the day it is made, and no step is activity in the future.
 *
 * Repeatable: a discussion is recognised by its exact title. One that already exists is left alone, with everything a
 * Guardian has since done to it, and nothing about it is written again, so running this again adds nothing.
 *
 * Two things are written directly, because the use cases cannot say them, and both are scoped to the demo's own rows:
 *
 *  1. REPAIR. An author, editor or resolver is provenance with no foreign key, so when the operators a thread was written as
 *     have since been replaced (the browser suite recreates its fixture Accounts, and their Persons, on every run) it names a
 *     Person that no longer exists. The next run points such a row at the current operators, as the demo first chose them.
 *     A row whose Person still exists is not touched.
 *  2. THE UNKNOWN AUTHOR. One message is repointed at {@see self::DEPARTED_PERSON}, a Person that never existed, so a
 *     Guardian can see how a message whose author Identity no longer holds is shown (the words stay; nobody can edit them).
 *     Repair recognises that id and leaves it exactly as it is.
 */
final class DiscussionsDemoSeeder extends Seeder
{
    /** A Person that Identity never held: the author of the one demo message whose author is unknown. */
    public const string DEPARTED_PERSON = '00000000000000000000depart';

    /** The newest thread: two authors, one message edited: where a Guardian sees the controls on their own words. */
    public const string OPEN_THREAD = 'Autumn gathering: set-up crew and roles';

    /** Resolved, with a removed message (a tombstone) and a resolution that is newer than any message in it. */
    public const string RESOLVED_THREAD = 'Room hire for the winter series';

    /** Open, with a message whose author is unknown. */
    public const string UNKNOWN_AUTHOR_THREAD = 'Volunteer welcome checklist';

    /** More messages than one page holds (a page is 25), for paging. */
    public const string PAGING_THREAD = 'Harvest gathering debrief';

    /** One message and no replies, the oldest activity. Its word "lanterns" is in the message only, never in a title. */
    public const string SINGLE_THREAD = 'Banner and signage ideas';

    /** The distinctive word that appears only in a message of {@see self::SINGLE_THREAD}: a title search must not find it. */
    public const string MESSAGE_ONLY_WORD = 'lanterns';

    /** The number of messages in {@see self::PAGING_THREAD}. */
    public const int PAGING_MESSAGES = 27;

    /**
     * title => the steps, oldest first. A step is [when (UTC), who ('a' or 'b'), what, ...]; what is one of
     * 'start' (title is the key, text), 'reply' (text), 'edit' (sequence, text), 'remove' (sequence), 'resolve', or
     * 'unknown' (sequence: the message just written becomes the unknown author's).
     * 'a' is the first operator, 'b' the second (the same one when there is only one).
     *
     * @return array<string, list<array<int, mixed>>>
     */
    private static function threads(): array
    {
        $debrief = [
            'Stage and sound were ready on time.',
            'The queue for tea was too long at the start.',
            'Twenty-five came before the doors opened.',
            'The tidy-up took less than an hour with six people.',
            'Signage near the car park was missed by many.',
            'Children enjoyed the drumming circle.',
            'We ran out of mugs; a second box is needed.',
            'The weather held, but a covered area would help.',
        ];
        $paging = [['2026-08-20 18:00:00', 'b', 'start', 'Notes from the harvest gathering, so the next one is easier. Add what you noticed, one point per message.']];
        for ($n = 2; $n <= self::PAGING_MESSAGES; $n++) {
            $paging[] = [sprintf('2026-08-20 18:%02d:00', $n * 2), $n % 2 === 0 ? 'a' : 'b', 'reply', 'Point '.($n - 1).': '.$debrief[($n - 2) % count($debrief)]];
        }

        return [
            self::OPEN_THREAD => [
                ['2026-09-24 09:00:00', 'a', 'start', 'We need to settle who is on the set-up crew for the autumn gathering. Can we agree three people and a lead?'],
                ['2026-09-24 11:30:00', 'b', 'reply', 'I can lead set-up if someone takes the tea table. A volunteer has offered to shadow the crew for one gathering.'],
                ['2026-09-25 08:15:00', 'a', 'reply', 'Good. Then the lead is settled. I will ask for two more hands.'],
                ['2026-09-25 08:40:00', 'a', 'edit', 3, 'Good. Then the lead is settled. I will ask for two more hands on Thursday.'],
                ['2026-09-28 17:05:00', 'b', 'reply', 'Two more confirmed. Doors open at four; the crew arrives at two.'],
            ],
            self::RESOLVED_THREAD => [
                ['2026-09-02 10:00:00', 'b', 'start', 'The hall wants a decision on the winter series bookings by the end of the week.'],
                ['2026-09-02 14:20:00', 'a', 'reply', 'Four Tuesday evenings fits their calendar. I would book the large room for the first and the last.'],
                ['2026-09-03 09:10:00', 'a', 'reply', 'Scrap that: the large room costs twice as much, and the small one is enough.'],
                ['2026-09-03 09:30:00', 'a', 'remove', 3],
                ['2026-09-03 16:40:00', 'b', 'reply', 'Agreed: the small room for all four evenings. I will send the confirmation.'],
                ['2026-09-20 10:00:00', 'a', 'resolve'],
            ],
            self::UNKNOWN_AUTHOR_THREAD => [
                ['2026-09-15 13:00:00', 'a', 'start', 'Drafting a one-page welcome checklist for new volunteers. What belongs on it?'],
                ['2026-09-15 15:45:00', 'b', 'reply', 'Where the first-aid kit lives, who to ask for on the day, and where to park.'],
                ['2026-09-16 10:20:00', 'a', 'reply', 'Add the tidy-up rota; it is the thing people forget.'],
                ['2026-09-16 10:20:00', 'a', 'unknown', 3],
                ['2026-09-17 09:00:00', 'a', 'reply', 'Thanks, all three are added.'],
            ],
            self::PAGING_THREAD => $paging,
            self::SINGLE_THREAD => [
                ['2026-08-01 12:00:00', 'a', 'start', 'Could we hang paper '.self::MESSAGE_ONLY_WORD.' along the entrance instead of printing a banner this year?'],
            ],
        ];
    }

    public function run(): void
    {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The Discussions demo data may only be seeded in a local or testing environment.');
        }

        [$first, $second] = $this->operators();
        $operators = ['a' => $first, 'b' => $second];

        try {
            foreach (self::threads() as $title => $steps) {
                $existing = DB::table('discussions')->where('title', $title)->value('id');
                if (is_string($existing)) {
                    // Already here (and possibly changed since): left exactly as it is, but for people who no longer exist.
                    $this->repairProvenance($existing, $steps, $operators);

                    continue;
                }
                $this->write($title, $steps, $operators);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  list<array<int, mixed>>  $steps
     * @param  array{a: Actor, b: Actor}  $operators
     */
    private function write(string $title, array $steps, array $operators): void
    {
        $start = $this->container->make(StartDiscussion::class);
        $reply = $this->container->make(ReplyToDiscussion::class);
        $edit = $this->container->make(EditOwnMessage::class);
        $remove = $this->container->make(RemoveOwnMessage::class);
        $resolve = $this->container->make(ResolveDiscussion::class);

        $id = null;
        $last = null; // the message most recently written, for 'unknown'
        foreach ($steps as $step) {
            [$at, $who, $what] = $step;
            assert(is_string($at) && is_string($who) && is_string($what));
            Carbon::setTestNow(Carbon::parse($at.' UTC'));
            $actor = $operators[$who === 'b' ? 'b' : 'a'];

            switch ($what) {
                case 'start':
                    $text = $step[3];
                    assert(is_string($text));
                    $id = $start($actor, $title, $text)->discussion->id;
                    $last = $this->messageId($actor, $id, 1);
                    break;
                case 'reply':
                    assert($id instanceof DiscussionId && is_string($step[3]));
                    $last = $reply($actor, $id, $step[3])->message->id;
                    break;
                case 'edit':
                    assert($id instanceof DiscussionId && is_int($step[3]) && is_string($step[4]));
                    $edit($actor, $id, $this->messageId($actor, $id, $step[3]), $step[4]);
                    break;
                case 'remove':
                    assert($id instanceof DiscussionId && is_int($step[3]));
                    $remove($actor, $id, $this->messageId($actor, $id, $step[3]));
                    break;
                case 'resolve':
                    assert($id instanceof DiscussionId);
                    $resolve($actor, $id);
                    break;
                case 'unknown':
                    assert($last instanceof DiscussionMessageId);
                    DB::table('discussion_messages')->where('id', $last->value)->update(['author_person_id' => self::DEPARTED_PERSON]);
                    break;
                default:
                    throw new RuntimeException("Unknown demo step {$what}.");
            }
        }
    }

    /** The id of the message at a sequence, found through the public read use case (a page holds 100). */
    private function messageId(Actor $as, DiscussionId $in, int $sequence): DiscussionMessageId
    {
        $page = $this->container->make(PageDiscussionMessages::class)($as, $in, intdiv($sequence - 1, 100) + 1, 100);
        foreach ($page->messages as $view) {
            if ($view->message->sequence === $sequence) {
                return $view->message->id;
            }
        }

        throw new RuntimeException("The demo has no message {$sequence} to act on.");
    }

    /**
     * Points a demo discussion's messages and resolution whose Person no longer exists at the current operators: an author as
     * the demo first chose it (by sequence), an editor as that message's author, a resolver as the one the demo names. A row
     * whose Person still exists is not touched, the unknown author is never "repaired", and nothing is written when nothing
     * dangles.
     *
     * @param  list<array<int, mixed>>  $steps
     * @param  array{a: Actor, b: Actor}  $operators
     */
    private function repairProvenance(string $discussionId, array $steps, array $operators): void
    {
        $exists = static fn (string $id): bool => DB::table('people')->where('id', $id)->exists();

        // Who wrote each sequence, and who resolved, as the demo first chose it.
        $authors = [];
        $resolver = 'a';
        $sequence = 0;
        foreach ($steps as $step) {
            if ($step[2] === 'start' || $step[2] === 'reply') {
                $authors[++$sequence] = $step[1] === 'b' ? 'b' : 'a';
            }
            if ($step[2] === 'resolve') {
                $resolver = $step[1] === 'b' ? 'b' : 'a';
            }
        }

        foreach (DB::table('discussion_messages')->where('discussion_id', $discussionId)->orderBy('sequence')->get() as $message) {
            assert(is_string($message->author_person_id) && is_int($message->sequence));
            $changes = [];
            $author = $message->author_person_id;
            if ($author !== self::DEPARTED_PERSON && ! $exists($author)) {
                $author = $operators[$authors[$message->sequence] ?? 'a']->personId->value;
                $changes['author_person_id'] = $author;
            }
            if ($message->edited_by_person_id !== null) {
                assert(is_string($message->edited_by_person_id));
                if (! $exists($message->edited_by_person_id)) {
                    $changes['edited_by_person_id'] = $author;
                }
            }
            if ($changes !== []) {
                DB::table('discussion_messages')->where('id', $message->id)->update($changes);
            }
        }

        $resolved = DB::table('discussions')->where('id', $discussionId)->value('resolved_by_person_id');
        if (is_string($resolved) && ! $exists($resolved)) {
            DB::table('discussions')->where('id', $discussionId)->update(['resolved_by_person_id' => $operators[$resolver]->personId->value]);
        }
    }

    /**
     * The first one or two active Accounts, by email, that may take part in discussions.
     *
     * @return array{Actor, Actor}
     */
    private function operators(): array
    {
        $resolve = $this->container->make(ResolveActor::class);
        $authorize = $this->container->make(AuthorizeAction::class);

        $operators = [];
        foreach (DB::table('accounts')->where('status', 'active')->orderBy('email')->pluck('id') as $id) {
            if (! is_string($id)) {
                continue;
            }
            $actor = $resolve(AccountId::fromString($id));
            if ($actor === null) {
                continue;
            }
            try {
                $authorize($actor, Capability::ParticipateInDiscussions);
            } catch (AccessDenied) {
                continue;
            }
            $operators[] = $actor;
            if (count($operators) === 2) {
                break;
            }
        }

        if ($operators === []) {
            throw new RuntimeException('There is no active Account that may take part in discussions to write the demo data as. Create an administrator first (identity:create-administrator).');
        }

        return [$operators[0], $operators[1] ?? $operators[0]];
    }
}
