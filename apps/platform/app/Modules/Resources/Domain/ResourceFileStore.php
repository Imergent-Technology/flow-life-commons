<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Where File Cards' bytes are kept (ADR 0037, decision 4): a port, so the business model never names a disk, a directory or a
 * path, and a later storage backend is a new implementation rather than a change to what a Card is. Phase 1's implementation is a
 * PRIVATE Laravel disk under `storage/`, outside the web root; nothing in the store is reachable except through an authorized
 * Resources route that resolves a Card first.
 *
 * A store is not transactional and is never treated as if it were: a file is written BEFORE the row that references it commits,
 * and removed only AFTER the row's removal commits. Any failure in between leaves an unreferenced file, never a row without a
 * file; `resources:assets:prune` removes those.
 */
interface ResourceFileStore
{
    /**
     * Copies the file at `$sourcePath` into the store under `$key`. Never overwrites: a key that already holds a file is a failure,
     * so a write can never replace bytes a committed row refers to.
     *
     * @throws FileStoreFailure
     */
    public function put(StorageKey $key, string $sourcePath): void;

    /** The stored file, opened for reading, or null when there is no file under that key. */
    public function open(StorageKey $key): ?StoredFile;

    public function exists(StorageKey $key): bool;

    /**
     * Removes the file under `$key`. Removing a file that is already gone succeeds.
     *
     * @throws FileStoreFailure
     */
    public function delete(StorageKey $key): void;

    /**
     * Every file directly in the store, by its raw name, in name order: what the prune decides about. Directories and links are
     * not listed and nothing below the top level is: the store writes no directories, and follows no link.
     *
     * @return list<StoredObject>
     */
    public function listing(): array;
}
