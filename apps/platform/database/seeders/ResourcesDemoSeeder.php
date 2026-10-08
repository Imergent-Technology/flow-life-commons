<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Identity\Application\ResolveActor;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\CreateCategory;
use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Application\GetManagedPack;
use App\Modules\Resources\Application\IncomingFile;
use App\Modules\Resources\Application\ListCategories;
use App\Modules\Resources\Application\PageManagedPacks;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEVELOPMENT AND TESTING ONLY, and opt-in: a small, believable Flow Life Resource library (ADR 0037) so a Guardian can review
 * Resources, from management to the library, without authoring a Pack first, and so the browser suite has real data to read.
 *
 *     ./flow artisan db:seed --class=ResourcesDemoSeeder
 *
 * It is not part of DatabaseSeeder, refuses to run outside the local and testing environments, and is referenced by nothing in
 * the application. It writes through Resources' own use cases, as a real operator, so everything it makes obeys the rules a
 * Guardian's own authoring does: audiences and narrowing, publication requirements, the document profile, Card Types, and managed
 * files (each file is handed to the same intake a File Card's upload goes through, so the store and the asset row are written
 * by Resources, never here). The operator is the first (by email) ACTIVE Account that may manage Resources; it creates no
 * Account, role, Membership or Person.
 *
 * The dataset (see {@see self::packs()}): four Categories, eight Packs and sixteen Cards, three of them with a file. Each Pack
 * exists to show one thing: a single Card read plainly; several Cards chosen by title; a Series; a Series with a Member-only Card
 * between two Guardian Cards (the Guardian sees A, C and D as 1, 2 and 3 of 3); an External link; an image, a PDF and a CSV; a
 * Pack for Members only (whose Category a Guardian is never shown); a Draft Pack; and a Draft Card in a Published Pack.
 *
 * Each use case stamps its own time with `now()`, so every step is run with the clock held at a fixed past instant, one minute
 * after the one before: the data never depends on the day it is made and no step is activity in the future. The clock is put back
 * as it was.
 *
 * Repeatable: a Category is recognised by its exact name and a Pack by its exact title. A Pack that already exists is left alone,
 * with everything a Guardian has since done to it, and nothing about it is written again, so running this again adds nothing (not a
 * Category, Pack, Card, asset row or stored file). Each Pack is made in one transaction, so a Pack is whole or absent; a file stored
 * for a Pack that then failed is an orphan that `resources:assets:prune` removes.
 *
 * One thing is written directly, because the use cases cannot say it, and it is scoped to the demo's own rows in Resources' own
 * tables: REPAIR. A creator or editor is provenance with no foreign key, so when the operator a row was written as has since been
 * replaced (the browser suite recreates its fixture Accounts, and their Persons, on every run) it names a Person that no longer
 * exists. The next run points such a row at the current operator. A row whose Person still exists is not touched, and only the
 * `created_by_person_id` and `updated_by_person_id` of Categories, Packs and Cards are ever written (a file's uploader is not
 * repaired: nothing outside Resources may name the assets table, and an uploader who no longer exists is simply shown as unknown).
 */
final class ResourcesDemoSeeder extends Seeder
{
    public const string GETTING_STARTED = 'Getting Started';

    public const string RUNNING = 'Running the Sanctuary';

    public const string SAFETY = 'Safety and Wellbeing';

    /** A Category whose only Pack is for Members: a Guardian is never shown it. */
    public const string MEMBER_CIRCLE = 'Member Circle';

    /** One Card, titled as its Pack, read plainly: no Card navigation and no counter. Rich content: headings, lists, a link, a quotation. */
    public const string SINGLE = 'Opening and Closing Checklist';

    /** Several Cards chosen by title, not a Series. A table, a code block, a CSV file and a Draft Card. */
    public const string OPERATIONS = 'Sanctuary Operations';

    /** A Series of three: Previous, Next, "Card n of 3". Ends with a PDF. */
    public const string SERIES = 'Fire Safety Orientation';

    /** A Series of four, one of them for Members only, between Guardian Cards: a Guardian reads three. */
    public const string NARROWED = 'Guardian Onboarding';

    /** The Card of {@see self::NARROWED} that a Guardian cannot see. */
    public const string HIDDEN_CARD = 'Member circle facilitation notes';

    /** Words that are in the hidden Card's title and body and nowhere that a Guardian may read. */
    public const string HIDDEN_WORD = 'facilitation';

    /** One External link Card. */
    public const string LINK = 'Room Booking Calendar';

    /** One File Card: an image. */
    public const string IMAGE = 'Sanctuary Floor Plan';

    /** A Pack for Members only. */
    public const string MEMBERS_ONLY = 'Member Circle Handbook';

    /** A Draft Pack. */
    public const string DRAFT = 'Winter Gathering Plan';

    /** The address of the External link Card: a reserved example domain, never fetched by anything. */
    public const string LINK_ADDRESS = 'https://example.org/flow-life/room-booking';

    /** Where the demo's files are committed. */
    private const string FILES = __DIR__.'/resources-demo';

    /** @return list<string> */
    public static function categories(): array
    {
        return [self::GETTING_STARTED, self::RUNNING, self::SAFETY, self::MEMBER_CIRCLE];
    }

    /**
     * The Packs, in the order they are made (and so in the order they are shown within a Category). A Card is
     * [title, type, content or null, uri or null, file or null, summary or null, narrowed to Members, published].
     *
     * @return list<array{title: string, summary: string, series: bool, category: string, audiences: list<Audience>, published: bool, at: string, cards: list<array{title: string, type: CardType, content: array<string, mixed>|null, uri: string|null, file: string|null, summary: string|null, membersOnly: bool, published: bool}>}>
     */
    public static function packs(): array
    {
        $both = [Audience::Guardian, Audience::Member];

        return [
            [
                'title' => self::NARROWED, 'summary' => 'What a new Guardian needs in their first weeks.', 'series' => true,
                'category' => self::GETTING_STARTED, 'audiences' => $both, 'published' => true, 'at' => '2026-09-08 09:00:00',
                'cards' => [
                    self::basic('Welcome to the team', self::doc(
                        self::h(2, 'You are not on your own'),
                        self::p('Every Guardian looks after the sanctuary alongside others. Nobody opens or closes alone in their first month.'),
                        self::p('This short series walks through your first shift, one step at a time.'),
                    )),
                    self::basic(self::HIDDEN_CARD, self::doc(
                        self::p('Notes for the people who facilitate the Member circle: the '.self::HIDDEN_WORD.' prompts, the order of the evening, and who to ask for help.'),
                    ), membersOnly: true),
                    self::basic('Your first shift', self::doc(
                        self::h(2, 'Before you arrive'),
                        self::ul('Read the opening checklist.', 'Check who else is on the rota.', 'Bring your own water bottle.'),
                        self::h(2, 'When you arrive'),
                        self::p('Say hello to the other Guardian on shift, then walk the rooms together before doors open.'),
                    )),
                    self::basic('Handover and support', self::doc(
                        self::h(2, 'Handing over'),
                        self::p('Write what you noticed in the shift notes, then tell the next Guardian anything they should know before you leave.'),
                        self::p('Questions are always welcome in the Guardian discussions.'),
                    )),
                ],
            ],
            [
                'title' => self::SINGLE, 'summary' => 'The daily routine for opening and closing the sanctuary.', 'series' => false,
                'category' => self::RUNNING, 'audiences' => [Audience::Guardian], 'published' => true, 'at' => '2026-09-08 10:00:00',
                'cards' => [
                    self::basic(self::SINGLE, self::doc(
                        self::h(2, 'Opening'),
                        self::ol('Turn off the alarm at the front panel.', 'Open the shutters and the main hall windows.', 'Put the kettle on and set out the tea table.', 'Check the toilets are stocked.'),
                        self::h(2, 'Closing'),
                        self::ul('Walk every room and switch off the lights.', 'Wash and put away the mugs.', 'Take the bins to the yard.', 'Set the alarm and lock both doors.'),
                        self::h(3, 'Something went wrong?'),
                        self::pLink('Write it down in the ', 'incident log', 'https://example.org/flow-life/incident-log', ' so it can be fixed.'),
                        self::quote('If in doubt, ask before you act. Nobody minds a question.'),
                    )),
                ],
            ],
            [
                'title' => self::OPERATIONS, 'summary' => 'How the building runs from day to day.', 'series' => false,
                'category' => self::RUNNING, 'audiences' => [Audience::Guardian], 'published' => true, 'at' => '2026-09-09 10:00:00',
                'cards' => [
                    self::basic('Keys, alarm and access', self::doc(
                        self::p('Three people hold keys. Please do not copy them.'),
                        self::table(
                            ['Door', 'Held by', 'Notes'],
                            ['Front door', 'Opening Guardian', 'Return it to the key safe after closing.'],
                            ['Yard gate', 'Closing Guardian', 'Padlock; spin the dial after locking.'],
                            ['Store room', 'Any Guardian on shift', 'Keep it closed: the boiler is inside.'],
                        ),
                    )),
                    self::basic('Cleaning and supplies', self::doc(
                        self::h(2, 'After each gathering'),
                        self::ul('Sweep the hall and wipe the tables.', 'Empty the tea urn.', 'Restock the toilet paper from the store.'),
                        self::h(2, 'Colour codes'),
                        self::code("RED   kitchen\nBLUE  main hall\nGREEN toilets"),
                    )),
                    self::file('Shift rota template', 'two-week-shift-rota-template-for-sanctuary-guardians.csv', self::doc(
                        self::p('A blank two-week rota to copy and fill in for your shift. Open it in any spreadsheet.'),
                    ), 'A blank two-week rota to copy for your shift.'),
                    self::basic('Winter heating guide', self::doc(self::p('To be written before the cold weather arrives.')), published: false),
                ],
            ],
            [
                'title' => self::LINK, 'summary' => 'Where rooms are booked.', 'series' => false,
                'category' => self::RUNNING, 'audiences' => [Audience::Guardian], 'published' => true, 'at' => '2026-09-10 10:00:00',
                'cards' => [
                    [
                        'title' => 'Open the room booking calendar', 'type' => CardType::ExternalLink,
                        'content' => self::doc(self::p('All hires and gatherings are booked in the shared calendar. Check it before you promise anyone a room.')),
                        'uri' => self::LINK_ADDRESS, 'file' => null, 'summary' => null, 'membersOnly' => false, 'published' => true,
                    ],
                ],
            ],
            [
                'title' => self::IMAGE, 'summary' => 'Where everything is.', 'series' => false,
                'category' => self::RUNNING, 'audiences' => [Audience::Guardian], 'published' => true, 'at' => '2026-09-10 11:00:00',
                'cards' => [
                    self::file('Floor plan of the sanctuary', 'sanctuary-floor-plan.png', self::doc(
                        self::p('The main hall, the tea room, the quiet room and the store. The orange marks are the doors.'),
                    ), null),
                ],
            ],
            [
                'title' => self::DRAFT, 'summary' => 'Ideas for the winter series, not ready to share.', 'series' => false,
                'category' => self::RUNNING, 'audiences' => [Audience::Guardian], 'published' => false, 'at' => '2026-09-11 10:00:00',
                'cards' => [
                    self::basic('Winter dates', self::doc(self::p('Four Tuesday evenings, to be agreed with the hall.')), published: false),
                ],
            ],
            [
                'title' => self::SERIES, 'summary' => 'What to do if the alarm sounds, step by step.', 'series' => true,
                'category' => self::SAFETY, 'audiences' => [Audience::Guardian], 'published' => true, 'at' => '2026-09-11 11:00:00',
                'cards' => [
                    self::basic('Why fire safety matters', self::doc(
                        self::h(2, 'Everyone goes home safe'),
                        self::p('Most of the people who visit are not familiar with the building. A calm Guardian who knows the plan keeps everyone safe.'),
                    )),
                    self::basic('Know your exits, and where to meet if you have to leave the building', self::doc(
                        self::h(2, 'Three ways out'),
                        self::ol('The front door, past the tea room.', 'The fire door at the back of the main hall.', 'The yard gate, from the store room.'),
                        self::pLink('The full guidance is at ', 'the fire service website', 'https://example.org/fire-safety', '.'),
                    )),
                    self::file('Evacuation plan', 'evacuation-plan.pdf', self::doc(
                        self::p('Print this and keep a copy by the front door. Read it aloud at the start of every gathering.'),
                    ), 'The evacuation steps on one page, to print.'),
                ],
            ],
            [
                'title' => self::MEMBERS_ONLY, 'summary' => 'For Members of the circle.', 'series' => false,
                'category' => self::MEMBER_CIRCLE, 'audiences' => [Audience::Member], 'published' => true, 'at' => '2026-09-12 10:00:00',
                'cards' => [
                    self::basic('Circle etiquette', self::doc(self::p('How we listen to one another in the circle.'))),
                ],
            ],
        ];
    }

    public function run(): void
    {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The Resources demo data may only be seeded in a local or testing environment.');
        }

        $operator = $this->operator();
        $previous = Carbon::getTestNow();
        $ids = ['categories' => [], 'packs' => [], 'cards' => []];

        try {
            $categories = $this->categoryIds($operator, $ids);

            foreach (self::packs() as $spec) {
                $existing = $this->existingPack($operator, $spec['title']);
                if ($existing !== null) {
                    $ids['packs'][] = $existing->value;
                    $ids['cards'] = [...$ids['cards'], ...$this->cardIds($operator, $existing)];

                    continue;
                }
                DB::transaction(function () use ($operator, $spec, $categories, &$ids): void {
                    $ids['packs'][] = $this->write($operator, $spec, $categories[$spec['category']], $ids);
                });
            }
        } finally {
            Carbon::setTestNow($previous);
        }

        $this->repairProvenance($operator, $ids);
    }

    /**
     * @param  array{title: string, summary: string, series: bool, category: string, audiences: list<Audience>, published: bool, at: string, cards: list<array{title: string, type: CardType, content: array<string, mixed>|null, uri: string|null, file: string|null, summary: string|null, membersOnly: bool, published: bool}>}  $spec
     * @param  array{categories: list<string>, packs: list<string>, cards: list<string>}  $ids
     */
    private function write(Actor $as, array $spec, CategoryId $category, array &$ids): string
    {
        $minute = 0;
        $tick = static function () use ($spec, &$minute): void {
            Carbon::setTestNow(Carbon::parse($spec['at'].' UTC')->addMinutes($minute++ % 50));
        };

        $tick();
        $pack = $this->container->make(CreatePack::class)($as, $spec['title'], $spec['summary'], $spec['series'], $category)->pack->id;
        $tick();
        $this->container->make(SetPackAudiences::class)($as, $pack, $spec['audiences']);

        foreach ($spec['cards'] as $card) {
            $tick();
            $file = $card['file'] === null ? null : new IncomingFile(self::FILES.'/'.$card['file'], $card['file']);
            $made = $this->container->make(CreateCard::class)($as, $pack, $card['type'], $card['title'], $card['content'], $card['uri'], $card['summary'], $file)->card->id;
            $ids['cards'][] = $made->value;
            if ($card['membersOnly']) {
                $tick();
                $this->container->make(SetCardAudiences::class)($as, $pack, $made, AudienceMode::Narrowed, [Audience::Member]);
            }
            if ($card['published']) {
                $tick();
                $this->container->make(PublishCard::class)($as, $pack, $made);
            }
        }

        if ($spec['published']) {
            $tick();
            $this->container->make(PublishPack::class)($as, $pack);
        }

        return $pack->value;
    }

    /**
     * Every demo Category, made when it is not there (by exact name), in the demo's order.
     *
     * @param  array{categories: list<string>, packs: list<string>, cards: list<string>}  $ids
     * @return array<string, CategoryId>
     */
    private function categoryIds(Actor $as, array &$ids): array
    {
        $found = [];
        foreach ($this->container->make(ListCategories::class)($as) as $view) {
            $found[$view->category->name] = $view->category->id;
        }

        $out = [];
        $at = Carbon::parse('2026-09-08 08:00:00 UTC');
        foreach (self::categories() as $name) {
            if (! isset($found[$name])) {
                Carbon::setTestNow($at->copy()->addMinutes(count($out)));
                $found[$name] = $this->container->make(CreateCategory::class)($as, $name)->category->id;
            }
            $out[$name] = $found[$name];
            $ids['categories'][] = $found[$name]->value;
        }

        return $out;
    }

    /** The demo Pack with this exact title, if there is one. */
    private function existingPack(Actor $as, string $title): ?PackId
    {
        $page = $this->container->make(PageManagedPacks::class)($as, new ManagedPackFilter(titleContains: $title), 1, PageManagedPacks::MAX_PER_PAGE);
        foreach ($page->packs as $view) {
            if ($view->pack->title === $title) {
                return $view->pack->id;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function cardIds(Actor $as, PackId $pack): array
    {
        $view = $this->container->make(GetManagedPack::class)($as, $pack);

        return array_map(static fn ($card): string => $card->card->id->value, $view->cards ?? []);
    }

    /**
     * Points a demo Category, Pack or Card whose creator or editor no longer exists at the current operator. A row whose Person
     * still exists is not touched, and nothing is written when nothing dangles.
     *
     * @param  array{categories: list<string>, packs: list<string>, cards: list<string>}  $ids
     */
    private function repairProvenance(Actor $operator, array $ids): void
    {
        $tables = ['resource_categories' => $ids['categories'], 'resource_packs' => $ids['packs'], 'resource_cards' => $ids['cards']];
        foreach ($tables as $table => $rows) {
            if ($rows === []) {
                continue;
            }
            foreach (['created_by_person_id', 'updated_by_person_id'] as $column) {
                DB::table($table)->whereIn('id', $rows)
                    ->whereNotIn($column, DB::table('people')->select('id'))
                    ->update([$column => $operator->personId->value]);
            }
        }
    }

    /** The first active Account, by email, that may manage Resources. */
    private function operator(): Actor
    {
        $resolve = $this->container->make(ResolveActor::class);
        $authorize = $this->container->make(AuthorizeAction::class);

        foreach (DB::table('accounts')->where('status', 'active')->orderBy('email')->pluck('id') as $id) {
            if (! is_string($id)) {
                continue;
            }
            $actor = $resolve(AccountId::fromString($id));
            if ($actor === null) {
                continue;
            }
            try {
                $authorize($actor, Capability::ManageResources);
            } catch (AccessDenied) {
                continue;
            }

            return $actor;
        }

        throw new RuntimeException('There is no active Account that may manage Resources to write the demo data as. Create an administrator first (identity:create-administrator).');
    }

    // --- The content, in the Resources document profile (docs/adr/0037, decision 26) -------------------------------------------

    /**
     * @param  array<string, mixed>|null  $content
     * @return array{title: string, type: CardType, content: array<string, mixed>|null, uri: string|null, file: string|null, summary: string|null, membersOnly: bool, published: bool}
     */
    private static function basic(string $title, ?array $content, bool $membersOnly = false, bool $published = true): array
    {
        return ['title' => $title, 'type' => CardType::Basic, 'content' => $content, 'uri' => null, 'file' => null, 'summary' => null, 'membersOnly' => $membersOnly, 'published' => $published];
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array{title: string, type: CardType, content: array<string, mixed>|null, uri: string|null, file: string|null, summary: string|null, membersOnly: bool, published: bool}
     */
    private static function file(string $title, string $file, ?array $content, ?string $summary): array
    {
        return ['title' => $title, 'type' => CardType::File, 'content' => $content, 'uri' => null, 'file' => $file, 'summary' => $summary, 'membersOnly' => false, 'published' => true];
    }

    /**
     * @param  array<string, mixed>  ...$blocks
     * @return array<string, mixed>
     */
    private static function doc(array ...$blocks): array
    {
        return ['type' => 'doc', 'content' => array_values($blocks)];
    }

    /** @return array<string, mixed> */
    private static function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array<string, mixed> */
    private static function pLink(string $before, string $label, string $href, string $after): array
    {
        return ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => $before],
            ['type' => 'text', 'text' => $label, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => $after],
        ]];
    }

    /** @return array<string, mixed> */
    private static function h(int $level, string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array<string, mixed> */
    private static function ul(string ...$items): array
    {
        return ['type' => 'bulletList', 'content' => array_map(static fn (string $item): array => ['type' => 'listItem', 'content' => [self::p($item)]], array_values($items))];
    }

    /** @return array<string, mixed> */
    private static function ol(string ...$items): array
    {
        return ['type' => 'orderedList', 'content' => array_map(static fn (string $item): array => ['type' => 'listItem', 'content' => [self::p($item)]], array_values($items))];
    }

    /** @return array<string, mixed> */
    private static function quote(string $text): array
    {
        return ['type' => 'blockquote', 'content' => [self::p($text)]];
    }

    /** @return array<string, mixed> */
    private static function code(string $text): array
    {
        return ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * A table whose first row is its header.
     *
     * @param  list<string>  $header
     * @param  list<string>  ...$rows
     * @return array<string, mixed>
     */
    private static function table(array $header, array ...$rows): array
    {
        $out = [self::tableRow($header, 'tableHeader')];
        foreach ($rows as $cells) {
            $out[] = self::tableRow($cells, 'tableCell');
        }

        return ['type' => 'table', 'content' => $out];
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    private static function tableRow(array $cells, string $kind): array
    {
        return ['type' => 'tableRow', 'content' => array_map(
            static fn (string $text): array => ['type' => $kind, 'content' => [self::p($text)]],
            $cells,
        )];
    }
}
