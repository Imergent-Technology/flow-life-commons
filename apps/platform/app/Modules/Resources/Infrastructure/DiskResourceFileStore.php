<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Domain\StorageKey;
use App\Modules\Resources\Domain\StoredFile;
use App\Modules\Resources\Domain\StoredObject;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * The Resources file store over Laravel's filesystem (ADR 0037, decisions 4 and 63): the dedicated `resources` disk, configured in
 * `config/filesystems.php` as a PRIVATE local disk rooted at `storage/app/private/resources`. On the production host `storage/` is the
 * release-shared `shared/storage`, outside the document root (ADR 0027), so nothing here has a URL: the disk has no `url` and is not
 * `serve`d, and a file is reachable only through an authorized Resources route that has resolved a Card first.
 *
 * Each file is named by its storage key, directly in the disk's root: no directory, no extension, nothing from the uploader. The disk
 * `throw`s on failure (so a write that did not happen is never mistaken for one that did) and skips links rather than following them.
 * A later backend (another disk, object storage) is a configuration or adapter change here, not a change to what a Card is.
 */
final readonly class DiskResourceFileStore implements ResourceFileStore
{
    public const string DISK = 'resources';

    public function __construct(private Filesystems $filesystems) {}

    public function put(StorageKey $key, string $sourcePath): void
    {
        $disk = $this->disk();
        if ($disk->exists($key->value)) {
            throw new FileStoreFailure('A stored file is never overwritten.');
        }
        $source = @fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new FileStoreFailure('The uploaded file could not be read.');
        }

        try {
            if ($disk->writeStream($key->value, $source) !== true) {
                throw new FileStoreFailure('The file could not be stored.');
            }
        } catch (FileStoreFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FileStoreFailure('The file could not be stored.', 0, $e);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    public function open(StorageKey $key): ?StoredFile
    {
        $disk = $this->disk();
        try {
            if (! $disk->exists($key->value)) {
                return null;
            }
            $size = $disk->size($key->value);
            $stream = $disk->readStream($key->value);
        } catch (Throwable) {
            return null;
        }

        return is_resource($stream) ? new StoredFile($stream, $size) : null;
    }

    public function exists(StorageKey $key): bool
    {
        try {
            return $this->disk()->exists($key->value);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(StorageKey $key): void
    {
        $disk = $this->disk();
        try {
            if ($disk->exists($key->value) && $disk->delete($key->value) !== true) {
                throw new FileStoreFailure('The stored file could not be removed.');
            }
        } catch (FileStoreFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FileStoreFailure('The stored file could not be removed.', 0, $e);
        }
    }

    public function listing(): array
    {
        $disk = $this->disk();
        $objects = [];
        foreach ($disk->files('') as $name) {
            $objects[] = new StoredObject($name, $disk->lastModified($name));
        }
        usort($objects, static fn (StoredObject $a, StoredObject $b): int => strcmp($a->name, $b->name));

        return $objects;
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk(self::DISK);
    }
}
