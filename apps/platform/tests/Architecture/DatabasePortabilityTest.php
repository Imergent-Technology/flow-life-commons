<?php

declare(strict_types=1);

/*
 * Guards MariaDB <-> PostgreSQL portability (ADR 0005, ADR 0014).
 *
 * Vendor-specific constructs are not banned forever, only until an ADR justifies
 * them. Annotate the line (or the line above) with:
 *
 *     // portability-exception: ADR-0042
 *
 * The real proof of portability is running the suite on PostgreSQL
 * (`./flow test backend --pgsql`, also run in CI); this test catches the common
 * mistakes early and explains why.
 */

/**
 * @return array<string, list<string>> file => lines
 */
function sourceLines(string ...$dirs): array
{
    $files = [];

    foreach ($dirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
            }
        }
    }

    ksort($files);

    return $files;
}

/**
 * @param  array<string, string>  $rules  regex => explanation
 * @param  array<string, list<string>>  $files
 * @return list<string>
 */
function portabilityViolations(array $rules, array $files): array
{
    $violations = [];

    foreach ($files as $path => $lines) {
        foreach ($lines as $i => $line) {
            $excused = str_contains($line, 'portability-exception: ADR-')
                || ($i > 0 && str_contains($lines[$i - 1], 'portability-exception: ADR-'));

            if ($excused) {
                continue;
            }

            foreach ($rules as $regex => $why) {
                if (preg_match($regex, $line) === 1) {
                    $violations[] = sprintf('%s:%d  %s', str_replace(dirname(__DIR__, 2).'/', '', $path), $i + 1, $why);
                }
            }
        }
    }

    return $violations;
}

it('keeps migrations free of vendor-specific schema features', function () {
    $violations = portabilityViolations([
        '/->enum\(/' => 'database ENUM is engine-specific; use a string column + validation/PHP enum',
        '/->set\(/' => 'MySQL SET type does not exist on PostgreSQL',
        '/->(virtualAs|storedAs|virtualAsJson|storedAsJson)\(/' => 'generated columns behave differently per engine',
        '/->useCurrentOnUpdate\(/' => 'ON UPDATE CURRENT_TIMESTAMP is MySQL-only; set updated_at in application code',
        '/->(engine|charset|collation)\s*=/' => 'table engine/charset/collation is MySQL-specific',
        '/DB::(unprepared|statement)\(|->unprepared\(/' => 'raw DDL is vendor-specific',
        '/CREATE\s+(OR\s+REPLACE\s+)?(TRIGGER|PROCEDURE|FUNCTION|EVENT|VIEW)/i' => 'triggers/procedures/functions/events are not portable',
    ], sourceLines(dirname(__DIR__, 2).'/database'));

    expect($violations)->toBe([]);
});

it('keeps application code free of MariaDB-specific SQL', function () {
    $violations = portabilityViolations([
        '/ON\s+DUPLICATE\s+KEY/i' => 'use Eloquent/query-builder upsert()',
        '/INSERT\s+IGNORE/i' => 'use insertOrIgnore()',
        '/GROUP_CONCAT|STRAIGHT_JOIN|FORCE\s+INDEX|USE\s+INDEX/i' => 'MySQL/MariaDB-only SQL',
        '/\bIFNULL\s*\(|\bSHOW\s+(TABLES|COLUMNS|INDEX)/i' => 'use COALESCE / Laravel Schema facade',
        '/JSON_(TABLE|EXTRACT|UNQUOTE)\s*\(/i' => 'JSON functions differ per engine; use query-builder JSON operators',
    ], sourceLines(dirname(__DIR__, 2).'/app'));

    expect($violations)->toBe([]);
});
