<?php

declare(strict_types=1);

/*
 * The rules ADR 0025 makes concrete. The mechanism only works if the pairing holds, so the pairing is a
 * source rule rather than a convention: revoking an Account's sessions and advancing its security
 * generation are ONE act, and a sixth such operation cannot be added with only half of it.
 *
 * One subject per expectation, and every scan carries a positive control (see README.md).
 */

$applicationDir = dirname(__DIR__, 2).'/app/Modules/Identity/Application';

/** @return array<string, string> class name => source, for every Identity Application class */
function generationSources(string $dir): array
{
    $found = [];
    foreach (new DirectoryIterator($dir) as $file) {
        if ($file->getExtension() === 'php') {
            $found[$file->getBasename('.php')] = (string) file_get_contents($file->getPathname());
        }
    }

    return $found;
}

/**
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function generationCallers(array $sources, string $pattern): array
{
    $hits = [];
    foreach ($sources as $class => $source) {
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $class;
        }
    }
    sort($hits);

    return $hits;
}

/** The five operations ADR 0025 names, and nothing else. */
$invalidating = ['ChangePassword', 'ConfirmAuthenticatorReplacement', 'DisableAccount', 'ResetMultiFactor', 'ResetPassword'];

it('advances the security generation in exactly the use cases that revoke sessions', function () use ($applicationDir, $invalidating) {
    $sources = generationSources($applicationDir);

    expect(generationCallers($sources, '/\$this->sessions->revokeAll/'))->toBe($invalidating);
});

it('revokes sessions in exactly the use cases that advance the security generation', function () use ($applicationDir, $invalidating) {
    // The other direction: an advance without a revocation is just as wrong, because it would sign
    // someone out without ending the rows, and the pair is what makes the invariant complete.
    $sources = generationSources($applicationDir);

    expect(generationCallers($sources, '/\$this->generations->advance\(/'))->toBe($invalidating);
});

it('keeps the security generation out of the Account aggregate', function () {
    // An aggregate is saved whole; a whole-row save of a copy read earlier would undo a concurrent
    // advance. Nothing in the Domain may know the column exists.
    $domain = dirname(__DIR__, 2).'/app/Modules/Identity/Domain';
    $offenders = [];
    foreach (new DirectoryIterator($domain) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'security_generation')) {
            $offenders[] = $file->getBasename();
        }
    }

    // Positive control: the same scan does find it where it legitimately lives.
    expect($offenders)->toBe([])
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Modules/Identity/Infrastructure/Persistence/DatabaseAccountSecurityGeneration.php'))
        ->toContain('security_generation');
});

it('names "security_generation" in exactly two places outside the migration', function () {
    // The column, which only its adapter may touch (a second raw reader is how an advance gets
    // forgotten or a read escapes a lock), and the session key of the same name, which only the
    // transport may write. Nothing else may spell it at all.
    $root = dirname(__DIR__, 2);
    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), "'security_generation'")) {
                $offenders[] = str_replace("{$root}/", '', $file->getPathname());
            }
        }
    }

    sort($offenders);
    expect($offenders)->toBe([
        'app/Modules/Identity/Http/ConsoleSession.php',
        'app/Modules/Identity/Infrastructure/Persistence/DatabaseAccountSecurityGeneration.php',
    ]);
});

it('binds a session to a generation only in the transport', function () {
    // Application and Domain must not touch the session; only ConsoleSession writes the mark.
    $root = dirname(__DIR__, 2);
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app", FilesystemIterator::SKIP_DOTS)) as $file) {
        assert($file instanceof SplFileInfo);
        $source = (string) file_get_contents($file->getPathname());
        if ($file->getExtension() === 'php' && preg_match('/session\(\)->put\(self::SECURITY_GENERATION/', $source) === 1) {
            $offenders[] = $file->getBasename();
        }
    }

    expect($offenders)->toBe(['ConsoleSession.php']);
});
