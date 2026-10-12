<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Relationships\Application\QualifyingRelationships;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Resources\Application\AudienceEligibility;
use App\Shared\Domain\PersonId;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Resources;
use Tests\Support\SourceScan;

it('registers the third type through the catalog and generates its routes', function () {
    $catalog = app(RelationshipCatalog::class);
    expect(array_map(fn ($definition) => $definition->type->key, $catalog->all()))->toBe(['volunteer', 'apprentice'])
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.apprentices.store'))->not->toBeNull()
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.guardians.store'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.apprentices.default-role'))->toBeNull();

    $delete = app('router')->getRoutes()->getByName('api.v1.admin.relationships.apprentices.destroy');
    $intake = app('router')->getRoutes()->getByName('api.v1.admin.relationships.apprentices.store');
    assert($delete instanceof Route && $intake instanceof Route);
    expect($delete->gatherMiddleware())->toContain('can:guardians.manage', 'security.verified');
    expect($intake->gatherMiddleware())->toContain('can:guardians.manage')
        ->and($intake->gatherMiddleware())->not->toContain('security.verified');
});

it('runs the third type through intake, lifecycle, manage-only fields and qualification', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Apprentice Ada');
    $created = $console->post('/api/v1/admin/relationships/apprentices', [
        'person_id' => $person->id->value,
        'confirm_existing_person' => true,
        'status' => 'prospective',
        'fields' => ['mentor' => 'Grace'],
    ])->assertCreated();
    expect($created->json('fields.mentor'))->toBe('Grace')
        ->and($person->displayName)->toBe('Apprentice Ada');

    $record = $console->get('/api/v1/admin/relationships/apprentices/'.$person->id->value)->assertOk();
    expect($record->json('fields'))->not->toHaveKey('mentor')
        ->and($record->json('history'))->toHaveCount(1);

    $console->put('/api/v1/admin/relationships/apprentices/'.$person->id->value.'/status', [
        'relationship_id' => $created->json('relationship_id'),
        'revision' => 1,
        'status' => 'placed',
    ])->assertOk()->assertJson(['status' => 'placed']);

    $qualifying = array_map(
        fn ($type) => $type->key,
        app(QualifyingRelationships::class)(PersonId::fromString($person->id->value)),
    );
    expect($qualifying)->toBe(['apprentice']);

    $types = $console->get('/api/v1/admin/relationship-types')->assertOk()->json('data');
    assert(is_array($types));
    expect(array_column($types, 'type'))->toBe(['volunteer', 'apprentice']);
});

it('gives a guardian the third type\'s view and refuses its manage capability', function () {
    [$console] = Resources::signedInGuardian();
    $person = Identity::savedPerson('Seen Apprentice');
    $console->get('/api/v1/admin/relationships/apprentices')->assertOk();
    $console->post('/api/v1/admin/relationships/apprentices', [
        'person_id' => $person->id->value,
        'confirm_existing_person' => true,
        'status' => 'prospective',
    ])->assertForbidden();
});

it('is unknown to Resources', function () {
    $hits = [];
    foreach (SourceScan::phpFiles(['app/Modules/Resources']) as $path) {
        if (str_contains(SourceScan::code(SourceScan::read($path)), 'apprentice')) {
            $hits[] = SourceScan::relative($path);
        }
    }

    expect($hits)->toBe([]);
});

it('offers a type with neither People feature nothing of the scoped People access', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Plain Apprentice');
    $created = $console->post('/api/v1/admin/relationships/apprentices', [
        'person_id' => $person->id->value, 'confirm_existing_person' => true, 'status' => 'prospective',
    ])->assertCreated();

    // No basics route is generated, and the views carry no basic details: both come from the definition's features.
    expect(app('router')->getRoutes()->getByName('api.v1.admin.relationships.apprentices.basics'))->toBeNull()
        ->and($created->json('basics'))->toBeNull()
        ->and($console->get('/api/v1/admin/relationships/apprentices/'.$person->id->value)->json('basics'))->toBeNull()
        ->and($console->get('/api/v1/admin/relationships/apprentices')->json('data.0.basics'))->toBeNull();
    $console->patch('/api/v1/admin/relationships/apprentices/'.$person->id->value.'/basics', [
        'relationship_id' => $created->json('relationship_id'),
        'display_name' => ['from' => 'Plain Apprentice', 'to' => 'Edited'],
    ])->assertStatus(404);
    expect(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Plain Apprentice');

    // A type without `people.create_person` records no new Person, and a type with `people.lookup` can look People up.
    $people = DB::table('people')->count();
    $console->post('/api/v1/admin/relationships/apprentices', [
        'new_person' => ['display_name' => 'Should Not Exist'], 'status' => 'prospective',
    ])->assertStatus(422)->assertJson(['code' => 'new_person_not_supported']);
    expect(DB::table('people')->count())->toBe($people);
    $console->get('/api/v1/admin/relationships/apprentices/candidates?q=Plain')->assertOk()->assertJsonPath('data.0.person.display_name', 'Plain Apprentice');
});

it('lists a qualifying apprentice relationship yet gives it no audience: a type with no audience row confers no eligibility (fail closed)', function () {
    [$console] = Mfa::signedInAdmin();
    $account = Identity::savedActiveAccount('addie.apprentice@example.org', name: 'Addie Apprentice');
    Access::grant($account, Role::ConsoleParticipant);
    $created = $console->post('/api/v1/admin/relationships/apprentices', [
        'person_id' => $account->personId->value, 'confirm_existing_person' => true, 'status' => 'prospective',
    ])->assertCreated();
    $console->put('/api/v1/admin/relationships/apprentices/'.$account->personId->value.'/status', [
        'relationship_id' => $created->json('relationship_id'), 'revision' => 1, 'status' => 'placed',
    ])->assertOk();

    // The catalog says the Person qualifies as an apprentice; Resources maps no audience to that type, so eligibility is empty.
    expect(array_map(fn ($type) => $type->key, app(QualifyingRelationships::class)($account->personId)))->toBe(['apprentice'])
        ->and(app(AudienceEligibility::class)(Access::actorFor($account))->values())->toBe([]);
});
