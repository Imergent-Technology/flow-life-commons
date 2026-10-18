<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Every row and the structure of the 16 tables of the v0.3.0 schema, taken before and after the upgrade (G6 WP3b), and the judgement
 * of whether the second is what the first should have become.
 *
 * Rows are read exactly as stored (`select *` through the same PDO path before and after, so each value keeps whatever type and text the
 * driver gives it) and ordered by primary key. Nothing is normalised: a timestamp that moved, a trailing space that was trimmed or a
 * ciphertext that was re-encoded is a difference.
 *
 * The judgement is by EXPECTATION, not by tolerance. `expected()` is the "before" with exactly the changes the upgrade is allowed to
 * make, each named by the caller: the role assignments listed in $renamed move from `guardian` to `guardian-full`, and the 14 migrations
 * the upgrade ran are appended to `migrations`. `differences()` then requires the "after" to equal that, row for row and column for column.
 *
 * @phpstan-type Rows array<string, list<array<string, mixed>>>
 * @phpstan-type Structure array<string, array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>}>
 */
final class HistoricalSnapshot
{
    /** The 16 tables of v0.3.0 (15 and `migrations`) and each one's primary key. */
    public const array TABLES = [
        'account_invitations' => 'id',
        'account_recovery_codes' => 'id',
        'account_totp_factors' => 'id',
        'accounts' => 'id',
        'cache' => 'key',
        'cache_locks' => 'key',
        'failed_jobs' => 'id',
        'job_batches' => 'id',
        'jobs' => 'id',
        'membership_grants' => 'id',
        'migrations' => 'id',
        'password_reset_tokens' => 'email',
        'people' => 'id',
        'role_assignments' => 'id',
        'security_events' => 'id',
        'sessions' => 'id',
    ];

    /** @return Rows table => every row, by primary key */
    public static function take(ConnectionInterface $db): array
    {
        $rows = [];
        foreach (self::TABLES as $table => $key) {
            /** @var list<array<string, mixed>> $stored */
            $stored = array_values($db->table($table)->orderBy($key)->get()->map(static fn (object $row): array => (array) $row)->all());
            $rows[$table] = $stored;
        }

        return $rows;
    }

    /**
     * The shape of each v0.3.0 table: its columns, indexes and foreign keys, as the engine reports them.
     *
     * @return Structure
     */
    public static function structure(string $connection): array
    {
        $schema = Schema::connection($connection);
        $shape = [];
        foreach (array_keys(self::TABLES) as $table) {
            $columns = $schema->getColumns($table);
            $indexes = $schema->getIndexes($table);
            $foreignKeys = $schema->getForeignKeys($table);
            foreach ([&$columns, &$indexes, &$foreignKeys] as &$list) {
                usort($list, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            }
            unset($list);
            $shape[$table] = ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
        }

        return $shape;
    }

    /**
     * What the "before" should have become: the listed role assignments renamed, the migrations the upgrade ran appended.
     *
     * @param  Rows  $before
     * @param  list<string>  $renamed  ids of the role assignments that held exactly `guardian`, written out by the caller
     * @param  list<string>  $migrations  the names (with `.php`) of the migrations the upgrade ran, in order
     * @return Rows
     */
    public static function expected(array $before, array $renamed, array $migrations, int $batch): array
    {
        $expected = $before;

        $seen = [];
        foreach ($expected['role_assignments'] as &$row) {
            if (in_array($row['id'], $renamed, true)) {
                if ($row['role_key'] !== 'guardian') {
                    throw new LogicException('Role assignment '.self::text($row['id']).' is listed as renamed but its key is not exactly guardian.');
                }
                $row['role_key'] = 'guardian-full';
                $seen[] = $row['id'];
            }
        }
        unset($row);
        if (count($seen) !== count($renamed)) {
            throw new LogicException('A role assignment listed as renamed is not in the fixture.');
        }

        $next = count($expected['migrations']);
        foreach ($migrations as $name) {
            $expected['migrations'][] = ['id' => ++$next, 'migration' => preg_replace('/\.php$/', '', $name), 'batch' => $batch];
        }

        return $expected;
    }

    /**
     * One sentence per way $actual differs from $expected; none when they are the same.
     *
     * @param  Rows  $expected
     * @param  Rows  $actual
     * @return list<string>
     */
    public static function differences(array $expected, array $actual): array
    {
        $differences = [];
        foreach (self::TABLES as $table => $key) {
            $want = self::keyed($expected[$table] ?? [], $key);
            $have = self::keyed($actual[$table] ?? [], $key);

            foreach (array_diff_key($want, $have) as $id => $row) {
                $differences[] = "{$table}: row {$id} was deleted";
            }
            foreach (array_diff_key($have, $want) as $id => $row) {
                $differences[] = "{$table}: row {$id} appeared";
            }
            foreach (array_intersect_key($want, $have) as $id => $row) {
                foreach ($row as $column => $value) {
                    if (! array_key_exists($column, $have[$id]) || $have[$id][$column] !== $value) {
                        $differences[] = "{$table}: row {$id} column {$column} is ".self::text($have[$id][$column] ?? null).', expected '.self::text($value);
                    }
                }
                foreach (array_diff_key($have[$id], $row) as $column => $value) {
                    $differences[] = "{$table}: row {$id} gained column {$column}";
                }
            }
        }

        return $differences;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(array $rows, string $key): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[self::text($row[$key])] = $row;
        }

        return $keyed;
    }

    /** A stored value as text, for reading a column the test knows to be text or a number. */
    public static function string(mixed $value): string
    {
        assert(is_string($value) || is_int($value), 'a stored text or number');

        return (string) $value;
    }

    /**
     * The foreign keys in a structure, as `table.column -> table.column`, sorted.
     *
     * @param  Structure  $structure
     * @return list<string>
     */
    public static function foreignKeys(array $structure): array
    {
        $labels = [];
        foreach ($structure as $table => $shape) {
            foreach ($shape['foreign_keys'] as $key) {
                $columns = $key['columns'] ?? null;
                $targets = $key['foreign_columns'] ?? null;
                assert(is_array($columns) && is_array($targets));
                $labels[] = $table.'.'.implode(',', array_map(self::string(...), $columns)).' -> '.self::string($key['foreign_table'] ?? null).'.'.implode(',', array_map(self::string(...), $targets));
            }
        }
        sort($labels, SORT_STRING);

        return $labels;
    }

    /**
     * The names of a table's indexes other than its primary key, which each engine names for itself, sorted.
     *
     * @param  Structure  $structure
     * @return list<string>
     */
    public static function indexNames(array $structure, string $table): array
    {
        $names = [];
        foreach ($structure[$table]['indexes'] as $index) {
            if (($index['primary'] ?? false) !== true) {
                $names[] = self::string($index['name'] ?? null);
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The tables a table's foreign keys point at.
     *
     * @return list<string>
     */
    public static function foreignTargets(string $connection, string $table): array
    {
        return array_map(
            static fn (array $key): string => $key['foreign_table'],
            Schema::connection($connection)->getForeignKeys($table),
        );
    }

    private static function text(mixed $value): string
    {
        return $value === null ? 'NULL' : "'".(is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR))."'";
    }
}
