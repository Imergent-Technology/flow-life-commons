<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;

/**
 * G6 WP3c S2.2C-1. The structure `contact_methods` has in the half-failed database the recovery rehearsal produces, written out, and the
 * comparison that holds a database to it.
 *
 * Why it is written out. The rehearsal compares the 15 tables the completed migrations made with the structure the FIRST (full) upgrade
 * recorded. For 14 of them that is exact. For `contact_methods` it cannot be: `2026_10_11_000001_add_provenance_to_contact_methods` adds
 * `updated_by_account_id` to it, that migration is the 29th, and the half-failed state stops at the 26th (Relationships), so the table is
 * exactly what `2026_10_01_000002_create_contact_methods_table` made. The full upgrade's digest of it differs from the half-failed one, by
 * that column and nothing else.
 *
 * What is compared. Columns (type, nullability, default, auto-increment), indexes and the foreign key, each by name. Not the collation, the
 * charset, the comment or the foreign key's schema: those follow the database the rehearsal runs in, not the migration, and the schema
 * digest `restore-historical` compares at restore time covers the whole table as the engine reports it. HalfFailedMigrationTest measures
 * this against a real MariaDB: the real half-failed table equals it, the real fully upgraded table equals it plus exactly the one column, and
 * an unrelated deviation is refused.
 *
 * The rehearsal helper (scripts/rehearsal/php/rehearse.php) requires this file from the prepared source by path and prints the result of
 * `check`; the Pest test calls the same function, so the logic that judges the live state is the logic that was measured.
 */
final class HalfFailedShape
{
    public const TABLE = 'contact_methods';

    /** The one column the later migration adds, which the half-failed table must not have. */
    public const LATER_COLUMN = 'updated_by_account_id';

    /**
     * What the creating migration leaves. Written out from `2026_10_01_000002_create_contact_methods_table`, never derived from a database.
     *
     * @return array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign_keys: array<string, array<string, mixed>>}
     */
    public static function expected(): array
    {
        $column = static fn (string $type, string $typeName, bool $nullable): array => [
            'type' => $type, 'type_name' => $typeName, 'nullable' => $nullable, 'default' => $nullable ? 'NULL' : null, 'auto_increment' => false,
        ];

        $shape = [
            'columns' => [
                'id' => $column('char(26)', 'char', false),
                'person_id' => $column('char(26)', 'char', false),
                'kind' => $column('varchar(16)', 'varchar', false),
                'value' => $column('varchar(255)', 'varchar', false),
                'search_value' => $column('varchar(255)', 'varchar', false),
                'label' => $column('varchar(64)', 'varchar', true),
                'primary_kind' => $column('varchar(16)', 'varchar', true),
                'created_at' => $column('datetime', 'datetime', false),
                'updated_at' => $column('datetime', 'datetime', false),
            ],
            'indexes' => [
                'primary' => ['columns' => ['id'], 'type' => 'btree', 'unique' => true, 'primary' => true],
                'contact_methods_person_kind_value_unique' => ['columns' => ['person_id', 'kind', 'search_value'], 'type' => 'btree', 'unique' => true, 'primary' => false],
                'contact_methods_one_primary_per_kind_unique' => ['columns' => ['person_id', 'primary_kind'], 'type' => 'btree', 'unique' => true, 'primary' => false],
                'contact_methods_kind_search_value_index' => ['columns' => ['kind', 'search_value'], 'type' => 'btree', 'unique' => false, 'primary' => false],
            ],
            'foreign_keys' => [
                'contact_methods_person_id_foreign' => [
                    'columns' => ['person_id'], 'foreign_table' => 'people', 'foreign_columns' => ['id'], 'on_update' => 'restrict', 'on_delete' => 'restrict',
                ],
            ],
        ];
        foreach ($shape as &$section) {
            ksort($section, SORT_STRING);
        }
        unset($section);

        return $shape;
    }

    /**
     * The table as the engine reports it, in the same portable terms as `expected`.
     *
     * @return array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign_keys: array<string, array<string, mixed>>}
     */
    public static function portable(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $shape = ['columns' => [], 'indexes' => [], 'foreign_keys' => []];
        foreach ($schema->getColumns(self::TABLE) as $column) {
            $shape['columns'][(string) $column['name']] = [
                'type' => $column['type'], 'type_name' => $column['type_name'], 'nullable' => $column['nullable'],
                'default' => $column['default'], 'auto_increment' => $column['auto_increment'],
            ];
        }
        foreach ($schema->getIndexes(self::TABLE) as $index) {
            $shape['indexes'][(string) $index['name']] = ['columns' => $index['columns'], 'type' => $index['type'], 'unique' => $index['unique'], 'primary' => $index['primary']];
        }
        foreach ($schema->getForeignKeys(self::TABLE) as $key) {
            $shape['foreign_keys'][(string) $key['name']] = [
                'columns' => $key['columns'], 'foreign_table' => $key['foreign_table'], 'foreign_columns' => $key['foreign_columns'],
                'on_update' => $key['on_update'], 'on_delete' => $key['on_delete'],
            ];
        }
        foreach ($shape as &$section) {
            ksort($section, SORT_STRING);
        }
        unset($section);

        return $shape;
    }

    /**
     * What differs from the structure the half-failed table must have: one entry for each column, index or foreign key that is missing,
     * unexpected or defined differently, by name. Empty when the table is exactly that structure. Only names are returned.
     *
     * @param  array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign_keys: array<string, array<string, mixed>>}  $actual
     * @return list<string>
     */
    public static function differences(array $actual): array
    {
        $differences = [];
        foreach (self::expected() as $section => $want) {
            $got = $actual[$section];
            ksort($want, SORT_STRING);
            ksort($got, SORT_STRING);
            $label = ['columns' => 'column', 'indexes' => 'index', 'foreign_keys' => 'foreign key'][$section];
            foreach (array_unique([...array_keys($want), ...array_keys($got)]) as $name) {
                if (! array_key_exists($name, $got)) {
                    $differences[] = "missing $label ".self::safe($name);
                } elseif (! array_key_exists($name, $want)) {
                    $differences[] = "unexpected $label ".self::safe($name);
                } elseif (json_encode($want[$name], JSON_THROW_ON_ERROR) !== json_encode($got[$name], JSON_THROW_ON_ERROR)) {
                    $differences[] = "different $label ".self::safe($name);
                }
            }
        }

        return $differences;
    }

    /** The judgement the rehearsal helper prints: `ok`, or the differences joined by `, `. */
    public static function check(Connection $db): string
    {
        $differences = self::differences(self::portable($db));

        return $differences === [] ? 'ok' : implode(', ', $differences);
    }

    /** A name as it may appear in protocol output: the engine's own names are expected to be plain, anything else is shown as `?`. */
    private static function safe(string $name): string
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) === 1 ? $name : '?';
    }
}
