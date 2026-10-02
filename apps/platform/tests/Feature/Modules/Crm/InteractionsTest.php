<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Crm\Application\EditInteraction;
use App\Modules\Crm\Application\InteractionNotFound;
use App\Modules\Crm\Application\ListInteractions;
use App\Modules\Crm\Application\NewInteraction;
use App\Modules\Crm\Application\RecordInteraction;
use App\Modules\Crm\Application\RemoveInteraction;
use App\Modules\Crm\Application\UnknownPerson;
use App\Modules\Crm\Domain\Interaction;
use App\Modules\Crm\Domain\InteractionId;
use App\Modules\Crm\Domain\InteractionKind;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * Notes and interactions through the real use cases (ADR 0034): the lifecycle, the order, the paging, who wrote what, and who may
 * do any of it. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

/** @return list<string> the bodies of a Person's interactions as the list returns them */
function bodiesOf(PersonId $person, int $page = 1, int $perPage = 25): array
{
    return array_map(
        static fn ($view): string => $view->interaction->body,
        app(ListInteractions::class)(Crm::manager(), $person, $page, $perPage)->interactions,
    );
}

it('records a note about a Person, authored by the caller, defaulting to now', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    $view = Crm::interaction($by, $ada->id, "  Met at the market.\r\nWants the newsletter.  ");

    expect($view->interaction->personId->equals($ada->id))->toBeTrue()
        ->and($view->interaction->kind)->toBe(InteractionKind::Note)
        ->and($view->interaction->body)->toBe("Met at the market.\nWants the newsletter.")
        ->and($view->author?->displayName)->toBe(Crm::MANAGER_NAME)
        ->and($view->author?->id->equals($by->personId))->toBeTrue()
        ->and($view->updatedBy)->toBeNull()
        ->and($view->interaction->occurredAt->format('Y-m-d H:i:s'))->toBe('2026-10-01 12:00:00');

    $row = DB::table('contact_interactions')->first();
    assert($row instanceof stdClass);
    expect($row->author_person_id)->toBe($by->personId->value)
        ->and($row->updated_by_person_id)->toBeNull()
        ->and($row->occurred_at)->toBe('2026-10-01 12:00:00');
});

it('does not create the CRM profile row: a note holds no invariant that needs the Person\'s write lock', function () {
    Crm::interaction(Crm::manager(), Identity::savedPerson('Ada')->id, 'Hello');

    expect(DB::table('contact_profiles')->count())->toBe(0);
});

it('orders by when it happened, newest first, then by when it was recorded, then id, so the order is total', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::interaction($by, $ada->id, 'old', '2026-09-01 10:00:00');
    Carbon::setTestNow('2026-10-01 12:00:05');
    Crm::interaction($by, $ada->id, 'recorded later, happened earlier', '2026-09-15 10:00:00');
    Crm::interaction($by, $ada->id, 'newest', '2026-09-30 10:00:00');
    // Same instant, same second of recording: the id decides, and it decides the same way every time.
    $a = Crm::interaction($by, $ada->id, 'tie a', '2026-09-20 10:00:00');
    $b = Crm::interaction($by, $ada->id, 'tie b', '2026-09-20 10:00:00');
    $tied = [$a->interaction->id->value => 'tie a', $b->interaction->id->value => 'tie b'];
    krsort($tied);

    expect(bodiesOf($ada->id))->toBe(['newest', ...array_values($tied), 'recorded later, happened earlier', 'old'])
        ->and(bodiesOf($ada->id))->toBe(bodiesOf($ada->id));
});

it('pages without gaps or repeats, and reports the total', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    foreach (range(1, 5) as $n) {
        Crm::interaction($by, $ada->id, "n{$n}", "2026-09-0{$n} 10:00:00");
    }

    $list = app(ListInteractions::class);
    $second = $list($by, $ada->id, 2, 2);

    expect(bodiesOf($ada->id, 1, 2))->toBe(['n5', 'n4'])
        ->and(bodiesOf($ada->id, 2, 2))->toBe(['n3', 'n2'])
        ->and(bodiesOf($ada->id, 3, 2))->toBe(['n1'])
        ->and(bodiesOf($ada->id, 4, 2))->toBe([])
        ->and($second->total)->toBe(5)->and($second->lastPage())->toBe(3)->and($second->page)->toBe(2)
        ->and($list($by, $ada->id, 1, 1000)->perPage)->toBe(ListInteractions::MAX_PER_PAGE)
        ->and($list($by, $ada->id, -3, 0)->page)->toBe(1);
});

it('lists only the Person\'s own interactions', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    Crm::interaction($by, $ada->id, 'about ada');
    Crm::interaction($by, $grace->id, 'about grace');

    expect(bodiesOf($ada->id))->toBe(['about ada'])->and(bodiesOf($grace->id))->toBe(['about grace']);
});

it('stores the instant in UTC whatever offset it was given', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    Crm::interaction($by, $ada->id, 'call', '2026-09-30T08:30:00-05:00');

    expect(DB::table('contact_interactions')->value('occurred_at'))->toBe('2026-09-30 13:30:00');
});

it('refuses invalid input and writes nothing', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $record = fn (string $body, ?string $at = null): Closure => fn () => Crm::interaction($by, $ada->id, $body, $at);

    expect($record('   '))->toThrow(InvalidContactInput::class)
        ->and($record(str_repeat('x', Interaction::MAX_BODY_LENGTH + 1)))->toThrow(InvalidContactInput::class)
        ->and($record("bell\x07"))->toThrow(InvalidContactInput::class)
        ->and($record('tomorrow', '2026-10-01 12:06:00'))->toThrow(InvalidContactInput::class) // beyond the clock-skew allowance
        ->and(DB::table('contact_interactions')->count())->toBe(0);

    Crm::interaction($by, $ada->id, str_repeat('é', Interaction::MAX_BODY_LENGTH), '2026-10-01 12:04:00'); // exactly at the limit, with a fast clock
    Crm::interaction($by, $ada->id, "Tabs\tand\nnewlines are fine");
    expect(DB::table('contact_interactions')->count())->toBe(2);
});

it('names the field that broke the rule', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $field = static function (Closure $attempt): ?string {
        try {
            $attempt();
        } catch (InvalidContactInput $e) {
            return $e->field;
        }

        return null;
    };

    expect($field(fn () => Crm::interaction($by, $ada->id, 'x', '2027-01-01 00:00:00')))->toBe('occurred_at')
        ->and($field(fn () => Crm::interaction($by, $ada->id, '')))->toBe('body');
});

it('corrects a note in place, recording who changed it and when, and keeping the original author', function () {
    $author = Crm::manager();
    $editorAccount = Identity::savedActiveAccount('editor@example.org', name: 'Edith Editor');
    Access::grant($editorAccount, Role::Guardian);
    $editor = Access::actorFor($editorAccount);
    $ada = Identity::savedPerson('Ada');
    $note = Crm::interaction($author, $ada->id, 'Spoke on the phone', '2026-09-30 09:00:00');
    Carbon::setTestNow('2026-10-02 08:00:00');

    $changed = app(EditInteraction::class)($editor, $ada->id, $note->interaction->id, ['body' => 'Spoke by phone; follow up', 'kind' => InteractionKind::Call]);

    expect($changed->interaction->body)->toBe('Spoke by phone; follow up')
        ->and($changed->interaction->kind)->toBe(InteractionKind::Call)
        ->and($changed->interaction->occurredAt->format('Y-m-d H:i:s'))->toBe('2026-09-30 09:00:00') // not sent: left alone
        ->and($changed->author?->displayName)->toBe(Crm::MANAGER_NAME)
        ->and($changed->updatedBy?->displayName)->toBe('Edith Editor')
        ->and($changed->interaction->createdAt->format('Y-m-d H:i:s'))->toBe('2026-10-01 12:00:00')
        ->and($changed->interaction->updatedAt->format('Y-m-d H:i:s'))->toBe('2026-10-02 08:00:00')
        ->and(DB::table('contact_interactions')->count())->toBe(1);
});

it('re-orders a note whose occurred_at is corrected', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $first = Crm::interaction($by, $ada->id, 'first', '2026-09-01 10:00:00');
    Crm::interaction($by, $ada->id, 'second', '2026-09-02 10:00:00');
    expect(bodiesOf($ada->id))->toBe(['second', 'first']);

    app(EditInteraction::class)($by, $ada->id, $first->interaction->id, ['occurred_at' => new DateTimeImmutable('2026-09-03 10:00:00')]);

    expect(bodiesOf($ada->id))->toBe(['first', 'second']);
});

it('refuses an invalid correction and leaves the note as it was', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $note = Crm::interaction($by, $ada->id, 'keep me');
    $edit = app(EditInteraction::class);

    expect(fn () => $edit($by, $ada->id, $note->interaction->id, ['body' => '  ']))->toThrow(InvalidContactInput::class)
        ->and(fn () => $edit($by, $ada->id, $note->interaction->id, ['occurred_at' => new DateTimeImmutable('2027-01-01')]))->toThrow(InvalidContactInput::class)
        ->and(DB::table('contact_interactions')->value('body'))->toBe('keep me')
        ->and(DB::table('contact_interactions')->value('updated_by_person_id'))->toBeNull();
});

it('reports a note that is not that Person\'s, or does not exist, as not found, for edit and remove alike', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    $note = Crm::interaction($by, $ada->id, 'ada\'s');
    $edit = app(EditInteraction::class);
    $remove = app(RemoveInteraction::class);

    expect(fn () => $edit($by, $grace->id, $note->interaction->id, ['body' => 'hijack']))->toThrow(InteractionNotFound::class)
        ->and(fn () => $remove($by, $grace->id, $note->interaction->id))->toThrow(InteractionNotFound::class)
        ->and(fn () => $edit($by, $ada->id, InteractionId::generate(), ['body' => 'x']))->toThrow(InteractionNotFound::class)
        ->and(fn () => $remove($by, $ada->id, InteractionId::generate()))->toThrow(InteractionNotFound::class)
        ->and(DB::table('contact_interactions')->value('body'))->toBe('ada\'s');
});

it('removes a note outright: no soft delete, nothing left behind, and nothing in the security audit trail', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $note = Crm::interaction($by, $ada->id, 'remove me');
    $keep = Crm::interaction($by, $ada->id, 'keep me');
    $events = DB::table('security_events')->count();

    app(RemoveInteraction::class)($by, $ada->id, $note->interaction->id);

    expect(bodiesOf($ada->id))->toBe(['keep me'])
        ->and(DB::table('contact_interactions')->pluck('id')->all())->toBe([$keep->interaction->id->value])
        ->and(fn () => app(RemoveInteraction::class)($by, $ada->id, $note->interaction->id))->toThrow(InteractionNotFound::class) // a second removal
        ->and(DB::table('security_events')->count())->toBe($events);
});

it('writes nothing to security_events for recording or editing either: CRM business content is not a security event', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $events = DB::table('security_events')->count();

    $note = Crm::interaction($by, $ada->id, 'hello');
    app(EditInteraction::class)($by, $ada->id, $note->interaction->id, ['body' => 'hello again']);

    expect(DB::table('security_events')->count())->toBe($events);
});

it('reports an unknown Person before anything else, and creates nothing', function () {
    $by = Crm::manager();
    $nobody = PersonId::generate();

    expect(fn () => app(RecordInteraction::class)($by, $nobody, new NewInteraction(InteractionKind::Note, 'x')))->toThrow(UnknownPerson::class)
        ->and(fn () => app(EditInteraction::class)($by, $nobody, InteractionId::generate(), ['body' => 'x']))->toThrow(UnknownPerson::class)
        ->and(fn () => app(RemoveInteraction::class)($by, $nobody, InteractionId::generate()))->toThrow(UnknownPerson::class)
        ->and(fn () => app(ListInteractions::class)($by, $nobody, 1, 25))->toThrow(UnknownPerson::class)
        ->and(DB::table('contact_interactions')->count())->toBe(0);
});

it('refuses every operation to an Actor holding neither capability, before touching anything', function () {
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org')); // signed in, no role
    $ada = Identity::savedPerson('Ada');
    $note = Crm::interaction(Crm::manager(), $ada->id, 'for guardians');

    expect(fn () => app(ListInteractions::class)($stranger, $ada->id, 1, 25))->toThrow(AccessDenied::class)
        ->and(fn () => app(RecordInteraction::class)($stranger, $ada->id, new NewInteraction(InteractionKind::Note, 'x')))->toThrow(AccessDenied::class)
        ->and(fn () => app(EditInteraction::class)($stranger, $ada->id, $note->interaction->id, ['body' => 'x']))->toThrow(AccessDenied::class)
        ->and(fn () => app(RemoveInteraction::class)($stranger, $ada->id, $note->interaction->id))->toThrow(AccessDenied::class)
        ->and(DB::table('contact_interactions')->count())->toBe(1)
        ->and(DB::table('contact_interactions')->value('body'))->toBe('for guardians');
});

it('shows the author\'s current name, so a rename is reflected on notes already written', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::interaction($by, $ada->id, 'hello');
    DB::table('people')->where('id', $by->personId->value)->update(['display_name' => 'Zz Renamed']);

    $page = app(ListInteractions::class)($by, $ada->id, 1, 25);

    expect($page->interactions[0]->author?->displayName)->toBe('Zz Renamed');
});
