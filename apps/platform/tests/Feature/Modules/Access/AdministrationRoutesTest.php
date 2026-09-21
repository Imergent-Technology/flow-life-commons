<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
use Tests\Support\Api;

/*
 * The administration surface's route table (docs/adr/0024). Walks the registered routes, so a route added later that
 * forgets a layer fails here rather than in review.
 */

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

    if (array_diff($methods, ['GET', 'HEAD']) !== []) {
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
        ->and(adminRouteProblems(['stateful', 'auth:web', 'can:console.access', 'can:made.up'], ['GET']))->toContain('does not name exactly one capability from the catalog');
});
