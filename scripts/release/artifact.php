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
    'public/index.php', 'public/.htaccess', 'public/index.html', 'public/maintenance.php',
    'vendor/autoload.php', 'vendor/composer/installed.json',
];

const REQUIRED_DIRS = [
    'app', 'config', 'database/migrations', 'routes',
    'storage', 'bootstrap/cache', 'public/assets',
];

/**
 * The persistent-storage skeleton: EXACTLY these placeholder files, and exactly this content, may
 * exist under storage/ or bootstrap/cache/. This is pinned tighter than an ordinary allowlist because
 * the deployment runbook seeds `shared/storage` from this exact tree with a non-destructive copy
 * (`cp -an storage/. ../../shared/storage/`) on first deployment, so whatever ships here can become
 * PERSISTENT host state, forever, silently: `cp -an` never overwrites an existing file, so wrong
 * content here is wrong content on the host the moment it is first seeded and on every host after that
 * until someone notices and removes it by hand.
 *
 * Content is pinned, not merely presence, for the same reason: a `.gitignore` that happens to be named
 * right but carries something else (a stray value, a different Laravel version's skeleton) would still
 * pass a name-only check and still become permanent.
 */
const STORAGE_SKELETON = [
    'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
    'storage/app/.gitignore' => "*\n!private/\n!public/\n!.gitignore\n",
    'storage/app/private/.gitignore' => "*\n!.gitignore\n",
    'storage/app/public/.gitignore' => "*\n!.gitignore\n",
    'storage/framework/.gitignore' => "compiled.php\nconfig.php\ndown\nevents.scanned.php\nlsp-*.php\nmaintenance.php\nroutes.php\nroutes.scanned.php\nschedule-*\nservices.json\n",
    'storage/framework/cache/.gitignore' => "*\n!data/\n!.gitignore\n",
    'storage/framework/cache/data/.gitignore' => "*\n!.gitignore\n",
    'storage/framework/sessions/.gitignore' => "*\n!.gitignore\n",
    'storage/framework/testing/.gitignore' => "*\n!.gitignore\n",
    'storage/framework/views/.gitignore' => "*\n!.gitignore\n",
    'storage/logs/.gitignore' => "*\n!.gitignore\n",
];

/**
 * Every directory the skeleton is allowed to create. A directory NAMED `down` here (rather than the
 * regular file `php artisan down` writes) would make `file_exists(storage/framework/down)` true for
 * Laravel's own front-controller check while Apache's `-f` test (a regular-file test) stays false: the
 * two maintenance enforcement points would disagree about whether the site is down, and `php artisan
 * up`, which calls `unlink()`, cannot remove a directory at all.
 */
const STORAGE_SKELETON_DIRS = [
    'bootstrap/cache',
    'storage',
    'storage/app',
    'storage/app/private',
    'storage/app/public',
    'storage/framework',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/testing',
    'storage/framework/views',
    'storage/logs',
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

/**
 * The only entries permitted in public/, which IS the production document root: the platform's own
 * public files plus the Console's build merged into them. Anything else is served to the world, so a
 * new entry has to be added here deliberately rather than arriving with a build-tool upgrade.
 */
const PUBLIC_TOP_LEVEL = [
    '.htaccess', 'assets', 'favicon.ico', 'index.html', 'index.php', 'maintenance.php', 'robots.txt',
];

/**
 * Rule classes the composed .htaccess must carry (ADR 0027; deployment runbook sections 4 and 8).
 *
 * Deliberately NOT an Apache parser. It checks that each class of rule is present, that the
 * mechanisms known to be wrong are absent, and that the few orderings that matter hold. Behaviour is
 * proved against a live origin by the browser suite; pretending to evaluate mod_rewrite here would
 * only prove that two implementations of it agree.
 */
const HTACCESS_REQUIRED = [
    'DirectoryIndex index.html index.php' => 'the Console shell must answer / ahead of the front controller',
    'Content-Security-Policy' => 'the generated browser security policy (ADR 0026)',
    '%{DOCUMENT_ROOT}/../storage/framework/down -f' => 'the maintenance arm must read Laravel\'s own flag through the storage symlink',
    '/maintenance.php [L]' => 'maintenance must be an INTERNAL rewrite to the standalone responder',
    '!^/(api($|/)|up$|maintenance\\.php$)' => 'the API, /up and the responder must be excluded from the maintenance rewrite',
    'index.php [END]' => 'the API and /up must reach Laravel\'s front controller, and END rather than L so Apache\'s own internal redirect cannot re-run this ruleset and let the maintenance rule catch the rewritten request on a second pass',
    '/index.html [L]' => 'the Console\'s client-side routes must fall back to its shell',
    '[F,L]' => 'private paths must be denied',
];

/** Mechanisms that must never come back. The first three failed on this host; the rest are not ours. */
const HTACCESS_FORBIDDEN = [
    'ErrorDocument' => 'the ErrorDocument idiom returned the server\'s own bare 503 body on this host',
    'R=503' => 'the maintenance rewrite is internal ([L]); an external redirect was measured not to work',
    'index.php [L]' => 'measured on a real Apache container: [L] lets Apache\'s own internal redirect to '
        .'index.php re-run this ruleset, and the maintenance rule then catches the rewritten request on '
        .'that second pass, sending every /api and /up request to the HTML responder instead of Laravel '
        .'while the flag is raised. The API and /up rewrite must use [END].',
    'maintenance.html' => 'the responder is public/maintenance.php, which sets its own status and headers',
    'LSCache' => 'Commons is direct to origin: there is no edge cache to purge',
    'X-Forwarded' => 'Commons trusts no reverse proxy (trust boundaries)',
];

/** The responder must answer when the release around it does not, so it may load nothing. */
const RESPONDER_FORBIDDEN = [
    'vendor/autoload' => 'Composer\'s autoloader',
    'bootstrap/app' => 'the Laravel application',
    'Illuminate' => 'the framework',
    '$_ENV' => 'the environment',
    'getenv' => 'the environment',
];

const RESPONDER_REQUIRED = [
    'http_response_code(503)' => 'the 503 status',
    'Retry-After: 120' => 'the Retry-After header',
    'Cache-Control: no-store, no-cache, must-revalidate' => 'the Cache-Control header',
    'text/html; charset=utf-8' => 'the Content-Type header',
];

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

/**
 * A non-empty, non-control-character string, matching what `ReleaseIdentity::string()`
 * (app/Modules/Release/Application/ReleaseIdentity.php) requires of the same field at read time on the
 * host — this exists so a manifest this validator accepts cannot later be rejected, or accepted with a
 * different meaning, there. `ReleaseIdentity` itself only requires non-empty after trim; the control-
 * character rule is stricter than that on purpose, matching what `./flow release build` already
 * refuses for `classified_by` (scripts/commands/release.sh), because these values are echoed to an
 * operator's terminal by `release:show` and a control character there is a display-injection risk,
 * not merely a cosmetic one.
 */
function isCleanString(mixed $value): bool
{
    return is_string($value) && trim($value) !== '' && preg_match('/[[:cntrl:]]/', $value) !== 1;
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
        // setuid/setgid: GNU tar preserves these bits on extraction (release_verify_artifact extracts
        // with --same-permissions), so a bit set inside the archive's own metadata is a bit that really
        // exists on the extracted tree afterward, not merely a number this validator computed. Nothing
        // this build produces sets either deliberately, so any occurrence is either a tampered archive
        // or a packaging accident — refused either way, files and directories alike.
        if ((fileperms($full) & 06000) !== 0) {
            problem("carries a setuid or setgid bit: $relative");
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
    checkStorageSkeleton($root);
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

    // public/ IS the document root. Everything in it is served.
    foreach (array_diff(scandir("$root/public") ?: [], ['.', '..']) as $entry) {
        if (! in_array($entry, PUBLIC_TOP_LEVEL, true)) {
            problem("unexpected entry in the document root: public/$entry (permitted: ".implode(', ', PUBLIC_TOP_LEVEL).')');
        }
    }

    checkHtaccess($root);
    checkResponder($root);
}

/** Commentary names the mechanisms that must not be used, and explains why; absence is about rules. */
function withoutComments(string $text, string $marker): string
{
    return implode("\n", array_filter(
        explode("\n", $text),
        static fn (string $line): bool => ! str_starts_with(ltrim($line), $marker),
    ));
}

function checkHtaccess(string $root): void
{
    $file = "$root/public/.htaccess";
    if (! is_file($file)) {
        return; // already reported as missing
    }
    $htaccess = (string) file_get_contents($file);
    $rules = withoutComments($htaccess, '#');

    foreach (HTACCESS_REQUIRED as $needle => $why) {
        if (! str_contains($rules, $needle)) {
            problem("public/.htaccess is missing a required rule ($why): expected to find '$needle'");
        }
    }
    foreach (HTACCESS_FORBIDDEN as $needle => $why) {
        if (str_contains($rules, $needle)) {
            problem("public/.htaccess uses a forbidden mechanism '$needle' ($why)");
        }
    }

    // The orderings that matter. Each of these, reversed, still serves an ordinary request correctly
    // and fails only for the request class nobody tried by hand.
    $denials = strpos($rules, '[F,L]');
    $maintenance = strpos($rules, '%{DOCUMENT_ROOT}/../storage/framework/down -f');
    $api = strpos($rules, 'index.php [END]');
    $fallback = strpos($rules, '/index.html [L]');
    if ($denials === false || $maintenance === false || $api === false || $fallback === false) {
        return; // each absence is already reported above
    }
    if ($denials > $maintenance) {
        problem('public/.htaccess denies private paths AFTER the maintenance arm: during an outage a private path would be answered with the maintenance page instead of being refused');
    }
    if ($denials > $fallback) {
        problem("public/.htaccess denies private paths AFTER the SPA fallback: a private path would be answered 200 with the Console's shell");
    }
    if ($api > $fallback) {
        problem("public/.htaccess routes the API AFTER the SPA fallback: an unknown API path would be answered 200 with the Console's shell instead of Laravel's JSON 404");
    }
    if ($maintenance > $fallback) {
        problem('public/.htaccess places the maintenance arm AFTER the SPA fallback: the Console would keep serving while the platform is down');
    }
}

function checkResponder(string $root): void
{
    $file = "$root/public/maintenance.php";
    if (! is_file($file)) {
        return; // already reported as missing
    }
    $responder = (string) file_get_contents($file);
    $code = withoutComments(preg_replace('#/\*.*?\*/#s', '', $responder) ?? $responder, '//');

    foreach (RESPONDER_REQUIRED as $needle => $what) {
        if (! str_contains($responder, $needle)) {
            problem("public/maintenance.php does not set $what (expected '$needle')");
        }
    }
    foreach (RESPONDER_FORBIDDEN as $needle => $what) {
        if (str_contains($code, $needle)) {
            problem("public/maintenance.php loads $what ('$needle'): the responder must answer even when the release around it is broken");
        }
    }
    if (preg_match('/\b(require|require_once|include|include_once)\b/', $code) === 1) {
        problem('public/maintenance.php includes another file: it must be self-contained');
    }
    if (! str_contains($responder, '<h1>Down for maintenance</h1>')) {
        problem('public/maintenance.php does not emit the maintenance page');
    }
    if (preg_match('/<(script|link|img)\b/', $code) === 1) {
        problem('public/maintenance.php references an external asset: the maintenance rewrite intercepts assets too, so it would be answered with this same page');
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

/**
 * The persistent-storage skeleton, pinned exactly (STORAGE_SKELETON, STORAGE_SKELETON_DIRS above).
 * Stronger than the generic per-file loop in checkTree(), which only refuses a non-`.gitignore` FILE
 * under storage/ or bootstrap/cache/: it does not know that a DIRECTORY named `down` is exactly as
 * dangerous as a file of the wrong content, because `cp -an` will seed either one into shared storage
 * and neither is a mistake the generic loop is positioned to see. A symlink here is already refused by
 * checkSymlink (anything outside vendor/ is a problem), so it is not re-checked here.
 */
function checkStorageSkeleton(string $root): void
{
    foreach (['storage', 'bootstrap/cache'] as $prefix) {
        if (! is_dir("$root/$prefix") || is_link("$root/$prefix")) {
            continue; // already reported: a required directory missing (or itself a symlink)
        }
        foreach (walk("$root/$prefix") as $rel) {
            $relative = "$prefix/$rel";
            $full = "$root/$relative";
            if (is_link($full)) {
                continue; // already reported by checkSymlink: a symlink outside vendor/ is a problem
            }
            if (is_dir($full)) {
                if (! in_array($relative, STORAGE_SKELETON_DIRS, true)) {
                    problem("unexpected directory in the persistent-storage skeleton: $relative (this tree "
                        .'is seeded into shared/storage on first deployment, so only the documented skeleton '
                        .'directories may exist under storage/ or bootstrap/cache/)');
                }

                continue;
            }
            if (! array_key_exists($relative, STORAGE_SKELETON)) {
                problem("unexpected file in the persistent-storage skeleton: $relative (only the documented "
                    .'.gitignore placeholders may exist under storage/ or bootstrap/cache/, because this tree '
                    .'is seeded into shared/storage on first deployment)');

                continue;
            }
            $content = (string) file_get_contents($full);
            if ($content !== STORAGE_SKELETON[$relative]) {
                problem("$relative does not carry its expected placeholder content: shipping different "
                    .'content here makes that content PERSISTENT on the host, since the deployment runbook '
                    .'seeds shared/storage from this tree with a copy that never overwrites an existing file');
            }
        }
    }
    foreach (array_keys(STORAGE_SKELETON) as $relative) {
        if (! is_file("$root/$relative") || is_link("$root/$relative")) {
            problem("missing persistent-storage skeleton placeholder: $relative");
        }
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
    $version = $m['version'] ?? null;
    if ($version !== null && ! isCleanString($version)) {
        problem('release.json: version must be null or a non-empty string with no control characters');
    }
    if (! isCleanString($source['ref'] ?? null)) {
        problem('release.json: source.ref must be a non-empty string with no control characters');
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
        // Nullability matches ReleaseIdentity::nullableString() exactly, in both directions: an
        // unannotated build that still names a tag is a false provenance claim waiting to be read by an
        // operator, not merely a type error, so it is refused here rather than left for release:show to
        // discover on the host.
        $tag = $source['tag'] ?? null;
        if ($annotated && ! isCleanString($tag)) {
            problem('release.json: source.tag must name the annotated tag when annotated_tag is true');
        } elseif (! $annotated && $tag !== null) {
            problem('release.json: source.tag must be null when annotated_tag is false '
                .'(a tag recorded here without annotated_tag is a false provenance claim)');
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

    // ADR 0027: "modified or removed previously-applied migrations are scanner findings." A manifest
    // naming one without the matching finding is claiming a migration comparison the build recorded no
    // evidence for; the finding is where the WHERE and WHY (line, text) actually live, so its absence
    // is not a formality.
    $findingKeys = [];
    foreach ($findings as $finding) {
        if (is_array($finding) && is_string($finding['migration'] ?? null) && is_string($finding['kind'] ?? null)) {
            $findingKeys[$finding['migration'].'|'.$finding['kind']] = true;
        }
    }
    foreach ($modified as $name) {
        if (! isset($findingKeys["$name|modified"])) {
            problem("release.json: '$name' is listed in migrations.modified with no matching scanner_findings "
                ."entry (kind 'modified')");
        }
    }
    foreach ($removed as $name) {
        if (! isset($findingKeys["$name|removed"])) {
            problem("release.json: '$name' is listed in migrations.removed with no matching scanner_findings "
                ."entry (kind 'removed')");
        }
    }

    $previous = $migrations['previous'] ?? null;
    if ($previous !== null) {
        // ref must be a non-empty, clean string: ReleaseIdentity::previous() applies the same rule
        // (via its string() helper) when release:show reads this on the host, so an empty or
        // whitespace-only ref here would be judged valid by this validator and then refused there.
        if (! is_array($previous) || ! isCleanString($previous['ref'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/', (string) ($previous['commit'] ?? '')) !== 1) {
            problem('release.json: migrations.previous must be null or {ref, commit} with a full sha and a non-empty ref');
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
    // Matches what `./flow release build` already refuses at build time (scripts/lib/release.sh,
    // release_build): no control characters, at most 200 bytes. Checked here too, and not left to the
    // build alone, because this validator judges the artifact as it will ship — including one a build
    // step's own check did not produce, such as a hand-edited release.json. `classified_by` is echoed
    // to an operator's terminal by `release:show`, so a control character in it is a display-injection
    // risk there, not merely a cosmetic one.
    $by = $rollback['classified_by'] ?? null;
    if (! isCleanString($by) || strlen((string) $by) > 200) {
        problem('release.json: schema_rollback.classified_by must record who supplied the classification, '
            .'with no control characters and at most 200 bytes');
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
