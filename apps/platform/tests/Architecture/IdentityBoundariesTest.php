<?php

declare(strict_types=1);

/*
 * Identity-specific boundaries (docs/architecture/identity-and-access.md, "Layer
 * placement"). The generic rules in ModuleBoundariesTest already cover Identity because
 * modules are discovered from disk: Domain depends on no other layer, no HTTP transport
 * in Domain/Application, and no other module may use Identity's Domain, Infrastructure
 * or Http. The rules here are the ones the frozen Identity design makes concrete now that
 * the module exists.
 *
 * They are scoped to Identity on purpose. docs/architecture/module-map.md currently says
 * Eloquent is fine in Domain; tightening that for every module is a separate decision.
 *
 * One subject per expectation (see README.md): an array of subjects passes vacuously.
 */

$identity = 'App\\Modules\\Identity';

arch('Identity: persistence does not leak into Domain', function () use ($identity) {
    // Eloquent models are Infrastructure (the layer table); Domain holds plain entities.
    expect("{$identity}\\Domain")->not->toUse([
        'Illuminate\\Database',
        'Illuminate\\Support\\Facades\\DB',
        'Illuminate\\Support\\Facades\\Schema',
    ]);
});

arch('Identity: Domain is independent of the Laravel authentication machinery', function () use ($identity) {
    // Account is not itself Authenticatable; the Eloquent record and, later, the user
    // provider adapter are Infrastructure.
    expect("{$identity}\\Domain")->not->toUse(['Illuminate\\Contracts\\Auth', 'Illuminate\\Auth']);
});

arch('Identity: Eloquent is confined to Infrastructure', function () use ($identity) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn([
        "{$identity}\\Domain",
        "{$identity}\\Application",
        "{$identity}\\Http",
    ]);
});

arch('Identity: Application does not depend on Infrastructure', function () use ($identity) {
    // Direction is Infrastructure -> Application/Domain, never the reverse.
    expect("{$identity}\\Application")->not->toUse("{$identity}\\Infrastructure");
});

arch('Identity: persistence records are internal to Identity Infrastructure', function () use ($identity) {
    // Other modules reach Identity through its Application layer, never through its tables.
    expect("{$identity}\\Infrastructure\\Persistence")->toOnlyBeUsedIn("{$identity}\\Infrastructure");
});
