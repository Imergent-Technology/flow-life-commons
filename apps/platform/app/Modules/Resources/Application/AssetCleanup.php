<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Domain\StorageKey;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes files from the store at the only safe moments (ADR 0037, decision 61). Internal to Resources.
 *
 * - `afterCommit`: a file whose row the current transaction deleted (a deleted Card, a deleted Pack's Cards, a replaced file) is
 *   removed once that transaction has COMMITTED, never before: removing it first would leave a row pointing at nothing if the
 *   transaction then rolled back. Registered from inside the transaction; if it rolls back (a refused deletion, a failed audit write,
 *   a deadlock retry), the removal is forgotten and the file stays with its row. Outside any transaction it runs at once.
 * - `discard`: a file just written for an upload whose transaction failed, which no row refers to.
 *
 * Neither ever throws. Business state has already been decided by then, and a file that cannot be removed is an orphan: no row
 * refers to it, so it can never be served, and `resources:assets:prune` removes it later. The failure is logged with the storage
 * key, which is operational detail and goes to the application log only, never to a response or a security event.
 */
final readonly class AssetCleanup
{
    public function __construct(private ResourceFileStore $files, private ConnectionInterface $database, private LoggerInterface $log) {}

    /** @param  list<StorageKey>  $keys */
    public function afterCommit(array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $remove = fn () => $this->remove($keys, 'after its row was deleted');
        if ($this->database instanceof Connection) {
            $this->database->afterCommit($remove);
        } else {
            $remove();
        }
    }

    public function discard(StorageKey $key): void
    {
        $this->remove([$key], 'after the change that wrote it failed');
    }

    /** @param  list<StorageKey>  $keys */
    private function remove(array $keys, string $when): void
    {
        foreach ($keys as $key) {
            try {
                $this->files->delete($key);
            } catch (Throwable $e) {
                $this->log->warning("Resources could not remove a stored file {$when}; resources:assets:prune will remove it.", [
                    'storage_key' => $key->value,
                    'exception' => $e::class,
                ]);
            }
        }
    }
}
