<?php

declare(strict_types=1);

/*
 * Release artifact inspector: `php artifact.php inspect <extracted-artifact-dir>`.
 *
 * Run by `./flow release build` (against the artifact it has just packaged) and by `./flow release
 * inspect`, inside the project's PHP image with the artifact mounted read-only and no network. It is
 * deliberately standalone: no Laravel, no Composer autoloader, no dependency on the tree it is
 * judging. The artifact is the thing under test, so nothing in it is trusted to run.
 *
 * It answers one question — "is this directory a well-formed Commons release?" — and prints every
 * reason it is not, rather than the first. Exit status: 0 valid, 1 invalid, 2 usage.
 *
 * What it enforces (docs/adr/0027-release-and-deployment-model.md):
 *   - the artifact holds exactly the documented top-level entries and nothing else (an allowlist, so
 *     `.env`, `tests/`, `.git`, or anything nobody thought of is refused by default);
 *   - no secrets or runtime state: env files, logs, dumps, keys, populated storage/, populated
 *     bootstrap/cache/ (cached configuration belongs to the host it was cached on);
 *   - no development dependencies, per Composer's own record of what it installed;
 *   - symlinks only inside vendor/ (Composer's bin links) and only relative and contained: the
 *     `storage` and `.env` links are made on the host from shared/, never shipped;
 *   - release.json is well-formed and internally consistent, including the rollback-classification
 *     rules the ADR gives a human ownership of, and its recorded composer.lock digest matches.
 */

const MANIFEST_VERSION = 1;
const ROLLBACK_VALUES = ['not-applicable', 'code-only', 'restore-required'];

/** The only entries permitted at the artifact root. */
const TOP_LEVEL = [
    'app', 'artisan', 'bootstrap', 'composer.json', 'composer.lock', 'config',
    'database', 'public', 'release.json', 'routes', 'storage', 'vendor',
];

const REQUIRED_FILES = [
    'release.json', 'artisan', 'composer.json', 'composer.lock',
    'bootstrap/app.php', 'bootstrap/providers.php',
    'public/index.php', 'public/.htaccess', 'public/index.html',
    'vendor/autoload.php', 'vendor/composer/installed.json',
];

const REQUIRED_DIRS = [
    'app', 'config', 'database/migrations', 'routes',
    'storage', 'bootstrap/cache', 'public/assets',
];

/** Directory names that must not exist anywhere (`.git` even inside vendor). */
const FORBIDDEN_DIRS_ANYWHERE = ['.git'];

/** Directory names that must not exist outside vendor/ (packages legitimately ship their own tests). */
const FORBIDDEN_DIRS_OUTSIDE_VENDOR = ['node_modules', 'tests', '.github', '.idea', '.vscode'];

/** File-name patterns that must not exist outside vendor/. */
const FORBIDDEN_FILE_PATTERNS = [
    '/^\.env($|\.)/' => 'an environment file',
    '/^auth\.json$/' => 'Composer credentials',
    '/\.(log|sqlite|sqlite3|sql|dump|bak|orig|swp)$/i' => 'runtime state or a backup',
    '/\.(pem|key|p12|pfx)$/i' => 'key material',
    '/^(\.DS_Store|Thumbs\.db)$/' => 'editor or OS debris',
    '/^(\.gitattributes|\.editorconfig|phpunit\.xml(\.dist)?|phpstan\.neon(\.dist)?|pint\.json)$/' => 'development configuration',
];

/** Dev-only commands `./flow` has no business shipping; a Vite dev-server marker in the Console shell. */
const DEV_SERVER_MARKERS = ['@vite/client', 'localhost:5173'];

$problems = [];
$notes = [];

function problem(string $message): void
{
    global $problems;
    $problems[] = $message;
}

function note(string $message): void
{
    global $notes;
    $notes[] = $message;
}

/** @return list<string> paths relative to $root, depth-first, without following symlinks */
function walk(string $root): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $path => $info) {
        $found[] = substr((string) $path, strlen($root) + 1);
        unset($info);
    }
    sort($found, SORT_STRING);

    return $found;
}

function inVendor(string $relative): bool
{
    return str_starts_with($relative, 'vendor/');
}

function inSkeleton(string $relative): bool
{
    return str_starts_with($relative, 'storage/') || str_starts_with($relative, 'bootstrap/cache/');
}

function isList(mixed $value): bool
{
    return is_array($value) && array_is_list($value);
}

/** @param mixed $value */
function isStringList(mixed $value): bool
{
    if (! isList($value)) {
        return false;
    }
    foreach ($value as $item) {
        if (! is_string($item)) {
            return false;
        }
    }

    return true;
}

function checkTree(string $root): void
{
    $rootReal = realpath($root);
    if ($rootReal === false) {
        problem("cannot resolve $root");

        return;
    }

    $top = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
    foreach ($top as $entry) {
        if (! in_array($entry, TOP_LEVEL, true)) {
            problem("unexpected top-level entry '$entry' (the artifact holds only: ".implode(', ', TOP_LEVEL).')');
        }
    }

    foreach (REQUIRED_FILES as $file) {
        if (! is_file("$root/$file") || is_link("$root/$file")) {
            problem("required file missing: $file");
        }
    }
    foreach (REQUIRED_DIRS as $dir) {
        if (! is_dir("$root/$dir") || is_link("$root/$dir")) {
            problem("required directory missing (or a symlink): $dir");
        }
    }
    if (is_dir("$root/public/assets") && count(array_diff(scandir("$root/public/assets") ?: [], ['.', '..'])) === 0) {
        problem('public/assets is empty: the Console build was not assembled into the artifact');
    }

    foreach (walk($root) as $relative) {
        $full = "$root/$relative";
        $name = basename($relative);
        $vendor = inVendor($relative);

        if (is_link($full)) {
            checkSymlink($rootReal, $relative, $full, $vendor);

            continue;
        }

        if (! is_dir($full) && ! is_file($full)) {
            problem("not a regular file or directory: $relative");

            continue;
        }
        if ((fileperms($full) & 0002) !== 0) {
            problem("world-writable: $relative");
        }

        if (is_dir($full)) {
            if (in_array($name, FORBIDDEN_DIRS_ANYWHERE, true)) {
                problem("forbidden directory: $relative");
            }
            if (! $vendor && in_array($name, FORBIDDEN_DIRS_OUTSIDE_VENDOR, true)) {
                problem("forbidden directory: $relative");
            }

            continue;
        }

        // A file. Vendor packages are third-party trees and are judged by Composer's record below,
        // apart from the one file name that must never exist anywhere.
        if ($vendor) {
            if ($name === '.env') {
                problem("environment file inside vendor/: $relative");
            }

            continue;
        }

        // An inert placeholder (Laravel tracks them in storage/, bootstrap/cache/ and database/). The
        // skeleton rule below still makes them the ONLY thing allowed in storage/ and bootstrap/cache/.
        if ($name === '.gitignore') {
            continue;
        }
        if (inSkeleton($relative)) {
            problem("runtime state in the shipped skeleton (only .gitignore placeholders belong there): $relative");

            continue;
        }
        foreach (FORBIDDEN_FILE_PATTERNS as $pattern => $what) {
            if (preg_match($pattern, $name) === 1) {
                problem("forbidden file ($what): $relative");
            }
        }
        if ($relative === 'public/hot') {
            problem('public/hot marks a running Vite dev server: public/hot');
        }
    }

    checkPublicSurface($root);
    checkVendorRecord($root);
}

function checkSymlink(string $rootReal, string $relative, string $full, bool $vendor): void
{
    if (! $vendor) {
        problem("symlink outside vendor/: $relative (the storage and .env links are created on the host from shared/, never shipped)");

        return;
    }
    $target = readlink($full);
    if ($target === false || str_starts_with($target, '/')) {
        problem("vendor symlink is absolute or unreadable: $relative");

        return;
    }
    $resolved = realpath($full);
    if ($resolved === false) {
        problem("vendor symlink is broken: $relative -> $target");

        return;
    }
    if (! str_starts_with($resolved.'/', $rootReal.'/')) {
        problem("vendor symlink escapes the artifact: $relative -> $target");
    }
}

function checkPublicSurface(string $root): void
{
    $index = "$root/public/index.html";
    if (is_file($index)) {
        $html = (string) file_get_contents($index);
        foreach (DEV_SERVER_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                problem("public/index.html references the Vite dev server ('$marker'): this is not a production build");
            }
        }
    }

    // Until the public-surface work lands (composed .htaccess, standalone maintenance responder) an
    // artifact cannot serve the static half's maintenance page. Said plainly rather than passed
    // silently; it becomes a required file when that work exists.
    $htaccess = is_file("$root/public/.htaccess") ? (string) file_get_contents("$root/public/.htaccess") : '';
    if (! is_file("$root/public/maintenance.php") || ! str_contains($htaccess, 'maintenance.php')) {
        note('NOT DEPLOYABLE YET: no public/maintenance.php responder and no maintenance rule in public/.htaccess (deployment runbook §4). '
            .'This artifact is for rehearsal and inspection only.');
    }
}

function checkVendorRecord(string $root): void
{
    $file = "$root/vendor/composer/installed.json";
    if (! is_file($file)) {
        return; // already reported as missing
    }
    try {
        $installed = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        problem('vendor/composer/installed.json is not valid JSON: '.$e->getMessage());

        return;
    }
    if (! is_array($installed) || ($installed['dev'] ?? null) !== false) {
        problem("vendor/composer/installed.json does not record a --no-dev install (dev must be false)");
    }
    $devPackages = is_array($installed) ? ($installed['dev-package-names'] ?? null) : null;
    if ($devPackages !== [] && $devPackages !== null) {
        problem('development packages are installed in vendor/: '.implode(', ', array_map('strval', (array) $devPackages)));
    }
}

/** @return array<string, mixed>|null */
function loadManifest(string $root): ?array
{
    $file = "$root/release.json";
    if (! is_file($file)) {
        return null; // already reported as missing
    }
    try {
        $manifest = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        problem('release.json is not valid JSON: '.$e->getMessage());

        return null;
    }
    if (! is_array($manifest) || array_is_list($manifest)) {
        problem('release.json must be a JSON object');

        return null;
    }

    return $manifest;
}

/**
 * @param  array<string, mixed>  $m
 * @return array<string, mixed>
 */
function section(array $m, string $key): array
{
    $value = $m[$key] ?? null;
    if (! is_array($value) || array_is_list($value) && $value !== []) {
        problem("release.json: '$key' must be an object");

        return [];
    }

    return $value;
}

/** @param array<string, mixed> $m */
function checkManifest(string $root, array $m): void
{
    if (($m['manifest_version'] ?? null) !== MANIFEST_VERSION) {
        problem('release.json: manifest_version must be '.MANIFEST_VERSION);
    }

    $releaseId = $m['release_id'] ?? null;
    $source = section($m, 'source');
    $commit = $source['commit'] ?? null;
    if (! is_string($releaseId) || preg_match('/^\d{8}T\d{6}-[0-9a-f]{7}$/', $releaseId) !== 1) {
        problem("release.json: release_id must look like 20260921T140311-96c9eb1");
    }
    if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        problem('release.json: source.commit must be a full 40-character sha');
    } elseif (is_string($releaseId) && ! str_ends_with($releaseId, '-'.substr($commit, 0, 7))) {
        problem('release.json: release_id does not end in the short sha of source.commit');
    }
    if (! is_string($m['built_at'] ?? null) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $m['built_at']) !== 1) {
        problem('release.json: built_at must be a UTC ISO-8601 timestamp');
    }

    // Provenance: the ADR's "normally an annotated tag reachable from main" is enforced at build; the
    // manifest must say honestly whether it was met.
    $annotated = $source['annotated_tag'] ?? null;
    $onMain = $source['on_main'] ?? null;
    $override = $source['provenance_override'] ?? null;
    if (! is_bool($annotated) || ! is_bool($onMain) || ! is_bool($override)) {
        problem('release.json: source.annotated_tag, on_main and provenance_override must be booleans');
    } else {
        $met = $annotated && $onMain;
        if ($met && $override) {
            problem('release.json: provenance_override is set although the provenance requirements were met');
        }
        if (! $met && ! $override) {
            problem('release.json: provenance requirements were not met and no override is recorded');
        }
        if ($annotated && ! is_string($source['tag'] ?? null)) {
            problem('release.json: source.tag must name the annotated tag');
        }
        if ($override) {
            note('BUILT WITH --allow-untagged: this artifact does NOT meet the ADR 0027 provenance rule '
                .'(annotated tag reachable from main). annotated_tag='.json_encode($annotated).' on_main='.json_encode($onMain).'.');
        }
    }

    $lockfiles = section($m, 'lockfiles');
    $composerLock = "$root/composer.lock";
    $recorded = $lockfiles['composer.lock'] ?? null;
    if (! is_string($recorded) || preg_match('/^[0-9a-f]{64}$/', $recorded) !== 1) {
        problem('release.json: lockfiles.composer.lock must be a sha256');
    } elseif (is_file($composerLock) && hash_file('sha256', $composerLock) !== $recorded) {
        problem('release.json: lockfiles.composer.lock does not match the composer.lock inside the artifact');
    }
    if (! is_string($lockfiles['package-lock.json'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $lockfiles['package-lock.json']) !== 1) {
        problem('release.json: lockfiles.package-lock.json must be a sha256');
    }

    checkMigrations($root, $m);
}

/** @param array<string, mixed> $m */
function checkMigrations(string $root, array $m): void
{
    $migrations = section($m, 'migrations');
    $rollback = section($m, 'schema_rollback');

    foreach (['all', 'new', 'removed', 'modified'] as $key) {
        if (! isStringList($migrations[$key] ?? null)) {
            problem("release.json: migrations.$key must be a list of names");
        }
    }
    if (! isList($migrations['scanner_findings'] ?? null)) {
        problem('release.json: migrations.scanner_findings must be a list');
        $findings = [];
    } else {
        $findings = $migrations['scanner_findings'];
        foreach ($findings as $finding) {
            if (! is_array($finding)
                || ! is_string($finding['migration'] ?? null)
                || ! in_array($finding['kind'] ?? null, ['keyword', 'removed', 'modified', 'unparsed'], true)
                || ! is_int($finding['line'] ?? null)
                || ! is_string($finding['text'] ?? null)) {
                problem('release.json: a scanner finding is malformed (needs migration, kind, line, text)');
                break;
            }
        }
    }

    // The manifest's migration list must be the artifact's migration directory, not an assertion about it.
    $onDisk = [];
    if (is_dir("$root/database/migrations")) {
        foreach (scandir("$root/database/migrations") ?: [] as $entry) {
            if (str_ends_with($entry, '.php')) {
                $onDisk[] = $entry;
            }
        }
        sort($onDisk, SORT_STRING);
    }
    $all = isStringList($migrations['all'] ?? null) ? $migrations['all'] : [];
    if ($all !== $onDisk) {
        problem('release.json: migrations.all does not match database/migrations/ inside the artifact');
    }
    $new = isStringList($migrations['new'] ?? null) ? $migrations['new'] : [];
    $removed = isStringList($migrations['removed'] ?? null) ? $migrations['removed'] : [];
    $modified = isStringList($migrations['modified'] ?? null) ? $migrations['modified'] : [];
    if (array_diff($new, $all) !== [] || array_diff($modified, $all) !== []) {
        problem('release.json: migrations.new and migrations.modified must be subsets of migrations.all');
    }
    if (array_intersect($removed, $all) !== []) {
        problem('release.json: migrations.removed lists a migration that is present in the artifact');
    }

    $previous = $migrations['previous'] ?? null;
    if ($previous !== null) {
        if (! is_array($previous) || ! is_string($previous['ref'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/', (string) ($previous['commit'] ?? '')) !== 1) {
            problem('release.json: migrations.previous must be null or {ref, commit} with a full sha');
        }
    } elseif ($removed !== [] || $modified !== []) {
        problem('release.json: with no previous release nothing can be removed or modified');
    } elseif ($new !== $all) {
        problem('release.json: with no previous release every migration is new');
    }

    // The classification: a human's decision, checked here only for consistency with the facts.
    $value = $rollback['value'] ?? null;
    if (! in_array($value, ROLLBACK_VALUES, true)) {
        problem('release.json: schema_rollback.value must be one of '.implode(', ', ROLLBACK_VALUES));

        return;
    }
    $by = $rollback['classified_by'] ?? null;
    if (! is_string($by) || trim($by) === '' || strlen($by) > 200) {
        problem('release.json: schema_rollback.classified_by must record who supplied the classification');
    }
    if ($previous === null && $value !== 'restore-required') {
        problem('release.json: a first release (no previous) must be classified restore-required');
    }
    if ($previous !== null && ($new === []) !== ($value === 'not-applicable')) {
        problem('release.json: not-applicable is valid exactly when there are no new migrations');
    }

    $ack = $rollback['acknowledgement'] ?? null;
    $needed = $findings !== [] && $value !== 'restore-required';
    if (! is_array($ack) || ! is_bool($ack['required'] ?? null) || ! is_bool($ack['provided'] ?? null)) {
        problem('release.json: schema_rollback.acknowledgement needs boolean required and provided');
    } else {
        if ($ack['required'] !== $needed) {
            problem('release.json: acknowledgement.required disagrees with the findings and the classification');
        }
        if ($needed && $ack['provided'] !== true) {
            problem('release.json: scanner findings with a classification below restore-required need the acknowledgement, and none is recorded');
        }
    }
}

/** @param array<string, mixed> $m */
function printSummary(array $m): void
{
    $source = is_array($m['source'] ?? null) ? $m['source'] : [];
    $migrations = is_array($m['migrations'] ?? null) ? $m['migrations'] : [];
    $rollback = is_array($m['schema_rollback'] ?? null) ? $m['schema_rollback'] : [];
    $lockfiles = is_array($m['lockfiles'] ?? null) ? $m['lockfiles'] : [];
    $previous = $migrations['previous'] ?? null;
    $ack = is_array($rollback['acknowledgement'] ?? null) ? $rollback['acknowledgement'] : [];
    $show = static fn (mixed $v): string => is_string($v) || is_int($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES);
    $list = static fn (mixed $v): string => isStringList($v) && $v !== [] ? "\n      ".implode("\n      ", $v) : ' none';

    echo "  release id       {$show($m['release_id'] ?? '?')}\n";
    echo "  version          {$show($m['version'] ?? null)}\n";
    echo "  built            {$show($m['built_at'] ?? '?')}\n";
    echo "  commit           {$show($source['commit'] ?? '?')}\n";
    echo "  ref              {$show($source['ref'] ?? '?')}   (annotated tag: {$show($source['annotated_tag'] ?? '?')}, on main: {$show($source['on_main'] ?? '?')}, override: {$show($source['provenance_override'] ?? '?')})\n";
    echo "  composer.lock    {$show($lockfiles['composer.lock'] ?? '?')}\n";
    echo "  package-lock     {$show($lockfiles['package-lock.json'] ?? '?')}  (recorded; the Console source is not in the artifact)\n";
    echo '  previous release '.(is_array($previous) ? "{$show($previous['ref'] ?? '?')} @ {$show($previous['commit'] ?? '?')}" : 'none (first release)')."\n";
    echo '  migrations       '.count(isStringList($migrations['all'] ?? null) ? $migrations['all'] : []).' total; new:'.$list($migrations['new'] ?? null)."\n";
    if (isStringList($migrations['removed'] ?? null) && $migrations['removed'] !== []) {
        echo '      removed since previous:'.$list($migrations['removed'])."\n";
    }
    if (isStringList($migrations['modified'] ?? null) && $migrations['modified'] !== []) {
        echo '      modified since previous:'.$list($migrations['modified'])."\n";
    }
    $findings = isList($migrations['scanner_findings'] ?? null) ? $migrations['scanner_findings'] : [];
    echo '  scanner findings '.($findings === [] ? "none (a clean scan proves nothing: see ADR 0027)\n" : count($findings)."\n");
    foreach ($findings as $f) {
        if (is_array($f)) {
            echo "      {$show($f['migration'] ?? '?')}:{$show($f['line'] ?? '?')} [{$show($f['kind'] ?? '?')}] {$show($f['text'] ?? '')}\n";
        }
    }
    echo "  schema_rollback  {$show($rollback['value'] ?? '?')}\n";
    echo "  classified by    {$show($rollback['classified_by'] ?? '?')}\n";
    echo "  acknowledgement  required: {$show($ack['required'] ?? '?')}, provided: {$show($ack['provided'] ?? '?')}\n";
}

function main(array $argv): int
{
    if (($argv[1] ?? '') !== 'inspect' || ! isset($argv[2]) || count($argv) !== 3) {
        fwrite(STDERR, "usage: php artifact.php inspect <extracted-artifact-dir>\n");

        return 2;
    }
    $root = rtrim($argv[2], '/');
    if (! is_dir($root)) {
        fwrite(STDERR, "not a directory: $root\n");

        return 2;
    }

    checkTree($root);
    $manifest = loadManifest($root);
    if ($manifest !== null) {
        checkManifest($root, $manifest);
        printSummary($manifest);
    }

    global $problems, $notes;
    echo "\n";
    foreach ($notes as $n) {
        echo "  ! $n\n";
    }
    if ($problems !== []) {
        foreach ($problems as $p) {
            echo "  x $p\n";
        }
        echo '  INVALID: '.count($problems)." problem(s)\n";

        return 1;
    }
    echo "  VALID\n";

    return 0;
}

exit(main($argv));
