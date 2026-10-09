<?php

declare(strict_types=1);

use App\Modules\Relationships\Application\QualifyingRelationships;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Shared\Domain\PersonId;
use Illuminate\Routing\Route;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Resources;
use Tests\Support\SourceScan;

it('registers the third type through the catalog and generates its routes', function () {
    $catalog = app(RelationshipCatalog::class);
    expect(array_map(fn ($definition) => $definition->type->key, $catalog->all()))->toBe(['volunteer', 'apprentice'])
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.apprentices.store'))->not->toBeNull()
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.guardians.store'))->toBeNull();

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
