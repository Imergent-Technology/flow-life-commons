<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Audiences;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\ReadingFixture;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;

/*
 * POLICY C over HTTP (ADR 0038, R10-R14): `/admin/my-resources` serves a signed-in Person the PUBLISHED Resources of the audiences their
 * own current relationships make them eligible for, and nothing else, whatever their role, capabilities or what they send. Personas are
 * real: Accounts with real roles (or a relationship-managed Guardian Initiate grant) and relationships recorded through Relationships'
 * own use cases. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    ResourceFiles::fake();
});

const MINE_ROOT = '/api/v1/admin/my-resources';
const NOT_THERE = ['message' => 'There is no such resource.', 'code' => 'resource_pack_not_found'];

/**
 * What every reader is or is not meant to see:
 *
 * - `guardian`   For Guardians: a Published Card, a Draft Card, a published File Card and a Draft File Card.
 * - `volunteer`  For Volunteers: two Published Cards.
 * - `member`     For Members.
 * - `shared`     For Guardians and Volunteers; one Card inherits, one is narrowed to Volunteers.
 * - `hollow`     For Guardians and Members, but its only Card is narrowed to Members: empty for a Guardian after projection.
 * - `draft`      A Draft Pack for Guardians (and every audience's) with a Published Card.
 */
function readingLibrary(): ReadingFixture
{
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    $category = Resources::category($by, 'Guides')->category->id;
    $second = Resources::category($by, 'Rotas')->category->id;
    $published = function (ManagedPackView $pack, array $audiences) use ($by): ManagedPackView {
        app(SetPackAudiences::class)($by, $pack->pack->id, Resources::audiences($audiences));

        return app(PublishPack::class)($by, $pack->pack->id);
    };

    $guardian = Resources::pack($by, 'Guardian guide', $category, summary: 'For the core team');
    $guardianLive = Resources::publishedCard($by, $guardian, 'Opening procedure', 'Unlock the door');
    $guardianDraftCard = Resources::card($by, $guardian, 'Secret draft card', 'Draft words', summary: 'Quokka draft summary');
    $guardianFile = ResourceFiles::publishedCard($by, $guardian, 'Floor plan', 'plan.pdf');
    $guardianDraftFile = ResourceFiles::card($by, $guardian, 'Draft plan', 'draft-plan.pdf', ResourceFiles::pdf('draft'));
    $guardian = $published($guardian, [Audience::Guardian]);

    $volunteer = Resources::pack($by, 'Volunteer handbook', $category);
    Resources::publishedCard($by, $volunteer, 'Shift basics', 'Arrive early');
    Resources::publishedCard($by, $volunteer, 'Shift extras', 'Stay late');
    $volunteer = $published($volunteer, [Audience::Volunteer]);

    $member = Resources::pack($by, 'Member news', $second);
    Resources::publishedCard($by, $member, 'Newsletter', 'Spring edition');
    $member = $published($member, [Audience::Member]);

    $shared = Resources::pack($by, 'Shared rota', $second);
    $both = Resources::publishedCard($by, $shared, 'For both', 'The rota');
    $volunteersOnly = Resources::publishedCard($by, $shared, 'Zanzibar volunteers only', 'Volunteer shifts');
    app(SetPackAudiences::class)($by, $shared->pack->id, [Audience::Guardian, Audience::Volunteer]);
    app(SetCardAudiences::class)($by, $shared->pack->id, $volunteersOnly->card->id, AudienceMode::Narrowed, [Audience::Volunteer]);
    $shared = app(PublishPack::class)($by, $shared->pack->id);

    $hollow = Resources::pack($by, 'Hollow pack', $second);
    $hollowCard = Resources::publishedCard($by, $hollow, 'Members only card', 'Hidden from guardians');
    app(SetPackAudiences::class)($by, $hollow->pack->id, [Audience::Guardian, Audience::Member]);
    app(SetCardAudiences::class)($by, $hollow->pack->id, $hollowCard->card->id, AudienceMode::Narrowed, [Audience::Member]);
    $hollow = app(PublishPack::class)($by, $hollow->pack->id);

    $draft = Resources::pack($by, 'Draft pack', $category);
    Resources::publishedCard($by, $draft, 'In a draft', 'Never live');
    app(SetPackAudiences::class)($by, $draft->pack->id, [Audience::Guardian, Audience::Volunteer, Audience::Member]);

    return new ReadingFixture($guardian, $guardianLive, $guardianDraftCard, $guardianFile, $guardianDraftFile, $volunteer, $member, $shared, $both, $volunteersOnly, $hollow, $hollowCard, $draft);
}

/**
 * A Console for a real Account holding exactly `console.access`, through a relationship-managed Guardian Initiate grant (WP2B).
 *
 * @return array{Console, Account}
 */
function restrictedGuardian(string $email = 'gwen.guardian@example.org'): array
{
    $account = Identity::savedActiveAccount($email, name: 'Gwen Guardian');
    Audiences::relate($account->personId, 'guardian', 'active', grant: true);

    return [Audiences::signIn($account), $account];
}

/** @return array<string, mixed> */
function readJson(Console $console, string $path): array
{
    return Api::map($console->get($path)->assertOk()->json());
}

/** @return list<string> the Pack titles in a reader's library */
function readerTitles(Console $console, string $query = ''): array
{
    $titles = [];
    foreach (Api::rows(readJson($console, MINE_ROOT.$query)['data']) as $group) {
        foreach (Api::rows($group['packs']) as $pack) {
            $titles[] = Api::string($pack['title']);
        }
    }

    return $titles;
}

// --- The restricted Guardian: a real guardian-initiate, eligible through an Active Guardian relationship -------------------------

it('serves a restricted Guardian Initiate the Published Guardian Resources and nothing they are not entitled to', function () {
    $f = readingLibrary();
    [$console, $account] = restrictedGuardian();

    // The persona is what it claims: Console admission from the relationship-managed grant, and no Resources capability whatever.
    $me = Api::map($console->me()->assertOk()->json());
    expect(Api::strings(Api::map($me['account'] ?? $me)['capabilities'] ?? $me['capabilities']))->toBe(['console.access'])
        ->and(DB::table('sourced_role_grants')->where('person_id', $account->personId->value)->value('role_key'))->toBe('guardian-initiate')
        ->and(DB::table('role_assignments')->where('person_id', $account->personId->value)->count())->toBe(0);

    expect(readerTitles($console))->toBe(['Guardian guide', 'Shared rota']);

    $guardian = readJson($console, MINE_ROOT.'/packs/'.$f->guardian->pack->id->value);
    expect(array_column(Api::rows($guardian['cards']), 'title'))->toBe(['Opening procedure', 'Floor plan'])
        ->and(array_column(Api::rows($guardian['cards']), 'index'))->toBe([1, 2])
        ->and($guardian['card_count'])->toBe(2);
});

it('shows a restricted Guardian no Draft: not a Draft Pack, not a Draft Card, not a Draft file, by listing, search, deep link or file route', function () {
    $f = readingLibrary();
    [$console] = restrictedGuardian();
    $pack = $f->guardian->pack->id->value;

    $text = (string) $console->get(MINE_ROOT.'/packs/'.$pack)->getContent().(string) $console->get(MINE_ROOT)->getContent();
    foreach (['Secret draft card', 'Draft words', 'Quokka draft summary', 'Draft plan', 'draft-plan.pdf', $f->guardianDraftCard->card->id->value, $f->guardianDraftFile->card->id->value, 'Draft pack', 'In a draft'] as $leak) {
        expect($text)->not->toContain($leak);
    }
    expect(readerTitles($console, '?q=Quokka'))->toBe([])
        ->and(readerTitles($console, '?q=draft'))->toBe([])
        ->and(readerTitles($console, '?q=never+live'))->toBe([]);
    $console->get(MINE_ROOT.'/packs/'.$f->draft->pack->id->value)->assertNotFound()->assertExactJson(NOT_THERE);
    $console->get(MINE_ROOT."/packs/{$pack}/cards/".$f->guardianDraftFile->card->id->value.'/file')->assertNotFound()->assertExactJson(NOT_THERE);
    $console->get(MINE_ROOT."/packs/{$pack}/cards/".$f->guardianDraftCard->card->id->value.'/file')->assertNotFound()->assertExactJson(NOT_THERE);

    // ...and the Published file is theirs.
    expect($console->get(MINE_ROOT."/packs/{$pack}/cards/".$f->guardianFile->card->id->value.'/file')->assertOk()->streamedContent())->toBe(ResourceFiles::pdf());
});

it('refuses a restricted Guardian the privileged library and management outright, whatever they ask for', function () {
    $f = readingLibrary();
    [$console] = restrictedGuardian();

    foreach ([
        '/api/v1/admin/resource-library', '/api/v1/admin/resource-library?publication=draft', '/api/v1/admin/resource-library/packs/'.$f->draft->pack->id->value,
        '/api/v1/admin/resource-library/packs/'.$f->guardian->pack->id->value.'/cards/'.$f->guardianDraftFile->card->id->value.'/file',
        '/api/v1/admin/resources/packs', '/api/v1/admin/resources/packs/'.$f->draft->pack->id->value, '/api/v1/admin/resources/packs/'.$f->draft->pack->id->value.'/preview?audience=guardian',
    ] as $path) {
        $console->get($path)->assertForbidden();
    }
});

it('serves the Guardian audience to no one with a Guardian-named role and no Guardian relationship, who keeps what their role genuinely grants', function (Role $role) {
    $f = readingLibrary();
    $account = Audiences::reader([], $role, "named.{$role->value}@example.org");
    $console = Audiences::signIn($account);

    // Not a Guardian here: the library is empty, every Pack is the one refusal.
    expect(readJson($console, MINE_ROOT)['data'])->toBe([]);
    $console->get(MINE_ROOT.'/packs/'.$f->guardian->pack->id->value)->assertNotFound()->assertExactJson(NOT_THERE);

    // ...while the role's own capabilities are untouched (a Guardian role reads the privileged library; initiate and participant do not).
    $privileged = in_array($role, [Role::GuardianFull, Role::GuardianSenior, Role::PlatformAdministrator], true);
    expect($console->get('/api/v1/admin/resource-library')->getStatusCode())->toBe($privileged ? 200 : 403);
})->with([Role::GuardianFull, Role::GuardianSenior, Role::GuardianInitiate, Role::ConsoleParticipant, Role::PlatformAdministrator]);

// --- Console Participant and the other audiences -----------------------------------------------------------------------------

it('gives a Console Participant with no relationship an empty library, then Volunteer content while an Active Volunteer, and not otherwise', function () {
    $f = readingLibrary();
    $account = Audiences::reader([], Role::ConsoleParticipant, 'pat.participant@example.org');
    $console = Audiences::signIn($account);

    expect(readJson($console, MINE_ROOT))->toBe(['data' => []]);

    Audiences::relate($account->personId, 'volunteer', 'pending');
    expect(readerTitles($console))->toBe([]);
    Audiences::change($account->personId, 'volunteer', 'active');
    expect(readerTitles($console))->toBe(['Volunteer handbook', 'Shared rota'])
        ->and(array_column(Api::rows(readJson($console, MINE_ROOT.'/packs/'.$f->volunteer->pack->id->value)['cards']), 'title'))->toBe(['Shift basics', 'Shift extras']);
    Audiences::change($account->personId, 'volunteer', 'inactive');
    expect(readerTitles($console))->toBe([]);
    $console->get(MINE_ROOT.'/packs/'.$f->volunteer->pack->id->value)->assertNotFound()->assertExactJson(NOT_THERE);
});

it('serves a Member the Member audience, and a Member who is an Inactive Volunteer only that', function () {
    readingLibrary();
    $account = Audiences::reader(['member', 'volunteer:inactive'], Role::ConsoleParticipant, 'mel.member@example.org');

    expect(readerTitles(Audiences::signIn($account)))->toBe(['Member news', 'Hollow pack']);
});

it('combines audiences by OR and keeps a Card\'s narrowing: each audience sees its own Cards of a shared Pack, and a Guardian and Volunteer sees both', function () {
    $f = readingLibrary();
    $shared = MINE_ROOT.'/packs/'.$f->shared->pack->id->value;
    $guardian = Audiences::signIn(Audiences::reader(['guardian'], Role::ConsoleParticipant, 'g.only@example.org'));
    $volunteer = Audiences::signIn(Audiences::reader(['volunteer'], Role::ConsoleParticipant, 'v.only@example.org'));
    $both = Audiences::signIn(Audiences::reader(['guardian', 'volunteer'], Role::ConsoleParticipant, 'g.and.v@example.org'));
    $all = Audiences::signIn(Audiences::reader(['guardian', 'volunteer', 'member'], Role::ConsoleParticipant, 'g.v.m@example.org'));

    $cards = fn (Console $c): array => array_column(Api::rows(readJson($c, $shared)['cards']), 'title');
    // Counts and numbering are the reader's own: the Guardian sees one Card, 1 of 1, with no sign of the second.
    expect($cards($guardian))->toBe(['For both'])
        ->and(readJson($guardian, $shared)['card_count'])->toBe(1)
        ->and(array_column(Api::rows(readJson($guardian, $shared)['cards']), 'index'))->toBe([1])
        ->and($cards($volunteer))->toBe(['For both', 'Zanzibar volunteers only'])
        ->and(array_column(Api::rows(readJson($volunteer, $shared)['cards']), 'index'))->toBe([1, 2])
        ->and($cards($both))->toBe(['For both', 'Zanzibar volunteers only'])
        ->and(readerTitles($both))->toBe(['Guardian guide', 'Volunteer handbook', 'Shared rota'])
        ->and(readerTitles($all))->toBe(['Guardian guide', 'Volunteer handbook', 'Member news', 'Shared rota', 'Hollow pack']);

    // A Pack for {guardian, volunteer} is listed for either audience alone, and each library counts only its own Cards.
    $listed = fn (Console $c): array => Api::rows(Api::rows(readJson($c, MINE_ROOT)['data'])[1]['packs']);
    expect($listed($guardian)[0]['card_count'])->toBe(1)->and($listed($volunteer)[0]['card_count'])->toBe(2);
});

it('lists a Pack with no Card this reader may see nowhere, and answers it as it would a missing one', function () {
    $f = readingLibrary();
    $guardian = Audiences::signIn(Audiences::reader(['guardian'], Role::ConsoleParticipant, 'g.hollow@example.org'));
    $member = Audiences::signIn(Audiences::reader(['member'], Role::ConsoleParticipant, 'm.hollow@example.org'));

    expect(readerTitles($guardian))->not->toContain('Hollow pack')
        ->and(readerTitles($member))->toContain('Hollow pack');
    $guardian->get(MINE_ROOT.'/packs/'.$f->hollow->pack->id->value)->assertNotFound()->assertExactJson(NOT_THERE);
    $member->get(MINE_ROOT.'/packs/'.$f->hollow->pack->id->value)->assertOk();
});

// --- Search, hidden Cards and the one refusal ----------------------------------------------------------------------------------

it('never lets a hidden Card\'s words, id or count reach a reader: search, listing, deep link and file route all say nothing', function () {
    $f = readingLibrary();
    $guardian = Audiences::signIn(Audiences::reader(['guardian'], Role::ConsoleParticipant, 'g.search@example.org'));
    $hidden = $f->volunteersOnly->card;
    $shared = $f->shared->pack->id->value;

    expect(readerTitles($guardian, '?q=Zanzibar'))->toBe([])
        ->and(readerTitles($guardian, '?q=volunteer+shifts'))->toBe([])
        ->and(readerTitles($guardian, '?q=rota'))->toBe(['Shared rota'])    // the Pack's own title matches
        ->and(readerTitles($guardian, '?q=for+both'))->toBe(['Shared rota']);

    $everything = (string) $guardian->get(MINE_ROOT)->getContent().(string) $guardian->get(MINE_ROOT."/packs/{$shared}")->getContent();
    foreach (['Zanzibar', 'Volunteer shifts', $hidden->id->value, 'Members only card', 'Hidden from guardians', $f->hollowCard->card->id->value] as $leak) {
        expect($everything)->not->toContain($leak);
    }
    // The hidden Card's id, asked for directly as a Card of its own Pack, and of another, is the same refusal as a Card that never existed.
    $missing = $guardian->get(MINE_ROOT."/packs/{$shared}/cards/01jaaaaaaaaaaaaaaaaaaaaaa0/file");
    $direct = $guardian->get(MINE_ROOT."/packs/{$shared}/cards/{$hidden->id->value}/file");
    expect($direct->getStatusCode())->toBe(404)->and((string) $direct->getContent())->toBe((string) $missing->getContent())
        ->and($direct->json())->toBe(NOT_THERE);
});

it('answers every reason a Pack is not a reader\'s with byte-identical bodies and the same headers: missing, Draft, ineligible and empty after projection', function () {
    $f = readingLibrary();
    $guardian = Audiences::signIn(Audiences::reader(['guardian'], Role::ConsoleParticipant, 'g.same@example.org'));
    $ids = ['01jaaaaaaaaaaaaaaaaaaaaaa0', $f->draft->pack->id->value, $f->volunteer->pack->id->value, $f->member->pack->id->value, $f->hollow->pack->id->value];

    $responses = array_map(fn (string $id) => $guardian->get(MINE_ROOT."/packs/{$id}"), $ids);
    $seen = array_map(fn ($r): array => [$r->getStatusCode(), (string) $r->getContent(), $r->headers->get('Content-Type'), $r->headers->get('Cache-Control')], $responses);

    expect(array_unique(array_map('serialize', $seen)))->toHaveCount(1)
        ->and($seen[0][0])->toBe(404)->and($seen[0][3])->toContain('no-store');
});

it('sends authorized content nowhere a shared cache could keep it: no-store on the library, a Pack and a file, no signed address', function () {
    $f = readingLibrary();
    [$console] = restrictedGuardian();
    $pack = $f->guardian->pack->id->value;
    $file = $console->get(MINE_ROOT."/packs/{$pack}/cards/".$f->guardianFile->card->id->value.'/file')->assertOk();

    foreach ([$console->get(MINE_ROOT), $console->get(MINE_ROOT."/packs/{$pack}"), $file] as $response) {
        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Location'))->toBeNull();
    }
    $card = Api::rows(readJson($console, MINE_ROOT."/packs/{$pack}")['cards'])[1];
    expect(Api::map($card['file'])['download_path'])->toBe(MINE_ROOT."/packs/{$pack}/cards/".$f->guardianFile->card->id->value.'/file')
        ->and((string) $console->get(MINE_ROOT."/packs/{$pack}")->getContent())->not->toContain('signature', 'expires', 'http');
});

// --- Revocation between requests ---------------------------------------------------------------------------------------------------

it('withdraws a restricted Guardian\'s Console admission with the relationship that provisioned it, so the very next request is refused at the door', function () {
    $f = readingLibrary();
    [$console, $account] = restrictedGuardian();
    $pack = MINE_ROOT.'/packs/'.$f->guardian->pack->id->value;
    $file = "{$pack}/cards/".$f->guardianFile->card->id->value.'/file';

    $console->get($pack)->assertOk();
    expect($console->get($file)->assertOk()->streamedContent())->toBe(ResourceFiles::pdf());

    // Inactivation withdraws the sourced Initiate grant in the same transaction (K7): the same session loses admission, not just content.
    Audiences::change($account->personId, 'guardian', 'inactive');

    $console->get($pack)->assertForbidden();
    $console->get($file)->assertForbidden();
    $console->get(MINE_ROOT)->assertForbidden();
});

it('stops serving on the very next request when admission is kept and the relationship ends: inactivation, deletion, a Volunteer going Pending, a Membership ending', function (Role $role) {
    $f = readingLibrary();
    $account = Audiences::reader(['guardian', 'volunteer', 'member'], $role, "ria.revoked.{$role->value}@example.org");
    $console = Audiences::signIn($account);
    $guardianFile = MINE_ROOT.'/packs/'.$f->guardian->pack->id->value.'/cards/'.$f->guardianFile->card->id->value.'/file';
    $volunteerPack = MINE_ROOT.'/packs/'.$f->volunteer->pack->id->value;
    $memberPack = MINE_ROOT.'/packs/'.$f->member->pack->id->value;

    $console->get($guardianFile)->assertOk();
    $console->get($volunteerPack)->assertOk();
    $console->get($memberPack)->assertOk();

    // Inactivate the Guardian relationship: its file is gone on the next request, the other audiences untouched.
    Audiences::change($account->personId, 'guardian', 'inactive');
    $console->get($guardianFile)->assertNotFound()->assertExactJson(NOT_THERE);
    $console->get($volunteerPack)->assertOk();

    // A Volunteer going Pending loses Volunteer content; reactivating restores it; deleting the relationship outright removes it again.
    Audiences::change($account->personId, 'volunteer', 'pending');
    $console->get($volunteerPack)->assertNotFound()->assertExactJson(NOT_THERE);
    Audiences::change($account->personId, 'volunteer', 'active');
    $console->get($volunteerPack)->assertOk();
    Audiences::remove($account->personId, 'volunteer');
    $console->get($volunteerPack)->assertNotFound()->assertExactJson(NOT_THERE);
    $console->get($memberPack)->assertOk();

    // End the Membership.
    DB::table('membership_grants')->where('person_id', $account->personId->value)->update(['ends_at' => Identity::now()->modify('-1 minute')->format('Y-m-d H:i:s')]);
    $console->get($memberPack)->assertNotFound()->assertExactJson(NOT_THERE);
    expect(readJson($console, MINE_ROOT))->toBe(['data' => []]);
})->with([Role::ConsoleParticipant, Role::GuardianInitiate]);

it('stops a Person who loses their Account\'s ability to sign in: no eligibility survives a disabled Account', function () {
    $f = readingLibrary();
    [$console, $account] = restrictedGuardian('dee.disabled@example.org');
    $console->get(MINE_ROOT.'/packs/'.$f->guardian->pack->id->value)->assertOk();

    app(DisableAccount::class)($account->id);

    $console->get(MINE_ROOT.'/packs/'.$f->guardian->pack->id->value)->assertUnauthorized();
    $console->get(MINE_ROOT)->assertUnauthorized();
});

// --- Console admission is separate ------------------------------------------------------------------------------------------------

it('keeps a Person with an Active Guardian relationship and no Console admission out of every my-resources route', function () {
    $f = readingLibrary();
    $account = Identity::savedActiveAccount('nell.noaccess@example.org', name: 'Nell Noaccess');
    // Recognized as a Guardian; the operator DECLINES the Initiate role, so there is no Console admission at all.
    Audiences::relate($account->personId, 'guardian', 'active');
    $browser = new Console;
    $browser->login('nell.noaccess@example.org', Identity::PASSWORD)->assertOk();
    $pack = $f->guardian->pack->id->value;

    foreach ([MINE_ROOT, MINE_ROOT."/packs/{$pack}", MINE_ROOT."/packs/{$pack}/cards/".$f->guardianFile->card->id->value.'/file'] as $path) {
        $browser->get($path)->assertForbidden();
    }
    expect(DB::table('person_relationships')->where('person_id', $account->personId->value)->value('status'))->toBe('active');
});

it('refuses every my-resources route without a session', function () {
    $f = readingLibrary();
    $anonymous = new Console;

    foreach ([MINE_ROOT, MINE_ROOT.'/packs/'.$f->guardian->pack->id->value, MINE_ROOT.'/packs/'.$f->guardian->pack->id->value.'/cards/'.$f->guardianFile->card->id->value.'/file'] as $path) {
        $anonymous->get($path)->assertUnauthorized();
    }
});

// --- No client elevation -----------------------------------------------------------------------------------------------------------

it('cannot be widened by anything a client sends: no audience, Person, mode, publication or capability parameter changes a byte', function () {
    $f = readingLibrary();
    $other = Audiences::reader(['guardian', 'volunteer', 'member'], Role::ConsoleParticipant, 'other.everything@example.org');
    [$console] = restrictedGuardian();
    $pack = MINE_ROOT.'/packs/'.$f->guardian->pack->id->value;
    $elevations = '?audience=member&audiences[]=volunteer&audiences[]=member&person_id='.$other->personId->value.'&person='.$other->personId->value
        .'&account_id='.$other->id->value.'&mode=viewer&viewer=1&publication=draft&as=admin&resources.view=1&state=draft&eligible=guardian,member,volunteer';

    foreach ([MINE_ROOT => MINE_ROOT.$elevations, $pack => $pack.$elevations, "{$pack}/cards/".$f->guardianFile->card->id->value.'/file' => "{$pack}/cards/".$f->guardianFile->card->id->value.'/file'.$elevations] as $plain => $elevated) {
        $a = $console->get($plain);
        $b = $console->get($elevated);
        expect($b->getStatusCode())->toBe($a->getStatusCode());
        if ($a->headers->get('Content-Type') === 'application/json') {
            expect((string) $b->getContent())->toBe((string) $a->getContent());
        } else {
            expect($b->streamedContent())->toBe($a->streamedContent());
        }
    }
    foreach ([['X-Audience' => 'member'], ['X-Person-Id' => $other->personId->value], ['X-Mode' => 'viewer']] as $headers) {
        expect((string) $console->get(MINE_ROOT, $headers)->getContent())->toBe((string) $console->get(MINE_ROOT)->getContent());
    }
    // The privileged library is still refused whatever is claimed.
    $console->get('/api/v1/admin/resource-library'.$elevations)->assertForbidden();
});

// --- Management is not viewing, and a manager reads as a Person ---------------------------------------------------------------------

it('lets a manager without resources.view manage and preview, read the library as nobody privileged, and read Resources only as their own relationships allow', function () {
    $f = readingLibrary();
    [$console, $account] = Resources::signedInGuardian('mona.manager@example.org', 'Mona Manager');
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => $ability === 'resources.view' ? false : null);
    $pack = $f->draft->pack->id->value;

    // Manage: allowed. View: refused.
    $console->get("/api/v1/admin/resources/packs/{$pack}")->assertOk();
    $console->get("/api/v1/admin/resources/packs/{$pack}/preview?audience=guardian")->assertOk();
    $console->get('/api/v1/admin/resource-library')->assertForbidden();
    $console->get("/api/v1/admin/resource-library/packs/{$pack}")->assertForbidden();

    // Audience reading: no relationship, so nothing; becoming an Active Volunteer gives exactly Volunteer content, never the rest.
    expect(readJson($console, MINE_ROOT))->toBe(['data' => []]);
    Audiences::relate($account->personId, 'volunteer');
    expect(readerTitles($console))->toBe(['Volunteer handbook', 'Shared rota']);
    $console->get(MINE_ROOT."/packs/{$pack}")->assertNotFound()->assertExactJson(NOT_THERE);
});

it('lets a holder of resources.view read everything privileged and not be served more on my-resources than their own relationships give', function () {
    $f = readingLibrary();
    [$console] = Resources::signedInGuardian('vera.viewer@example.org', 'Vera Viewer');
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => $ability === 'resources.manage' ? false : null);

    expect(Api::rows(readJson($console, '/api/v1/admin/resource-library')['data']))->not->toBe([])
        ->and(readJson($console, '/api/v1/admin/resource-library/packs/'.$f->draft->pack->id->value)['state'])->toBe('draft')
        // ...and holds no relationship, so audience reading is empty: viewing authority is not eligibility.
        ->and(readJson($console, MINE_ROOT))->toBe(['data' => []]);
    $console->get(MINE_ROOT.'/packs/'.$f->guardian->pack->id->value)->assertNotFound()->assertExactJson(NOT_THERE);
});

// --- Exact shapes ------------------------------------------------------------------------------------------------------------------------

it('serves exactly the delivery shape at every depth, and none of the privileged or management fields', function () {
    $f = readingLibrary();
    $reader = Audiences::signIn(Audiences::reader(['guardian', 'volunteer'], Role::ConsoleParticipant, 'shape.reader@example.org'));
    $library = readJson($reader, MINE_ROOT);
    $pack = readJson($reader, MINE_ROOT.'/packs/'.$f->guardian->pack->id->value);
    $cards = Api::rows($pack['cards']);

    expect(array_keys($library))->toBe(['data'])
        ->and(array_keys(Api::rows($library['data'])[0]))->toBe(['category', 'packs'])
        ->and(array_keys(Api::map(Api::rows($library['data'])[0]['category'])))->toBe(['id', 'name'])
        ->and(array_keys(Api::rows(Api::rows($library['data'])[0]['packs'])[0]))->toBe(['id', 'title', 'summary', 'is_series', 'card_count'])
        ->and(array_keys($pack))->toBe(['id', 'title', 'summary', 'is_series', 'category', 'card_count', 'cards'])
        ->and(array_keys($cards[0]))->toBe(['id', 'index', 'type', 'title', 'summary', 'uri', 'file', 'content'])
        ->and(array_keys(Api::map($cards[1]['file'])))->toBe(['name', 'media_type', 'byte_size', 'download_path'])
        ->and(array_keys(Api::map($cards[0]['content'])))->toBe(['format', 'version', 'document']);

    // Not one privileged or management key anywhere in either body, however deep.
    $text = (string) $reader->get(MINE_ROOT)->getContent().(string) $reader->get(MINE_ROOT.'/packs/'.$f->guardian->pack->id->value)->getContent();
    foreach (['"state"', '"audiences"', '"audience_mode"', '"revision"', '"position"', '"draft_card_count"', '"updated_by"', '"updated_at"', '"created_by"', '"display_name"', '"publication"', 'draft'] as $privileged) {
        expect($text)->not->toContain($privileged);
    }
});

// --- Pure helpers referenced above -----------------------------------------------------------------------------------------------------------

it('builds its personas from real roles and relationships: a restricted Guardian holds no role assignment and exactly one sourced grant', function () {
    [, $account] = restrictedGuardian('persona.check@example.org');

    expect(DB::table('role_assignments')->where('person_id', $account->personId->value)->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('person_id', $account->personId->value)->count())->toBe(1)
        ->and($account)->toBeInstanceOf(Account::class)
        ->and(Access::actorFor($account))->toBeInstanceOf(Actor::class)
        ->and(Membership::class)->toBeString();
});

it('offers a visible file as an attachment, or inline only when asked and only for a PDF or a raster image', function () {
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    $pack = Resources::pack($by, 'Files', Resources::category($by, 'Guides')->category->id);
    $png = ResourceFiles::publishedCard($by, $pack, 'Picture', 'map.png', ResourceFiles::png());
    $text = ResourceFiles::publishedCard($by, $pack, 'Notes', 'notes.txt', "plain notes\n");
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Volunteer]);
    app(PublishPack::class)($by, $pack->pack->id);
    $console = Audiences::signIn(Audiences::reader(['volunteer'], Role::ConsoleParticipant, 'ivy.inline@example.org'));
    $base = MINE_ROOT.'/packs/'.$pack->pack->id->value.'/cards/';

    $disposition = fn (string $card, string $query): string => (string) $console->get($base.$card.'/file'.$query)->assertOk()->headers->get('Content-Disposition');

    expect($disposition($png->card->id->value, ''))->toStartWith('attachment;')
        ->and($disposition($png->card->id->value, '?disposition=inline'))->toStartWith('inline;')
        ->and($disposition($text->card->id->value, '?disposition=inline'))->toStartWith('attachment;')
        ->and($disposition($text->card->id->value, '?disposition=attachment'))->toStartWith('attachment;');
    $console->get($base.$png->card->id->value.'/file?disposition=render')->assertStatus(422);
});
