<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\StorageKey;
use App\Modules\Resources\Infrastructure\DiskResourceFileStore;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ResourceFileArchive as Files;
use Tests\Support\ResourceFileRefusal;

/*
 * G6 WP3c S2.2C-2. The Resource-file half of the recovery rehearsal (Tests\Support\ResourceFileArchive, run in the rehearsal by
 * scripts/rehearsal/php/files.php), measured against a REAL directory and the REAL `tar`, with no framework state and no database.
 *
 * Two parts.
 *
 *  1. What it does when the state is clean: an absent directory and an empty one, a directory of several files and their facts, an archive that
 *     goes round (create, verify, move aside, extract) and comes back byte for byte, and that the layout is the one the application's own
 *     `resources` disk (DiskResourceFileStore, over config/filesystems.php) lists.
 *  2. What it REFUSES, and that each refusal is needed. Every safeguard in ResourceFileArchive is one line ending `// guard:F-<name>`. The
 *     matrix at the bottom copies the class, replaces each marked line with `;`, loads the copy under another name, and requires that the
 *     scenario written for that guard no longer ends in that guard's refusal. A marker with no scenario, and a scenario with no marker, fail.
 *
 * Everything happens in a private temporary directory made here and removed here. No file of the application, the development stack or any
 * Resource is read or written.
 *
 * `./flow test backend -- tests/Unit/Rehearsal/ResourceFileArchiveTest.php`
 */

const S22C2_CLASS_FILE = __DIR__.'/../../Support/ResourceFileArchive.php';

afterAll(function (): void {
    foreach (s22c2_made() as $path) {
        s22c2_remove($path);
    }
});

/**
 * The directories this test made, so that it can remove them; passing a path records it.
 *
 * @return list<string>
 */
function s22c2_made(?string $path = null): array
{
    /** @var list<string> $made */
    static $made = [];
    if ($path !== null) {
        $made[] = $path;
    }

    return $made;
}

/** Removes a path this test made, and nothing outside it: a test helper, not part of the code under test. */
function s22c2_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (is_dir($path)) {
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                s22c2_remove($path.'/'.$name);
            }
        }
        @rmdir($path);
    }
}

/** A new private directory under the system temporary directory. */
function s22c2_dir(): string
{
    $path = sys_get_temp_dir().'/flc-s22c2-'.bin2hex(random_bytes(6));
    mkdir($path, 0700);
    s22c2_made($path);

    return $path;
}

/** A files root holding the four fixture files. */
function s22c2_seeded(): string
{
    $root = s22c2_dir();
    Files::seed($root);

    return $root;
}

/** @return array<string, array{bytes: int, sha256: string}> */
function s22c2_rows(string $root): array
{
    return Files::inventory($root)['files'];
}

/** A key that is valid but not the fixture's. */
function s22c2_key(int $n, string $prefix = '01jcg'): string
{
    return $prefix.sprintf('%021x', $n);
}

/** @param array<string, mixed> $entry */
function s22c2_field(array $entry, string $key, string $default = ''): string
{
    $value = $entry[$key] ?? $default;

    return is_string($value) ? $value : (is_int($value) ? (string) $value : $default);
}

/**
 * @param  array<string, mixed>  $entry
 * @return array<mixed>
 */
function s22c2_overrides(array $entry): array
{
    $overrides = $entry['o'] ?? [];

    return is_array($overrides) ? $overrides : [];
}

/**
 * One ustar header. $o overrides: magic, prefix, sizefield, name_raw, mode, badsum.
 *
 * @param  array<mixed>  $o
 */
function s22c2_header(string $name, string $type, int $size = 0, string $link = '', array $o = []): string
{
    $text = static fn (string $key, string $default): string => is_string($o[$key] ?? null) ? $o[$key] : $default;
    $head = $text('name_raw', str_pad($name, 100, "\0"))
        .$text('mode', sprintf('%07o', 0600)."\0").sprintf('%07o', 0)."\0".sprintf('%07o', 0)."\0"
        .$text('sizefield', sprintf('%011o', $size)."\0")
        .sprintf('%011o', 0)."\0"
        .'        '
        .$type
        .str_pad($link, 100, "\0")
        .$text('magic', "ustar\0".'00')
        .str_repeat("\0", 64)
        .str_repeat("\0", 16)
        .str_pad($text('prefix', ''), 155, "\0")
        .str_repeat("\0", 12);
    /** @var array<int, int> $bytes */
    $bytes = unpack('C*', $head);
    $sum = array_sum($bytes);
    $field = sprintf('%06o', ($o['badsum'] ?? false) === true ? $sum + 1 : $sum)."\0 ";

    return substr($head, 0, 148).$field.substr($head, 156);
}

/**
 * A tar made by hand, so that states `tar` itself would never write can be read. Each entry: name, type (default '0'), content, size, link, after, o.
 *
 * @param  array<array<string, mixed>>  $entries
 */
function s22c2_tar(array $entries, bool $end = true): string
{
    $tar = '';
    foreach ($entries as $entry) {
        $content = s22c2_field($entry, 'content');
        $size = is_int($entry['size'] ?? null) ? $entry['size'] : strlen($content);
        $tar .= s22c2_header(s22c2_field($entry, 'name'), s22c2_field($entry, 'type', '0'), $size, s22c2_field($entry, 'link'), s22c2_overrides($entry));
        $tar .= $content.str_repeat("\0", (int) (ceil(strlen($content) / 512) * 512) - strlen($content));
        $tar .= s22c2_field($entry, 'after');
    }

    return $tar.($end ? str_repeat("\0", 1024) : '');
}

/** @param  array<array<string, mixed>>  $entries */
function s22c2_gz(array $entries, bool $end = true): string
{
    return (string) gzencode(s22c2_tar($entries, $end));
}

/**
 * A gzipped tar of the resources/ directory entry and then these entries.
 *
 * @param  array<array<string, mixed>>  $more
 */
function s22c2_valid(array $more, bool $end = true): string
{
    return s22c2_gz([s22c2_dir_entry(), ...$more], $end);
}

/** @return array<string, mixed> */
function s22c2_dir_entry(): array
{
    return ['name' => 'resources/', 'type' => '5'];
}

/** @return array{0: string, 1: array<string, array{bytes: int, sha256: string}>} a real archive of a seeded root, and its rows */
function s22c2_real_archive(): array
{
    $root = s22c2_seeded();
    $inventory = Files::inventory($root);
    $to = s22c2_dir().'/archive.tar.gz';
    Files::createArchive($root, $to, $inventory);

    return [(string) file_get_contents($to), $inventory['files']];
}

/** The first refusal a callable ends in, or null when it ends in none. Any other throwable is returned as such: it is not a refusal. */
function s22c2_outcome(Closure $run): ?Throwable
{
    try {
        $run();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

// --- 1. the clean states ----------------------------------------------------------------------------------------------------------------

it('treats an absent Resources directory as a state, and a zero-file one when no asset row exists', function (): void {
    $root = s22c2_dir();
    $inventory = Files::inventory($root);

    expect($inventory)->toBe(['state' => 'absent', 'files' => []]);
    Files::agree($inventory, null);
    Files::agree($inventory, []);
    expect(Files::spec(null))->toBe('none')->and(Files::spec([]))->toBe('empty');
});

it('treats an existing empty directory as present, with no files, and as clean when no asset row exists', function (): void {
    $root = s22c2_dir();
    mkdir($root.'/resources', 0700);
    $inventory = Files::inventory($root);

    expect($inventory)->toBe(['state' => 'present', 'files' => []]);
    Files::agree($inventory, null);
    Files::agree($inventory, []);
});

it('reads several referenced files with their counts and checksums, and agrees with matching rows', function (): void {
    $root = s22c2_seeded();
    $inventory = Files::inventory($root);

    expect($inventory['state'])->toBe('present')->and($inventory['files'])->toHaveCount(Files::FIXTURE_FILES);
    foreach (Files::fixture() as $key => $file) {
        expect($inventory['files'][$key])->toBe(['bytes' => $file['bytes'], 'sha256' => $file['sha256']]);
        expect(hash('sha256', (string) file_get_contents($root.'/resources/'.$key)))->toBe($file['sha256']);
        expect(fileperms($root.'/resources/'.$key) & 0777)->toBe(0600)->and(filemtime($root.'/resources/'.$key))->toBe(Files::FIXTURE_MTIME);
    }
    Files::agree($inventory, $inventory['files']);
});

it('has a deterministic fixture of valid storage keys', function (): void {
    expect(Files::fixture())->toBe(Files::fixture());
    foreach (array_keys(Files::fixture()) as $key) {
        expect(StorageKey::tryFromName($key))->not->toBeNull()->and(preg_match(Files::KEY_PATTERN, $key))->toBe(1);
    }
    foreach (['', 'README', '01JCF000000000000000000001', '01jcf00000000000000000000', '01jcf0000000000000000000011', '81jcf000000000000000000001', '01jcf00000000000000000000i', '.01jcf00000000000000000001'] as $notKey) {
        expect(preg_match(Files::KEY_PATTERN, $notKey))->toBe(0)->and(StorageKey::tryFromName($notKey))->toBeNull();
    }
});

it('round-trips a file list through its command-line form', function (): void {
    $rows = s22c2_rows(s22c2_seeded());

    expect(Files::parseSpec(Files::spec($rows)))->toBe($rows)->and(Files::parseSpec('none'))->toBeNull()->and(Files::parseSpec('empty'))->toBe([]);
});

it('archives with the runbook command, reads the archive back, and restores it byte for byte after the live directory is moved aside', function (): void {
    $root = s22c2_seeded();
    $inventory = Files::inventory($root);
    $archive = s22c2_dir().'/resource-files.tar.gz';

    Files::createArchive($root, $archive, $inventory);
    $gz = (string) file_get_contents($archive);
    $entries = Files::readArchive($gz);
    expect($entries[0]['name'])->toBe('resources/')->and($entries[0]['type'])->toBe('dir')->and($entries)->toHaveCount(Files::FIXTURE_FILES + 1);
    Files::verifyArchive($gz, $inventory['files']);

    // The restore: the live directory is moved aside (never deleted), the archive is extracted, and what comes back is what went in.
    $aside = Files::setAside($root, '20261116T120000Z');
    $name = $aside['name'];
    expect($name)->toBe('resources.before-restore-20261116T120000Z')->and(is_dir($root.'/'.$name))->toBeTrue()->and(file_exists($root.'/resources'))->toBeFalse();
    // Measured, not assumed: the inventory read just before the rename and the one read of the renamed directory are the same, key by key.
    expect($aside['before'])->toBe($inventory)->and($aside['after'])->toBe($inventory);
    Files::extract($gz, $root, $inventory['files']);
    expect(Files::inventory($root))->toBe($inventory);
    foreach (array_keys($inventory['files']) as $key) {
        expect(file_get_contents($root.'/resources/'.$key))->toBe(file_get_contents($root.'/'.$name.'/'.$key));
        expect(filemtime($root.'/resources/'.$key))->toBe(Files::FIXTURE_MTIME)->and(fileperms($root.'/resources/'.$key) & 0777)->toBe(0600);
    }
    // The aside copy is untouched by the restore, and holds every file.
    expect(Files::inventory($root)['files'])->toBe(s22c2_inventory_of($root.'/'.$name));
});

/** @return array<string, array{bytes: int, sha256: string}> the inventory of a directory that is itself the Resources directory */
function s22c2_inventory_of(string $directory): array
{
    $wrapper = s22c2_dir();
    mkdir($wrapper.'/resources', 0700);
    foreach (scandir($directory) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            copy($directory.'/'.$name, $wrapper.'/resources/'.$name);
        }
    }

    return Files::inventory($wrapper)['files'];
}

it('archives and restores an existing empty directory (a zero-file archive)', function (): void {
    $root = s22c2_dir();
    mkdir($root.'/resources', 0700);
    $inventory = Files::inventory($root);
    $archive = s22c2_dir().'/resource-files.tar.gz';

    Files::createArchive($root, $archive, $inventory);
    $gz = (string) file_get_contents($archive);
    expect(Files::readArchive($gz))->toHaveCount(1);
    Files::setAside($root, '20261116T120001Z');
    Files::extract($gz, $root, []);
    expect(Files::inventory($root))->toBe(['state' => 'present', 'files' => []]);
});

it('keeps the application\'s own Resources disk and this layout in agreement', function (): void {
    // The disk is private, rooted at storage/app/private/resources, and the rehearsal's files volume IS storage/app/private.
    $config = config()->array('filesystems.disks.resources');
    expect($config['root'])->toBe(storage_path('app/private/resources'))->and($config['driver'])->toBe('local')->and($config['visibility'])->toBe('private');

    // The real DiskResourceFileStore over that disk configuration, rooted at the seeded directory instead, lists exactly the fixture's keys and opens
    // each one: the layout `<root>/resources/<storage key>` is what the application reads.
    $root = s22c2_seeded();
    config(['filesystems.disks.resources.root' => $root.'/'.Files::STORE]);
    Storage::forgetDisk(DiskResourceFileStore::DISK);
    $store = new DiskResourceFileStore(app('filesystem'));

    expect(array_map(static fn ($object): string => $object->name, $store->listing()))->toBe(array_keys(Files::fixture()));
    foreach (Files::fixture() as $key => $file) {
        $stored = $store->open(StorageKey::fromStored($key));
        assert($stored !== null);
        expect($stored->size)->toBe($file['bytes']);
        expect(hash('sha256', (string) stream_get_contents($stored->stream)))->toBe($file['sha256']);
    }
    Storage::forgetDisk(DiskResourceFileStore::DISK);
});

it('never deletes: moving aside and restoring leave every file that was there', function (): void {
    $root = s22c2_seeded();
    $before = s22c2_rows($root);
    $aside = Files::setAside($root, '20261116T120002Z');

    expect(Files::inventory($root))->toBe(['state' => 'absent', 'files' => []]);
    expect(s22c2_inventory_of($root.'/'.$aside['name']))->toBe($before)->and($aside['after']['files'])->toBe($before);
    foreach (Files::fixture() as $key => $file) {
        expect(file_get_contents($root.'/'.$aside['name'].'/'.$key))->toBe($file['content']);
    }
});

it('measures what was moved aside against what was there, with real bytes, and refuses each kind of difference', function (): void {
    $original = Files::inventory(s22c2_seeded());
    // A second real directory, made by copying the first, and then changed in one way at a time.
    $changed = static function (Closure $change): array {
        $root = s22c2_seeded();
        $change($root.'/resources');

        return Files::inventory($root);
    };
    $key = array_key_first($original['files']);
    $last = array_key_last($original['files']);
    $cases = [
        'a file with the same name and size and other bytes' => $changed(fn (string $d) => file_put_contents($d.'/'.$key, str_repeat('Z', $original['files'][$key]['bytes']))),
        'a file with other bytes and another size' => $changed(fn (string $d) => file_put_contents($d.'/'.$key, 'short')),
        'a file lost' => $changed(fn (string $d) => unlink($d.'/'.$last)),
        'a file that was not there' => $changed(fn (string $d) => file_put_contents($d.'/'.s22c2_key(9), 'new')),
        'a directory that is not there' => ['state' => 'absent', 'files' => []],
    ];
    Files::verifyPreserved($original, $original);
    foreach ($cases as $label => $after) {
        expect(s22c2_outcome(fn () => Files::verifyPreserved($original, $after)))->toBeInstanceOf(ResourceFileRefusal::class, $label);
    }
});

it('leaves a moved directory exactly where it is when its check fails after the rename, and renames nothing back', function (): void {
    $root = s22c2_seeded();
    $key = array_key_first(Files::fixture());
    $outcome = s22c2_outcome(fn () => Files::setAside($root, '20261116T120003Z', function (string $aside) use ($key): void {
        file_put_contents($aside.'/'.$key, str_repeat('Z', Files::fixture()[$key]['bytes']));
    }));

    expect($outcome)->toBeInstanceOf(ResourceFileRefusal::class);
    assert($outcome !== null);
    expect($outcome->getMessage())->toContain('not the content it was');
    expect(file_exists($root.'/resources'))->toBeFalse()->and(is_dir($root.'/resources.before-restore-20261116T120003Z'))->toBeTrue();
    expect(file_get_contents($root.'/resources.before-restore-20261116T120003Z/'.$key))->toBe(str_repeat('Z', Files::fixture()[$key]['bytes']));
    expect(scandir($root.'/resources.before-restore-20261116T120003Z'))->toHaveCount(Files::FIXTURE_FILES + 2);
});

it('refuses before any rename when the directory holds something the inventory refuses, or cannot be read', function (): void {
    $root = s22c2_seeded();
    symlink($root.'/resources/'.array_key_first(Files::fixture()), $root.'/resources/'.s22c2_key(5));
    expect(s22c2_outcome(fn () => Files::setAside($root, '20261116T120004Z')))->toBeInstanceOf(ResourceFileRefusal::class);
    expect(is_dir($root.'/resources'))->toBeTrue()->and(file_exists($root.'/resources.before-restore-20261116T120004Z'))->toBeFalse();

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $root = s22c2_seeded();
        $key = array_key_first(Files::fixture());
        chmod($root.'/resources/'.$key, 0000);
        expect(s22c2_outcome(fn () => Files::setAside($root, '20261116T120005Z')))->toBeInstanceOf(ResourceFileRefusal::class);
        expect(is_dir($root.'/resources'))->toBeTrue()->and(file_exists($root.'/resources.before-restore-20261116T120005Z'))->toBeFalse();
        chmod($root.'/resources/'.$key, 0600);
    }
});

it('reports the number of entries the archive reader accepted, not a number derived from the list it was given', function (): void {
    [$gz, $rows] = s22c2_real_archive();
    expect(Files::verifyArchive($gz, $rows))->toBe(Files::FIXTURE_FILES + 1)->and(count(Files::readArchive($gz)))->toBe(Files::FIXTURE_FILES + 1);

    $empty = s22c2_dir();
    mkdir($empty.'/resources', 0700);
    $to = s22c2_dir().'/e.tar.gz';
    Files::createArchive($empty, $to, Files::inventory($empty));
    expect(Files::verifyArchive((string) file_get_contents($to), []))->toBe(1);
});

// --- 2. refusal, before anything is written ------------------------------------------------------------------------------------------

it('writes nothing when it refuses an archive, whatever the archive is', function (): void {
    $key = array_key_first(Files::fixture());
    $hostile = [
        'traversal' => [s22c2_dir_entry(), ['name' => '../escaped', 'content' => 'x']],
        'absolute' => [s22c2_dir_entry(), ['name' => '/tmp/flc-s22c2-absolute', 'content' => 'x']],
        'symlink' => [s22c2_dir_entry(), ['name' => 'resources/'.$key, 'type' => '2', 'link' => '/etc/passwd']],
        'hardlink' => [s22c2_dir_entry(), ['name' => 'resources/'.$key, 'type' => '1', 'link' => 'resources/other']],
        'subdirectory' => [s22c2_dir_entry(), ['name' => 'resources/sub/', 'type' => '5']],
        'duplicate' => [s22c2_dir_entry(), ['name' => 'resources/'.$key, 'content' => 'a'], ['name' => 'resources/'.$key, 'content' => 'b']],
    ];
    foreach ($hostile as $label => $entries) {
        $base = s22c2_dir();
        mkdir($base.'/root', 0700);
        $gz = gzencode(s22c2_tar($entries));
        $outcome = s22c2_outcome(fn () => Files::extract((string) $gz, $base.'/root', [$key => ['bytes' => 1, 'sha256' => str_repeat('a', 64)]]));

        expect($outcome)->toBeInstanceOf(ResourceFileRefusal::class, $label);
        expect(scandir($base))->toBe(['.', '..', 'root'], $label)->and(scandir($base.'/root'))->toBe(['.', '..'], $label);
    }
});

it('refuses to extract over an existing directory and leaves it as it was', function (): void {
    [$gz, $rows] = s22c2_real_archive();
    $root = s22c2_seeded();
    file_put_contents($root.'/resources/'.array_key_first($rows), 'newer content');
    $before = s22c2_rows($root);

    expect(s22c2_outcome(fn () => Files::extract($gz, $root, $rows)))->toBeInstanceOf(ResourceFileRefusal::class);
    expect(s22c2_rows($root))->toBe($before);
});

it('does not repair: a refused state is left exactly as found', function (): void {
    $root = s22c2_seeded();
    file_put_contents($root.'/resources/README', 'stray');
    $before = scandir($root.'/resources');

    expect(s22c2_outcome(fn () => Files::inventory($root)))->toBeInstanceOf(ResourceFileRefusal::class);
    expect(scandir($root.'/resources'))->toBe($before)->and(file_get_contents($root.'/resources/README'))->toBe('stray');
});

// --- 3. every guard, and a scenario that needs it ----------------------------------------------------------------------------------------

/**
 * Guard ID => [a fragment of the refusal it raises, the scenario]. The scenario is given the class to run (the real one, or a copy with that
 * guard's line removed), prepares its own state with the REAL class, and calls the class under test as the rehearsal would.
 *
 * @return array<string, array{0: string, 1: Closure(class-string<Files>): mixed}>
 */
function s22c2_scenarios(): array
{
    $key5 = s22c2_key(5);
    $zero = str_repeat('0', 64);
    $facts = static fn (string $content): array => ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];

    return [
        'F-root-dir' => ['is not a directory', fn (string $c) => $c::inventory('/nonexistent/flc-s22c2')],
        'F-store-link' => ['symbolic link', function (string $c): void {
            $root = s22c2_dir();
            mkdir($root.'/elsewhere');
            symlink($root.'/elsewhere', $root.'/resources');
            $c::inventory($root);
        }],
        'F-store-dir' => ['exists and is not a directory', function (string $c): void {
            $root = s22c2_dir();
            file_put_contents($root.'/resources', 'a file');
            $c::inventory($root);
        }],
        'F-entry-symlink' => ['holds a symbolic link', function (string $c) use ($key5): void {
            $root = s22c2_seeded();
            symlink($root.'/resources/'.array_key_first(Files::fixture()), $root.'/resources/'.$key5);
            $c::inventory($root);
        }],
        'F-entry-subdirectory' => ['holds a subdirectory', function (string $c) use ($key5): void {
            $root = s22c2_seeded();
            mkdir($root.'/resources/'.$key5);
            $c::inventory($root);
        }],
        'F-entry-regular' => ['is not a regular file', function (string $c) use ($key5): void {
            $root = s22c2_seeded();
            $socket = stream_socket_server('unix://'.$root.'/resources/'.$key5);
            if ($socket === false) {
                throw new LogicException('a socket could not be made for the scenario');
            }
            fclose($socket);
            $c::inventory($root);
        }],
        'F-entry-name' => ['not named as a storage key', function (string $c): void {
            $root = s22c2_seeded();
            file_put_contents($root.'/resources/README.txt', 'stray');
            $c::inventory($root);
        }],
        'F-entry-hardlink' => ['more than one hard link', function (string $c) use ($key5): void {
            $root = s22c2_seeded();
            link($root.'/resources/'.array_key_first(Files::fixture()), $root.'/resources/'.$key5);
            $c::inventory($root);
        }],
        'F-file-limit' => ['more files than the rehearsal accepts', function (string $c): void {
            $root = s22c2_dir();
            mkdir($root.'/resources');
            for ($n = 1; $n <= Files::MAX_FILES + 1; $n++) {
                file_put_contents($root.'/resources/'.s22c2_key($n), 'x');
            }
            $c::inventory($root);
        }],
        'F-absent-with-rows' => ['absent while resource_assets has 1 row(s)', fn (string $c) => $c::agree(['state' => 'absent', 'files' => []], [$key5 => ['bytes' => 1, 'sha256' => $zero]])],
        'F-files-without-table' => ['no resource_assets table', fn (string $c) => $c::agree(Files::inventory(s22c2_seeded()), null)],
        'F-missing-file' => ['a referenced file is missing', function (string $c) use ($key5, $zero): void {
            $root = s22c2_seeded();
            $c::agree(Files::inventory($root), [...s22c2_rows($root), $key5 => ['bytes' => 1, 'sha256' => $zero]]);
        }],
        'F-unexpected-file' => ['no asset refers to', function (string $c): void {
            // An orphan is refused whatever its age: one written a day ago and one written a second ago (a "young" orphan, which the prune itself would leave alone).
            foreach ([Files::FIXTURE_MTIME, time()] as $modified) {
                $root = s22c2_seeded();
                $rows = s22c2_rows($root);
                $orphan = array_key_last($rows);
                unset($rows[$orphan]);
                touch($root.'/resources/'.$orphan, $modified);
                $outcome = s22c2_outcome(fn () => $c::agree(Files::inventory($root), $rows));
                if (! $outcome instanceof ResourceFileRefusal || ! str_contains($outcome->getMessage(), 'no asset refers to')) {
                    throw $outcome ?? new LogicException("an orphan modified at $modified was accepted");
                }
            }

            throw new ResourceFileRefusal('no asset refers to');
        }],
        'F-size-differs' => ['not the size its asset row records', function (string $c): void {
            $root = s22c2_seeded();
            $rows = s22c2_rows($root);
            $rows[array_key_first($rows)]['bytes']++;
            $c::agree(Files::inventory($root), $rows);
        }],
        'F-content-differs' => ['not the content its asset row records', function (string $c): void {
            $root = s22c2_seeded();
            $rows = s22c2_rows($root);
            $key = array_key_first($rows);
            // Same name, same size, different bytes.
            file_put_contents($root.'/resources/'.$key, str_repeat('Z', $rows[$key]['bytes']));
            $c::agree(Files::inventory($root), $rows);
        }],
        'F-spec-shape' => ['is not key:bytes:sha256', fn (string $c) => $c::parseSpec('abc')],
        'F-spec-fields' => ['field of the wrong form', fn (string $c) => $c::parseSpec('NOTAKEY:1:'.$zero)],
        'F-spec-duplicate' => ['names a key twice', fn (string $c) => $c::parseSpec($key5.':1:'.$zero.','.$key5.':1:'.$zero)],
        'F-spec-limit' => ['longer than the rehearsal accepts', function (string $c) use ($zero): void {
            $items = [];
            for ($n = 1; $n <= Files::MAX_FILES + 1; $n++) {
                $items[] = s22c2_key($n).':1:'.$zero;
            }
            $c::parseSpec(implode(',', $items));
        }],
        'F-archive-needs-directory' => ['no Resources directory to archive', fn (string $c) => $c::createArchive(s22c2_dir(), s22c2_dir().'/a.tar.gz', ['state' => 'absent', 'files' => []])],
        'F-archive-no-overwrite' => ['never overwritten', function (string $c): void {
            $root = s22c2_seeded();
            $to = s22c2_dir().'/a.tar.gz';
            file_put_contents($to, 'an earlier archive');
            $c::createArchive($root, $to, Files::inventory($root));
        }],
        'F-archive-status' => ['the archive command failed (exit status', function (string $c): void {
            $root = s22c2_seeded();
            $c::createArchive($root, s22c2_dir().'/a.tar.gz', Files::inventory($root), 'false');
        }],
        'F-archive-written' => ['wrote no archive', function (string $c): void {
            $root = s22c2_seeded();
            $c::createArchive($root, s22c2_dir().'/a.tar.gz', Files::inventory($root), 'true');
        }],
        'F-gzip-size' => ['empty or larger', fn (string $c) => $c::readArchive('')],
        'F-gzip-valid' => ['not valid gzip data', fn (string $c) => $c::readArchive('this is not gzip data at all, only text')],
        'F-gzip-expansion' => ['expands beyond', fn (string $c) => $c::readArchive((string) gzencode(str_repeat("\0", Files::MAX_EXPANDED + 1024 * 1024)))],
        'F-gzip-complete' => ['gzip stream is incomplete', fn (string $c) => $c::readArchive(substr(s22c2_valid([]), 0, -10))],
        'F-gzip-trailing' => ['after its gzip stream', fn (string $c) => $c::readArchive(s22c2_valid([]).'extra bytes')],
        'F-tar-blocks' => ['whole number of 512-byte', fn (string $c) => $c::readArchive((string) gzencode(str_repeat('a', 100)))],
        'F-tar-end' => ['not two zero blocks', fn (string $c) => $c::readArchive((string) gzencode(s22c2_header('resources/', '5').str_repeat("\0", 512).str_repeat('x', 512)))],
        'F-tar-ended' => ['has no end marker', fn (string $c) => $c::readArchive(s22c2_valid([], false))],
        'F-tar-nonempty' => ['holds no entries', fn (string $c) => $c::readArchive((string) gzencode(str_repeat("\0", 1024)))],
        'F-tar-checksum' => ['fails its checksum', fn (string $c) => $c::readArchive(s22c2_gz([['name' => 'resources/', 'type' => '5', 'o' => ['badsum' => true]]]))],
        'F-tar-magic' => ['not the ustar format', fn (string $c) => $c::readArchive(s22c2_gz([['name' => 'resources/', 'type' => '5', 'o' => ['magic' => "xxxxx\0".'00']]]))],
        'F-tar-prefix' => ['name prefix', fn (string $c) => $c::readArchive(s22c2_gz([['name' => 'resources/', 'type' => '5', 'o' => ['prefix' => 'escape']]]))],
        'F-tar-mode' => ['setuid, setgid or sticky', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'resources/'.$key5, 'content' => 'x', 'o' => ['mode' => sprintf('%07o', 04755)."\0"]]]))],
        'F-tar-linkname' => ['carries a link target', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'resources/'.$key5, 'content' => 'x', 'link' => 'elsewhere']]))],
        'F-tar-field-nul' => ['has bytes after its end', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'x', 'content' => 'x', 'o' => ['name_raw' => str_pad('resources/'.$key5."\0junk", 100, "\0")]]]))],
        'F-tar-octal' => ['not an octal number', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'resources/'.$key5, 'content' => 'x', 'o' => ['sizefield' => "zzzzzzzzzzz\0"]]]))],
        'F-tar-directory-name' => ['other than resources/', fn (string $c) => $c::readArchive(s22c2_gz([['name' => 'other/', 'type' => '5']]))],
        'F-tar-directory-first' => ['does not start with its resources/ directory entry', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'resources/', 'type' => '5']]))],
        'F-tar-directory-first-file' => ['comes before the resources/ directory entry', fn (string $c) => $c::readArchive(s22c2_gz([['name' => 'resources/'.$key5, 'content' => 'x']]))],
        'F-tar-type' => ['not a regular file or its directory', function (string $c) use ($key5): void {
            // A symbolic link, a hard link, a character device, a FIFO, a pax header and a GNU long-name header: each is refused for what it is.
            foreach (['2', '1', '3', '6', 'x', 'L'] as $type) {
                $outcome = s22c2_outcome(fn () => $c::readArchive(s22c2_valid([['name' => 'resources/'.$key5, 'type' => $type]])));
                if (! $outcome instanceof ResourceFileRefusal || ! str_contains($outcome->getMessage(), 'not a regular file or its directory')) {
                    throw $outcome ?? new LogicException("type $type was accepted");
                }
            }
            throw new ResourceFileRefusal('not a regular file or its directory');
        }],
        'F-tar-entry-name' => ['outside resources/<storage key>', function (string $c) use ($key5): void {
            // Traversal, an absolute path, a leading ./, a nested path, something that is not a key, a doubled slash and another top directory.
            foreach (['../'.$key5, '/etc/'.$key5, './resources/'.$key5, 'resources/sub/'.$key5, 'resources/NOTAKEY', 'resources//'.$key5, 'other/'.$key5] as $name) {
                $outcome = s22c2_outcome(fn () => $c::readArchive(s22c2_valid([['name' => $name, 'content' => 'x']])));
                if (! $outcome instanceof ResourceFileRefusal || ! str_contains($outcome->getMessage(), 'outside resources/<storage key>')) {
                    throw $outcome ?? new LogicException("the name $name was accepted");
                }
            }
            throw new ResourceFileRefusal('outside resources/<storage key>');
        }],
        'F-tar-duplicate' => ['the same entry twice', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'resources/'.$key5, 'content' => 'a'], ['name' => 'resources/'.$key5, 'content' => 'b']]))],
        'F-tar-truncated' => ['runs past the end', fn (string $c) => $c::readArchive(s22c2_valid([['name' => 'resources/'.$key5, 'size' => 100000, 'content' => 'x']]))],
        'F-tar-padding' => ['padding is not zero', fn (string $c) => $c::readArchive((string) gzencode(s22c2_header('resources/', '5').s22c2_header('resources/'.$key5, '0', 10).'0123456789'.str_repeat('X', 502).str_repeat("\0", 1024)))],
        'F-tar-entry-limit' => ['more entries than the rehearsal accepts', function (string $c): void {
            $entries = [];
            for ($n = 1; $n <= Files::MAX_FILES + 1; $n++) {
                $entries[] = ['name' => 'resources/'.s22c2_key($n), 'content' => 'x'];
            }
            $c::readArchive((string) gzencode(s22c2_tar([s22c2_dir_entry(), ...$entries])));
        }],
        'F-archive-missing-entry' => ['lacks a file the backup records', function (string $c) use ($key5, $zero): void {
            [$gz, $rows] = s22c2_real_archive();
            $c::verifyArchive($gz, [...$rows, $key5 => ['bytes' => 1, 'sha256' => $zero]]);
        }],
        'F-archive-extra-entry' => ['the backup does not record', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            unset($rows[array_key_last($rows)]);
            $c::verifyArchive($gz, $rows);
        }],
        'F-archive-entry-size' => ['not the recorded size', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            $rows[array_key_first($rows)]['bytes']++;
            $c::verifyArchive($gz, $rows);
        }],
        'F-archive-entry-content' => ['not the recorded content', function (string $c) use ($facts): void {
            $content = 'the bytes the archive holds';
            $key = array_key_first(Files::fixture());
            $gz = (string) gzencode(s22c2_tar([s22c2_dir_entry(), ['name' => 'resources/'.$key, 'content' => $content]]));
            // Same name and same size as recorded; different content.
            $c::verifyArchive($gz, [$key => ['bytes' => strlen($content), 'sha256' => $facts('another set of bytes')['sha256']]]);
        }],
        'F-extract-verifies-first' => ['outside resources/<storage key>', function (string $c) use ($key5): void {
            $base = s22c2_dir();
            mkdir($base.'/root', 0700);
            $gz = (string) gzencode(s22c2_tar([s22c2_dir_entry(), ['name' => 'resources/'.$key5, 'content' => 'x'], ['name' => 'resources/README', 'content' => 'y']]));
            $c::extract($gz, $base.'/root', [$key5 => ['bytes' => 1, 'sha256' => hash('sha256', 'x')]]);
        }],
        'F-extract-root' => ['files root is not a directory', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            $c::extract($gz, '/nonexistent/flc-s22c2', $rows);
        }],
        'F-extract-target-absent' => ['never extracts into, over or beside it', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            $c::extract($gz, s22c2_seeded(), $rows);
        }],
        'F-extract-status' => ['the extraction failed (exit status', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            $c::extract($gz, s22c2_dir(), $rows, 'false');
        }],
        'F-extract-result-present' => ['did not create the Resources directory', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            $c::extract($gz, s22c2_dir(), $rows, 'true');
        }],
        'F-extract-result' => ['not the content its asset row records', function (string $c): void {
            [$gz, $rows] = s22c2_real_archive();
            $root = s22c2_dir();
            // A `tar` that extracts, and then changes one byte of one file, keeping its size: what the post-extraction check exists to notice.
            $wrapper = s22c2_dir().'/tar-then-tamper';
            file_put_contents($wrapper, "#!/bin/sh\ntar \"\$@\" || exit \$?\nprintf Z | dd of='".$root.'/resources/'.array_key_first($rows)."' bs=1 count=1 conv=notrunc 2>/dev/null\n");
            chmod($wrapper, 0700);
            $c::extract($gz, $root, $rows, $wrapper);
        }],
        'F-aside-stamp' => ['not a UTC time', fn (string $c) => $c::setAside(s22c2_seeded(), 'yesterday')],
        'F-aside-root' => ['files root is not a directory', fn (string $c) => $c::setAside('/nonexistent/flc-s22c2', '20261116T120000Z')],
        'F-aside-source' => ['no Resources directory to move aside', fn (string $c) => $c::setAside(s22c2_dir(), '20261116T120000Z')],
        'F-aside-verifies' => ['not the content it was', function (string $c): void {
            $key = array_key_first(Files::fixture());
            $c::setAside(s22c2_seeded(), '20261116T120000Z', function (string $aside) use ($key): void {
                file_put_contents($aside.'/'.$key, str_repeat('Z', Files::fixture()[$key]['bytes']));
            });
        }],
        'F-preserved-present' => ['moved-aside directory is not there', function (string $c): void {
            $before = Files::inventory(s22c2_seeded());
            $c::verifyPreserved($before, ['state' => 'absent', 'files' => []]);
        }],
        'F-preserved-missing' => ['missing from the moved-aside directory', function (string $c): void {
            $before = Files::inventory(s22c2_seeded());
            $after = $before;
            unset($after['files'][array_key_last($after['files'])]);
            $c::verifyPreserved($before, $after);
        }],
        'F-preserved-extra' => ['was not there before', function (string $c) use ($key5): void {
            $before = Files::inventory(s22c2_seeded());
            $after = $before;
            $after['files'][$key5] = ['bytes' => 1, 'sha256' => str_repeat('0', 64)];
            $c::verifyPreserved($before, $after);
        }],
        'F-preserved-size' => ['not the size it was', function (string $c): void {
            $before = Files::inventory(s22c2_seeded());
            $after = $before;
            $after['files'][array_key_first($after['files'])]['bytes']++;
            $c::verifyPreserved($before, $after);
        }],
        'F-preserved-content' => ['not the content it was', function (string $c): void {
            $before = Files::inventory(s22c2_seeded());
            $after = $before;
            $after['files'][array_key_first($after['files'])]['sha256'] = str_repeat('f', 64);
            $c::verifyPreserved($before, $after);
        }],
        'F-aside-target' => ['aside name is taken', function (string $c): void {
            $root = s22c2_seeded();
            mkdir($root.'/resources.before-restore-20261116T120000Z');
            $c::setAside($root, '20261116T120000Z');
        }],
        'F-seed-root' => ['files root is not a directory', fn (string $c) => $c::seed('/nonexistent/flc-s22c2')],
        'F-seed-target-absent' => ['seeding never writes into one', fn (string $c) => $c::seed(s22c2_seeded())],
    ];
}

/** @return list<string> the guard IDs marked in a copy of the class's source */
function s22c2_guards(string $source): array
{
    preg_match_all('#// guard:(F-[a-z0-9-]+)$#m', $source, $matches);

    return array_values(array_unique($matches[1]));
}

it('has a scenario for every guard and a guard for every scenario', function (): void {
    $guards = s22c2_guards((string) file_get_contents(S22C2_CLASS_FILE));
    $scenarios = array_keys(s22c2_scenarios());
    sort($guards);
    sort($scenarios);

    expect(array_values(array_diff($guards, $scenarios)))->toBe([], 'guards with no scenario')
        ->and(array_values(array_diff($scenarios, $guards)))->toBe([], 'scenarios with no guard');
});

it('ends every scenario in its guard\'s refusal when the class is as committed', function (): void {
    $wrong = [];
    foreach (s22c2_scenarios() as $guard => [$fragment, $scenario]) {
        $outcome = s22c2_outcome(fn () => $scenario(Files::class));
        if (! $outcome instanceof ResourceFileRefusal) {
            $wrong[] = "$guard: expected a refusal, got ".($outcome === null ? 'none' : $outcome::class.': '.$outcome->getMessage());
        } elseif (! str_contains($outcome->getMessage(), $fragment)) {
            $wrong[] = "$guard: refused for another reason: {$outcome->getMessage()}";
        }
    }

    expect($wrong)->toBe([]);
});

it('is told by a scenario when a guard is removed: no scenario still ends in its guard\'s refusal without it', function (): void {
    $source = (string) file_get_contents(S22C2_CLASS_FILE);
    $survivors = [];
    $mutations = 0;
    foreach (s22c2_scenarios() as $guard => [$fragment, $scenario]) {
        $lines = explode("\n", $source);
        $marked = array_keys(array_filter($lines, static fn (string $line): bool => str_ends_with($line, '// guard:'.$guard)));
        expect($marked)->toHaveCount(1, "$guard is marked on exactly one line");
        $lines[$marked[0]] = ';';
        $name = 'ResourceFileArchiveMutant'.(++$mutations);
        $copy = s22c2_dir().'/'.$name.'.php';
        file_put_contents($copy, str_replace('final class ResourceFileArchive', 'final class '.$name, implode("\n", $lines)));
        require_once $copy;
        /** @var class-string<Files> $class */
        $class = 'Tests\\Support\\'.$name;

        $outcome = s22c2_outcome(fn () => $scenario($class));
        if ($outcome instanceof ResourceFileRefusal && str_contains($outcome->getMessage(), $fragment)) {
            $survivors[] = "$guard (still: {$outcome->getMessage()})";
        }
    }

    expect($survivors)->toBe([], 'guards whose removal changed nothing the scenario notices');
});
