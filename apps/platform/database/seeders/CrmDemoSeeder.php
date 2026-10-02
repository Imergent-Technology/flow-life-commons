<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\EditInteraction;
use App\Modules\Crm\Application\ListTags;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\NewInteraction;
use App\Modules\Crm\Application\RecordInteraction;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\InteractionKind;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEVELOPMENT AND TESTING ONLY, and opt-in: a small, believable set of People, tags and notes so a Guardian can review the
 * CRM (ADR 0034) without typing a dozen records in first, and so the browser suite has something real to read.
 *
 *     ./flow artisan db:seed --class=CrmDemoSeeder
 *
 * It is not part of DatabaseSeeder, refuses to run outside the local and testing environments, and is referenced by nothing
 * in the application. It writes through the CRM's own use cases, as real operators, so everything it makes obeys the rules a
 * Guardian's own entries do (one primary per kind, advice rather than merging, authorship from the Actor); it writes no table
 * directly and creates no Account, role or Membership. The operators are the first (by email) one or two ACTIVE Accounts that
 * may manage People: it needs an administrator or Guardian to exist (`identity:create-administrator`, or the browser suite's
 * fixtures), and it makes the second author of an edited note out of a second operator when there is one.
 *
 * Repeatable: a Person is recognised by their exact display name and a tag by its name. One that already exists is left alone
 * (with everything a Guardian has since done to it), and a Person's notes are only written when this run creates the Person,
 * so running it again, or after a Guardian renamed a tag, adds nothing it should not. One thing IS repaired: an author or last
 * editor is provenance with no foreign key, so when the operators it was written as have since been replaced (the browser
 * suite recreates its fixture Accounts, and their Persons, on every run) a demo note's author names a Person that no longer
 * exists. The next run points such a note at the current operators, the same way it first chose them, and touches nothing else. The tag names are DEMO LABELS: nothing
 * reads them, and a Guardian may rename or delete any of them.
 */
final class CrmDemoSeeder extends Seeder
{
    /** The Person the advice demo points at: registering anyone with this email as a CRM contact method meets them as a candidate. */
    public const string DUPLICATE_ADVICE_PERSON = 'Hannah Moreau';

    public const string DUPLICATE_ADVICE_EMAIL = 'hannah.moreau@example.org';

    /** The Person with more notes than one page holds (the page is 10), for paging. */
    public const string PAGING_PERSON = 'Marguerite Hale';

    /** The Person whose one note is long, for wrapping at narrow widths. */
    public const string LONG_NOTE_PERSON = 'Daniel Okoye';

    /** @var list<string> */
    public const array TAGS = ['Lead', 'Partner', 'Facilitator', 'Performer', 'Vendor', 'Volunteer Interest', 'Artist'];

    /**
     * name, how we know them, affiliation, [kind, value, label], [tags], [[kind, occurred_at, body, edited body or null]].
     * Dates are fixed and in the past, so a run never depends on the day it is made.
     *
     * @var list<array{name: string, how: ?string, affiliation: ?string, methods: list<array{string, string, ?string}>, tags: list<string>, notes: list<array{string, string, string, ?string}>}>
     */
    private const array PEOPLE = [
        [
            'name' => self::PAGING_PERSON,
            'how' => 'Co-led the winter movement series with us.',
            'affiliation' => 'Hale Studio',
            'methods' => [['email', 'marguerite.hale@example.org', 'studio'], ['phone', '555 010 0141', 'mobile']],
            'tags' => ['Facilitator', 'Partner'],
            'notes' => [
                ['meeting', '2026-01-12 10:00:00', 'Coffee to plan the winter movement series. She suggested four Tuesday evenings.', null],
                ['email', '2026-01-14 09:30:00', 'Sent the draft schedule and the room hire costs.', null],
                ['call', '2026-01-20 16:15:00', 'Confirmed the dates. She will bring two assistants.', 'Confirmed the dates. She will bring two assistants and her own mats.'],
                ['note', '2026-02-03 12:00:00', 'First session went well; twenty-two came.', null],
                ['note', '2026-02-10 12:00:00', 'Second session: twenty-six came. Two asked about volunteering.', null],
                ['email', '2026-02-18 11:20:00', 'Shared the feedback form results.', null],
                ['note', '2026-02-24 12:00:00', 'Third session; the heating failed, so we moved to the small room.', null],
                ['meeting', '2026-03-05 14:00:00', 'Review of the series. She would like to run a spring one.', null],
                ['call', '2026-03-12 10:45:00', 'Agreed a shorter spring series of three weeks.', null],
                ['email', '2026-03-20 08:50:00', 'Spring dates sent for her approval.', null],
                ['note', '2026-04-02 12:00:00', 'Spring series announced.', null],
                ['meeting', '2026-04-15 17:00:00', 'Walked through the room set-up before the first spring session.', null],
            ],
        ],
        [
            'name' => 'Tomasz Vance',
            'how' => 'Runs the stall that supplies our tea and snacks.',
            'affiliation' => 'Vance & Daughter',
            'methods' => [['email', 'orders@vanceanddaughter.example.org', 'orders']],
            'tags' => ['Vendor'],
            'notes' => [
                ['email', '2026-05-06 09:00:00', 'Asked for a quote for forty people, twice monthly.', null],
                ['call', '2026-05-09 13:30:00', 'Agreed the quote. Invoices go to the office.', null],
            ],
        ],
        [
            'name' => 'Priya Raman',
            'how' => 'Performed at the harvest gathering.',
            'affiliation' => null,
            'methods' => [['phone', '555 010 0173', null]],
            'tags' => ['Performer', 'Artist'],
            'notes' => [
                ['note', '2026-09-14 20:30:00', 'Played a twenty-minute set. People asked for her contact afterwards.', null],
            ],
        ],
        [
            'name' => self::LONG_NOTE_PERSON,
            'how' => 'Asked about volunteering after the harvest gathering.',
            'affiliation' => 'Riverside Cycling Club',
            'methods' => [['email', 'daniel.okoye@example.org', null], ['phone', '555 010 0188', null]],
            'tags' => ['Lead', 'Volunteer Interest'],
            'notes' => [
                ['meeting', '2026-09-18 11:00:00', 'Met at the cafe. He described what he could offer: Saturday mornings, a car for deliveries, and some carpentry. He would like to start with something small and see how it feels before committing to a regular slot. We talked about the welcome table, the set-up crew and the Thursday tidy-up, and agreed that he would shadow the set-up crew for one gathering first. He mentioned that his club might lend bikes and trailers for larger events, which we should follow up in the autumn. Nothing is promised either way; the next step is for us to send him the date of the next gathering and the name of the person to ask for on the day.', null],
                ['email', '2026-09-20 08:15:00', 'Sent the date of the next gathering and the set-up crew contact.', null],
                ['call', '2026-09-27 18:10:00', 'He confirmed he will come to set up on the 4th.', null],
            ],
        ],
        [
            'name' => 'Sofia Lindqvist',
            'how' => null,
            'affiliation' => null,
            'methods' => [['email', 'sofia.lindqvist@example.org', null]],
            'tags' => [],
            'notes' => [
                ['note', '2026-08-30 15:00:00', 'Introduced by Marguerite. Has not yet been to a gathering.', null],
            ],
        ],
        [
            'name' => 'Ruth Abernathy',
            'how' => null,
            'affiliation' => null,
            'methods' => [],
            'tags' => [],
            'notes' => [],
        ],
        [
            'name' => 'Kenji Watanabe',
            'how' => 'Friend of the studio; paints the banners.',
            'affiliation' => 'Independent',
            'methods' => [['email', 'kenji@example.org', 'personal'], ['email', 'kenji.watanabe@studio.example.org', 'work']],
            'tags' => ['Artist'],
            'notes' => [
                ['meeting', '2026-07-21 13:00:00', 'Agreed to paint the banner for the autumn gathering.', null],
                ['note', '2026-09-01 12:00:00', 'Banner delivered, and lovely.', null],
            ],
        ],
        [
            'name' => self::DUPLICATE_ADVICE_PERSON,
            'how' => 'Neighbour of the venue; helped with the licence.',
            'affiliation' => 'Moreau & Co',
            'methods' => [['email', self::DUPLICATE_ADVICE_EMAIL, null], ['phone', '555 010 0199', null]],
            'tags' => ['Partner'],
            'notes' => [
                ['call', '2026-06-04 10:00:00', 'Talked through the entertainment licence renewal.', null],
                ['email', '2026-06-05 09:20:00', 'She sent the forms we needed.', null],
            ],
        ],
    ];

    public function run(): void
    {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The CRM demo data may only be seeded in a local or testing environment.');
        }

        [$first, $second] = $this->operators();
        $tags = $this->tags($first);

        $register = $this->container->make(RegisterContact::class);
        $setTags = $this->container->make(SetPersonTags::class);
        $record = $this->container->make(RecordInteraction::class);
        $edit = $this->container->make(EditInteraction::class);

        foreach (self::PEOPLE as $spec) {
            $existing = DB::table('people')->where('display_name', $spec['name'])->value('id');
            if (is_string($existing)) {
                // Already here (and possibly changed since): left exactly as it is, but for authors that no longer exist.
                $this->repairProvenance($existing, $first, $second);

                continue;
            }

            $methods = array_map(
                static fn (array $m): NewContactMethod => new NewContactMethod(ContactMethodKind::from($m[0]), $m[1], $m[2]),
                $spec['methods'],
            );
            $person = $register($first, $spec['name'], $spec['how'], $spec['affiliation'], $methods, true)->person->id;
            $setTags($first, $person, array_map(static fn (string $name): ContactTagId => $tags[$name], $spec['tags']));

            foreach ($spec['notes'] as $index => [$kind, $at, $body, $corrected]) {
                // The notes alternate between the operators, so the history has more than one author where there is more than one.
                $author = $index % 2 === 0 ? $first : $second;
                $made = $record($author, $person, new NewInteraction(InteractionKind::from($kind), $body, new DateTimeImmutable($at.' UTC')));
                if ($corrected !== null) {
                    $edit($second, $person, $made->interaction->id, ['body' => $corrected]); // last-editor provenance
                }
            }
        }
    }

    /**
     * Points a demo Person's notes whose author or last editor no longer exists at the current operators: the author as the
     * demo first chose it (the operators alternate, oldest note first) and a last editor as the second operator. A note whose
     * author still exists is not touched, and nothing is written when nothing dangles.
     */
    private function repairProvenance(string $personId, Actor $first, Actor $second): void
    {
        $exists = static fn (string $id): bool => DB::table('people')->where('id', $id)->exists();

        $notes = DB::table('contact_interactions')->where('person_id', $personId)->orderBy('occurred_at')->orderBy('created_at')->orderBy('id')->get();
        foreach ($notes as $index => $note) {
            $changes = [];
            assert(is_string($note->author_person_id));
            if (! $exists($note->author_person_id)) {
                $changes['author_person_id'] = ($index % 2 === 0 ? $first : $second)->personId->value;
            }
            if ($note->updated_by_person_id !== null) {
                assert(is_string($note->updated_by_person_id));
                if (! $exists($note->updated_by_person_id)) {
                    $changes['updated_by_person_id'] = $second->personId->value;
                }
            }
            if ($changes !== []) {
                DB::table('contact_interactions')->where('id', $note->id)->update($changes);
            }
        }
    }

    /**
     * The tags the demo uses, created when missing, by name. A tag a Guardian has renamed is simply created again under the
     * demo name: the names are labels, and nothing depends on them.
     *
     * @return array<string, ContactTagId>
     */
    private function tags(Actor $by): array
    {
        $byName = [];
        foreach ($this->container->make(ListTags::class)($by) as $existing) {
            $byName[$existing->tag->name] = $existing->tag->id;
        }
        foreach (self::TAGS as $name) {
            $byName[$name] ??= $this->container->make(CreateTag::class)($by, $name)->tag->id;
        }

        return $byName;
    }

    /**
     * The first one or two active Accounts, by email, that may manage People.
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
                $authorize($actor, Capability::ManagePeople);
            } catch (AccessDenied) {
                continue;
            }
            $operators[] = $actor;
            if (count($operators) === 2) {
                break;
            }
        }

        if ($operators === []) {
            throw new RuntimeException('There is no active Account that may manage People to write the demo data as. Create an administrator first (identity:create-administrator).');
        }

        return [$operators[0], $operators[1] ?? $operators[0]];
    }
}
