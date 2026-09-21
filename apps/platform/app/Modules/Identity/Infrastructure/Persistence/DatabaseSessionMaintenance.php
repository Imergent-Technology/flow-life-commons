<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\SessionMaintenance;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Pruning the `sessions` table, in bounded batches.
 *
 * The batching is not an optimisation. One unbounded `DELETE` over a table that has been accumulating
 * since the last successful run is exactly the long-lock, long-transaction event that the scheduled
 * sweep exists to avoid; and `DELETE ... LIMIT` is MariaDB-only, so the portable form is to collect a
 * page of ids and delete those. Each batch is its own statement, so an interrupted run has still done
 * real work and the next one carries on.
 */
final readonly class DatabaseSessionMaintenance implements SessionMaintenance
{
    /** Rows per statement. Small enough that no single delete is long, large enough to be few. */
    private const int BATCH = 1000;

    public function __construct(private ConnectionInterface $database) {}

    public function pruneIdleBefore(DateTimeImmutable $idleBefore): int
    {
        $cutoff = $idleBefore->getTimestamp();
        $removed = 0;

        do {
            $ids = $this->database->table('sessions')
                ->where('last_activity', '<', $cutoff)
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $removed += $this->database->table('sessions')->whereIn('id', $ids)->delete();
        } while (count($ids) === self::BATCH);

        return $removed;
    }
}
