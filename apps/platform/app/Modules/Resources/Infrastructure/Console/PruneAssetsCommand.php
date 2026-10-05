<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure\Console;

use App\Modules\Resources\Application\PruneOrphanedAssets;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * `resources:assets:prune`: removes files in the Resources store that no asset row refers to and that are older than the grace
 * period (see PruneOrphanedAssets for exactly what it will and will not touch). `--dry-run` reports the same decisions and removes
 * nothing.
 *
 * Production runs it daily from Laravel's scheduler (routes/console.php), which does not run it while the application is in
 * maintenance mode, so it never runs in the middle of a restore. It is safe to run by hand at any time and repeatedly. After a
 * database restore, run it with `--dry-run` first: an orphan then is a file newer than the restored dump (backup runbook).
 *
 * Exits non-zero only when an orphan it tried to remove could not be removed. Asset rows whose file is missing are reported as a
 * warning, never repaired: that needs a person and a backup.
 */
final class PruneAssetsCommand extends Command
{
    protected $signature = 'resources:assets:prune {--dry-run : Report what would be removed, and remove nothing}';

    protected $description = 'Remove Resources files that no asset refers to and that are older than the grace period.';

    public function handle(PruneOrphanedAssets $prune): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $prune(DateTimeImmutable::createFromInterface(now()), $dryRun);

        $found = $report->kept + count($report->removed) + count($report->failed) + count($report->recent) + count($report->ignored);
        $this->line(sprintf('Resources file store: %d file(s) found%s.', $found, $dryRun ? ' (dry run: nothing is removed)' : ''));
        $this->line(sprintf('  referenced by an asset, kept: %d', $report->kept));
        $this->listed(sprintf('  unreferenced but younger than %d hour(s), left alone: %d', $report->graceHours, count($report->recent)), $report->recent);
        $this->listed(sprintf('  not a Resources storage key, left alone: %d', count($report->ignored)), $report->ignored);
        $this->listed(sprintf('  orphaned, %s: %d', $dryRun ? 'would be removed' : 'removed', count($report->removed)), $report->removed);
        $this->listed(sprintf('  orphaned, could NOT be removed (tried again next run): %d', count($report->failed)), $report->failed);
        $this->listed(sprintf('Asset rows whose file is missing from the store (asset_unavailable): %d', count($report->missing)), $report->missing);
        if ($report->missing !== []) {
            $this->warn('Restore those files from the backup taken with the database; they cannot be served until then.');
        }

        return $report->failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<string>  $names */
    private function listed(string $heading, array $names): void
    {
        $this->line($heading);
        foreach ($names as $name) {
            $this->line("    {$name}");
        }
    }
}
