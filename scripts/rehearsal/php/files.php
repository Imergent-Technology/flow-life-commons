<?php

declare(strict_types=1);

use Tests\Support\ResourceFileArchive;
use Tests\Support\ResourceFileRefusal;

// G6 WP3c slice 2.2C-2 fixed helper, run by `restore-rehearsal.sh` in the disposable FILES container: no network, no database credential, no
// encryption key, no application, a read-only root, and exactly two mounts: the prepared source (read-only) and the rehearsal's files volume,
// which is the application's `storage/app/private` (so the Resources disk's root, `storage/app/private/resources`, is `<volume>/resources`).
// One action per run, chosen by the first argument. Every argument is built by the script from values it has validated, never by a caller.
//
//   check <rows>           the Resources directory against `resource_assets` (or its absence): refuses any state the backup contract does not
//                          allow; prints the state, the count and one line per file (key, bytes, SHA-256). Read-only.
//   seed                   writes the fixed synthetic fixture into a Resources directory that does not exist; prints what it wrote.
//   archive <rows>         `check`, then the runbook's `tar -C <root> -czf <archive> resources`, its status checked, the archive read back by the
//                          strict reader and held to the inventory. The archive's bytes are the ONLY thing written to standard output.
//   verify-archive <rows>  the archive on standard input holds exactly these files, nothing else, and nothing outside `resources/`. Read-only.
//   aside <stamp>          reads `resources`, renames it to `resources.before-restore-<stamp>`, reads the renamed directory and requires the two to be the
//                          same (keys, sizes, SHA-256). Prints both inventories (`before:` and `after:` lines). Nothing is deleted.
//   inspect-aside <stamp>  (WU3) reads `resources.before-restore-<stamp>` (a sibling of `resources`) and the active `resources` beside it. Read-only; prints
//                          the aside's inventory (`aside:` lines) and the active one (`active:` lines).
//   restore <rows>         the archive on standard input, verified first, extracted into a root with no `resources`; the result held to <rows>.
//
// <rows> is `none` (no resource_assets table), `empty`, or `key:bytes:sha256[,...]`. The root is a constant here: it is not an argument, and it
// is refused unless it is a mount point, so that a container started without its volume cannot report an "absent" directory that is really
// the image's. What this prints is protocol (names, counts, digests), never content. A failure prints the exception CLASS and code to standard
// error, never its message; a refusal prints its message, which names keys and counts only.

const FILES_ROOT = '/var/www/html';

/** The root is a mount point of this container, or this refuses: an unmounted path would make "absent" mean nothing. */
function requireMount(): void
{
    $mounts = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
    if ($mounts === false) {
        throw new ResourceFileRefusal('the container\'s mounts cannot be read');
    }
    foreach ($mounts as $line) {
        $fields = explode(' ', $line);
        if (($fields[4] ?? '') === FILES_ROOT) {
            return;
        }
    }

    throw new ResourceFileRefusal('the files volume is not mounted at '.FILES_ROOT);
}

/** Standard input, whole, refused above the largest archive the rehearsal accepts. */
function archiveFromStdin(): string
{
    $bytes = stream_get_contents(STDIN, ResourceFileArchive::MAX_COMPRESSED + 1);
    if ($bytes === false || $bytes === '' || strlen($bytes) > ResourceFileArchive::MAX_COMPRESSED) {
        throw new ResourceFileRefusal('the archive on standard input is empty or larger than the rehearsal accepts');
    }

    return $bytes;
}

/** @return array<string, array{bytes: int, sha256: string}> the rows of an archive's file list: `none` is refused, an archive is never of "no table" */
function archiveRows(string $spec): array
{
    $rows = ResourceFileArchive::parseSpec($spec);
    if ($rows === null) {
        throw new ResourceFileRefusal('an archive is checked against a file list, not against "no table"');
    }

    return $rows;
}

/** @param  array{state: string, files: array<string, array{bytes: int, sha256: string}>}  $inventory */
function printInventory(array $inventory, string $prefix = ''): void
{
    echo $prefix.'files:state='.$inventory['state']."\n";
    echo $prefix.'files:count='.count($inventory['files'])."\n";
    foreach ($inventory['files'] as $key => $facts) {
        echo sprintf("%sfile %s %d %s\n", $prefix, $key, $facts['bytes'], $facts['sha256']);
    }
}

/** The Support classes of the CURRENT tree, by path: nothing is autoloaded, and nothing else is loaded. */
function loadSupport(): void
{
    $support = dirname(__DIR__).'/current/tests/Support';
    foreach (['ResourceFileRefusal', 'ResourceFileArchive'] as $class) {
        if (! is_file("$support/$class.php")) {
            throw new RuntimeException("the test support file $class.php is not in the prepared source");
        }
        require_once "$support/$class.php";
    }
}

/**
 * Standard output is protocol, and for `archive` it is the archive itself: a PHP warning or notice printed there would corrupt it. So diagnostics go to
 * standard error, and a warning that is not deliberately suppressed (`@`) ends the run as an error whose CLASS is all that is printed.
 */
function quietly(): void
{
    ini_set('display_errors', 'stderr');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

/** @param  list<string>  $args */
function run(array $args): void
{
    quietly();
    loadSupport();
    $action = $args[0] ?? '';
    $argument = $args[1] ?? '';
    $needs = ['check' => 1, 'seed' => 0, 'archive' => 1, 'verify-archive' => 1, 'aside' => 1, 'inspect-aside' => 1, 'restore' => 1];
    if (! isset($needs[$action]) || count($args) !== $needs[$action] + 1) {
        fwrite(STDERR, "usage: files.php check <rows> | seed | archive <rows> | verify-archive <rows> | aside <stamp> | inspect-aside <stamp> | restore <rows>\n");
        exit(64);
    }
    // The archive is only verified here, so it needs no volume; every other action needs the mount.
    if ($action !== 'verify-archive') {
        requireMount();
    }

    switch ($action) {
        case 'check':
            $inventory = ResourceFileArchive::inventory(FILES_ROOT);
            ResourceFileArchive::agree($inventory, ResourceFileArchive::parseSpec($argument));
            printInventory($inventory);
            echo "files:check=ok\n";
            break;
        case 'seed':
            printInventory(ResourceFileArchive::seed(FILES_ROOT));
            echo "files:seed=ok\n";
            break;
        case 'archive':
            $inventory = ResourceFileArchive::inventory(FILES_ROOT);
            ResourceFileArchive::agree($inventory, ResourceFileArchive::parseSpec($argument));
            $archive = '/tmp/flc-resource-files.tar.gz';
            ResourceFileArchive::createArchive(FILES_ROOT, $archive, $inventory);
            $bytes = file_get_contents($archive);
            @unlink($archive); // the container's own scratch file in its tmpfs, never a Resources file
            if ($bytes === false) {
                throw new ResourceFileRefusal('the archive could not be read back');
            }
            echo $bytes;
            break;
        case 'verify-archive':
            $accepted = ResourceFileArchive::verifyArchive(archiveFromStdin(), archiveRows($argument));
            echo 'archive:entries='.$accepted."\n"; // what the reader accepted, not what was asked for
            echo "archive:verified=ok\n";
            break;
        case 'aside':
            $aside = ResourceFileArchive::setAside(FILES_ROOT, $argument);
            printInventory($aside['before'], 'before:'); // what the directory held immediately before the rename
            printInventory($aside['after'], 'after:'); // what the renamed directory holds
            echo 'aside:name='.$aside['name']."\n";
            echo "aside:complete=ok\n";
            break;
        case 'inspect-aside':
            $inspected = ResourceFileArchive::inspectAside(FILES_ROOT, $argument);
            printInventory($inspected['aside'], 'aside:'); // what the aside directory holds now
            printInventory($inspected['active'], 'active:'); // what the active Resources directory beside it holds (absent after the aside of the historical restore)
            echo 'aside:name='.$inspected['name']."\n";
            echo "aside:inspected=ok\n";
            break;
        case 'restore':
            ResourceFileArchive::extract(archiveFromStdin(), FILES_ROOT, archiveRows($argument));
            printInventory(ResourceFileArchive::inventory(FILES_ROOT));
            echo "restore:extracted=ok\n";
            break;
    }
}

// Run only when this file is the script.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        run(array_slice($argv, 1));
    } catch (ResourceFileRefusal $refusal) {
        fwrite(STDERR, 'harness:refused '.$refusal->getMessage()."\n");
        exit(3);
    } catch (Throwable $failure) {
        fwrite(STDERR, 'harness:error '.$failure::class.' code='.$failure->getCode()."\n");
        exit(1);
    }
}
