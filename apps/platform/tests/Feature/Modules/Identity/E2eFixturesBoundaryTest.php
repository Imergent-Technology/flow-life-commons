<?php

declare(strict_types=1);

use App\Modules\Access\Application\ConsoleUserFixture;
use App\Modules\Access\Application\Role;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\E2eAccountSeeder;
use Database\Seeders\E2eSessionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/*
 * The browser suite's test-only seams (E2eAccountSeeder, E2eSessionSeeder, and the administrator variant of the Console user
 * fixture) exist to keep journeys that are not about signing in from spending the public login budget. They are development
 * tools and must be unable to run, or be reached, anywhere real.
 */

/** Runs $work as though the application were in the named environment. */
function inEnvironment(string $environment, Closure $work): void
{
    $original = app()->environment();
    app()->instance('env', $environment);
    config()->set('app.env', $environment);

    try {
        $work();
    } finally {
        app()->instance('env', $original);
        config()->set('app.env', $original);
    }
}

it('refuses to mint sessions outside local and testing', function () {
    foreach (['production', 'staging'] as $environment) {
        inEnvironment($environment, function () use ($environment): void {
            expect(fn () => (new E2eSessionSeeder)->setContainer(app())->run())
                ->toThrow(RuntimeException::class, 'only be minted in a local or testing environment');
            expect(DB::table('sessions')->count())->toBe(0, "a session was written in {$environment}");
        });
    }
});

it('refuses to seed the e2e accounts outside local and testing', function () {
    inEnvironment('production', function (): void {
        expect(fn () => app()->call([(new E2eAccountSeeder)->setContainer(app()), 'run']))->toThrow(RuntimeException::class);
        expect(DB::table('accounts')->count())->toBe(0);
    });
});

it('refuses to make anyone an administrator through the fixture outside local and testing', function () {
    $person = Identity::savedPerson();

    inEnvironment('production', function () use ($person): void {
        expect(fn () => app(ConsoleUserFixture::class)->administrator($person->id))->toThrow(LogicException::class);
    });

    expect(DB::table('role_assignments')->count())->toBe(0);
});

it('gives the administrator fixture only what the administrator role gives, and is idempotent', function () {
    $person = Identity::savedPerson();

    app(ConsoleUserFixture::class)->administrator($person->id);
    app(ConsoleUserFixture::class)->administrator($person->id);

    expect(DB::table('role_assignments')->where('person_id', $person->id->value)->pluck('role_key')->all())->toBe([Role::PlatformAdministrator->value]);
});

it('is not part of the default seeder, and nothing in the application refers to the e2e seeders or their output', function () {
    $root = dirname(__DIR__, 4);
    $seeder = (string) file_get_contents("{$root}/database/seeders/DatabaseSeeder.php");
    expect($seeder)->not->toContain('E2e');

    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && preg_match('/E2eSessionSeeder|E2eAccountSeeder|e2e-sessions/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = str_replace("{$root}/", '', $file->getPathname());
            }
        }
    }

    // Positive control: the same scan does find the reference where it legitimately lives.
    expect($offenders)->toBe([])
        ->and(preg_match('/E2eSessionSeeder|e2e-sessions/', (string) file_get_contents("{$root}/database/seeders/E2eSessionSeeder.php")))->toBe(1)
        ->and(class_exists(DatabaseSeeder::class))->toBeTrue();
});

it('writes the minted sessions where git ignores everything', function () {
    // The file holds live (if worthless) session cookies. The platform's private storage ignores its contents; the runner
    // copies it into the browser suite's own git-ignored .fixtures directory (scripts/commands/test.sh).
    expect((string) file_get_contents(base_path('storage/app/private/.gitignore')))->toContain('*')
        ->and(E2eSessionSeeder::FILE)->toStartWith('app/private/');
});
