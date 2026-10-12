<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Role;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\ProfileChanges;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Application\UpdatePerson;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * Relationship-scoped People access (ADR 0038, Part P): the Volunteer manager's basic-detail upkeep, the basics a relationship
 * reads, the directory's primary-only contact search, the exact-match candidate lookup, and intake of an existing or a new
 * Person. Every scenario is a real request by a real persona; the delegated CRM services have no route of their own.
 *
 * Helper functions are prefixed `pa` so a test file run alone has everything it needs.
 */

const PA_API = '/api/v1/admin/relationships';

/**
 * The rows of a paged or listed response, narrowed.
 *
 * @param  TestResponse<Response>  $response
 * @return list<array<string, mixed>>
 */
function paRows(TestResponse $response): array
{
    return Api::rows($response->json('data'));
}

/**
 * The display names of a response's rows, in order.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function paNames(TestResponse $response): array
{
    return array_map(fn (array $row): string => Api::string(Api::map($row['person'])['display_name']), paRows($response));
}

/**
 * The Person ids of a response's rows, in order.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function paPersonIds(TestResponse $response): array
{
    return array_map(fn (array $row): string => Api::string(Api::map($row['person'])['id']), paRows($response));
}

/**
 * The keys of an object a response carries.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function paKeys(TestResponse $response, ?string $path = null): array
{
    return array_map(strval(...), array_keys(Api::map($response->json($path))));
}

/** @return array{0: string, 1: int} the relationship id and revision */
function paVolunteer(Console $console, string $person, string $status = 'active'): array
{
    $created = $console->post(PA_API.'/volunteers', ['person_id' => $person, 'confirm_existing_person' => true, 'status' => $status])->assertCreated();

    $revision = $created->json('revision');
    assert(is_int($revision));

    return [Mfa::text($created->json('relationship_id')), $revision];
}

/**
 * @param  array<string, mixed>  $changes
 * @return TestResponse<Response>
 */
function paEdit(Console $console, string $person, string $relationship, array $changes): TestResponse
{
    return $console->patch(PA_API."/volunteers/{$person}/basics", ['relationship_id' => $relationship, ...$changes]);
}

/** @return array{from: ?string, to: string} */
function paChange(?string $from, string $to): array
{
    return ['from' => $from, 'to' => $to];
}

/** A Person with a name and primary CRM contact values, planted through CRM's own use cases. */
function paPerson(string $name, ?string $email = null, ?string $phone = null): string
{
    $id = Identity::savedPerson($name)->id;
    $by = Crm::manager('crm.manager@example.org');
    if ($email !== null) {
        Crm::email($by, $id, $email);
    }
    if ($phone !== null) {
        Crm::phone($by, $id, $phone);
    }

    return $id->value;
}

/** @return list<array{value: string, label: ?string, primary: bool, stamp: ?string}> */
function paMethods(string $person, string $kind): array
{
    $rows = [];
    foreach (DB::table('contact_methods')->where('person_id', $person)->where('kind', $kind)->orderBy('created_at')->orderBy('id')->get() as $row) {
        assert(is_string($row->value) && ($row->label === null || is_string($row->label)));
        assert($row->updated_by_account_id === null || is_string($row->updated_by_account_id));
        $rows[] = ['value' => $row->value, 'label' => $row->label, 'primary' => $row->primary_kind !== null, 'stamp' => $row->updated_by_account_id];
    }

    return $rows;
}

function paName(string $person): string
{
    return Mfa::text(DB::table('people')->where('id', $person)->value('display_name'));
}

function paRenames(): int
{
    return DB::table('security_events')->where('type', 'person.renamed')->count();
}

/** A Console user holding exactly one real role. */
function paSignedIn(Role $role, string $email): Console
{
    $account = Identity::savedActiveAccount($email, name: 'Persona '.$role->value);
    Access::grant($account, $role);
    Mfa::enroll($account);
    $console = new Console;
    $console->loginWithMfa($email, Identity::PASSWORD)->assertOk();

    return $console;
}

// --- Scope and authorization -----------------------------------------------------------------------------------------------------

it('corrects the three basic details of a Volunteer in any state, and nothing else moves', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = paPerson('Ada Lovelace', 'Old@Example.org', '+1 555 010 0100');
    [$id, $revision] = paVolunteer($console, $person, 'pending');
    $console->put(PA_API."/volunteers/{$person}/status", ['relationship_id' => $id, 'revision' => 1, 'status' => 'inactive'])->assertOk();
    $before = paRenames();

    $response = paEdit($console, $person, $id, [
        'display_name' => paChange('Ada Lovelace', 'Ada Byron'),
        'primary_email' => paChange('Old@Example.org', 'new@example.org'),
        'primary_phone' => paChange('+1 555 010 0100', '+1 555 010 0199'),
    ])->assertOk();

    expect($response->json('person.display_name'))->toBe('Ada Byron')
        ->and($response->json('basics'))->toBe(['primary_email' => 'new@example.org', 'primary_phone' => '+1 555 010 0199'])
        ->and($response->json('revision'))->toBe(2) // the status change moved it; the details edit did not
        ->and($response->json('status'))->toBe('inactive')
        ->and(DB::table('person_relationships')->where('id', $id)->value('revision'))->toBe(2)
        ->and(paName($person))->toBe('Ada Byron')
        ->and(paRenames())->toBe($before + 1)
        ->and(paMethods($person, 'email'))->toHaveCount(1)
        ->and(paMethods($person, 'email')[0]['stamp'])->toBe($admin->id->value)
        ->and(paMethods($person, 'phone')[0]['stamp'])->toBe($admin->id->value)
        ->and($revision)->toBe(1);
    $event = Identity::events('person.renamed')[0];
    expect($event->actor_account_id)->toBe($admin->id->value)
        ->and(Mfa::auditText())->not->toContain('Ada Byron')->and(Mfa::auditText())->not->toContain('new@example.org');
});

it('answers one 404 for a Person out of scope, whether or not the Person exists, and changes nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $guardianOnly = paPerson('Only A Guardian', 'g@example.org');
    $console->post(PA_API.'/guardians', ['person_id' => $guardianOnly, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer'])->assertCreated();
    $nobody = paPerson('Nobody Special', 'n@example.org');
    $missing = strtolower((string) Str::ulid());
    $someId = strtolower((string) Str::ulid());

    $answers = [];
    foreach ([$guardianOnly, $nobody, $missing] as $person) {
        $answer = paEdit($console, $person, $someId, ['primary_email' => paChange('x@example.org', 'y@example.org')]);
        $answer->assertNotFound();
        $answers[] = $answer->json();
    }
    // A malformed body to an out-of-scope Person is the same 404: shape is never decided before scope.
    $malformed = paEdit($console, $nobody, $someId, ['account_email' => 'x', 'primary_email' => 'not an object']);
    $malformed->assertNotFound();

    expect($answers[0])->toBe($answers[1])->and($answers[1])->toBe($answers[2])
        ->and($malformed->json())->toBe($answers[0])
        ->and(Api::map($answers[0])['code'])->toBe('relationship_not_found')
        ->and(paMethods($guardianOnly, 'email')[0]['value'])->toBe('g@example.org')
        ->and(paMethods($nobody, 'email')[0]['value'])->toBe('n@example.org')
        ->and(paName($nobody))->toBe('Nobody Special');
});

it('refuses a stale or foreign instance, and a re-created relationship, with 409 and writes nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $person = paPerson('Re Created', 'old@example.org');
    [$old] = paVolunteer($console, $person);
    $console->delete(PA_API."/volunteers/{$person}?relationship_id={$old}&revision=1")->assertNoContent();
    [$new] = paVolunteer($console, $person);

    $stale = paEdit($console, $person, $old, ['primary_email' => paChange('old@example.org', 'new@example.org')])->assertStatus(409);
    expect($stale->json('code'))->toBe('stale_revision')
        ->and($stale->json('current.relationship_id'))->toBe($new)
        ->and(paMethods($person, 'email')[0]['value'])->toBe('old@example.org');

    paEdit($console, $person, strtolower((string) Str::ulid()), ['primary_email' => paChange('old@example.org', 'x@example.org')])->assertStatus(409);
    $console->patch(PA_API."/volunteers/{$person}/basics", ['primary_email' => paChange('old@example.org', 'x@example.org')])->assertStatus(409); // no instance named at all
    expect(paMethods($person, 'email')[0]['value'])->toBe('old@example.org');
});

it('has no basics route for Guardians, and a Volunteer edit leaves the same Person\'s Guardian relationship untouched', function () {
    [$console] = Mfa::signedInAdmin();
    $person = paPerson('Both Hats', 'both@example.org');
    $guardian = $console->post(PA_API.'/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant', 'fields' => ['stewardship' => 'Choir'],
    ])->assertCreated();
    [$volunteer] = paVolunteer($console, $person);
    $guardianRow = (array) DB::table('person_relationships')->where('id', $guardian->json('relationship_id'))->first();
    $decision = DB::table('person_relationship_role_provisions')->get()->map(fn ($row) => (array) $row)->all();
    $grants = DB::table('sourced_role_grants')->get()->map(fn ($row) => (array) $row)->all();
    $events = DB::table('security_events')->whereIn('type', ['role.granted', 'role.revoked'])->count();

    $console->patch(PA_API."/guardians/{$person}/basics", [
        'relationship_id' => $guardian->json('relationship_id'), 'primary_email' => paChange('both@example.org', 'x@example.org'),
    ])->assertNotFound();
    paEdit($console, $person, $volunteer, ['display_name' => paChange('Both Hats', 'Both Hats Renamed')])->assertOk();
    // A Guardian relationship's id sent to the Volunteer route is a stale instance: the type is the route's.
    paEdit($console, $person, Mfa::text($guardian->json('relationship_id')), ['display_name' => paChange('Both Hats Renamed', 'Hijacked')])->assertStatus(409);

    expect((array) DB::table('person_relationships')->where('id', $guardian->json('relationship_id'))->first())->toBe($guardianRow)
        ->and(DB::table('person_relationship_role_provisions')->get()->map(fn ($row) => (array) $row)->all())->toBe($decision)
        ->and(DB::table('sourced_role_grants')->get()->map(fn ($row) => (array) $row)->all())->toBe($grants)
        ->and(DB::table('security_events')->whereIn('type', ['role.granted', 'role.revoked'])->count())->toBe($events)
        ->and(paName($person))->toBe('Both Hats Renamed')
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.guardians.basics'))->toBeNull();
});

it('asks for volunteers.manage alone: a Console user without it is refused, and CRM access is not needed', function () {
    [$admin] = Mfa::signedInAdmin();
    $person = paPerson('Scoped', 'scoped@example.org');
    [$id] = paVolunteer($admin, $person);

    $participant = paSignedIn(Role::ConsoleParticipant, 'participant@example.org');
    $initiate = paSignedIn(Role::GuardianInitiate, 'initiate@example.org');
    foreach ([$participant, $initiate] as $console) {
        paEdit($console, $person, $id, ['display_name' => paChange('Scoped', 'Changed')])->assertForbidden();
    }
    expect(paName($person))->toBe('Scoped');

    // Route capability isolation: only volunteers.manage (and Console admission) is allowed, with no CRM capability at all.
    $allowed = ['console.access', 'volunteers.manage'];
    Gate::before(function ($user, string $ability) use (&$allowed): bool {
        return in_array($ability, $allowed, true);
    });
    paEdit($admin, $person, $id, ['display_name' => paChange('Scoped', 'Scoped Again')])->assertOk();
    $admin->get('/api/v1/admin/people')->assertForbidden(); // no broad CRM access came with it
    $allowed = ['console.access', 'crm.people.manage', 'crm.people.view', 'volunteers.view', 'guardians.manage'];
    paEdit($admin, $person, $id, ['display_name' => paChange('Scoped Again', 'Nope')])->assertForbidden();
    expect(paName($person))->toBe('Scoped Again');
});

// --- Shape, expected values and normalization ------------------------------------------------------------------------------------

it('refuses what is not exactly the three details as {from, to}, with 422 and nothing written', function (array $body) {
    /** @var array<string, mixed> $body */
    [$console] = Mfa::signedInAdmin();
    $person = paPerson('Shaped', 'shaped@example.org', '+1 555 010 0100');
    [$id] = paVolunteer($console, $person);
    $before = paRenames();

    $response = paEdit($console, $person, $id, $body)->assertStatus(422);

    expect($response->json('code'))->toBe('invalid_person_basics')
        ->and(paName($person))->toBe('Shaped')
        ->and(paMethods($person, 'email')[0]['value'])->toBe('shaped@example.org')
        ->and(paMethods($person, 'phone')[0]['value'])->toBe('+1 555 010 0100')
        ->and(paRenames())->toBe($before)
        ->and($response->getContent())->not->toContain('shaped@example.org');
})->with([
    'an unknown key' => [['account_email' => paChange('a@example.org', 'b@example.org')]],
    'a label' => [['primary_email_label' => 'work']],
    'nothing at all' => [[]],
    'an unknown key beside a valid one' => [['display_name' => paChange('Shaped', 'Other'), 'password' => 'x']],
    'a bare value' => [['display_name' => 'Other']],
    'an extra key in the pair' => [['display_name' => ['from' => 'Shaped', 'to' => 'Other', 'also' => 'x']]],
    'a missing from' => [['display_name' => ['to' => 'Other']]],
    'a missing to' => [['display_name' => ['from' => 'Shaped']]],
    'a null to' => [['primary_email' => ['from' => 'shaped@example.org', 'to' => null]]],
    'a blank to' => [['primary_phone' => ['from' => '+1 555 010 0100', 'to' => '   ']]],
    'an empty to' => [['primary_email' => ['from' => 'shaped@example.org', 'to' => '']]],
    'a numeric to' => [['primary_phone' => ['from' => '+1 555 010 0100', 'to' => 5550100]]],
    'a null display-name from' => [['display_name' => ['from' => null, 'to' => 'Other']]],
    'an email that is not one' => [['primary_email' => paChange('shaped@example.org', 'not-an-email')]],
    'a phone with too few digits' => [['primary_phone' => paChange('+1 555 010 0100', '12')]],
    'a name that is too long' => [['display_name' => paChange('Shaped', str_repeat('n', 256))]],
]);

it('compares every expected value exactly after the lock, and refuses the whole request with the three current values', function () {
    [$console] = Mfa::signedInAdmin();
    $person = paPerson('Compared', 'Mixed@Example.org', '+1 555 010 0100');
    [$id] = paVolunteer($console, $person);
    $current = ['display_name' => 'Compared', 'primary_email' => 'Mixed@Example.org', 'primary_phone' => '+1 555 010 0100'];
    $before = paRenames();

    foreach ([
        'case of the email' => ['primary_email' => paChange('mixed@example.org', 'x@example.org')],
        'spacing of the phone' => ['primary_phone' => paChange('+1  555 010 0100', '+1 555 010 0199')],
        'a name that differs' => ['display_name' => paChange('compared', 'Other')],
        'no primary expected where one exists' => ['primary_email' => paChange(null, 'x@example.org')],
    ] as $label => $changes) {
        $stale = paEdit($console, $person, $id, $changes)->assertStatus(409);
        expect($stale->json('code'))->toBe('stale_person_basics', $label)
            ->and($stale->json('current'))->toBe($current, $label);
    }

    // All or nothing: a matching name beside a stale email applies neither.
    $mixed = paEdit($console, $person, $id, [
        'display_name' => paChange('Compared', 'Renamed Anyway'),
        'primary_email' => paChange('mixed@example.org', 'x@example.org'),
    ])->assertStatus(409);
    expect($mixed->json('current'))->toBe($current)
        ->and(paName($person))->toBe('Compared')
        ->and(paRenames())->toBe($before)
        ->and(paMethods($person, 'email')[0]['value'])->toBe('Mixed@Example.org');

    // A name that is stale beside a matching phone writes neither either.
    paEdit($console, $person, $id, [
        'display_name' => paChange('Someone Else', 'Renamed'),
        'primary_phone' => paChange('+1 555 010 0100', '+1 555 010 0199'),
    ])->assertStatus(409);
    expect(paMethods($person, 'phone')[0]['value'])->toBe('+1 555 010 0100');
});

it('refuses an expected primary that is there to be none, and adds a primary where there was none', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = paPerson('No Contacts');
    [$id] = paVolunteer($console, $person);

    $stale = paEdit($console, $person, $id, ['primary_email' => paChange('ghost@example.org', 'new@example.org')])->assertStatus(409);
    expect($stale->json('current'))->toBe(['display_name' => 'No Contacts', 'primary_email' => null, 'primary_phone' => null])
        ->and(DB::table('contact_methods')->where('person_id', $person)->count())->toBe(0);

    $added = paEdit($console, $person, $id, ['primary_email' => paChange(null, '  New@Example.org ')])->assertOk();
    expect($added->json('basics'))->toBe(['primary_email' => 'New@Example.org', 'primary_phone' => null])
        ->and(paMethods($person, 'email'))->toBe([['value' => 'New@Example.org', 'label' => null, 'primary' => true, 'stamp' => $admin->id->value]]);
});

it('applies only what is sent, normalizes by CRM\'s rules, and treats an unchanged value as nothing', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = paPerson('Partial', 'keep@example.org', '+1 555 010 0100');
    [$id] = paVolunteer($console, $person);
    $before = paRenames();

    $response = paEdit($console, $person, $id, ['primary_phone' => paChange('+1 555 010 0100', '+1   555 010 0199')])->assertOk();
    expect($response->json('basics'))->toBe(['primary_email' => 'keep@example.org', 'primary_phone' => '+1 555 010 0199'])
        ->and(paName($person))->toBe('Partial')
        ->and(paMethods($person, 'email')[0]['stamp'])->not->toBe($admin->id->value); // the email, not sent, was not stamped

    // An unchanged value (and an unchanged name) is a no-op: no stamp moves, no event is recorded.
    $stamp = paMethods($person, 'phone')[0]['stamp'];
    $updated = DB::table('contact_methods')->where('person_id', $person)->where('kind', 'phone')->value('updated_at');
    $other = Crm::manager('other.manager@example.org');
    DB::table('contact_methods')->where('person_id', $person)->where('kind', 'phone')->update(['updated_by_account_id' => $other->accountId->value]);
    paEdit($console, $person, $id, [
        'display_name' => paChange('Partial', 'Partial'),
        'primary_phone' => paChange('+1 555 010 0199', '+1 555 010 0199'),
    ])->assertOk();
    expect(paMethods($person, 'phone')[0]['stamp'])->toBe($other->accountId->value)
        ->and(DB::table('contact_methods')->where('person_id', $person)->where('kind', 'phone')->value('updated_at'))->toBe($updated)
        ->and(paRenames())->toBe($before)
        ->and($stamp)->toBe($admin->id->value);
});

// --- Hidden-method collisions: promoted, and indistinguishable ---------------------------------------------------------------------

/**
 * One Person with a primary of a kind, a hidden NON-primary method of the same kind, and a labelled hidden method; another with
 * the same primary and nothing hidden. The same scoped edit is made on both.
 *
 * @return array{hidden: TestResponse<Response>, control: TestResponse<Response>, person: string, controlPerson: string, console: Console}
 */
function paCollide(ContactMethodKind $kind, string $primary, string $hiddenValue, string $to): array
{
    [$console] = Mfa::signedInAdmin();
    $by = Crm::manager('hidden.manager@example.org');
    $key = $kind === ContactMethodKind::Email ? 'primary_email' : 'primary_phone';

    $person = Identity::savedPerson('Has A Hidden Method')->id->value;
    $control = Identity::savedPerson('Has A Hidden Method')->id->value;
    foreach ([$person, $control] as $id) {
        Crm::method($by, PersonId::fromString($id), $kind, $primary, true);
    }
    Crm::method($by, PersonId::fromString($person), $kind, $hiddenValue, false, 'work');
    [$relationship] = paVolunteer($console, $person);
    [$controlRelationship] = paVolunteer($console, $control);

    return [
        'hidden' => paEdit($console, $person, $relationship, [$key => paChange($primary, $to)]),
        'control' => paEdit($console, $control, $controlRelationship, [$key => paChange($primary, $to)]),
        'person' => $person,
        'controlPerson' => $control,
        'console' => $console,
    ];
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function paShape(TestResponse $response): array
{
    $body = Api::map($response->json());
    $person = Api::map($body['person']);
    unset($body['relationship_id'], $person['id']);
    $body['person'] = $person;

    return $body;
}

it('promotes a hidden email that differs only by case, keeps the old primary and the label, and shows the manager exactly what a plain replacement shows', function () {
    $result = paCollide(ContactMethodKind::Email, 'first@example.org', 'Ana@Example.org', 'ana@example.org');
    $result['hidden']->assertOk();
    $result['control']->assertOk();
    $admin = Mfa::text(DB::table('accounts')->where('email', 'admin@example.org')->value('id'));

    $rows = paMethods($result['person'], 'email');
    expect($rows)->toHaveCount(2)
        ->and(array_values(array_filter($rows, fn (array $row): bool => $row['primary'])))->toBe([
            ['value' => 'ana@example.org', 'label' => 'work', 'primary' => true, 'stamp' => $admin],
        ])
        ->and(array_values(array_filter($rows, fn (array $row): bool => ! $row['primary'])))->toBe([
            ['value' => 'first@example.org', 'label' => null, 'primary' => false, 'stamp' => $admin],
        ])
        ->and(DB::table('contact_methods')->where('person_id', $result['person'])->where('kind', 'email')->pluck('search_value')->sort()->values()->all())->toBe(['ana@example.org', 'first@example.org'])
        // The manager's view is the same as for a Person with nothing hidden.
        ->and(paShape($result['hidden']))->toBe(paShape($result['control']))
        ->and($result['hidden']->getContent())->not->toContain('Ana@Example.org')->and($result['hidden']->getContent())->not->toContain('work');
});

it('promotes a hidden phone written differently, storing the requested form', function () {
    $result = paCollide(ContactMethodKind::Phone, '+1 555 010 0100', '+1 (555) 010-0199', '+1 555 010 0199');
    $result['hidden']->assertOk();
    $result['control']->assertOk();

    $primary = array_values(array_filter(paMethods($result['person'], 'phone'), fn (array $row): bool => $row['primary']));
    expect($primary)->toHaveCount(1)
        ->and($primary[0]['value'])->toBe('+1 555 010 0199')
        ->and($primary[0]['label'])->toBe('work')
        ->and(paMethods($result['person'], 'phone'))->toHaveCount(2)
        ->and(DB::table('contact_methods')->where('person_id', $result['person'])->where('kind', 'phone')->where('value', '+1 (555) 010-0199')->exists())->toBeFalse()
        ->and(paShape($result['hidden']))->toBe(paShape($result['control']))
        ->and($result['hidden']->getContent())->not->toContain('(555)');
});

it('promotes a hidden method whose value is exactly the one asked for', function () {
    $result = paCollide(ContactMethodKind::Email, 'first@example.org', 'same@example.org', 'same@example.org');
    $result['hidden']->assertOk();
    $result['control']->assertOk();

    $rows = paMethods($result['person'], 'email');
    expect($rows)->toHaveCount(2)
        ->and(array_values(array_filter($rows, fn (array $row): bool => $row['primary']))[0]['value'])->toBe('same@example.org')
        ->and(paShape($result['hidden']))->toBe(paShape($result['control']));
});

it('never answers duplicate_contact_method, and leaves a later read identical with or without a hidden match', function () {
    $result = paCollide(ContactMethodKind::Email, 'first@example.org', 'Hidden@Example.org', 'hidden@example.org');
    $console = $result['console'];

    expect($result['hidden']->status())->toBe(200)
        ->and($result['hidden']->json('code'))->toBeNull();
    $read = $console->get(PA_API.'/volunteers/'.$result['person'].'/management')->assertOk();
    $controlRead = $console->get(PA_API.'/volunteers/'.$result['controlPerson'].'/management')->assertOk();
    expect(paShape($read))->toBe(paShape($controlRead))
        ->and($read->json('basics'))->toBe(['primary_email' => 'hidden@example.org', 'primary_phone' => null]);
});

it('stops matching the demoted former primary in the directory search after a hidden promotion', function () {
    $result = paCollide(ContactMethodKind::Email, 'former@example.org', 'Promoted@Example.org', 'promoted@example.org');
    $result['hidden']->assertOk();
    $console = $result['console'];

    $byFormer = $console->get(PA_API.'/volunteers?q=former@example.org')->assertOk();
    $byNew = $console->get(PA_API.'/volunteers?q=promoted@example.org')->assertOk();
    expect(paRows($byFormer))->toBe([])
        ->and($byFormer->json('meta.total'))->toBe(0)
        ->and(collect(paPersonIds($byNew))->sort()->values()->all())
        ->toBe(collect([$result['person'], $result['controlPerson']])->sort()->values()->all()); // the same edit was made on both

    // ...yet the exact-match lookup still finds the retained former primary, but only by its exact value (P4): the Person who
    // kept a hidden method is found, and the control, whose former primary was replaced in place, is not.
    $lookup = $console->get(PA_API.'/volunteers/candidates?q=former@example.org')->assertOk();
    expect(paPersonIds($lookup))->toBe([$result['person']]);
});

// --- Reads, directory search and lookup ------------------------------------------------------------------------------------------

it('shows the Person\'s primary email and phone beside the name, and nothing else of their contact data', function () {
    [$console] = Mfa::signedInAdmin();
    $by = Crm::manager('crm.manager@example.org');
    $person = paPerson('Visible', 'primary@example.org', '+1 555 010 0100');
    Crm::method($by, PersonId::fromString($person), ContactMethodKind::Email, 'secondary@example.org', false, 'private');
    app(UpdatePerson::class)($by, PersonId::fromString($person), null, new ProfileChanges(['how_we_know' => 'Marker profile text', 'affiliation' => 'Marker affiliation']));
    $tag = app(CreateTag::class)($by, 'Marker tag')->tag->id;
    app(SetPersonTags::class)($by, PersonId::fromString($person), [$tag]);
    [$id] = paVolunteer($console, $person);

    $record = $console->get(PA_API."/volunteers/{$person}")->assertOk();
    $management = $console->get(PA_API."/volunteers/{$person}/management")->assertOk();
    $directory = $console->get(PA_API.'/volunteers')->assertOk();

    foreach ([$record, $management] as $response) {
        expect($response->json('basics'))->toBe(['primary_email' => 'primary@example.org', 'primary_phone' => '+1 555 010 0100']);
    }
    expect($directory->json('data.0.basics'))->toBe(['primary_email' => 'primary@example.org', 'primary_phone' => '+1 555 010 0100']);
    foreach ([$record, $management, $directory] as $response) {
        expect($response->getContent())->not->toContain('secondary@example.org')
            ->and($response->getContent())->not->toContain('private')
            ->and($response->getContent())->not->toContain('Marker')
            ->and($response->getContent())->not->toContain('admin@example.org')
            ->and($response->getContent())->not->toContain('capabilit')
            ->and($response->getContent())->not->toContain('platform_administrator')
            ->and($response->getContent())->not->toContain('guardian-');
    }
    expect($id)->not->toBe('');
});

it('gives Guardians basics on the view, never on the management read of a type that does not edit them', function () {
    [$console] = Mfa::signedInAdmin();
    $person = paPerson('Guardian Basics', 'guard@example.org', '+1 555 010 0100');
    $console->post(PA_API.'/guardians', ['person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer'])->assertCreated();

    $record = $console->get(PA_API."/guardians/{$person}")->assertOk();
    $management = $console->get(PA_API."/guardians/{$person}/management")->assertOk();
    $directory = $console->get(PA_API.'/guardians')->assertOk();

    expect($record->json('basics'))->toBe(['primary_email' => 'guard@example.org', 'primary_phone' => '+1 555 010 0100'])
        ->and($directory->json('data.0.basics'))->toBe(['primary_email' => 'guard@example.org', 'primary_phone' => '+1 555 010 0100'])
        ->and($management->json('basics'))->toBeNull() // Guardians do not edit basics: management carries none (F13)
        ->and(paKeys($management))->toEqualCanonicalizing([
            'relationship_id', 'type', 'person', 'status', 'revision', 'transitions', 'fields', 'default_role', 'basics',
        ]);
});

it('keeps the management read available to a manage-only holder, without the directory or the record', function () {
    [$console] = Mfa::signedInAdmin();
    $person = paPerson('Manage Only', 'only@example.org');
    paVolunteer($console, $person);

    Gate::before(fn ($user, string $ability): bool => in_array($ability, ['console.access', 'volunteers.manage'], true));

    $console->get(PA_API."/volunteers/{$person}/management")->assertOk()->assertJsonPath('basics.primary_email', 'only@example.org');
    $console->get(PA_API.'/volunteers')->assertForbidden();
    $console->get(PA_API."/volunteers/{$person}")->assertForbidden();
});

it('searches names and the PRIMARY contact values of this type\'s People only, and never a hidden method', function () {
    [$console] = Mfa::signedInAdmin();
    $by = Crm::manager('crm.manager@example.org');
    $ada = paPerson('Ada Zephyr', 'ada.primary@example.org', '+1 (555) 010-0100');
    Crm::method($by, PersonId::fromString($ada), ContactMethodKind::Email, 'ada.hidden@example.org', false);
    Crm::method($by, PersonId::fromString($ada), ContactMethodKind::Phone, '+44 20 7946 0958', false);
    $grace = paPerson('Grace Quill', 'grace@example.org', '+1 555 010 0200');
    $outsider = paPerson('Not A Volunteer', 'outsider.volunteer@example.org', '+1 555 010 0300');
    paVolunteer($console, $ada);
    paVolunteer($console, $grace, 'pending');
    $console->post(PA_API.'/guardians', ['person_id' => $outsider, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer'])->assertCreated();

    $people = fn (string $query): array => paNames($console->get(PA_API.'/volunteers?q='.urlencode($query))->assertOk());

    expect($people('ada.primary'))->toBe(['Ada Zephyr'])             // a primary email fragment
        ->and($people('GRACE@EXAMPLE'))->toBe(['Grace Quill'])        // case-insensitive
        ->and($people('555010'))->toBe(['Ada Zephyr', 'Grace Quill']) // phone digits, 3 or more
        ->and($people('100100'))->toBe(['Ada Zephyr'])                // a run of the primary phone's digits
        ->and($people('Quill'))->toBe(['Grace Quill'])                // names still work
        ->and($people('ada.hidden'))->toBe([])                        // a non-primary email never matches
        ->and($people('7946'))->toBe([])                              // nor a non-primary phone
        ->and($people('01'))->toBe([])                                // under three digits is no phone search
        ->and($people('outsider.volunteer'))->toBe([])                // a Person of another type only
        ->and($people('nothing-like-this'))->toBe([]);
    // The hidden value is indistinguishable from a value no one holds.
    expect($console->get(PA_API.'/volunteers?q=ada.hidden')->json())->toBe($console->get(PA_API.'/volunteers?q=zzz.nobody')->json());

    // Ordering, paging and the status filter are unchanged.
    $all = $console->get(PA_API.'/volunteers?per_page=1&page=2')->assertOk();
    expect(paNames($all))->toBe(['Grace Quill'])
        ->and($all->json('meta'))->toBe(['page' => 2, 'per_page' => 1, 'total' => 2])
        ->and($people('555010'))->toBe(['Ada Zephyr', 'Grace Quill']);
    expect(paNames($console->get(PA_API.'/volunteers?status=pending&q=555')))->toBe(['Grace Quill']);
});

it('looks candidates up by exact address, exact phone digits, or name, and returns no contact value', function () {
    [$console] = Mfa::signedInAdmin();
    $by = Crm::manager('crm.manager@example.org');
    $ada = paPerson('Ada Lookup', 'ada@lookup.org', '+1 555 010 0100');
    Crm::method($by, PersonId::fromString($ada), ContactMethodKind::Email, 'ada.second@lookup.org', false);
    Crm::method($by, PersonId::fromString($ada), ContactMethodKind::Phone, '+44 20 7946 0958', false);
    $already = paPerson('Already Volunteer', 'already@lookup.org');
    $guardian = paPerson('Guardian Only', 'guardian.only@lookup.org');
    paVolunteer($console, $already, 'pending');
    $console->post(PA_API.'/guardians', ['person_id' => $guardian, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer'])->assertCreated();
    $login = Identity::savedActiveAccount('login.only@example.org', name: 'Has A Login');

    $lookup = fn (string $query): TestResponse => $console->get(PA_API.'/volunteers/candidates?q='.urlencode($query));
    $names = fn (TestResponse $response): array => paNames($response);

    $byEmail = $lookup('ADA@lookup.org')->assertOk();
    expect($names($byEmail))->toBe(['Ada Lookup'])
        ->and($byEmail->json('data.0.matched_on'))->toBe('email')
        ->and($byEmail->json('data.0.status'))->toBeNull()
        ->and($names($lookup('ada.second@lookup.org')))->toBe(['Ada Lookup']) // a non-primary email matches exactly (P4)
        ->and($names($lookup('ada@lookup')))->toBe([])                       // a partial address matches nothing
        ->and($names($lookup('@lookup.org')))->toBe([])
        ->and($names($lookup('login.only@example.org')))->toBe([]);           // Account logins are never searched
    $byPhone = $lookup('+1 (555) 010-0100')->assertOk();
    expect($names($byPhone))->toBe(['Ada Lookup'])
        ->and($byPhone->json('data.0.matched_on'))->toBe('phone')
        ->and($names($lookup('+44 20 7946 0958')))->toBe(['Ada Lookup'])
        ->and($names($lookup('555 010')))->toBe([])                              // under seven digits, and not a name either
        ->and($names($lookup('15550100100')))->toBe(['Ada Lookup'])              // the whole number's digits
        ->and($names($lookup('5550100')))->toBe([]);                             // part of it matches nothing
    $byName = $lookup('lookup')->assertOk();
    expect($names($byName))->toBe(['Ada Lookup'])
        ->and($names($lookup('already')))->toBe(['Already Volunteer'])
        ->and($lookup('already')->json('data.0.status'))->toBe('pending')  // this type only
        ->and($lookup('guardian only')->json('data.0.status'))->toBeNull()  // a Guardian is not a Volunteer here
        ->and($lookup('Has A Login')->json('data.0.matched_on'))->toBe('display_name');

    foreach ([$byEmail, $byPhone, $byName, $lookup('guardian only')] as $response) {
        expect(paKeys($response, 'data.0'))->toBe(['person', 'matched_on', 'status'])
            ->and(paKeys($response, 'data.0.person'))->toBe(['id', 'display_name'])
            ->and($response->getContent())->not->toContain('@')
            ->and($response->getContent())->not->toContain('555')
            ->and($response->getContent())->not->toContain('guardian"')
            ->and($response->json('truncated'))->toBeFalse();
    }
    expect($login->email->value)->toBe('login.only@example.org');
});

it('bounds the lookup at ten candidates with a truncated flag, and refuses a query outside 3 to 255 characters', function () {
    [$console] = Mfa::signedInAdmin();
    foreach (range(1, 12) as $n) {
        Identity::savedPerson(sprintf('Crowd Member %02d', $n));
    }

    $many = $console->get(PA_API.'/volunteers/candidates?q=Crowd')->assertOk();
    expect($many->json('data'))->toHaveCount(10)->and($many->json('truncated'))->toBeTrue();
    $console->get(PA_API.'/volunteers/candidates?q=ab')->assertStatus(422)->assertJson(['code' => 'invalid_relationship_query']);
    $console->get(PA_API.'/volunteers/candidates?q='.str_repeat('x', 256))->assertStatus(422);
    $console->get(PA_API.'/volunteers/candidates')->assertStatus(422);
    // Two People with one name are both returned and never merged: the page asks for an exact contact instead (WP5).
    Identity::savedPerson('Twin Name');
    Identity::savedPerson('Twin Name');
    expect($console->get(PA_API.'/volunteers/candidates?q=Twin Name')->json('data'))->toHaveCount(2);
});

it('lets only a holder of the manage capability look People up', function () {
    $participant = paSignedIn(Role::ConsoleParticipant, 'participant@example.org');
    $participant->get(PA_API.'/volunteers/candidates?q=anything')->assertForbidden();
    [$guardian] = Mfa::signedIn('guardian.full@example.org');
    $guardian->get(PA_API.'/volunteers/candidates?q=anything')->assertOk(); // guardian-full holds volunteers.manage

    [$admin] = Mfa::signedInAdmin();
    Gate::before(fn ($user, string $ability): bool => in_array($ability, ['console.access', 'volunteers.view'], true));
    $admin->get(PA_API.'/volunteers/candidates?q=anything')->assertForbidden(); // the view capability does not look anyone up
});

// --- Intake -----------------------------------------------------------------------------------------------------------------------

it('attaches an existing Person only on explicit confirmation, leaves them as they are, and names the operator', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = paPerson('Existing', 'existing@example.org', '+1 555 010 0100');
    $before = ['name' => paName($person), 'email' => paMethods($person, 'email'), 'phone' => paMethods($person, 'phone')];

    $console->post(PA_API.'/volunteers', ['person_id' => $person, 'status' => 'active'])->assertStatus(422)->assertJson(['code' => 'confirmation_required']);
    $console->post(PA_API.'/volunteers', ['person_id' => $person, 'confirm_existing_person' => false, 'status' => 'active'])->assertStatus(422);
    expect(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe(0);

    $created = $console->post(PA_API.'/volunteers', ['person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active'])->assertCreated();

    expect($created->json('basics'))->toBe(['primary_email' => 'existing@example.org', 'primary_phone' => '+1 555 010 0100'])
        ->and(['name' => paName($person), 'email' => paMethods($person, 'email'), 'phone' => paMethods($person, 'phone')])->toBe($before)
        ->and(DB::table('person_relationships')->value('created_by'))->toBe($admin->personId->value)
        ->and(DB::table('person_relationship_status_changes')->value('changed_by'))->toBe($admin->personId->value);
});

it('lets a Volunteer manager attach a Person who is a Guardian and holds an Account, which P8 accepts', function () {
    [$console] = Mfa::signedInAdmin();
    $account = Identity::savedActiveAccount('holder@example.org', name: 'Account Holder');
    $console->post(PA_API.'/guardians', ['person_id' => $account->personId->value, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer'])->assertCreated();

    $console->post(PA_API.'/volunteers', ['person_id' => $account->personId->value, 'confirm_existing_person' => true, 'status' => 'pending'])->assertCreated();

    expect(DB::table('person_relationships')->where('person_id', $account->personId->value)->count())->toBe(2);
});

it('registers a new Person through CRM with primary methods, in one transaction with the relationship, and creates no Account, Membership or role', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $people = DB::table('people')->count();
    $accounts = DB::table('accounts')->count();
    $assignments = DB::table('role_assignments')->count();
    $events = DB::table('security_events')->count();

    $created = $console->post(PA_API.'/volunteers', [
        'new_person' => ['display_name' => '  Brand New  ', 'email' => 'brand.new@example.org', 'phone' => '+1 555 010 0150'],
        'status' => 'pending',
        'fields' => ['interests' => 'Gardening'],
    ])->assertCreated();

    $person = Mfa::text($created->json('person.id'));
    expect($created->json('person.display_name'))->toBe('Brand New')
        ->and($created->json('basics'))->toBe(['primary_email' => 'brand.new@example.org', 'primary_phone' => '+1 555 010 0150'])
        ->and($created->json('fields.interests'))->toBe('Gardening')
        ->and(DB::table('people')->count())->toBe($people + 1)
        ->and(paMethods($person, 'email'))->toBe([['value' => 'brand.new@example.org', 'label' => null, 'primary' => true, 'stamp' => $admin->id->value]])
        ->and(paMethods($person, 'phone')[0]['stamp'])->toBe($admin->id->value)
        ->and(DB::table('person_relationships')->where('person_id', $person)->value('created_by'))->toBe($admin->personId->value)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe(1)
        ->and(DB::table('accounts')->count())->toBe($accounts)
        ->and(DB::table('role_assignments')->count())->toBe($assignments)
        ->and(DB::table('membership_grants')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(DB::table('security_events')->count())->toBe($events); // CRM data is not a security event

    $minimal = $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Name Only'], 'status' => 'active'])->assertCreated();
    expect($minimal->json('basics'))->toBe(['primary_email' => null, 'primary_phone' => null])
        ->and(DB::table('contact_methods')->where('person_id', $minimal->json('person.id'))->count())->toBe(0);
});

it('registers nothing when a detail of the new Person is invalid, and refuses unknown keys and both ways at once', function () {
    [$console] = Mfa::signedInAdmin();
    $existing = paPerson('Existing Too');
    $people = DB::table('people')->count();

    foreach ([
        ['new_person' => ['display_name' => 'Bad Email', 'email' => 'nope'], 'field' => 'new_person.email'],
        ['new_person' => ['display_name' => 'Bad Phone', 'phone' => '12'], 'field' => 'new_person.phone'],
        ['new_person' => ['display_name' => ''], 'field' => 'new_person.display_name'],
        ['new_person' => ['display_name' => 'Extra', 'role' => 'admin'], 'field' => 'new_person.role'],
        ['new_person' => ['display_name' => 'Number', 'email' => 5], 'field' => 'new_person.email'],
        ['new_person' => ['email' => 'no.name@example.org'], 'field' => 'new_person.display_name'],
    ] as $case) {
        $response = $console->post(PA_API.'/volunteers', ['new_person' => $case['new_person'], 'status' => 'active'])->assertStatus(422);
        expect($response->json('code'))->toBe('invalid_person_basics')
            ->and(paKeys($response, 'errors'))->toBe([$case['field']]);
    }
    $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Both'], 'person_id' => $existing, 'confirm_existing_person' => true, 'status' => 'active'])
        ->assertStatus(422)->assertJson(['code' => 'invalid_relationship_query']);
    $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Wrong Status'], 'status' => 'retired'])->assertStatus(422);

    expect(DB::table('people')->count())->toBe($people)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('contact_methods')->count())->toBe(0);
});

it('gives CRM\'s duplicate advice for a new Person, never merges, and proceeds only when told the Person is distinct', function () {
    [$console] = Mfa::signedInAdmin();
    $by = Crm::manager('crm.manager@example.org');
    $namesake = paPerson('Dana Duplicate');
    $mailbox = paPerson('Someone Else Entirely', 'shared@example.org');
    Identity::savedActiveAccount('login.address@example.org', name: 'Login Person');
    $people = DB::table('people')->count();

    $byName = $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'dana duplicate'], 'status' => 'active'])->assertStatus(409);
    expect($byName->json('code'))->toBe('possible_duplicate')
        ->and($byName->json('candidates'))->toBe([['id' => $namesake, 'display_name' => 'Dana Duplicate', 'matched_on' => ['display_name']]]);
    $byEmail = $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Fresh Name', 'email' => 'SHARED@example.org'], 'status' => 'active'])->assertStatus(409);
    expect($byEmail->json('candidates'))->toBe([['id' => $mailbox, 'display_name' => 'Someone Else Entirely', 'matched_on' => ['email']]])
        ->and($byEmail->getContent())->not->toContain('shared@example.org')
        ->and(DB::table('people')->count())->toBe($people)
        ->and(DB::table('person_relationships')->count())->toBe(0);

    // An Account's login address is never advice (CRM may not read Account storage).
    $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Another Person', 'email' => 'login.address@example.org'], 'status' => 'active'])->assertCreated();

    $distinct = $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Dana Duplicate'], 'confirm_distinct' => true, 'status' => 'active'])->assertCreated();
    expect($distinct->json('person.id'))->not->toBe($namesake)
        ->and(DB::table('people')->where('display_name', 'Dana Duplicate')->count())->toBe(2) // two People, never merged
        ->and($by->accountId->value)->not->toBe('');
    $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'Strict'], 'confirm_distinct' => 'yes', 'status' => 'active'])->assertStatus(422);
});

it('rolls the new Person back with the relationship when the intake fails after the Person was registered', function () {
    [$console] = Mfa::signedInAdmin();
    $people = DB::table('people')->count();
    $inner = app(SecurityEventWriter::class);
    app()->instance(SecurityEventWriter::class, new class($inner) implements SecurityEventWriter
    {
        public function __construct(private SecurityEventWriter $inner) {}

        public function append(SecurityEvent $event): void
        {
            if ($event->type === 'role.granted') {
                throw new RuntimeException('audit failed');
            }
            $this->inner->append($event);
        }
    });

    // A Guardian recognition with a grant records `role.granted` last: its failure must take the Person and methods with it.
    $console->post(PA_API.'/guardians', [
        'new_person' => ['display_name' => 'Doomed Person', 'email' => 'doomed@example.org'], 'status' => 'active', 'default_role' => 'grant',
    ])->assertStatus(500);

    expect(DB::table('people')->count())->toBe($people)
        ->and(DB::table('contact_methods')->count())->toBe(0)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

// --- What a scoped edit can never reach --------------------------------------------------------------------------------------------

it('leaves the Account, credentials, sessions, roles, grants, Guardian state and every other CRM record exactly as they were', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target.login@example.org', name: 'Target Person');
    Access::grant($target, Role::GuardianFull);
    Mfa::enroll($target);
    $targetConsole = new Console;
    $targetConsole->loginWithMfa('target.login@example.org', Identity::PASSWORD)->assertOk();
    $person = $target->personId->value;
    $by = Crm::manager('crm.manager@example.org');
    Crm::email($by, $target->personId, 'contact.email@example.org');
    Crm::phone($by, $target->personId, '+1 555 010 0100');
    Crm::method($by, $target->personId, ContactMethodKind::Email, 'other.contact@example.org', false, 'other');
    app(UpdatePerson::class)($by, $target->personId, null, new ProfileChanges(['how_we_know' => 'Marker', 'affiliation' => 'Marker']));
    app(SetPersonTags::class)($by, $target->personId, [app(CreateTag::class)($by, 'Marker')->tag->id]);
    $console->post(PA_API.'/guardians', ['person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant', 'fields' => ['stewardship' => 'Choir']])->assertCreated();
    [$id] = paVolunteer($console, $person);

    $snapshot = function () use ($person, $target): array {
        $account = DB::table('accounts')->where('person_id', $person)->first();
        assert($account !== null);
        $rows = fn (string $table, string $column, string $value): array => DB::table($table)->where($column, $value)->orderBy($column)->get()->map(fn ($row) => (array) $row)->all();
        $relationship = DB::table('person_relationships')->where('person_id', $person)->where('relationship_type', 'guardian')->first();
        assert($relationship !== null);

        return [
            'account' => (array) $account,
            'totp' => $rows('account_totp_factors', 'account_id', Mfa::text($account->id)),
            'recovery' => $rows('account_recovery_codes', 'account_id', Mfa::text($account->id)),
            'sessions' => DB::table('sessions')->where('user_id', Mfa::text($account->id))->orderBy('id')->get(['id', 'user_id', 'payload', 'last_activity'])->map(fn ($row) => (array) $row)->all(),
            'roles' => $rows('role_assignments', 'person_id', $person),
            'grants' => $rows('sourced_role_grants', 'person_id', $person),
            'guardian' => (array) $relationship,
            'guardian_fields' => $rows('person_relationship_field_values', 'relationship_id', Mfa::text($relationship->id)),
            'guardian_history' => $rows('person_relationship_status_changes', 'relationship_id', Mfa::text($relationship->id)),
            'decision' => $rows('person_relationship_role_provisions', 'relationship_id', Mfa::text($relationship->id)),
            'profile' => $rows('contact_profiles', 'person_id', $person),
            'tags' => $rows('contact_tag_assignments', 'person_id', $person),
            'interactions' => $rows('contact_interactions', 'person_id', $person),
            'membership' => $rows('membership_grants', 'person_id', $person),
            'other_method' => DB::table('contact_methods')->where('person_id', $person)->where('label', 'other')->get()->map(fn ($row) => (array) $row)->all(),
            'capabilities' => app(Authorizer::class)->capabilitiesOf(Access::actorFor($target)),
            'login' => Mfa::text($account->email),
        ];
    };
    $before = $snapshot();
    $events = DB::table('security_events')->whereNotIn('type', ['person.renamed'])->count();

    paEdit($console, $person, $id, [
        'display_name' => paChange('Target Person', 'Target Renamed'),
        'primary_email' => paChange('contact.email@example.org', 'changed.contact@example.org'),
        'primary_phone' => paChange('+1 555 010 0100', '+1 555 010 0199'),
    ])->assertOk();

    expect($snapshot())->toBe($before)
        ->and($before['login'])->toBe('target.login@example.org')
        ->and(Mfa::text(DB::table('accounts')->where('person_id', $person)->value('email')))->toBe('target.login@example.org')
        ->and(DB::table('security_events')->whereNotIn('type', ['person.renamed'])->count())->toBe($events)
        ->and(DB::table('security_events')->whereIn('type', ['role.granted', 'role.revoked'])->count())->toBe(1) // the one Guardian grant
        ->and(paName($person))->toBe('Target Renamed');
    $targetConsole->me()->assertOk(); // the Person's own session still stands
    expect($admin->id->value)->not->toBe('');
});

it('grants and withdraws no role through any Volunteer operation, intake of a new Person included', function () {
    [$console] = Mfa::signedInAdmin();
    $existing = paPerson('Existing Volunteer', 'ev@example.org');
    $events = DB::table('security_events')->whereIn('type', ['role.granted', 'role.revoked'])->count();
    $assignments = DB::table('role_assignments')->count();

    [$id] = paVolunteer($console, $existing, 'pending');
    $new = $console->post(PA_API.'/volunteers', ['new_person' => ['display_name' => 'New Volunteer', 'email' => 'nv@example.org'], 'status' => 'active'])->assertCreated();
    $console->put(PA_API."/volunteers/{$existing}/status", ['relationship_id' => $id, 'revision' => 1, 'status' => 'active'])->assertOk();
    $console->patch(PA_API."/volunteers/{$existing}/fields", ['relationship_id' => $id, 'revision' => 2, 'fields' => ['interests' => 'Gardening']])->assertOk();
    paEdit($console, $existing, $id, ['display_name' => paChange('Existing Volunteer', 'Existing Renamed')])->assertOk();
    $console->delete(PA_API.'/volunteers/'.Api::string($new->json('person.id')).'?relationship_id='.Api::string($new->json('relationship_id')).'&revision=1')->assertNoContent();
    $console->delete(PA_API."/volunteers/{$existing}?relationship_id={$id}&revision=3")->assertNoContent();

    expect(DB::table('security_events')->whereIn('type', ['role.granted', 'role.revoked'])->count())->toBe($events)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(DB::table('role_assignments')->count())->toBe($assignments)
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0);
});
