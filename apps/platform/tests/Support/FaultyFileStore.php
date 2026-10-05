<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Domain\StorageKey;
use App\Modules\Resources\Domain\StoredFile;

/**
 * The real Resources file store with failures switched on at one point (WP3): a write that fails, a removal that fails, and a log of
 * every removal and when, relative to the database, it happened. Decorates whatever store the container holds (the faked disk), so
 * what does happen is real. Bound with `FaultyFileStore::install()`.
 */
final class FaultyFileStore implements ResourceFileStore
{
    public bool $failPut = false;

    public bool $failDelete = false;

    /** @var list<array{key: string, transactionLevel: int}> every delete asked for, with the DB transaction depth at that moment */
    public array $deletes = [];

    /** @var list<string> */
    public array $puts = [];

    /** @var null|\Closure(StorageKey): void runs when a delete is asked for, before it happens */
    public ?\Closure $onDelete = null;

    public function __construct(private ResourceFileStore $inner) {}

    public static function install(): self
    {
        $store = new self(app(ResourceFileStore::class));
        app()->instance(ResourceFileStore::class, $store);

        return $store;
    }

    public function put(StorageKey $key, string $sourcePath): void
    {
        if ($this->failPut) {
            throw new FileStoreFailure('injected write failure');
        }
        $this->inner->put($key, $sourcePath);
        $this->puts[] = $key->value;
    }

    public function open(StorageKey $key): ?StoredFile
    {
        return $this->inner->open($key);
    }

    public function exists(StorageKey $key): bool
    {
        return $this->inner->exists($key);
    }

    public function delete(StorageKey $key): void
    {
        $this->deletes[] = ['key' => $key->value, 'transactionLevel' => app('db')->connection()->transactionLevel()];
        if ($this->onDelete !== null) {
            ($this->onDelete)($key);
        }
        if ($this->failDelete) {
            throw new FileStoreFailure('injected removal failure');
        }
        $this->inner->delete($key);
    }

    public function listing(): array
    {
        return $this->inner->listing();
    }

    /** @return list<string> */
    public function deletedKeys(): array
    {
        return array_column($this->deletes, 'key');
    }
}
