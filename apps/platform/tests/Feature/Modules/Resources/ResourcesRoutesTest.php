<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Tests\Support\Api;

/*
 * The Resources route table (ADR 0037, decisions 51-53). Walks the registered routes, so a route added later that forgets a layer,
 * or a deletion that loses its recent-verification requirement, fails here rather than in review.
 */

/** @return list<Route> */
function resourcesRoutes(string $prefix): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        fn (Route $route): bool => $route->uri() === "api/v1/admin/{$prefix}" || str_starts_with($route->uri(), "api/v1/admin/{$prefix}/"),
    ));
}

/** @return list<string> */
function resourcesMiddleware(Route $route): array
{
    return Api::strings($route->gatherMiddleware());
}

it('has exactly 25 management routes under /admin/resources and 3 delivery routes under /admin/resource-library, and no other Resources route', function () {
    $management = resourcesRoutes('resources');
    $delivery = resourcesRoutes('resource-library');
    $all = array_filter(app('router')->getRoutes()->getRoutes(), fn ($r): bool => str_contains($r->getActionName(), 'Modules\\Resources\\'));

    // WP3 added three: replace a File Card's file and download it under management, and download it from the library.
    expect($management)->toHaveCount(25)->and($delivery)->toHaveCount(3)->and(count($all))->toBe(28);
});

it('gives every Resources route authentication, the Console boundary and exactly one capability: manage for management, view for delivery', function () {
    foreach (resourcesRoutes('resources') as $route) {
        $middleware = resourcesMiddleware($route);
        $capabilities = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));
        expect($middleware)->toContain('stateful', 'auth:web', 'can:console.access')
            ->and($capabilities)->toBe(['can:resources.manage'], $route->uri());
    }
    foreach (resourcesRoutes('resource-library') as $route) {
        $middleware = resourcesMiddleware($route);
        $capabilities = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));
        expect($middleware)->toContain('stateful', 'auth:web', 'can:console.access')
            ->and($capabilities)->toBe(['can:resources.view'], $route->uri())
            ->and(Api::strings($route->methods()))->toBe(['GET', 'HEAD'])  // delivery only ever reads
            ->and($middleware)->not->toContain('security.verified');
    }
});

it('requires recent verification on exactly the two permanent-deletion routes, after the capability, and on nothing else in Resources', function () {
    $verified = [];
    foreach ([...resourcesRoutes('resources'), ...resourcesRoutes('resource-library')] as $route) {
        $middleware = resourcesMiddleware($route);
        if (in_array('security.verified', $middleware, true)) {
            $verified[] = implode('|', Api::strings($route->methods())).' '.$route->uri();
            // The capability is asked FIRST: a caller without it is refused plainly and is never asked to prove anything.
            expect(array_search('can:resources.manage', $middleware, true))->toBeLessThan((int) array_search('security.verified', $middleware, true));
        }
    }
    sort($verified);

    expect($verified)->toBe([
        'DELETE api/v1/admin/resources/packs/{pack}',
        'DELETE api/v1/admin/resources/packs/{pack}/cards/{card}',
    ]);
});

it('exempts every OTHER Resources mutation from recent verification: routine management is not step-up', function () {
    $routine = [];
    foreach (resourcesRoutes('resources') as $route) {
        $methods = Api::strings($route->methods());
        if (array_diff($methods, ['GET', 'HEAD']) !== [] && ! in_array('security.verified', resourcesMiddleware($route), true)) {
            $routine[] = implode('|', $methods).' '.$route->uri();
        }
    }

    // 17 routine mutations: 5 on Categories (create, reorder, rename, delete an EMPTY one, reorder its Packs), 6 on Packs (create, edit,
    // audiences, publish, unpublish, reorder Cards) and 6 on Cards (create, edit, audiences, publish, unpublish, replace the file). The 6
    // reads are not mutations and the 2 permanent deletions are verified.
    expect(count($routine))->toBe(17)
        ->and($routine)->toContain('DELETE api/v1/admin/resources/categories/{category}')   // an empty Category loses a name, not content
        ->and($routine)->toContain('POST api/v1/admin/resources/packs/{pack}/cards/{card}/file') // replacing a file is routine (decision 52)
        ->and($routine)->not->toContain('DELETE api/v1/admin/resources/packs/{pack}');
});

it('has no route that deletes or restores anything but the three DELETEs, none for Trash, Archive or Restore, and files only on a Card', function () {
    $deletes = [];
    $files = [];
    foreach ([...resourcesRoutes('resources'), ...resourcesRoutes('resource-library')] as $route) {
        if (in_array('DELETE', Api::strings($route->methods()), true)) {
            $deletes[] = $route->uri();
        }
        expect($route->uri())->not->toMatch('/trash|archive|restore|recycle|asset|upload|download|media|attachment|embed|import|export/i');
        if (str_contains($route->uri(), 'file')) {
            $files[] = implode('|', Api::strings($route->methods())).' '.$route->uri();
        }
    }
    sort($files);

    // A file is reached only as the file OF a Card, never by its own id or key, and is never deleted on its own: it goes with its Card.
    expect($files)->toBe([
        'GET|HEAD api/v1/admin/resource-library/packs/{pack}/cards/{card}/file',
        'GET|HEAD api/v1/admin/resources/packs/{pack}/cards/{card}/file',
        'POST api/v1/admin/resources/packs/{pack}/cards/{card}/file',
    ]);
    sort($deletes);

    expect($deletes)->toBe([
        'api/v1/admin/resources/categories/{category}',
        'api/v1/admin/resources/packs/{pack}',
        'api/v1/admin/resources/packs/{pack}/cards/{card}',
    ]);
});

it('keeps every id parameter to the lowercase ULID form, so a malformed id is a plain 404 before any code runs', function () {
    foreach ([...resourcesRoutes('resources'), ...resourcesRoutes('resource-library')] as $route) {
        foreach ($route->parameterNames() as $name) {
            assert(is_string($name));
            expect($route->wheres[$name] ?? null)->toBe('[0-7][0-9a-hjkmnp-tv-z]{25}', "{$route->uri()} {{$name}}");
        }
    }
});

it('names every route under api.v1.admin.resources.* or api.v1.admin.resource-library.*', function () {
    foreach ([...resourcesRoutes('resources'), ...resourcesRoutes('resource-library')] as $route) {
        expect((string) $route->getName())->toMatch('/^api\.v1\.admin\.(resources|resource-library)\./');
    }
});
