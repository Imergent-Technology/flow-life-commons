<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
use Tests\Support\Api;

/*
 * The administration surface's route table (docs/adr/0024). Walks the registered routes, so a route added later that
 * forgets a layer fails here rather than in review.
 */

/**
 * The capabilities whose mutations are exempt from recent verification, each pinned to the one module whose routes it may
 * cover. Routine maintenance and discussion (a note about how we know someone, a phone number, a tag, a typo in a name, a
 * reply in a thread) grants and removes no authority, so a fresh password and second factor for it would only make it
 * unusable: CRM's by ADR 0034, Discussions' by ADR 0035, and Resources' (`resources.manage`, ADR 0037), whose two PERMANENT DELETE
 * routes still carry `security.verified` anyway (pinned in ResourcesRoutesTest). Every other administration mutation still needs the proof. The
 * exemption is by capability AND by module, and a test below pins that each covers exactly its own module's routes and nothing
 * else. It is a short list on purpose: adding to it is a decision, not a convenience.
 */
const STEP_UP_EXEMPT = [
    'can:crm.people.manage' => 'App\\Modules\\Crm\\Http\\',
    'can:discussions.participate' => 'App\\Modules\\Discussions\\Http\\',
    'can:resources.manage' => 'App\\Modules\\Resources\\Http\\',
];

/**
 * What is wrong with an administration route's middleware, for the table walk below.
 *
 * @param  list<string>  $middleware
 * @param  list<string>  $methods
 * @return list<string>
 */
function adminRouteProblems(array $middleware, array $methods): array
{
    $problems = [];
    foreach (['stateful', 'auth:web', 'can:console.access'] as $required) {
        if (! in_array($required, $middleware, true)) {
            $problems[] = "lacks {$required}";
        }
    }

    $capabilities = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));
    $known = array_map(fn (Capability $c): string => 'can:'.$c->value, Capability::cases());
    if (count($capabilities) !== 1 || ! in_array($capabilities[0], $known, true)) {
        $problems[] = 'does not name exactly one capability from the catalog';
    }

    $exempt = array_key_exists($capabilities[0] ?? '', STEP_UP_EXEMPT);
    if (array_diff($methods, ['GET', 'HEAD']) !== [] && ! $exempt) {
        $verified = array_search('security.verified', $middleware, true);
        $capability = $capabilities === [] ? false : array_search($capabilities[0], $middleware, true);
        if ($verified === false) {
            $problems[] = 'changes something without security.verified';
        } elseif ($capability !== false && $capability > $verified) {
            $problems[] = 'asks for the proof BEFORE the capability';
        }
    }

    return $problems;
}

it('gives every administration route authentication, the Console boundary, exactly one catalog capability and, for a mutation, recent verification', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/'));
    $offenders = [];
    foreach ($routes as $route) {
        $problems = adminRouteProblems(Api::strings($route->gatherMiddleware()), Api::strings($route->methods()));
        if ($problems !== []) {
            $offenders[$route->uri().' ['.implode('|', Api::strings($route->methods())).']'] = $problems;
        }
    }

    expect($routes->count())->toBeGreaterThanOrEqual(10)
        ->and($offenders)->toBe([])
        // Positive controls: the check does fail what it should.
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:identity.accounts.manage'], ['POST']))->toBe(['changes something without security.verified'])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'security.verified'], ['POST']))->toContain('does not name exactly one capability from the catalog')
        ->and(adminRouteProblems(['stateful', 'can:console.access', 'can:identity.accounts.view'], ['GET']))->toBe(['lacks auth:web'])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'security.verified', 'can:identity.accounts.manage'], ['POST']))->toBe(['asks for the proof BEFORE the capability'])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:made.up'], ['GET']))->toContain('does not name exactly one capability from the catalog')
        // The exemption is for CRM maintenance and discussion alone: the same mutation under any other capability is still refused.
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:crm.people.manage'], ['PATCH']))->toBe([])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:discussions.participate'], ['POST']))->toBe([])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:discussions.view'], ['POST']))->toBe(['changes something without security.verified'])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:resources.manage'], ['PATCH']))->toBe([])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:resources.view'], ['POST']))->toBe(['changes something without security.verified'])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:crm.people.view'], ['PATCH']))->toBe(['changes something without security.verified'])
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:membership.records.manage'], ['POST']))->toBe(['changes something without security.verified']);
});

it('exempts exactly the CRM, Discussions and Resources mutations from recent verification, each only on its own module\'s routes, and no other administration mutation', function () {
    $unverified = [];
    foreach (collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/')) as $route) {
        $middleware = Api::strings($route->gatherMiddleware());
        if (array_diff(Api::strings($route->methods()), ['GET', 'HEAD', 'OPTIONS']) !== [] && ! in_array('security.verified', $middleware, true)) {
            $unverified[] = [$route->uri(), $route->getActionName(), $middleware];
        }
    }

    $seen = [];
    foreach ($unverified as [$uri, $action, $middleware]) {
        $exemptions = array_values(array_intersect(array_keys(STEP_UP_EXEMPT), $middleware));
        expect($exemptions)->toHaveCount(1, "{$uri} changes something without recent verification and holds no exempt capability");
        expect($action)->toStartWith(STEP_UP_EXEMPT[$exemptions[0]], "{$uri} holds {$exemptions[0]} but is not that module's route");
        $seen[$exemptions[0]] = true;
    }

    // Positive control: both exempt surfaces really are here, and the list is exactly these two.
    expect(array_keys($seen))->toEqualCanonicalizing(['can:crm.people.manage', 'can:discussions.participate', 'can:resources.manage'])
        ->and(array_keys(STEP_UP_EXEMPT))->toBe(['can:crm.people.manage', 'can:discussions.participate', 'can:resources.manage']);
});

it('keeps every Discussions route under the Console boundary, one catalog capability, and no recent-verification middleware', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/discussions'));
    $capabilities = [];
    foreach ($routes as $route) {
        $middleware = Api::strings($route->gatherMiddleware());
        expect($middleware)->toContain('stateful', 'auth:web', 'can:console.access')
            ->and($middleware)->not->toContain('security.verified');
        $isRead = array_diff(Api::strings($route->methods()), ['GET', 'HEAD', 'OPTIONS']) === [];
        $capability = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:discussions.')));
        // Reads need view and only view; every change needs participate and only participate.
        expect($capability)->toBe([$isRead ? 'can:discussions.view' : 'can:discussions.participate'], $route->uri());
        $capabilities[$route->uri().implode('|', Api::strings($route->methods()))] = $capability[0];
    }

    expect($routes)->toHaveCount(10);
});
