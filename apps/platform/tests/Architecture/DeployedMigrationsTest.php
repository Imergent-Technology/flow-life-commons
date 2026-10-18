<?php

declare(strict_types=1);

/*
 * A migration that has run on production is history, not code to improve (ADR 0027; G6 WP3a). Editing one changes what a fresh
 * database is built from without changing what production already ran, so the two silently diverge; deleting one does the same.
 * A new migration dated before the last deployed one runs in the middle of the sequence on a fresh database but last on production.
 * The fix for a deployed migration is always a NEW migration, dated after every deployed one.
 *
 * This pins the exact bytes of every migration a confirmed production release shipped, and requires every other migration to sort
 * after the last of them. It reads files only: it never requires a migration and needs no database. Bytes are stable across
 * platforms because .gitattributes checks every text file out with LF line endings.
 *
 * Extending it, after a release is CONFIRMED deployed (`php artisan release:show` on the host, docs/runbooks/deployment.md), never
 * before: add one block for that release, listing only the migrations it added, oldest first, each hash taken from the release's
 * annotated tag rather than the working tree:
 *
 *     git show vX.Y.Z:apps/platform/database/migrations/<name> | sha256sum
 *
 * Existing blocks never change. A reviewer checks a new block against the tag the same way.
 */

/**
 * Every confirmed production release, oldest first, with the migrations it added and the SHA-256 of each file's bytes.
 *
 * v0.3.0: tag `v0.3.0` -> commit 2f385b79a7b83323c6f15a6685ee610ccd85b5bf, release id 20260927T072528-2f385b7, confirmed on the
 * host with release:show by the product owner (docs/audits/g6/README.md §2). Its 15 migrations are the whole of production's schema.
 *
 * @return array<string, array<string, string>> release => [migration file name => sha256]
 */
function deployedMigrationReleases(): array
{
    return [
        'v0.3.0' => [
            '0001_01_01_000001_create_cache_table.php' => '08debeda8255b50ec3f8d541dfa879514cbb866fcaebd19323fe2468ef0f4b03',
            '0001_01_01_000002_create_jobs_table.php' => 'ea140f9f7863608b0fd779afc0ef2f5aadbe45e7a56413ced9aa55b7fea202f7',
            '2026_09_19_000001_create_people_table.php' => '494f3b77ecd600c3aabcf1108c5b536a98bccab7c61ac4b0053ba051a2d42002',
            '2026_09_19_000002_create_accounts_table.php' => '7460f0ce3793b92ec0ad0363c40f38d0ccea26554a1a414330d5c72a9fc65497',
            '2026_09_19_000003_create_account_invitations_table.php' => '4a0bc5fb7f6fe447ffd999d8317c856f283574607057752016f38726a33bb02d',
            '2026_09_20_000001_create_security_events_table.php' => '6f22dc21b1ba53a9690e3e23ec74cab9bc6d13ca92f13775b71809a620f5020c',
            '2026_09_20_000002_create_sessions_table.php' => '9bf07048157ea98d946c6028e7ab3ecc17e42541a9e0cc762d35c408a382064a',
            '2026_09_20_000003_create_role_assignments_table.php' => '8b63c71b45e5ca91fd8814565fc21ae7a95205cd8373cd32c6c3f52c5e2abf66',
            '2026_09_20_000004_add_role_key_index_to_role_assignments.php' => '95d37d2deb426c7538eb52477037b808e8fc0f142f760567e6795ecf35a7d194',
            '2026_09_20_000005_create_password_reset_tokens_table.php' => '1b287718ed579e4b1054d2fe3c9bc8ce53cab601422d4593bc9c6ffde1ab3f30',
            '2026_09_21_000001_create_account_totp_factors_table.php' => '0e88d5042fbd5929b0cf240c25c3a4112fbf71584a440a89d2355d9780804fa1',
            '2026_09_21_000002_create_account_recovery_codes_table.php' => 'aaf58ef06ffdbcb24650894dce871ec2a4057baa201d6297ba2affbd21a24c80',
            '2026_09_22_000001_add_channel_to_account_invitations.php' => '7efa72bd54f8c061afb3bee2217ae004c95ab2890655b6e0b996d7a4b1373337',
            '2026_09_23_000001_add_security_generation_to_accounts.php' => '430f1428a70de337d528fc5f7c4213c73d726b72c673d9af2f7deaf0c36296d3',
            '2026_09_23_000002_create_membership_grants_table.php' => 'deb4cc756b4d133e8d956a9236d89c7f03dcdffdc4efca48b9f8cf706d43102f',
        ],
    ];
}

/**
 * One sentence per way the migrations in $directory break the pinned history; none when they keep it.
 *
 * Migration files are found and ordered as Laravel's Migrator finds and orders them: `*_*.php`, by file name.
 *
 * @param  array<string, array<string, string>>  $releases  release => [file name => sha256], oldest first
 * @return list<string>
 */
function deployedMigrationViolations(string $directory, array $releases): array
{
    $violations = [];
    $present = array_map(basename(...), glob($directory.'/*_*.php') ?: []);
    usort($present, strcmp(...));

    $pinned = [];
    $last = null; // the entry before this one, in the manifest's order
    $latest = null; // the deployed migration that sorts last
    foreach ($releases as $release => $files) {
        foreach ($files as $name => $hash) {
            if (isset($pinned[$name])) {
                $violations[] = "{$name} is pinned twice ({$pinned[$name]} and {$release})";

                continue;
            }
            if ($last !== null && strcmp($name, $last['name']) <= 0) {
                $violations[] = "{$name} ({$release}) is pinned out of order: it does not sort after {$last['name']} ({$last['release']})";
            }
            $pinned[$name] = $release;
            $last = ['name' => $name, 'release' => $release];
            if ($latest === null || strcmp($name, $latest['name']) > 0) {
                $latest = $last;
            }

            $path = $directory.'/'.$name;
            if (! is_file($path)) {
                $violations[] = "{$name} was deployed in {$release} and is missing. A deployed migration is never deleted";

                continue;
            }
            $actual = hash_file('sha256', $path);
            if ($actual !== $hash) {
                $violations[] = "{$name} was deployed in {$release} and has changed (sha256 {$actual}, pinned {$hash}). "
                    .'Restore it and make the change in a new migration';
            }
        }
    }

    if ($latest !== null) {
        foreach ($present as $name) {
            if (! isset($pinned[$name]) && strcmp($name, $latest['name']) <= 0) {
                $violations[] = "{$name} is not deployed but sorts before {$latest['name']}, the last migration deployed ({$latest['release']}). "
                    .'It would run mid-sequence on a fresh database and last on production: date it after every deployed migration';
            }
        }
    }

    return $violations;
}

/** A throwaway migrations directory holding the given files, for the hand-built cases. */
function deployedMigrationFixture(string ...$files): string
{
    $directory = sys_get_temp_dir().'/deployed-migrations-'.bin2hex(random_bytes(6));
    mkdir($directory);
    foreach ($files as $name) {
        file_put_contents($directory.'/'.$name, "<?php\n\n// {$name}\n");
    }

    return $directory;
}

function removeDeployedMigrationFixture(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}

/** @return array<string, string> file name => sha256 of the fixture's content for that name */
function deployedMigrationFixtureHashes(string ...$files): array
{
    $hashes = [];
    foreach ($files as $name) {
        $hashes[$name] = hash('sha256', "<?php\n\n// {$name}\n");
    }

    return $hashes;
}

it('keeps every migration production has run byte for byte, and dates every other one after them', function () {
    expect(deployedMigrationViolations(dirname(__DIR__, 2).'/database/migrations', deployedMigrationReleases()))->toBe([]);
});

it('pins exactly the 15 migrations v0.3.0 shipped, the whole of production schema', function () {
    expect(deployedMigrationReleases())->toHaveKeys(['v0.3.0'])
        ->and(deployedMigrationReleases()['v0.3.0'])->toHaveCount(15)
        ->and(array_keys(deployedMigrationReleases()))->toBe(['v0.3.0']);
});

describe('the judgement, on hand-built directories', function () {
    $one = '2026_01_01_000001_first.php';
    $two = '2026_01_01_000002_second.php';
    $three = '2026_02_01_000001_third.php';

    it('accepts an untouched history and a new migration dated after it', function () use ($one, $two, $three) {
        $directory = deployedMigrationFixture($one, $two, $three);

        try {
            expect(deployedMigrationViolations($directory, ['v1' => deployedMigrationFixtureHashes($one, $two)]))->toBe([]);
        } finally {
            removeDeployedMigrationFixture($directory);
        }
    });

    it('rejects an edited deployed migration, a whitespace-only edit included', function (string $edit) use ($one, $two) {
        $directory = deployedMigrationFixture($one, $two);
        file_put_contents($directory.'/'.$two, $edit === 'whitespace' ? "<?php\n\n// {$two}\n\n" : "<?php\n\n// changed\n");

        try {
            $violations = deployedMigrationViolations($directory, ['v1' => deployedMigrationFixtureHashes($one, $two)]);
            expect($violations)->toHaveCount(1)
                ->and($violations[0])->toStartWith("{$two} was deployed in v1 and has changed");
        } finally {
            removeDeployedMigrationFixture($directory);
        }
    })->with(['content', 'whitespace']);

    it('rejects a deleted deployed migration', function () use ($one, $two) {
        $directory = deployedMigrationFixture($one);

        try {
            expect(deployedMigrationViolations($directory, ['v1' => deployedMigrationFixtureHashes($one, $two)]))
                ->toBe(["{$two} was deployed in v1 and is missing. A deployed migration is never deleted"]);
        } finally {
            removeDeployedMigrationFixture($directory);
        }
    });

    it('rejects a new migration dated before or among the deployed ones', function (string $backdated) use ($one, $two) {
        $directory = deployedMigrationFixture($one, $two, $backdated);

        try {
            $violations = deployedMigrationViolations($directory, ['v1' => deployedMigrationFixtureHashes($one, $two)]);
            expect($violations)->toHaveCount(1)
                ->and($violations[0])->toStartWith("{$backdated} is not deployed but sorts before {$two}, the last migration deployed (v1)");
        } finally {
            removeDeployedMigrationFixture($directory);
        }
    })->with([
        'before all of them' => '2025_12_31_000001_backdated.php',
        'between two of them' => '2026_01_01_000001_firsts.php',
    ]);

    it('judges later releases after earlier ones, and refuses a manifest whose releases are out of order', function () use ($one, $two, $three) {
        $directory = deployedMigrationFixture($one, $two, $three);

        try {
            expect(deployedMigrationViolations($directory, ['v1' => deployedMigrationFixtureHashes($one), 'v2' => deployedMigrationFixtureHashes($two, $three)]))->toBe([])
                ->and(deployedMigrationViolations($directory, ['v1' => deployedMigrationFixtureHashes($two), 'v2' => deployedMigrationFixtureHashes($one)]))
                ->toBe(["{$one} (v2) is pinned out of order: it does not sort after {$two} (v1)"]);
        } finally {
            removeDeployedMigrationFixture($directory);
        }
    });
});
