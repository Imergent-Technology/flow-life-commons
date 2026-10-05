<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Domain\StorageKey;
use DateTimeImmutable;
use Throwable;

/**
 * Removes the Resources store's ORPHANS: files no asset row refers to, older than a grace period (ADR 0037, decision 61). An operator
 * task with no Actor and no capability, like Identity's prune; `resources:assets:prune` runs it, on the schedule and by hand.
 *
 * Orphans exist by design. A file is written before the row that names it commits and removed only after the row's removal commits,
 * so a failure at either end leaves a file that nothing refers to and that therefore can never be served. Nothing else makes one.
 *
 * What it will never touch:
 *
 * - a file an asset row refers to: the referenced keys are read from the database, and a referenced file is kept whatever its age;
 * - a file younger than the grace period (`resources.assets.prune_grace_hours`, default 24): an upload whose row has not committed
 *   yet looks exactly like an orphan, for a few seconds. A file can only BECOME referenced in the transaction that wrote it, so once
 *   it is older than that it never will be;
 * - anything in the store whose name is not a storage key this module writes (the lowercase ULID form): another file, a dotfile, a
 *   directory or a link is reported and left alone. The store lists only its top level and follows no link.
 *
 * Deterministic: it decides in name order and reports exactly what it decided. A dry run decides the same and removes nothing. It is
 * idempotent: a second run finds nothing left to remove. Running it right after a database restore would remove the files of every
 * asset newer than the restored dump, which is correct for that database and irreversible: see the backup runbook.
 *
 * It also reports, without changing anything, asset rows whose file is missing from the store (`asset_unavailable` on download).
 */
final readonly class PruneOrphanedAssets
{
    public const int DEFAULT_GRACE_HOURS = 24;

    public function __construct(private ResourceFileStore $files, private CardRepository $cards) {}

    public static function graceHours(): int
    {
        return max(1, config()->integer('resources.assets.prune_grace_hours', self::DEFAULT_GRACE_HOURS));
    }

    public function __invoke(DateTimeImmutable $now, bool $dryRun): PruneReport
    {
        $grace = self::graceHours();
        $cutoff = $now->getTimestamp() - $grace * 3600;

        // Listed first, then the references: a file written and referenced in between is then seen as referenced, or as too recent.
        $listing = $this->files->listing();
        $referenced = [];
        foreach ($this->cards->assetStorageKeys() as $key) {
            $referenced[$key->value] = true;
        }

        $report = new PruneReport($grace, $dryRun);
        $present = [];
        foreach ($listing as $object) {
            $key = StorageKey::tryFromName($object->name);
            if ($key === null) {
                $report->ignored[] = $object->name;

                continue;
            }
            $present[$key->value] = true;
            if (isset($referenced[$key->value])) {
                $report->kept++;
            } elseif ($object->lastModified > $cutoff) {
                $report->recent[] = $key->value;
            } elseif ($dryRun) {
                $report->removed[] = $key->value;
            } else {
                try {
                    $this->files->delete($key);
                    $report->removed[] = $key->value;
                } catch (Throwable) {
                    $report->failed[] = $key->value;
                }
            }
        }

        foreach (array_keys($referenced) as $key) {
            if (! isset($present[$key])) {
                $report->missing[] = $key;
            }
        }
        sort($report->missing);

        return $report;
    }
}
