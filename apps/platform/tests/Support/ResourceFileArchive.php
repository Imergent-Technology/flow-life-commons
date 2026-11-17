<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * G6 WP3c S2.2C-2. What the recovery rehearsal does with the Resource FILES, as pure PHP with no framework: it is run by
 * scripts/rehearsal/php/files.php inside a disposable container that has no network, no database credential and no application, and by
 * tests/Unit/Rehearsal/ResourceFileArchiveTest.php against a real directory and the real `tar`.
 *
 * It implements the backup contract in docs/runbooks/backup-and-restore.md ("Resource files") and docs/runbooks/deployment.md §5, and only that:
 *
 *  - `inventory`   reads the Resources directory, `<root>/resources`, and refuses anything the contract does not allow: a symbolic link, a
 *                  subdirectory, a hard link, a file that is not named as a Resources storage key. An ABSENT directory is a state, not an error.
 *  - `agree`       holds that inventory to the `resource_assets` rows (key, byte size, SHA-256) or to their absence: content, not just names.
 *  - `createArchive` is the runbook's `tar -C <root> -czf <archive> resources`, its exit status checked, followed by `verifyArchive`.
 *  - `readArchive` / `verifyArchive` read the archive with a deliberately strict reader of their own (not `tar`), so the tool that wrote
 *                  an archive is not the only judge of it: gzip must be one complete stream, the tar must be whole blocks ending in its
 *                  end marker, and the ONLY entries allowed are the directory `resources/` (first) and `resources/<storage key>` regular files.
 *  - `extract`     verifies the archive and the target BEFORE it writes anything, refuses unless `<root>/resources` does not exist, and
 *                  extracts with `tar --keep-old-files`; it then holds the result to the archive's own inventory.
 *  - `setAside`    renames `resources` to `resources.before-restore-<stamp>`. A rename: nothing is deleted, merged or overwritten.
 *  - `seed`        writes the fixed synthetic fixture into a directory that does not exist.
 *
 * Nothing here deletes a file, follows a link, takes a path from a caller beyond the one root it is given, or repairs a state. The real
 * `resources:assets:prune` is never involved. A refusal is a {@see ResourceFileRefusal}; its message names keys and counts, never content.
 *
 * Every safeguard is one `self::must(...)` line ending in `// guard:<ID>`. ResourceFileArchiveTest removes each, in a copy of this file,
 * and requires its scenario to stop being refused: a guard with no scenario, or a scenario no guard needs, fails that test.
 *
 * @phpstan-type FileFacts array{bytes: int, sha256: string}
 * @phpstan-type Rows array<string, FileFacts>
 * @phpstan-type Inventory array{state: 'absent'|'present', files: array<string, FileFacts>}
 * @phpstan-type Entry array{name: string, type: 'dir'|'file', key: string|null, bytes: int, sha256: string|null}
 */
final class ResourceFileArchive
{
    /** The directory the private `resources` disk is rooted at, inside the root (config/filesystems.php: storage/app/private/resources). */
    public const string STORE = 'resources';

    /** App\Modules\Resources\Domain\StorageKey::PATTERN, written out: the lowercase ULID form and nothing else. */
    public const string KEY_PATTERN = '/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/';

    public const string ASIDE_PREFIX = 'resources.before-restore-';

    public const string STAMP_PATTERN = '/\A[0-9]{8}T[0-9]{6}Z\z/';

    public const int MAX_FILES = 500;

    /** The gzip archive's size and its expanded size: a rehearsal archive is kilobytes, so anything near these is not one. */
    public const int MAX_COMPRESSED = 8388608;

    public const int MAX_EXPANDED = 16777216;

    /** 2026-01-01T00:00:00Z: the fixture's modification time, well outside the prune's 24-hour grace. */
    public const int FIXTURE_MTIME = 1767225600;

    public const int FIXTURE_FILES = 4;

    private const int TYPE_MASK = 0170000;

    private const int TYPE_FILE = 0100000;

    private const int TYPE_DIRECTORY = 0040000;

    private const int TYPE_LINK = 0120000;

    /**
     * The synthetic files: deterministic keys (valid lowercase ULIDs) and bytes (SHA-256 expansions of fixed labels), of sizes that cross the
     * hashing buffer. Nothing of Commons is here.
     *
     * @return array<string, array{bytes: int, sha256: string, content: string}>
     */
    public static function fixture(): array
    {
        $files = [];
        foreach ([64, 1500, 9000, 33333] as $index => $size) {
            $number = $index + 1;
            $content = '';
            for ($block = 0; strlen($content) < $size; $block++) {
                $content .= hash('sha256', "flc-s22c2-fixture-$number-$block", true);
            }
            $content = substr($content, 0, $size);
            $files['01jcf'.sprintf('%021x', $number)] = ['bytes' => $size, 'sha256' => hash('sha256', $content), 'content' => $content];
        }

        return $files;
    }

    /**
     * What the directory holds. Refuses a state the contract does not allow; reads every file, so the SHA-256 is of the bytes there now.
     *
     * @return Inventory
     */
    public static function inventory(string $root): array
    {
        clearstatcache(true);
        self::must(is_dir($root) && ! is_link($root), 'the files root is not a directory'); // guard:F-root-dir
        $store = $root.'/'.self::STORE;
        self::must(! is_link($store), 'the Resources directory is a symbolic link'); // guard:F-store-link
        if (! file_exists($store)) {
            return ['state' => 'absent', 'files' => []];
        }
        self::must(is_dir($store), 'the Resources path exists and is not a directory'); // guard:F-store-dir
        $names = scandir($store);
        self::must($names !== false, 'the Resources directory cannot be listed');
        $files = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $store.'/'.$name;
            $stat = lstat($path);
            self::must($stat !== false, 'an entry of the Resources directory cannot be read');
            $type = $stat['mode'] & self::TYPE_MASK;
            self::must($type !== self::TYPE_LINK, 'the Resources directory holds a symbolic link'); // guard:F-entry-symlink
            self::must($type !== self::TYPE_DIRECTORY, 'the Resources directory holds a subdirectory'); // guard:F-entry-subdirectory
            self::must($type === self::TYPE_FILE, 'the Resources directory holds an entry that is not a regular file'); // guard:F-entry-regular
            self::must(preg_match(self::KEY_PATTERN, $name) === 1, 'the Resources directory holds a file that is not named as a storage key'); // guard:F-entry-name
            self::must($stat['nlink'] === 1, 'the Resources directory holds a file with more than one hard link (key '.$name.')'); // guard:F-entry-hardlink
            $sha = hash_file('sha256', $path);
            self::must($sha !== false, 'a stored file cannot be read (key '.$name.')');
            $files[$name] = ['bytes' => $stat['size'], 'sha256' => $sha];
        }
        self::must(count($files) <= self::MAX_FILES, 'the Resources directory holds more files than the rehearsal accepts'); // guard:F-file-limit
        ksort($files, SORT_STRING);

        return ['state' => 'present', 'files' => $files];
    }

    /**
     * The inventory agrees with the `resource_assets` rows ($rows, or null when the table does not exist), or this refuses. The rows' byte
     * size and SHA-256 are compared with what is in the file: matching names are not enough.
     *
     * @param  Inventory  $inventory
     * @param  Rows|null  $rows
     */
    public static function agree(array $inventory, ?array $rows): void
    {
        $files = $inventory['files'];
        if ($inventory['state'] === 'absent') {
            self::must($rows === null || $rows === [], 'the Resources directory is absent while resource_assets has '.count($rows ?? []).' row(s): this is not a zero-file state'); // guard:F-absent-with-rows

            return;
        }
        self::must($rows !== null || $files === [], 'the Resources directory holds files but there is no resource_assets table for them to belong to'); // guard:F-files-without-table
        $rows ??= [];
        $missing = array_keys(array_diff_key($rows, $files));
        self::must($missing === [], 'a referenced file is missing (key '.($missing[0] ?? '').', '.count($missing).' in all)'); // guard:F-missing-file
        $extra = array_keys(array_diff_key($files, $rows));
        self::must($extra === [], 'the Resources directory holds a file no asset refers to (key '.($extra[0] ?? '').', '.count($extra).' in all), however recently it was written'); // guard:F-unexpected-file
        foreach ($rows as $key => $row) {
            self::must($files[$key]['bytes'] === $row['bytes'], 'a file is not the size its asset row records (key '.$key.')'); // guard:F-size-differs
            self::must($files[$key]['sha256'] === $row['sha256'], 'a file is not the content its asset row records (key '.$key.')'); // guard:F-content-differs
        }
    }

    /**
     * The command-line form of rows: `none` (no resource_assets table), `empty` (a table with no rows), or `key:bytes:sha256[,...]`.
     *
     * @return Rows|null
     */
    public static function parseSpec(string $spec): ?array
    {
        if ($spec === 'none') {
            return null;
        }
        if ($spec === 'empty') {
            return [];
        }
        $rows = [];
        foreach (explode(',', $spec) as $item) {
            $parts = explode(':', $item);
            self::must(count($parts) === 3, 'a file list entry is not key:bytes:sha256'); // guard:F-spec-shape
            [$key, $bytes, $sha] = $parts;
            self::must(preg_match(self::KEY_PATTERN, $key) === 1 && preg_match('/\A(0|[1-9][0-9]{0,11})\z/', $bytes) === 1 && preg_match('/\A[0-9a-f]{64}\z/', $sha) === 1, 'a file list entry has a field of the wrong form'); // guard:F-spec-fields
            self::must(! isset($rows[$key]), 'a file list names a key twice'); // guard:F-spec-duplicate
            $rows[$key] = ['bytes' => (int) $bytes, 'sha256' => $sha];
            self::must(count($rows) <= self::MAX_FILES, 'a file list is longer than the rehearsal accepts'); // guard:F-spec-limit
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    /** @param  Rows|null  $rows */
    public static function spec(?array $rows): string
    {
        if ($rows === null) {
            return 'none';
        }
        if ($rows === []) {
            return 'empty';
        }
        $items = [];
        foreach ($rows as $key => $row) {
            $items[] = $key.':'.$row['bytes'].':'.$row['sha256'];
        }

        return implode(',', $items);
    }

    /**
     * The runbook's archive command, its status checked, then the archive read back by the strict reader and held to the inventory the caller
     * has already agreed with the database. Never overwrites: $to must not exist.
     *
     * @param  Inventory  $inventory  the inventory of $root, state 'present'
     */
    public static function createArchive(string $root, string $to, array $inventory, string $tar = 'tar'): void
    {
        self::must($inventory['state'] === 'present', 'there is no Resources directory to archive'); // guard:F-archive-needs-directory
        self::must(! file_exists($to) && ! is_link($to), 'the archive path already exists and is never overwritten'); // guard:F-archive-no-overwrite
        $status = self::run([$tar, '-C', $root, '-czf', $to, self::STORE]);
        self::must($status === 0, "the archive command failed (exit status $status)"); // guard:F-archive-status
        $bytes = is_file($to) ? file_get_contents($to) : false;
        self::must($bytes !== false && $bytes !== '', 'the archive command wrote no archive'); // guard:F-archive-written
        self::verifyArchive($bytes, $inventory['files']);
    }

    /**
     * The archive's entries, or a refusal. The only entries allowed are the directory `resources/`, first, and `resources/<storage key>`
     * regular files, each once; the gzip stream and the tar must be complete and have nothing after them.
     *
     * @return list<Entry>
     */
    public static function readArchive(string $gz): array
    {
        $tar = self::gunzip($gz);
        $length = strlen($tar);
        /** @var list<Entry> $entries */
        $entries = [];
        self::must($length > 0 && $length % 512 === 0, 'the archive is not a whole number of 512-byte tar blocks'); // guard:F-tar-blocks
        $seen = [];
        $offset = 0;
        $ended = false;
        while ($offset < $length) {
            $header = substr($tar, $offset, 512);
            if (self::isZero($header)) {
                self::must($length - $offset >= 1024 && self::isZero(substr($tar, $offset)), 'the end of the archive is not two zero blocks and nothing else'); // guard:F-tar-end
                $ended = true;
                break;
            }
            $name = self::text($header, 0, 100, 'a name');
            self::must(self::checksum($header) === self::octal(substr($header, 148, 8), 'a checksum'), 'a tar header fails its checksum'); // guard:F-tar-checksum
            self::must(str_starts_with(substr($header, 257, 6), 'ustar'), 'a tar header is not the ustar format'); // guard:F-tar-magic
            self::must(self::isZero(substr($header, 345, 155)), 'a tar header uses a name prefix'); // guard:F-tar-prefix
            self::must((self::octal(substr($header, 100, 8), 'a mode') & 07000) === 0, 'a tar header sets a setuid, setgid or sticky bit'); // guard:F-tar-mode
            self::must(self::isZero(substr($header, 157, 100)), 'a tar header carries a link target'); // guard:F-tar-linkname
            $flag = $header[156];
            $size = self::octal(substr($header, 124, 12), 'a size');
            $offset += 512;
            if ($flag === '5') {
                self::must($name === self::STORE.'/', 'the archive holds a directory other than resources/'); // guard:F-tar-directory-name
                self::must($entries === [], 'the archive does not start with its resources/ directory entry'); // guard:F-tar-directory-first
                self::must($size === 0, 'the directory entry has content');
                $entries[] = ['name' => $name, 'type' => 'dir', 'key' => null, 'bytes' => 0, 'sha256' => null];

                continue;
            }
            self::must($flag === '0' || $flag === "\0", 'the archive holds an entry that is not a regular file or its directory (type '.bin2hex($flag).')'); // guard:F-tar-type
            self::must($entries !== [], 'a file entry comes before the resources/ directory entry'); // guard:F-tar-directory-first-file
            self::must(preg_match('#\A'.preg_quote(self::STORE, '#').'/([0-7][0-9a-hjkmnp-tv-z]{25})\z#', $name, $match) === 1, 'the archive holds an entry outside resources/<storage key>'); // guard:F-tar-entry-name
            $key = $match[1];
            self::must(! isset($seen[$key]), 'the archive holds the same entry twice (key '.$key.')'); // guard:F-tar-duplicate
            $seen[$key] = true;
            $span = intdiv($size + 511, 512) * 512;
            self::must($offset + $span <= $length, 'an entry\'s content runs past the end of the archive (key '.$key.')'); // guard:F-tar-truncated
            $content = substr($tar, $offset, $size);
            self::must(self::isZero(substr($tar, $offset + $size, $span - $size)), 'an entry\'s padding is not zero (key '.$key.')'); // guard:F-tar-padding
            $entries[] = ['name' => $name, 'type' => 'file', 'key' => $key, 'bytes' => $size, 'sha256' => hash('sha256', $content)];
            $offset += $span;
            self::must(count($entries) <= self::MAX_FILES + 1, 'the archive holds more entries than the rehearsal accepts'); // guard:F-tar-entry-limit
        }
        self::must($ended, 'the archive has no end marker (it is incomplete)'); // guard:F-tar-ended
        self::must($entries !== [], 'the archive holds no entries'); // guard:F-tar-nonempty

        return $entries;
    }

    /**
     * The archive holds exactly these files, each with the size and SHA-256 given, and nothing else.
     *
     * @param  Rows  $expected
     */
    public static function verifyArchive(string $gz, array $expected): void
    {
        $found = [];
        foreach (self::readArchive($gz) as $entry) {
            if ($entry['type'] === 'file' && $entry['key'] !== null && $entry['sha256'] !== null) {
                $found[$entry['key']] = ['bytes' => $entry['bytes'], 'sha256' => $entry['sha256']];
            }
        }
        $missing = array_keys(array_diff_key($expected, $found));
        self::must($missing === [], 'the archive lacks a file the backup records (key '.($missing[0] ?? '').', '.count($missing).' in all)'); // guard:F-archive-missing-entry
        $extra = array_keys(array_diff_key($found, $expected));
        self::must($extra === [], 'the archive holds a file the backup does not record (key '.($extra[0] ?? '').', '.count($extra).' in all)'); // guard:F-archive-extra-entry
        foreach ($expected as $key => $facts) {
            self::must($found[$key]['bytes'] === $facts['bytes'], 'an archived file is not the recorded size (key '.$key.')'); // guard:F-archive-entry-size
            self::must($found[$key]['sha256'] === $facts['sha256'], 'an archived file is not the recorded content (key '.$key.')'); // guard:F-archive-entry-content
        }
    }

    /**
     * Restores the archive into a root whose Resources directory does NOT exist. The archive is verified, and the target checked, before
     * anything is written; the extraction cannot overwrite (`--keep-old-files`); the result is then held to the archive's own inventory.
     * Nothing is ever removed: a failure part-way leaves what was extracted for a person to read.
     *
     * @param  Rows  $expected
     */
    public static function extract(string $gz, string $root, array $expected, string $tar = 'tar'): void
    {
        self::verifyArchive($gz, $expected); // guard:F-extract-verifies-first
        self::must(is_dir($root) && ! is_link($root), 'the files root is not a directory'); // guard:F-extract-root
        $store = $root.'/'.self::STORE;
        self::must(! file_exists($store) && ! is_link($store), 'the Resources directory exists: a restore never extracts into, over or beside it. Move it aside first'); // guard:F-extract-target-absent
        $temporary = tempnam(sys_get_temp_dir(), 'flc-files-');
        self::must($temporary !== false, 'a temporary file cannot be made');
        try {
            self::must(file_put_contents($temporary, $gz) === strlen($gz), 'the archive cannot be staged');
            $status = self::run([$tar, '-x', '-z', '-f', $temporary, '-C', $root, '--no-same-owner', '--keep-old-files']);
        } finally {
            @unlink($temporary);
        }
        self::must($status === 0, "the extraction failed (exit status $status)"); // guard:F-extract-status
        $inventory = self::inventory($root);
        self::must($inventory['state'] === 'present', 'the extraction did not create the Resources directory'); // guard:F-extract-result-present
        self::agree($inventory, $expected); // guard:F-extract-result
    }

    /** Renames `resources` to `resources.before-restore-<stamp>` and returns the new name. A rename: nothing is deleted, merged or overwritten. */
    public static function setAside(string $root, string $stamp): string
    {
        self::must(preg_match(self::STAMP_PATTERN, $stamp) === 1, 'the stamp is not a UTC time of the form YYYYMMDDTHHMMSSZ'); // guard:F-aside-stamp
        self::must(is_dir($root) && ! is_link($root), 'the files root is not a directory'); // guard:F-aside-root
        $from = $root.'/'.self::STORE;
        self::must(is_dir($from) && ! is_link($from), 'there is no Resources directory to move aside'); // guard:F-aside-source
        $name = self::ASIDE_PREFIX.$stamp;
        self::must(! file_exists($root.'/'.$name) && ! is_link($root.'/'.$name), 'the aside name is taken and is never overwritten or merged'); // guard:F-aside-target
        self::must(rename($from, $root.'/'.$name), 'the directory could not be moved aside');

        return $name;
    }

    /**
     * Writes the fixed synthetic fixture into a root with no Resources directory and returns the inventory it then reads back.
     *
     * @return Inventory
     */
    public static function seed(string $root): array
    {
        self::must(is_dir($root) && ! is_link($root), 'the files root is not a directory'); // guard:F-seed-root
        $store = $root.'/'.self::STORE;
        self::must(! file_exists($store) && ! is_link($store), 'the Resources directory exists: seeding never writes into one'); // guard:F-seed-target-absent
        self::must(mkdir($store, 0700), 'the Resources directory cannot be created');
        $rows = [];
        foreach (self::fixture() as $key => $file) {
            $path = $store.'/'.$key;
            $handle = fopen($path, 'xb');
            self::must($handle !== false, 'a fixture file cannot be created (key '.$key.')');
            $written = fwrite($handle, $file['content']);
            fclose($handle);
            self::must($written === $file['bytes'], 'a fixture file was not written whole (key '.$key.')');
            self::must(chmod($path, 0600) && touch($path, self::FIXTURE_MTIME), 'a fixture file cannot be finished (key '.$key.')');
            $rows[$key] = ['bytes' => $file['bytes'], 'sha256' => $file['sha256']];
        }
        $inventory = self::inventory($root);
        self::agree($inventory, $rows);

        return $inventory;
    }

    // --- internals ---------------------------------------------------------------------------------------------------------------------

    /** @phpstan-assert true $holds */
    private static function must(bool $holds, string $message): void
    {
        if (! $holds) {
            throw new ResourceFileRefusal($message);
        }
    }

    /**
     * One complete gzip stream, with nothing after it, no larger than MAX_EXPANDED when expanded.
     */
    private static function gunzip(string $gz): string
    {
        self::must($gz !== '' && strlen($gz) <= self::MAX_COMPRESSED, 'the archive is empty or larger than the rehearsal accepts'); // guard:F-gzip-size
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        self::must($context !== false, 'zlib is not available');
        $tar = '';
        foreach (str_split($gz, 16384) as $chunk) {
            $piece = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            self::must($piece !== false, 'the archive is not valid gzip data'); // guard:F-gzip-valid
            $tar .= $piece;
            self::must(strlen($tar) <= self::MAX_EXPANDED, 'the archive expands beyond what the rehearsal accepts'); // guard:F-gzip-expansion
        }
        self::must(inflate_get_status($context) === ZLIB_STREAM_END, 'the gzip stream is incomplete'); // guard:F-gzip-complete
        self::must(inflate_get_read_len($context) === strlen($gz), 'the archive has data after its gzip stream'); // guard:F-gzip-trailing

        return $tar;
    }

    private static function isZero(string $bytes): bool
    {
        return ltrim($bytes, "\0") === '';
    }

    /** A NUL-padded text field: up to the first NUL, and nothing but NULs after it. */
    private static function text(string $header, int $start, int $length, string $what): string
    {
        $field = substr($header, $start, $length);
        $end = strpos($field, "\0");
        $text = $end === false ? $field : substr($field, 0, $end);
        self::must($end === false || self::isZero(substr($field, $end)), "$what in a tar header has bytes after its end"); // guard:F-tar-field-nul

        return $text;
    }

    /** An octal field, NUL or space terminated: digits only. A base-256 or signed value is refused. */
    private static function octal(string $field, string $what): int
    {
        $digits = rtrim($field, "\0 ");
        self::must(preg_match('/\A[0-7]{1,11}\z/', $digits) === 1, "$what in a tar header is not an octal number"); // guard:F-tar-octal

        return (int) octdec($digits);
    }

    /** The header's checksum: the sum of its bytes with the checksum field taken as eight spaces. */
    private static function checksum(string $header): int
    {
        $sum = 0;
        /** @var array<int, int> $bytes */
        $bytes = unpack('C*', substr($header, 0, 148).str_repeat(' ', 8).substr($header, 156, 356));
        foreach ($bytes as $byte) {
            $sum += $byte;
        }

        return $sum;
    }

    /**
     * One command, no shell, a fixed environment, nothing on its input. Its output is not kept: the contract is its exit status, and the
     * archive and the directory are then read back.
     *
     * @param  non-empty-list<string>  $command
     */
    private static function run(array $command): int
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C']);
        self::must(is_resource($process), 'a command could not be started');
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
