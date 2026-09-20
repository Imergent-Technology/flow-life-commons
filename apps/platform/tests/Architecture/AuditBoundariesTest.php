<?php

declare(strict_types=1);

/*
 * Audit sits below Identity in the frozen graph (Access -> Identity -> Audit -> Shared),
 * so it must never depend back on Identity, and other modules reach it only through its
 * Application layer (the generic rules in ModuleBoundariesTest already forbid touching its
 * Domain, Infrastructure or Http). Scoped to Audit on purpose; one subject per expectation.
 */

$audit = 'App\\Modules\\Audit';

arch('Audit: depends on no other module (no cycle back into Identity)', function () use ($audit) {
    expect($audit)->not->toUse('App\\Modules\\Identity');
});

arch('Audit: Domain is framework-independent', function () use ($audit) {
    expect("{$audit}\\Domain")->not->toUse('Illuminate');
});

arch('Audit: Application does not depend on Infrastructure', function () use ($audit) {
    expect("{$audit}\\Application")->not->toUse("{$audit}\\Infrastructure");
});

arch('Audit: no Eloquent model, so nothing can save() or delete() an event', function () use ($audit) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($audit);
});
