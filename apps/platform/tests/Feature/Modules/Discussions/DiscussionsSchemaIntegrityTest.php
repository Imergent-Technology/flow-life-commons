<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Api;

/*
 * What the Discussions schema itself guarantees, whatever the application does: the invariants that must hold even if a use
 * case forgot to check (ADR 0035). Runs on MariaDB and PostgreSQL (`./flow test backend --pgsql`).
 */

/**
 * Runs a statement the database must refuse, inside its own (nested) transaction: on PostgreSQL a failed statement aborts the
 * surrounding one (the test's), and a savepoint rolls back cleanly on both engines.
 *
 * @param  Closure(): mixed  $statement
 * @param  class-string<Throwable>  $exception
 */
function schemaRefuses(Closure $statement, string $exception): void
{
    expect(fn (): mixed => DB::transaction($statement))->toThrow($exception);
}

function newUlid(): string
{
    return strtolower((string) Str::ulid());
}

/** @return array<string, mixed> */
function schemaDiscussion(string $id): array
{
    return [
        'id' => $id, 'title' => 'T', 'state' => 'open', 'message_count' => 1, 'last_activity_at' => '2026-10-01 12:00:00',
        'resolved_at' => null, 'resolved_by_person_id' => null, 'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00',
    ];
}

/** @return array<string, mixed> */
function schemaMessage(string $discussion, int $sequence, ?string $author = null): array
{
    return [
        'id' => newUlid(), 'discussion_id' => $discussion, 'sequence' => $sequence, 'author_person_id' => $author ?? newUlid(), 'body' => 'Words',
        'created_at' => '2026-10-01 12:00:00', 'edited_at' => null, 'edited_by_person_id' => null, 'removed_at' => null,
    ];
}

it('refuses two messages with the same sequence in one discussion, but numbers each discussion on its own', function () {
    $one = newUlid();
    $two = newUlid();
    DB::table('discussions')->insert([schemaDiscussion($one), schemaDiscussion($two)]);
    DB::table('discussion_messages')->insert(schemaMessage($one, 1));

    schemaRefuses(fn () => DB::table('discussion_messages')->insert(schemaMessage($one, 1)), UniqueConstraintViolationException::class);

    DB::table('discussion_messages')->insert(schemaMessage($two, 1)); // the same sequence in another discussion is fine
    expect(DB::table('discussion_messages')->count())->toBe(2);
});

it('refuses a message with no discussion, and refuses to delete a discussion that still holds messages', function () {
    $id = newUlid();

    schemaRefuses(fn () => DB::table('discussion_messages')->insert(schemaMessage(newUlid(), 1)), QueryException::class);

    DB::table('discussions')->insert(schemaDiscussion($id));
    DB::table('discussion_messages')->insert(schemaMessage($id, 1));
    schemaRefuses(fn () => DB::table('discussions')->where('id', $id)->delete(), QueryException::class);

    expect(DB::table('discussions')->count())->toBe(1)->and(DB::table('discussion_messages')->count())->toBe(1);
});

it('puts a foreign key only where an invariant lives: the message to its discussion, and no Person column anywhere', function () {
    $keys = function (string $table): array {
        $keys = [];
        foreach (Api::rows(Schema::getForeignKeys($table)) as $fk) {
            $keys[] = implode(',', Api::strings($fk['columns'])).'->'.Api::string($fk['foreign_table']).'('.implode(',', Api::strings($fk['foreign_columns'])).')';
        }

        return $keys;
    };

    expect($keys('discussion_messages'))->toBe(['discussion_id->discussions(id)'])
        ->and($keys('discussions'))->toBe([]);

    // And so provenance can name a Person Identity does not hold, and never blocks or is broken by one (ADR 0021).
    $id = newUlid();
    DB::table('discussions')->insert([...schemaDiscussion($id), 'state' => 'resolved', 'resolved_at' => '2026-10-01 12:00:00', 'resolved_by_person_id' => newUlid()]);
    DB::table('discussion_messages')->insert([...schemaMessage($id, 1), 'edited_at' => '2026-10-01 13:00:00', 'edited_by_person_id' => newUlid()]);
    expect(DB::table('discussion_messages')->count())->toBe(1);
});

it('declares exactly the indexes the two orderings and the sequence need', function () {
    $indexes = function (string $table): array {
        $indexes = [];
        foreach (Api::rows(Schema::getIndexes($table)) as $index) {
            if ($index['primary'] === true) {
                continue;
            }
            $indexes[Api::string($index['name'])] = ($index['unique'] === true ? 'unique ' : '').implode(',', Api::strings($index['columns']));
        }

        return $indexes;
    };

    expect($indexes('discussions'))->toBe([
        'discussions_activity_index' => 'last_activity_at,id',
        'discussions_state_activity_index' => 'state,last_activity_at,id',
    ])->and($indexes('discussion_messages'))->toBe([
        'discussion_messages_sequence_unique' => 'unique discussion_id,sequence',
    ]);
});

it('holds the state as a plain string, never a database enum, and the message text as nullable text', function () {
    $column = function (string $table, string $name): array {
        foreach (Api::rows(Schema::getColumns($table)) as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }
        throw new InvalidArgumentException("{$table}.{$name} does not exist");
    };

    // The portability scan bans enums in migrations; this pins what the real engine made of the column.
    expect($column('discussions', 'state')['type_name'])->toBeIn(['varchar', 'character varying'])
        ->and($column('discussions', 'state')['type_name'])->not->toContain('enum')
        ->and($column('discussion_messages', 'body')['nullable'])->toBeTrue()
        ->and($column('discussion_messages', 'body')['type_name'])->toBeIn(['text', 'longtext'])
        ->and($column('discussion_messages', 'removed_at')['nullable'])->toBeTrue()
        ->and($column('discussion_messages', 'edited_at')['nullable'])->toBeTrue()
        ->and($column('discussion_messages', 'author_person_id')['nullable'])->toBeFalse();
});

it('has no updated_at on a message and no creator column on a discussion: each fact is stored once', function () {
    expect(Schema::getColumnListing('discussion_messages'))->toEqualCanonicalizing(['id', 'discussion_id', 'sequence', 'author_person_id', 'body', 'created_at', 'edited_at', 'edited_by_person_id', 'removed_at'])
        ->and(Schema::getColumnListing('discussions'))->toEqualCanonicalizing(['id', 'title', 'state', 'message_count', 'last_activity_at', 'resolved_at', 'resolved_by_person_id', 'created_at', 'updated_at']);
});
