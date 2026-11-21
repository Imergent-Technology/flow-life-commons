<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;

/**
 * Every row of the 34 tables of the CURRENT schema (the 16 of v0.3.0 and the 18 the 14 later migrations add), as digests (G6 WP3c S2.2C-3 WU4). It is what the
 * recovery rehearsal's paired backup measures twice, around its two artifacts, so that a database that changed while the backup was being taken is noticed.
 * HistoricalSnapshot is the 16-table version of the same idea and is left exactly as it was: the historical recovery path does not use this class.
 *
 * What it does and does not say.
 *  - A table's digest is the SHA-256 of its rows as the driver returns them, every value as text (NULL stays NULL), in the order of the PRIMARY KEY the engine
 *    reports for it (`Schema::getIndexes`, never a list written out here, so a key that changes is not silently ordered by something else). The text and the
 *    JSON are the ones rehearse.php's rowsDigest makes, so a table that both read has one digest whichever read it (a shell test holds the two equal).
 *  - A changed value, a lost or added row, or a changed type of text is a different digest. The order the rows were inserted in is not: a digest describes the
 *    data, not its history. A change and its exact reversal between two measurements is NOT noticed (the same state has the same digest).
 *  - The ROWS are never returned beyond this class's own use, never printed and never kept: what leaves is a count and a digest per table. A table whose rows
 *    cannot be read is an exception, never a missing or empty entry.
 *  - It does not judge a schema's structure (rehearse.php's structureLines does, with the same view of every present table), and it does not decide which
 *    tables are durable. TRANSIENT is the one classification it states, because the backup keeps the structure of those seven and none of their rows.
 *
 * Every safeguard is one line ending `// guard:S-<name>`: tests/Unit/Rehearsal/CurrentSnapshotTest.php copies this class, replaces each marked line with `;`
 * and requires the scenario written for that guard to stop ending in that guard's refusal. A marker with no scenario, and a scenario with no marker, fail.
 *
 * @phpstan-type Measured array{rows: int, digest: string}
 */
final class CurrentSnapshot
{
    /** The 34 tables of the current schema, sorted. Written out, not derived from the migrations: a migration that adds a table is a decision for this list. */
    public const array TABLES = [
        'account_invitations', 'account_recovery_codes', 'account_totp_factors', 'accounts', 'cache', 'cache_locks', 'contact_interactions', 'contact_methods',
        'contact_profiles', 'contact_tag_assignments', 'contact_tags', 'discussion_messages', 'discussions', 'failed_jobs', 'job_batches', 'jobs',
        'membership_grants', 'migrations', 'password_reset_tokens', 'people', 'person_relationship_field_values', 'person_relationship_role_provisions',
        'person_relationship_status_changes', 'person_relationships', 'resource_assets', 'resource_card_audiences', 'resource_cards', 'resource_categories',
        'resource_pack_audiences', 'resource_packs', 'role_assignments', 'security_events', 'sessions', 'sourced_role_grants',
    ];

    /** The seven tables a backup keeps the structure of and none of the rows (deployment runbook, "Taking a backup"). The other 27 are durable. */
    public const array TRANSIENT = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'password_reset_tokens', 'sessions'];

    /** @return list<string> the 27 durable tables, sorted */
    public static function durable(): array
    {
        return array_values(array_diff(self::TABLES, self::TRANSIENT));
    }

    /**
     * The tables the database holds, as information_schema lists them, must be exactly the 34, each a plain base table. A view, a missing table or an extra one is
     * a refusal that names it; nothing is judged from a partial list.
     *
     * @param  array<string, string>  $types  table name => information_schema's table_type
     */
    public static function requireInventory(array $types): void
    {
        $names = array_map(static fn (int|string $name): string => (string) $name, array_keys($types));
        $missing = array_values(array_diff(self::TABLES, $names));
        $extra = array_values(array_diff($names, self::TABLES));
        self::must($missing === [], 'tables are missing from the database: '.implode(', ', $missing)); // guard:S-missing
        self::must($extra === [], 'tables the current schema does not have are in the database: '.implode(', ', $extra)); // guard:S-unexpected
        foreach ($types as $name => $type) {
            self::must($type === 'BASE TABLE', "$name is a $type, not a base table"); // guard:S-base-table
        }
    }

    /**
     * The columns a table is read in order of: those of its primary key, as the engine reports it. No key, or two, is a refusal: without one the order of the rows,
     * and so the digest, would not be the data's.
     *
     * @param  list<array<string, mixed>>  $indexes  what `Schema::getIndexes` returns
     * @return non-empty-list<string>
     */
    public static function orderColumns(string $table, array $indexes): array
    {
        $primary = array_values(array_filter($indexes, static fn (array $index): bool => ($index['primary'] ?? false) === true));
        self::must(count($primary) === 1, "$table has no single primary key to put its rows in order by"); // guard:S-primary-key
        $columns = array_values(array_map(static fn (mixed $column): string => is_scalar($column) ? (string) $column : '', (array) ($primary[0]['columns'] ?? [])));
        self::must($columns !== [], "the primary key of $table names no column"); // guard:S-primary-columns
        /** @var non-empty-list<string> $named */
        $named = $columns;

        return $named;
    }

    /**
     * The digest of a table's rows, in the order given: each value as text (NULL stays NULL), the list as JSON. The rows are not returned or kept.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function digest(array $rows): string
    {
        $normal = array_map(static fn (array $row): array => array_map(static fn (mixed $value): ?string => $value === null ? null : (is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR)), $row), $rows);

        return hash('sha256', json_encode($normal, JSON_THROW_ON_ERROR));
    }

    /**
     * The row count and the digest of every one of the 34 tables, by name. The inventory is checked first, so a database that is not the current schema is refused
     * before a row is read.
     *
     * @return array<string, Measured>
     */
    public static function take(Connection $db): array
    {
        $types = [];
        /** @var list<object{name: string, type: string}> $found */
        $found = $db->select('select table_name as name, table_type as type from information_schema.tables where table_schema = database() order by table_name');
        foreach ($found as $row) {
            $types[$row->name] = $row->type;
        }
        self::requireInventory($types);

        $schema = $db->getSchemaBuilder();
        $measured = [];
        foreach (self::TABLES as $table) {
            $query = $db->table($table);
            foreach (self::orderColumns($table, $schema->getIndexes($table)) as $column) {
                $query->orderBy($column);
            }
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values($query->get()->map(static fn (object $row): array => (array) $row)->all());
            $measured[$table] = ['rows' => count($rows), 'digest' => self::digest($rows)];
        }

        return $measured;
    }

    /**
     * The migration ledger as the database holds it, in order of id: the names without `.php`. An empty ledger or a name that is not a plain migration name is a
     * refusal, because the ledger's identity is part of what the backup says it is of.
     *
     * @return non-empty-list<string>
     */
    public static function ledger(Connection $db): array
    {
        return self::requireLedger(array_values(array_map(static fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $db->table('migrations')->orderBy('id')->pluck('migration')->all())));
    }

    /**
     * @param  list<string>  $names  the ledger's migration names in order of id
     * @return non-empty-list<string>
     */
    public static function requireLedger(array $names): array
    {
        self::must($names !== [], 'the migration ledger is empty'); // guard:S-ledger-empty
        foreach ($names as $name) {
            self::must(preg_match('/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$/', $name) === 1, 'the migration ledger holds a name that is not a migration'); // guard:S-ledger-names
        }
        /** @var non-empty-list<string> $checked */
        $checked = $names;

        return $checked;
    }

    /**
     * What the backup records of a ledger: how many migrations, the last, and a digest of all of the names in order.
     *
     * @param  non-empty-list<string>  $names
     * @return array{count: int, last: string, digest: string}
     */
    public static function evidence(array $names): array
    {
        return ['count' => count($names), 'last' => $names[array_key_last($names)], 'digest' => hash('sha256', implode("\n", $names))];
    }

    /**
     * The protocol lines the rehearsal's `snapshot-all` prints for the tables: `all-table <table> <rows> <digest>`, one for each of the 34, by name. A kind of its
     * own, so that nothing reads them as the 16-table `table` lines of the historical snapshot.
     *
     * @param  array<string, Measured>  $measured
     */
    public static function lines(array $measured): string
    {
        $out = '';
        foreach (self::TABLES as $table) {
            self::must(isset($measured[$table]), "the snapshot has no entry for $table"); // guard:S-entry
            $out .= sprintf("all-table %s %d %s\n", $table, $measured[$table]['rows'], $measured[$table]['digest']);
        }

        return $out;
    }

    private static function must(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new CurrentSnapshotRefusal($message);
        }
    }
}
