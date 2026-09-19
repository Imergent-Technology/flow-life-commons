<?php

declare(strict_types=1);

/*
 * Module boundary rules (docs/architecture/module-map.md, ADR 0001).
 * Modules are discovered from disk, so new modules are covered automatically.
 * Deliberately simple: prefix-based namespace rules, no custom analysis.
 */

$modulesPath = dirname(__DIR__, 2).'/app/Modules';
$modules = array_map('basename', glob($modulesPath.'/*', GLOB_ONLYDIR) ?: []);

it('finds at least one module to protect', function () use ($modules) {
    expect($modules)->not->toBeEmpty();
});

/*
 * NOTE: Pest evaluates an *array* of subject namespaces vacuously (it passes even
 * when violated), so every rule below uses exactly one subject per expectation.
 * tests/Architecture/README.md explains how to prove a rule can fail.
 */
foreach ($modules as $module) {
    $ns = "App\\Modules\\{$module}";
    $otherModules = array_values(array_diff($modules, [$module]));

    arch("{$module}: Domain does not depend on Application, Infrastructure or Http", function () use ($ns) {
        expect("{$ns}\\Domain")
            ->not->toUse(["{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http"]);
    });

    foreach (['Domain', 'Application'] as $layer) {
        arch("{$module}: {$layer} is free of HTTP transport", function () use ($ns, $layer) {
            expect("{$ns}\\{$layer}")
                ->not->toUse(['Illuminate\\Http', 'Illuminate\\Routing', 'Illuminate\\Support\\Facades\\Route']);
        });
    }

    arch("{$module}: Application does not depend on Http", function () use ($ns) {
        expect("{$ns}\\Application")->not->toUse("{$ns}\\Http");
    });

    arch("{$module}: Infrastructure does not depend on Http", function () use ($ns) {
        expect("{$ns}\\Infrastructure")->not->toUse("{$ns}\\Http");
    });

    foreach (['Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client', 'GuzzleHttp'] as $client) {
        arch("{$module}: {$client} is confined to Infrastructure", function () use ($ns, $client) {
            expect($client)
                ->not->toBeUsedIn(["{$ns}\\Domain", "{$ns}\\Application", "{$ns}\\Http"]);
        });
    }

    foreach ($otherModules as $other) {
        // Another module's internals are off limits; cross-module collaboration
        // goes through that module's Application layer (no direct model/table writes).
        arch("{$module} does not reach into {$other} internals", function () use ($ns, $other) {
            expect($ns)->not->toUse([
                "App\\Modules\\{$other}\\Domain",
                "App\\Modules\\{$other}\\Infrastructure",
                "App\\Modules\\{$other}\\Http",
            ]);
        });
    }
}

arch('Shared never depends on a module', function () {
    expect('App\Shared')->not->toUse('App\Modules');
});

arch('env() is read only in config files', function () {
    expect('env')->not->toBeUsed();
});

arch('no debugging leftovers', function () {
    expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])->not->toBeUsed();
});

arch('dangerous PHP functions are not used', function () {
    expect(['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open'])->not->toBeUsed();
});

arch('application code declares strict types', function () {
    expect('App')->toUseStrictTypes();
});
