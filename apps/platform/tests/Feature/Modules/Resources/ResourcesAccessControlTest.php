<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Resources\Application\BrowseResourceLibrary;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\CreateCategory;
use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Application\DeleteCategory;
use App\Modules\Resources\Application\DeletePack;
use App\Modules\Resources\Application\GetManagedCard;
use App\Modules\Resources\Application\GetManagedPack;
use App\Modules\Resources\Application\GetResourcePack;
use App\Modules\Resources\Application\ListCategories;
use App\Modules\Resources\Application\PageManagedPacks;
use App\Modules\Resources\Application\PreviewPack;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\RenameCategory;
use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Application\ReorderCategories;
use App\Modules\Resources\Application\ReorderPacks;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UnpublishPack;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Application\UpdatePack;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\ManagedPackFilter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Resources;
use Tests\Support\SourceScan;

/*
 * Who may use the Resources API (ADR 0037), at each layer on its own. The role catalog has no role that holds one of
 * resources.view / resources.manage without the other (a Guardian holds both, deliberately), so, as for CRM and Discussions, ONE
 * layer is moved at a time through the platform's own seam, the Laravel Gate the route `can:` middleware asks, keeping every
 * other layer real. Runs on MariaDB and PostgreSQL.
 */

/** Makes the Gate answer `$answer` for these abilities only; every other ability still asks the real Authorizer. */
function resourcesGate(bool $answer, string ...$abilities): void
{
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => in_array($ability, $abilities, true) ? $answer : null);
}

/**
 * @param  TestResponse<Response>  $response
 */
function idOf(TestResponse $response): string
{
    $id = $response->json('id');
    assert(is_string($id));

    return $id;
}

/**
 * @return array{pack: string, card: string, category: string, spare: string, spareCard: string}
 */
function resourcesFixture(Console $console): array
{
    $category = idOf($console->post('/api/v1/admin/resources/categories', ['name' => 'Guides'])->assertCreated());
    $pack = idOf($console->post('/api/v1/admin/resources/packs', ['title' => 'Pack', 'category_id' => $category])->assertCreated());
    $card = idOf($console->post("/api/v1/admin/resources/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Card', 'content' => Resources::doc('Words')])->assertCreated());
    $console->post("/api/v1/admin/resources/packs/{$pack}/cards/{$card}/publish")->assertOk();
    $console->put("/api/v1/admin/resources/packs/{$pack}/audiences", ['audiences' => ['guardian']])->assertOk();
    $console->post("/api/v1/admin/resources/packs/{$pack}/publish")->assertOk();
    $spare = idOf($console->post('/api/v1/admin/resources/packs', ['title' => 'Spare'])->assertCreated());
    $spareCard = idOf($console->post("/api/v1/admin/resources/packs/{$spare}/cards", ['type' => 'basic', 'title' => 'Spare card', 'content' => Resources::doc('Spare')])->assertCreated());

    return ['pack' => $pack, 'card' => $card, 'category' => $category, 'spare' => $spare, 'spareCard' => $spareCard];
}

/**
 * Every operation: method, path, a body that would be valid, and which capability it asks for.
 *
 * @param  array{pack: string, card: string, category: string, spare: string, spareCard: string}  $f
 * @return list<array{string, string, array<string, mixed>, string}>
 */
function resourcesOperations(array $f): array
{
    $m = '/api/v1/admin/resources';
    $l = '/api/v1/admin/resource-library';

    return [
        ['GET', "{$m}/categories", [], 'manage'],
        ['POST', "{$m}/categories", ['name' => 'Another'], 'manage'],
        ['PUT', "{$m}/categories/order", ['ids' => [$f['category']]], 'manage'],
        ['PATCH', "{$m}/categories/{$f['category']}", ['name' => 'Renamed'], 'manage'],
        ['PUT', "{$m}/categories/{$f['category']}/pack-order", ['ids' => [$f['pack']]], 'manage'],
        ['GET', "{$m}/packs", [], 'manage'],
        ['POST', "{$m}/packs", ['title' => 'New pack'], 'manage'],
        ['GET', "{$m}/packs/{$f['pack']}", [], 'manage'],
        ['PATCH', "{$m}/packs/{$f['spare']}", ['revision' => 1, 'title' => 'Spare renamed'], 'manage'],
        ['PUT', "{$m}/packs/{$f['spare']}/audiences", ['audiences' => ['guardian']], 'manage'],
        ['POST', "{$m}/packs/{$f['spare']}/publish", [], 'manage'],
        ['POST', "{$m}/packs/{$f['spare']}/unpublish", [], 'manage'],
        ['GET', "{$m}/packs/{$f['pack']}/preview?audience=guardian", [], 'manage'],
        ['PUT', "{$m}/packs/{$f['pack']}/card-order", ['ids' => [$f['card']]], 'manage'],
        ['POST', "{$m}/packs/{$f['spare']}/cards", ['type' => 'basic', 'title' => 'Another', 'content' => Resources::doc('x')], 'manage'],
        ['GET', "{$m}/packs/{$f['pack']}/cards/{$f['card']}", [], 'manage'],
        ['PATCH', "{$m}/packs/{$f['spare']}/cards/{$f['spareCard']}", ['revision' => 1, 'title' => 'Renamed'], 'manage'],
        ['PUT', "{$m}/packs/{$f['spare']}/cards/{$f['spareCard']}/audiences", ['mode' => 'inherit'], 'manage'],
        ['POST', "{$m}/packs/{$f['spare']}/cards/{$f['spareCard']}/publish", [], 'manage'],
        ['POST', "{$m}/packs/{$f['spare']}/cards/{$f['spareCard']}/unpublish", [], 'manage'],
        ['DELETE', "{$m}/packs/{$f['spare']}/cards/{$f['spareCard']}", [], 'manage'],
        ['DELETE', "{$m}/packs/{$f['spare']}", [], 'manage'],
        ['DELETE', "{$m}/categories/{$f['category']}", [], 'manage'],
        ['GET', $l, [], 'view'],
        ['GET', "{$l}/packs/{$f['pack']}", [], 'view'],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function resourcesCall(Console $console, string $method, string $path, array $body): TestResponse
{
    return match ($method) {
        'GET' => $console->get($path),
        'POST' => $console->post($path, $body),
        'PUT' => $console->put($path, $body),
        'PATCH' => $console->patch($path, $body),
        'DELETE' => $console->delete($path),
        default => throw new LogicException($method),
    };
}

it('covers every Resources route with the table below, so a route added later cannot skip the capability tests', function () {
    [$console] = Resources::signedInGuardian();
    $table = array_map(fn (array $op): string => $op[0].' '.preg_replace('/[0-9a-z]{26}/', '{}', explode('?', $op[1])[0]), resourcesOperations(resourcesFixture($console)));
    $routes = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/admin/resource')) {
            foreach (array_diff(Api::strings($route->methods()), ['HEAD', 'OPTIONS']) as $method) {
                $routes[] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', '/'.$route->uri());
            }
        }
    }
    sort($table);
    sort($routes);

    expect(count($routes))->toBe(25)->and($table)->toBe($routes);
});

it('refuses EVERY operation to a signed-in Guardian who holds neither capability, and changes nothing', function () {
    [$console] = Resources::signedInGuardian();
    $f = resourcesFixture($console);
    $before = array_map(fn (string $t): int => DB::table($t)->count(), Resources::tables());
    resourcesGate(false, 'resources.view', 'resources.manage');

    foreach (resourcesOperations($f) as [$method, $path, $body]) {
        expect(resourcesCall($console, $method, $path, $body)->status())->toBe(403, "{$method} {$path}");
    }
    expect(array_map(fn (string $t): int => DB::table($t)->count(), Resources::tables()))->toBe($before)
        ->and(DB::table('security_events')->where('type', 'like', 'resource.%')->count())->toBe(0);
});

it('lets resources.manage do every management operation and refuses it the library: manage is not view', function () {
    [$console] = Resources::signedInGuardian();
    $f = resourcesFixture($console);
    resourcesGate(false, 'resources.view');

    foreach (resourcesOperations($f) as [$method, $path, $body, $needs]) {
        $status = resourcesCall($console, $method, $path, $body)->status();
        if ($needs === 'view') {
            expect($status)->toBe(403, "{$method} {$path}");
        } else {
            expect($status)->not->toBe(403, "{$method} {$path}")->and($status)->toBeLessThan(500);
        }
    }
});

it('lets resources.view read the library and refuses it every management operation, reads included: view is not manage', function () {
    [$console] = Resources::signedInGuardian();
    $f = resourcesFixture($console);
    resourcesGate(false, 'resources.manage');

    foreach (resourcesOperations($f) as [$method, $path, $body, $needs]) {
        $response = resourcesCall($console, $method, $path, $body);
        if ($needs === 'view') {
            $response->assertOk();
        } else {
            expect($response->status())->toBe(403, "{$method} {$path}");
        }
    }
});

it('refuses every operation to an account that can sign in but cannot use the Console, such as a Member', function () {
    [$console] = Resources::signedInGuardian();
    $f = resourcesFixture($console);
    $member = Identity::savedActiveAccount('mia.member@example.org', name: 'Mia Member');
    Membership::savedGrant($member->personId);
    $browser = new Console;
    $browser->login('mia.member@example.org', Identity::PASSWORD)->assertOk();

    foreach (resourcesOperations($f) as [$method, $path, $body]) {
        expect(resourcesCall($browser, $method, $path, $body)->status())->toBe(403, "{$method} {$path}");
    }
});

it('checks the capability again inside every use case, so no other caller can skip the route', function () {
    $by = Resources::editor();
    $outsider = Resources::outsider();
    $category = Resources::category($by, 'Guides')->category->id;
    $live = Resources::published($by, [Audience::Guardian], 1, 'Live');
    $spare = Resources::pack($by, 'Spare');
    $card = Resources::card($by, $spare);

    $calls = [
        fn () => app(ListCategories::class)($outsider),
        fn () => app(CreateCategory::class)($outsider, 'X'),
        fn () => app(RenameCategory::class)($outsider, $category, 'X'),
        fn () => app(DeleteCategory::class)($outsider, $category),
        fn () => app(ReorderCategories::class)($outsider, [$category]),
        fn () => app(PageManagedPacks::class)($outsider, new ManagedPackFilter, 1, 25),
        fn () => app(GetManagedPack::class)($outsider, $live->pack->id),
        fn () => app(CreatePack::class)($outsider, 'X', null, false, null),
        fn () => app(UpdatePack::class)($outsider, $spare->pack->id, 1, ['title' => 'X']),
        fn () => app(SetPackAudiences::class)($outsider, $spare->pack->id, [Audience::Guardian]),
        fn () => app(PublishPack::class)($outsider, $spare->pack->id),
        fn () => app(UnpublishPack::class)($outsider, $live->pack->id),
        fn () => app(ReorderPacks::class)($outsider, $category, []),
        fn () => app(DeletePack::class)($outsider, $spare->pack->id),
        fn () => app(PreviewPack::class)($outsider, $live->pack->id, Audience::Guardian),
        fn () => app(CreateCard::class)($outsider, $spare->pack->id, CardType::Basic, 'X', null, null, null),
        fn () => app(GetManagedCard::class)($outsider, $spare->pack->id, $card->card->id),
        fn () => app(UpdateCard::class)($outsider, $spare->pack->id, $card->card->id, 1, ['title' => 'X']),
        fn () => app(SetCardAudiences::class)($outsider, $spare->pack->id, $card->card->id, AudienceMode::Inherit, []),
        fn () => app(PublishCard::class)($outsider, $spare->pack->id, $card->card->id),
        fn () => app(UnpublishCard::class)($outsider, $spare->pack->id, $card->card->id),
        fn () => app(ReorderCards::class)($outsider, $spare->pack->id, []),
        fn () => app(DeleteCard::class)($outsider, $spare->pack->id, $card->card->id),
        fn () => app(BrowseResourceLibrary::class)($outsider, null, null),
        fn () => app(GetResourcePack::class)($outsider, $live->pack->id),
    ];

    expect(count($calls))->toBe(25);
    foreach ($calls as $i => $call) {
        try {
            $call();
            $refused = false;
        } catch (AccessDenied) {
            $refused = true;
        }
        expect($refused)->toBeTrue("use case #{$i} did not refuse a caller with no capability");
    }
});

it('asks each use case for exactly the one capability its route asks for', function () {
    $dir = dirname(__DIR__, 4).'/app/Modules/Resources/Application';
    $expected = [];
    foreach (['ListCategories', 'CreateCategory', 'RenameCategory', 'DeleteCategory', 'ReorderCategories', 'PageManagedPacks', 'GetManagedPack', 'CreatePack', 'UpdatePack', 'SetPackAudiences', 'PublishPack', 'UnpublishPack', 'ReorderPacks', 'DeletePack', 'PreviewPack', 'CreateCard', 'GetManagedCard', 'UpdateCard', 'SetCardAudiences', 'PublishCard', 'UnpublishCard', 'ReorderCards', 'DeleteCard'] as $useCase) {
        $expected[$useCase] = 'ManageResources';
    }
    $expected['BrowseResourceLibrary'] = 'ViewResources';
    $expected['GetResourcePack'] = 'ViewResources';

    foreach ($expected as $useCase => $capability) {
        preg_match_all('/Capability::(\w+)/', SourceScan::code((string) file_get_contents("{$dir}/{$useCase}.php")), $matches);
        expect(array_values(array_unique($matches[1])))->toBe([$capability], $useCase);
    }
    // Every Application class that takes an Actor is in the table: a new use case cannot skip the check.
    foreach (glob("{$dir}/*.php") ?: [] as $path) {
        if (str_contains(SourceScan::code((string) file_get_contents($path)), 'Actor $actor')) {
            expect(array_key_exists(basename($path, '.php'), $expected))->toBeTrue(basename($path).' takes an Actor but is not in the capability table');
        }
    }
});
