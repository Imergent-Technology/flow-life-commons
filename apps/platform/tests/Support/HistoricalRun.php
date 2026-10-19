<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What one historical upgrade looked like at each stage: the v0.3.0 schema as built, the rows as loaded, and the database after the
 * remaining migrations (G6 WP3b). Built once per test file by `historicalUpgradeRun()`.
 *
 * @phpstan-import-type Rows from HistoricalSnapshot
 * @phpstan-import-type Structure from HistoricalSnapshot
 */
final readonly class HistoricalRun
{
    /**
     * @param  list<string>  $historicalTables  the tables the 15 migrations made
     * @param  list<string>  $historicalRan  the migrations recorded after them
     * @param  list<int>  $historicalBatches  the batch of each
     * @param  Structure  $historicalStructure
     * @param  Rows  $before  every row of the 16 tables, with the fixture loaded
     * @param  Rows  $after  every row after the other migrations
     * @param  Structure  $afterStructure
     * @param  list<string>  $afterTables
     * @param  list<string>  $afterRan
     * @param  list<int>  $afterBatches
     */
    public function __construct(
        public array $historicalTables,
        public array $historicalRan,
        public array $historicalBatches,
        public array $historicalStructure,
        public array $before,
        public array $after,
        public array $afterStructure,
        public array $afterTables,
        public array $afterRan,
        public array $afterBatches,
    ) {}
}
