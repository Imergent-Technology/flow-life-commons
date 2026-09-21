<?php

declare(strict_types=1);

use App\Modules\Release\Application\ReleaseIdentity;
use App\Modules\Release\Application\ReleaseIdentityUnavailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\getJson;

/*
 * `release:show` (ADR 0027, "Release identity stays off the public surface").
 *
 * The question it answers — "which release is this, and what would rolling it back involve?" — is
 * asked on a live host, often mid-incident, by someone about to act on the answer. So the tests are
 * mostly about REFUSING: a command that printed a plausible identity from a damaged manifest would be
 * worse than one that printed nothing, because it would be believed.
 *
 * The manifests here have the exact shape `./flow release build` writes (scripts/lib/release.sh,
 * release_write_manifest). scripts/tests/release.sh checks from the other side that a really built
 * manifest carries every field this command requires, so the two cannot drift apart unnoticed.
 */

const TAGGED_COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const PREVIOUS_COMMIT = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c';

/**
 * Run `release:show` once and return its exit status and its WHOLE output.
 *
 * Whole-output assertions rather than `expectsOutputToContain`: that matcher lets each written line
 * satisfy only one expectation, so two facts on one line — "restore-required" and what it means —
 * cannot both be asserted. An operator reads the whole screen; so do these tests.
 *
 * @return array{0: int, 1: string}
 */
function releaseShow(): array
{
    $status = Artisan::call('release:show');

    return [$status, Artisan::output()];
}

/**
 * A manifest as `./flow release build` writes it, for a tagged release built against a predecessor.
 *
 * @return array<string, mixed>
 */
function releaseManifest(): array
{
    return [
        'manifest_version' => 1,
        'release_id' => '20260921T140311-'.substr(TAGGED_COMMIT, 0, 7),
        'version' => 'v1.2.0',
        'built_at' => '2026-09-21T14:03:11Z',
        'source' => [
            'ref' => 'v1.2.0',
            'commit' => TAGGED_COMMIT,
            'tag' => 'v1.2.0',
            'annotated_tag' => true,
            'on_main' => true,
            'provenance_override' => false,
        ],
        'lockfiles' => [
            'composer.lock' => str_repeat('a', 64),
            'package-lock.json' => str_repeat('b', 64),
        ],
        'migrations' => [
            'previous' => ['ref' => 'v1.1.0', 'commit' => PREVIOUS_COMMIT],
            'all' => ['2026_01_01_000001_create_things_table.php'],
            'new' => [],
            'removed' => [],
            'modified' => [],
            'scanner_findings' => [],
        ],
        'schema_rollback' => [
            'value' => 'not-applicable',
            'classified_by' => 'Ada Operator <ada@example.org>',
            'acknowledgement' => ['required' => false, 'provided' => false],
        ],
    ];
}

/** @param array<array-key, mixed>|string $manifest */
function shipManifest(array|string $manifest): void
{
    file_put_contents(
        ReleaseIdentity::path(app()),
        is_string($manifest) ? $manifest : json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );
}

/**
 * Set a nested key by dot path, or remove it with $remove.
 *
 * @param  array<array-key, mixed>  $manifest
 * @return array<array-key, mixed>
 */
function withField(array $manifest, string $path, mixed $value, bool $remove = false): array
{
    if ($remove) {
        Arr::forget($manifest, $path);
    } else {
        Arr::set($manifest, $path, $value);
    }

    return $manifest;
}

beforeEach(function () {
    // The suite runs in a source working tree, which has no manifest of its own.
    @unlink(ReleaseIdentity::path(app()));
});

afterEach(function () {
    // Never leave one behind: a stale manifest in a working tree would report an identity that is not
    // running. It is also git-ignored (apps/platform/.gitignore), so it could not be committed either.
    @unlink(ReleaseIdentity::path(app()));
});

describe('a well-formed release', function () {
    it('states the identity of a tagged release', function () {
        shipManifest(releaseManifest());

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('20260921T140311-a1b2c3d')
            ->and($output)->toContain('v1.2.0')
            ->and($output)->toContain(TAGGED_COMMIT)
            ->and($output)->toContain('2026-09-21T14:03:11Z')
            ->and($output)->toContain('built from the annotated tag v1.2.0, reachable from main');
    });

    it('states the rollback classification, and who decided it', function () {
        // The one line an operator acts on under pressure (deployment runbook section 3).
        shipManifest(withField(releaseManifest(), 'schema_rollback.value', 'restore-required'));

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('restore-required')
            ->and($output)->toContain('the database must be restored too')
            ->and($output)->toContain('Ada Operator <ada@example.org>');
    });

    it('names the previous release of an ordinary release', function () {
        shipManifest(releaseManifest());

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('v1.1.0 @ '.PREVIOUS_COMMIT);
    });

    it('says plainly that a first release has nothing to switch back to', function () {
        $manifest = withField(releaseManifest(), 'migrations.previous', null);
        shipManifest(withField($manifest, 'schema_rollback.value', 'restore-required'));

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('built as a FIRST release, so there is no earlier release to switch back to');
    });

    it('marks an --allow-untagged rehearsal build loudly, on every inspection', function () {
        $manifest = releaseManifest();
        $manifest['version'] = null;
        $manifest['source'] = [
            'ref' => 'HEAD', 'commit' => TAGGED_COMMIT, 'tag' => null,
            'annotated_tag' => false, 'on_main' => false, 'provenance_override' => true,
        ];
        shipManifest($manifest);

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('none (this build carries no version tag)')
            ->and($output)->toContain('does NOT meet the ADR 0027 provenance rule')
            ->and($output)->toContain('built with --allow-untagged: yes')
            ->and($output)->not->toContain('built from the annotated tag');
    });

    it('does not repeat build-time detail that is not part of the deployed identity', function () {
        // Lockfile digests and scanner findings are for deciding whether to BUILD; `./flow release inspect`
        // shows them. On the host the question is narrower, and a wall of hashes hides the answer.
        shipManifest(releaseManifest());

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->not->toContain(str_repeat('a', 64))
            ->and($output)->not->toContain('scanner');
    });
});

describe('a release that cannot identify itself', function () {
    it('fails in a source working tree, and says why rather than implying damage', function () {
        [$status, $output] = releaseShow();

        expect($status)->toBe(1, $output)
            ->and($output)->toContain('No release manifest at')
            ->and($output)->toContain('source working tree rather than a deployed release')
            ->and($output)->toContain('If this IS a deployed release, it is not identifiable and must not be trusted');
    });

    it('fails on malformed JSON', function () {
        shipManifest('{ "release_id": "20260921T140311-a1b2c3d",');

        [$status, $output] = releaseShow();

        expect($status)->toBe(1, $output)
            ->and($output)->toContain('is not valid')
            ->and($output)->toContain('Re-upload the artifact and verify its checksum');
    });

    it('fails on JSON that is not an object', function () {
        shipManifest('["not", "a", "manifest"]');

        [$status, $output] = releaseShow();

        expect($status)->toBe(1, $output)
            ->and($output)->toContain('not a JSON object');
    });

    $broken = [
        'the manifest version' => ['manifest_version', 2, false],
        'the release id' => ['release_id', null, true],
        'a malformed release id' => ['release_id', 'latest', false],
        'the commit' => ['source.commit', null, true],
        'a short commit' => ['source.commit', 'a1b2c3d', false],
        'the build time' => ['built_at', null, true],
        'the source ref' => ['source.ref', '', false],
        'the annotated-tag flag' => ['source.annotated_tag', null, true],
        'the on-main flag' => ['source.on_main', 'yes', false],
        'the override flag' => ['source.provenance_override', null, true],
        'the rollback classification' => ['schema_rollback.value', null, true],
        'an invented rollback classification' => ['schema_rollback.value', 'probably-fine', false],
        'who classified it' => ['schema_rollback.classified_by', '', false],
        'a malformed previous release' => ['migrations.previous', ['ref' => 'v1.1.0', 'commit' => 'abc'], false],
        'the source section' => ['source', null, true],
    ];

    foreach ($broken as $what => [$field, $value, $remove]) {
        it("fails without {$what}, rather than reporting it as unknown", function () use ($field, $value, $remove) {
            shipManifest(withField(releaseManifest(), $field, $value, $remove));

            [$status, $output] = releaseShow();

            expect($status)->toBe(1, $output)
                ->and($output)->toContain('The release manifest is missing or has an invalid');
        });
    }

    it('refuses a release id that does not name the commit it ships', function () {
        // A manifest copied between releases, or hand-edited, would otherwise report one commit while a
        // different one is executing.
        shipManifest(withField(releaseManifest(), 'release_id', '20260921T140311-0000000'));

        [$status, $output] = releaseShow();

        expect($status)->toBe(1, $output)
            ->and($output)->toContain('does not name the commit it ships');
    });
});

describe('what the command is not', function () {
    it('is read-only: the manifest is byte-for-byte unchanged afterwards', function () {
        shipManifest(releaseManifest());
        $path = ReleaseIdentity::path(app());
        $before = [(string) file_get_contents($path), filemtime($path)];

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output);

        clearstatcache();
        expect([(string) file_get_contents($path), filemtime($path)])->toBe($before);
    });

    it('reads the manifest from the application base path, not from where it was invoked', function () {
        // The release directory is the base path; the operator's working directory is not. Running it
        // from `/` must still find the manifest that shipped with this code.
        shipManifest(releaseManifest());
        $cwd = (string) getcwd();
        chdir('/');

        try {
            [$status, $output] = releaseShow();

            expect($status)->toBe(0, $output)
                ->and($output)->toContain(TAGGED_COMMIT);
        } finally {
            chdir($cwd);
        }
    });

    it('derives nothing from git, and depends on no repository', function () {
        // Production artifacts ship no .git (the release validator refuses one) and the host has no
        // repository. Identity comes from the manifest that shipped with the code, or from nowhere.
        foreach ([
            app_path('Modules/Release/Application/ReleaseIdentity.php'),
            app_path('Modules/Release/Infrastructure/Console/ShowReleaseCommand.php'),
        ] as $file) {
            $code = php_strip_whitespace($file);
            expect($code)->not->toMatch('/\b(exec|shell_exec|proc_open|passthru|system|popen)\s*\(/')
                ->and($code)->not->toContain("'.git")
                ->and($code)->not->toContain('git ')
                ->and($code)->not->toMatch('/https?:\/\//');
        }
    });

    it('has no HTTP surface: release identity stays off the public API', function () {
        // ADR 0027: /api/v1/health stays coarse and anonymous, and the document root denies /release.json.
        expect(is_dir(app_path('Modules/Release/Http')))->toBeFalse();

        getJson('/api/v1/health')->assertJsonMissingPath('release')->assertJsonMissingPath('commit');
    });

    it('never prints configuration secrets', function () {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('K', 32))]);
        shipManifest(releaseManifest());

        [$status, $output] = releaseShow();

        expect($status)->toBe(0, $output)
            ->and($output)->not->toContain(base64_encode(str_repeat('K', 32)))
            ->and($output)->not->toContain('APP_KEY');
    });
});

it('rejects a manifest programmatically with the reason, for callers other than the command', function () {
    expect(fn () => ReleaseIdentity::fromManifest(withField(releaseManifest(), 'schema_rollback.value', null)))
        ->toThrow(ReleaseIdentityUnavailable::class, 'schema_rollback.value');
});
