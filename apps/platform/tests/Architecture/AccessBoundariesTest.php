<?php

declare(strict_types=1);

use App\Modules\Access\Application\BootstrapAdministrator;
use App\Modules\Access\Application\ConsoleUserFixture;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\EffectiveCapabilities;

/*
 * Access sits at the top of the platform graph (Access -> Identity -> Audit -> Shared).
 * Membership sits above Access (Membership -> Access, Membership -> Identity; ADR 0028) without
 * closing a cycle or gaining a direct Audit dependency.
 * docs/architecture/identity-and-access.md, "Layer placement" and "What other modules use".
 *
 * Other modules consume Access only through Access\Application, and what they ask for is a
 * Capability, never a Role. The generic rules in ModuleBoundariesTest already forbid one
 * module using another's Domain, Infrastructure or Http; the rules here add what is specific
 * to authorization. Scoped to Access on purpose; one subject per expectation.
 */

$access = 'App\\Modules\\Access';

arch('Access: Domain is framework-independent', function () use ($access) {
    expect("{$access}\\Domain")->not->toUse('Illuminate');
});

arch('Access: Domain depends on no other module', function () use ($access) {
    // Its only outside dependency is the Shared kernel (PersonId, AccountId). That is why a
    // role key is a plain string there: the catalog is in Application, which Domain may not import.
    expect("{$access}\\Domain")->not->toUse(['App\\Modules\\Identity', 'App\\Modules\\Audit']);
});

arch('Access: Application does not depend on Infrastructure', function () use ($access) {
    expect("{$access}\\Application")->not->toUse("{$access}\\Infrastructure");
});

foreach (['Domain', 'Application'] as $layer) {
    arch("Access: {$layer} does not touch Laravel's HTTP, auth, session or gate machinery", function () use ($access, $layer) {
        // Only Infrastructure (the Gate registration) and Http meet the framework. The
        // authorization decision itself is plain PHP over the catalog and the assignments.
        expect("{$access}\\{$layer}")->not->toUse([
            'Illuminate\\Http',
            'Illuminate\\Routing',
            'Illuminate\\Auth',
            'Illuminate\\Contracts\\Auth',
            'Illuminate\\Session',
            'Illuminate\\Contracts\\Session',
            'Illuminate\\Support\\Facades\\Auth',
            'Illuminate\\Support\\Facades\\Gate',
            'Illuminate\\Support\\Facades\\Session',
            'Illuminate\\Support\\Facades\\Route',
        ]);
    });
}

arch('Access: no Eloquent, so an assignment cannot be changed outside the paths the module provides', function () use ($access) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($access);
});

arch('Access: uses Identity only through its Application layer', function () use ($access) {
    // The one edge that exists (ResolveActor, EffectiveCapabilities). It never reaches Identity's
    // Domain, Infrastructure or Http, and so never Identity's persistence.
    expect($access)->not->toUse(['App\\Modules\\Identity\\Domain', 'App\\Modules\\Identity\\Infrastructure', 'App\\Modules\\Identity\\Http']);
});

arch('Access: Application uses Audit only through its Application layer', function () use ($access) {
    expect("{$access}\\Application")->not->toUse(['App\\Modules\\Audit\\Domain', 'App\\Modules\\Audit\\Infrastructure']);
});

arch('Access: Application is free of console concerns', function () use ($access) {
    // Console commands are an Infrastructure adapter over an Application use case.
    expect("{$access}\\Application")->not->toUse('Illuminate\\Console');
});

arch('EffectiveCapabilities stays a presentation port: only /me and login read it, and only Access supplies it', function () {
    // It enriches the current-account projection with informational identifiers. Authorization
    // never flows through it, and Identity's Domain never sees it. The Authorizer, AuthorizeAction,
    // the Gate and both role use cases decide from persisted assignments and must not consult it.
    expect(EffectiveCapabilities::class)->toOnlyBeUsedIn([
        'App\\Modules\\Identity\\Application\\GetCurrentAccount',
        'App\\Modules\\Identity\\Application\\AuthenticateAccount',
        // Sign-in completes here once the second factor is proved (ADR 0023), and reports the same projection.
        'App\\Modules\\Identity\\Application\\CompleteSecondFactor',
        'App\\Modules\\Identity\\Application\\ConfirmTotpEnrollment',
        'App\\Modules\\Identity\\Infrastructure\\NoEffectiveCapabilities',
        'App\\Modules\\Identity\\Infrastructure\\IdentityServiceProvider',
        'App\\Modules\\Access\\Application\\AuthorizerEffectiveCapabilities',
        'App\\Modules\\Access\\Infrastructure\\AccessServiceProvider',
    ]);
});

arch('Identity does not depend on Access (the edge runs Access -> Identity, never back)', function () {
    expect('App\\Modules\\Identity')->not->toUse('App\\Modules\\Access');
});

arch('Audit does not depend on Access', function () {
    expect('App\\Modules\\Audit')->not->toUse('App\\Modules\\Access');
});

arch('Role is internal to Access: nothing outside it may name the type', function () {
    // A role is how capabilities are bundled and assigned, never how anything is authorized.
    // Business code that could import Role could write `if ($role === Role::Guardian)`.
    // No exceptions: not for seeders, not for fixtures. Code that needs a role's effect asks
    // Access for it (ConsoleUserFixture, BootstrapAdministrator) and never names the role.
    expect(Role::class)->toOnlyBeUsedIn('App\\Modules\\Access');
});

arch('The administrator bootstrap has one caller: the operator\'s console command', function () {
    // Its authority is server access, not an Actor. Nothing else may run it, and in particular no
    // HTTP layer: that would turn "the operator can run a command" into "a request can".
    expect(BootstrapAdministrator::class)
        ->toOnlyBeUsedIn('App\\Modules\\Access\\Infrastructure\\Console\\CreateAdministratorCommand');
});

arch('The Console user fixture is for the development seeder only', function () {
    // It bypasses authorization and audit, and refuses to run outside local and testing.
    expect(ConsoleUserFixture::class)
        ->toOnlyBeUsedIn(['App\\Modules\\Access', 'Database\\Seeders\\E2eAccountSeeder']);
});

// --- Source scans (each has a positive control, so it cannot pass by matching nothing) ---------

/**
 * @return list<string>
 */
function appPhpFilesOutside(string $module): array
{
    $files = [];
    $root = dirname(__DIR__, 2);
    foreach (['app', 'bootstrap', 'config', 'routes', 'database/seeders'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), "/Modules/{$module}/")) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('keeps every role key out of code outside Access, so nothing authorizes by role name', function () {
    $keys = array_map(fn (Role $r): string => $r->value, Role::cases());
    $offenders = [];

    foreach (appPhpFilesOutside('Access') as $path) {
        $source = (string) file_get_contents($path);
        foreach ($keys as $key) {
            if (preg_match('/[\'"]'.preg_quote($key, '/').'[\'"]/', $source) === 1) {
                $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path)." names role \"{$key}\"";
            }
        }
    }

    expect($offenders)->toBe([]);

    // Positive control: the same scan does see the keys where they legitimately live.
    $catalog = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Access/Application/Role.php');
    foreach ($keys as $key) {
        expect(preg_match('/[\'"]'.preg_quote($key, '/').'[\'"]/', $catalog))->toBe(1);
    }
});

it('keeps Access out of Identity\'s tables', function () {
    $tables = ['people', 'accounts', 'account_invitations', 'sessions'];
    $pattern = '/[\'"](?:'.implode('|', $tables).')[\'"]/';
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Modules/Access', FilesystemIterator::SKIP_DOTS)) as $file) {
        assert($file instanceof SplFileInfo);
        // Http/routes.php names URL paths (`accounts`), not tables; Access's Http layer touches no database at all
        // (its own rule below), so it is not where a table name could matter.
        if (str_contains($file->getPathname(), '/Access/Http/')) {
            continue;
        }
        if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);

    // Positive control: the pattern does match Identity's own persistence code.
    $identity = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Identity/Infrastructure/Persistence/AccountRecord.php');
    expect(preg_match($pattern, $identity))->toBe(1);
});

// --- The whole module graph ------------------------------------------------------------------------

/**
 * Module -> modules it references, read from the source.
 *
 * @return array<string, list<string>>
 */
function moduleGraph(): array
{
    $root = dirname(__DIR__, 2).'/app/Modules';
    $graph = [];

    foreach (glob("{$root}/*", GLOB_ONLYDIR) ?: [] as $moduleDir) {
        $module = basename($moduleDir);
        $edges = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/App\\\\Modules\\\\(\w+)\\\\/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $other) {
                if ($other !== $module) {
                    $edges[$other] = $other;
                }
            }
        }
        $graph[$module] = array_values($edges);
        sort($graph[$module]);
    }
    ksort($graph);

    return $graph;
}

it('has an acyclic module graph limited to the frozen edges', function () {
    $graph = moduleGraph();
    $problems = [];

    // Access -> Identity -> Audit -> Shared. Health, Security and Release are operational and stand
    // alone: each owns no table, no entity and no lifecycle, and nothing may come to depend on any of
    // them. Release in particular reads one file the build wrote and has no HTTP surface (ADR 0027).
    // Membership -> Access, Identity (ADR 0028): calling a module that itself depends on Audit does
    // not create a Membership -> Audit edge, and none is listed here.
    $allowed = [
        'Access' => ['Identity', 'Audit'], 'Identity' => ['Audit'], 'Audit' => [],
        'Health' => [], 'Security' => [], 'Release' => [],
        'Membership' => ['Access', 'Identity'],
    ];
    foreach ($graph as $module => $edges) {
        if (! array_key_exists($module, $allowed)) {
            $problems[] = "{$module} is a module the frozen graph does not know";

            continue;
        }
        foreach (array_diff($edges, $allowed[$module]) as $forbidden) {
            $problems[] = "{$module} -> {$forbidden} is not a frozen edge";
        }
    }

    // No cycle, by depth-first search over the edges actually present.
    $visit = function (string $module, array $path) use (&$visit, $graph, &$problems): void {
        if (in_array($module, $path, true)) {
            $chain = [];
            foreach ([...$path, $module] as $step) {
                assert(is_string($step));
                $chain[] = $step;
            }
            $problems[] = 'cycle: '.implode(' -> ', $chain);

            return;
        }
        foreach ($graph[$module] ?? [] as $next) {
            $visit($next, [...$path, $module]);
        }
    };
    foreach (array_keys($graph) as $module) {
        $visit($module, []);
    }

    expect(array_values(array_unique($problems)))->toBe([]);

    // Positive controls: the scan sees the edges that do exist, so an empty graph cannot pass.
    expect($graph['Identity'])->toContain('Audit')
        ->and($graph['Access'])->toContain('Identity')
        ->and($graph['Access'])->toContain('Audit') // role mutation is audited through Audit's Application layer
        ->and($graph['Membership'])->toContain('Access')
        ->and($graph['Membership'])->toContain('Identity')
        ->and($graph['Membership'])->not->toContain('Audit'); // no direct Audit dependency (ADR 0028)
});

// --- Operator administration (docs/adr/0024) -------------------------------------------------------------------

$adminHttp = 'App\\Modules\\Access\\Http';

arch('Access: Http reaches Access\'s Application layer only, never its Domain, its Infrastructure or the database', function () use ($adminHttp, $access) {
    // Controllers validate, call ONE use case and shape the response. Persistence and the authorization decision are
    // not theirs: nothing here can read or write a table, or ask a repository.
    expect($adminHttp)->not->toUse(["{$access}\\Domain", "{$access}\\Infrastructure", 'Illuminate\\Database', 'Illuminate\\Support\\Facades\\DB']);
});

arch('Access: no controller names a Role: what an Account holds arrives as data from the catalog', function () use ($adminHttp) {
    // RoleDescriptor is data; Role is the decision. A controller that could import Role could write `if ($role === ...)`.
    expect($adminHttp)->not->toUse('App\\Modules\\Access\\Application\\Role');
});

foreach ([
    'App\\Modules\\Identity\\Application\\DisableAccount',
    'App\\Modules\\Identity\\Application\\EnableAccount',
    'App\\Modules\\Identity\\Application\\ResetMultiFactor',
    'App\\Modules\\Identity\\Application\\InviteAccount',
    'App\\Modules\\Identity\\Application\\ReissueInvitation',
    'App\\Modules\\Identity\\Application\\DeliverInvitation',
    'App\\Modules\\Identity\\Application\\IssuedInvitation',
    'App\\Modules\\Identity\\Application\\AccountDeactivationGuard',
    'App\\Modules\\Access\\Application\\GrantRole',
    'App\\Modules\\Access\\Application\\RevokeRole',
    'App\\Modules\\Access\\Application\\AdministratorContinuity',
] as $mutation) {
    arch("Access: no controller calls {$mutation} directly, so none can skip the authorizing use case", function () use ($adminHttp, $mutation) {
        // Identity's use cases "do not authorize their caller". Whatever a controller calls must be an Access use case
        // that does, and that is the only thing a request is ever handed.
        expect($adminHttp)->not->toUse($mutation);
    });
}

arch('Identity\'s mutating use cases are called only by the Access use cases that authorize them (and their own wiring)', function () {
    $identity = 'App\\Modules\\Identity\\Application';
    $access = 'App\\Modules\\Access\\Application';

    expect("{$identity}\\DisableAccount")->toOnlyBeUsedIn(["{$access}\\DisableManagedAccount", 'App\\Modules\\Identity\\Infrastructure\\IdentityServiceProvider']);
    expect("{$identity}\\EnableAccount")->toOnlyBeUsedIn("{$access}\\EnableManagedAccount");
    expect("{$identity}\\ReissueInvitation")->toOnlyBeUsedIn("{$access}\\ReissueOperatorInvitation");
    expect("{$identity}\\InviteAccount")->toOnlyBeUsedIn(["{$access}\\InviteOperator", "{$access}\\BootstrapAdministrator"]);
    expect("{$identity}\\DeliverInvitation")->toOnlyBeUsedIn(["{$access}\\InviteOperator", "{$access}\\ReissueOperatorInvitation"]);
});

arch('Identity\'s account directory is read only through Access\'s presenting use cases', function () {
    expect('App\\Modules\\Identity\\Application\\AccountDirectory')->toOnlyBeUsedIn([
        'App\\Modules\\Access\\Application\\AccountViews',
        'App\\Modules\\Access\\Application\\ListManagedAccounts',
        'App\\Modules\\Access\\Application\\GrantRoleToAccount',
        'App\\Modules\\Access\\Application\\RevokeRoleFromAccount',
        'App\\Modules\\Identity\\Infrastructure\\IdentityServiceProvider',
        'App\\Modules\\Identity\\Infrastructure\\Persistence\\DatabaseAccountDirectory',
    ]);
});

arch('An invitation channel is Identity\'s own concept: Access chooses it by which method it calls, never by naming it', function () {
    expect('App\\Modules\\Identity\\Domain\\InvitationChannel')->toOnlyBeUsedIn('App\\Modules\\Identity');
});

arch('Access: the administration surface never sees an invitation secret or a notifier', function () use ($adminHttp) {
    expect($adminHttp)->not->toUse(['App\\Modules\\Identity\\Application\\InvitationNotifier', 'App\\Modules\\Identity\\Domain\\InvitationToken']);
    expect('App\\Modules\\Access\\Application\\OperatorInvitation')->not->toUse(['App\\Modules\\Identity\\Application\\IssuedInvitation', 'App\\Modules\\Identity\\Domain\\InvitationToken']);
});
