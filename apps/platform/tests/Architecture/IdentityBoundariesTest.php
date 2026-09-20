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

arch('Identity: Domain is independent of Laravel altogether', function () use ($identity) {
    // The narrower rules above name the parts that matter most; this is the whole claim.
    expect("{$identity}\\Domain")->not->toUse('Illuminate');
});

foreach (['Domain', 'Application'] as $layer) {
    arch("Identity: {$layer} does not depend on Laravel's authentication or session globals", function () use ($identity, $layer) {
        // Only Http (ConsoleSession) and Infrastructure (the user provider) touch the guard
        // and the session. Business code asks a use case instead of reaching for Auth::user().
        expect("{$identity}\\{$layer}")->not->toUse([
            'Illuminate\\Support\\Facades\\Auth',
            'Illuminate\\Support\\Facades\\Session',
            'Illuminate\\Support\\Facades\\Cookie',
            'Illuminate\\Contracts\\Auth',
            'Illuminate\\Contracts\\Session',
            'Illuminate\\Auth',
            'Illuminate\\Session',
        ]);
    });
}

arch('Identity: Http does not reach into Infrastructure', function () use ($identity) {
    // Controllers validate, call an Application use case and shape the response. What
    // implements a port is the container's business.
    expect("{$identity}\\Http")->not->toUse("{$identity}\\Infrastructure");
});

arch('Identity: Domain does not depend on Audit', function () use ($identity) {
    // Audit is consumed from Application only.
    expect("{$identity}\\Domain")->not->toUse('App\\Modules\\Audit');
});

arch('Identity: uses Audit only through its Application layer', function () use ($identity) {
    // The generic rule forbids other modules' Domain, Infrastructure and Http; this pins the
    // positive statement for the one edge that exists: Identity -> Audit\Application.
    expect($identity)->not->toUse(['App\\Modules\\Audit\\Domain', 'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Http']);
});
