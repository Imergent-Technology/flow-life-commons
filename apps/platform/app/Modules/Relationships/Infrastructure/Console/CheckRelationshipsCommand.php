<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Infrastructure\Console;

use App\Modules\Relationships\Application\CheckRelationships;
use Illuminate\Console\Command;

/**
 * `relationships:check`: types, states, field values and sourced-grant causes (ADR 0038, F15).
 * Read-only. A finding names a type, a key or an id, never a stored field value.
 */
final class CheckRelationshipsCommand extends Command
{
    protected $signature = 'relationships:check';

    protected $description = 'Check stored relationships against the current definitions (read-only).';

    public function handle(CheckRelationships $check): int
    {
        $findings = $check();
        foreach ($findings as $finding) {
            $this->line($finding);
        }
        if ($findings !== []) {
            $this->error(count($findings).' finding(s).');

            return self::FAILURE;
        }
        $this->info('Relationships are consistent with their definitions.');

        return self::SUCCESS;
    }
}
