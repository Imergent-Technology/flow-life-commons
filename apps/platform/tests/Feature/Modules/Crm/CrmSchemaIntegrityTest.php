<?php

declare(strict_types=1);

use App\Shared\Domain\PersonId;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Api;
use Tests\Support\Identity;

/*
 * What the CRM schema itself guarantees, whatever the application does: the invariants that must hold even if a use case
 * forgot to check (ADR 0034). Runs on MariaDB and PostgreSQL (`./flow test backend --pgsql`): the same unique indexes,
 * RESTRICT keys and "any number of NULLs" behaviour have to hold on both.
 */

/**
 * Runs a statement the database must refuse. Inside its own (nested) transaction, because on PostgreSQL a failed statement
 * aborts the surrounding transaction (the test's), which would fail every statement after it; the nested one is a savepoint
 * that rolls back cleanly on both engines. The application's own use cases get the same protection from their transactions.
 *
 * @param  Closure(): mixed  $statement
 * @param  class-string<Throwable>  $exception
 */
function refuses(Closure $statement, string $exception): void
{
    expect(fn (): mixed => DB::transaction($statement))->toThrow($exception);
}

/** @return array<string, mixed> */
function methodRow(PersonId $person, string $kind, string $search, ?string $primaryKind = null): array
{
    return [
        'id' => strtolower((string) Str::ulid()), 'person_id' => $person->value, 'kind' => $kind,
        'value' => $search, 'search_value' => $search, 'label' => null, 'primary_kind' => $primaryKind,
        'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00',
    ];
}

/** @return array<string, mixed> */
function interactionRow(PersonId $person, ?PersonId $author = null): array
{
    return [
        'id' => strtolower((string) Str::ulid()), 'person_id' => $person->value, 'kind' => 'note', 'body' => 'A note',
        'occurred_at' => '2026-10-01 12:00:00', 'author_person_id' => ($author ?? $person)->value, 'updated_by_person_id' => null,
        'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00',
    ];
}

/** @return array<string, mixed> */
function tagRow(string $name, string $canonical): array
{
    return ['id' => strtolower((string) Str::ulid()), 'name' => $name, 'name_canonical' => $canonical, 'created_by_account_id' => null, 'created_at' => '2026-10-01 12:00:00'];
}

it('does not make a contact method unique across People: a shared address is legitimate', function () {
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');

    DB::table('contact_methods')->insert(methodRow($ada->id, 'email', 'family@example.org'));
    DB::table('contact_methods')->insert(methodRow($grace->id, 'email', 'family@example.org'));

    expect(DB::table('contact_methods')->where('search_value', 'family@example.org')->count())->toBe(2);
});

it('refuses the same kind and value twice for one Person, but allows the same value as another kind', function () {
    $ada = Identity::savedPerson('Ada');
    DB::table('contact_methods')->insert(methodRow($ada->id, 'email', '5550100'));
    DB::table('contact_methods')->insert(methodRow($ada->id, 'phone', '5550100')); // a different kind

    refuses(fn () => DB::table('contact_methods')->insert(methodRow($ada->id, 'email', '5550100')), UniqueConstraintViolationException::class);
});

it('allows at most one primary per kind per Person, and any number of non-primaries', function () {
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');

    DB::table('contact_methods')->insert(methodRow($ada->id, 'email', 'a1@example.org', 'email'));
    DB::table('contact_methods')->insert(methodRow($ada->id, 'email', 'a2@example.org'));
    DB::table('contact_methods')->insert(methodRow($ada->id, 'email', 'a3@example.org'));   // many NULLs: fine
    DB::table('contact_methods')->insert(methodRow($ada->id, 'phone', '5550100', 'phone')); // another kind's primary: fine
    DB::table('contact_methods')->insert(methodRow($grace->id, 'email', 'g1@example.org', 'email')); // another Person's: fine

    refuses(fn () => DB::table('contact_methods')->insert(methodRow($ada->id, 'email', 'a4@example.org', 'email')), UniqueConstraintViolationException::class);
    expect(DB::table('contact_methods')->where('person_id', $ada->id->value)->whereNotNull('primary_kind')->count())->toBe(2);
});

it('keeps tag names unique by canonical form, whatever the collation does with the display name', function () {
    DB::table('contact_tags')->insert(tagRow('Lead', 'lead'));

    refuses(fn () => DB::table('contact_tags')->insert(tagRow('LEAD', 'lead')), UniqueConstraintViolationException::class);
    DB::table('contact_tags')->insert(tagRow('Leads', 'leads'));
    expect(DB::table('contact_tags')->count())->toBe(2);
});

it('assigns a tag to a Person once', function () {
    $ada = Identity::savedPerson('Ada');
    $tag = tagRow('Lead', 'lead');
    DB::table('contact_tags')->insert($tag);
    $assignment = ['person_id' => $ada->id->value, 'tag_id' => $tag['id'], 'assigned_by_account_id' => null, 'assigned_at' => '2026-10-01 12:00:00'];
    DB::table('contact_tag_assignments')->insert($assignment);

    refuses(fn () => DB::table('contact_tag_assignments')->insert($assignment), UniqueConstraintViolationException::class);
});

it('refuses to delete a tag that is still assigned (RESTRICT), so business meaning is never cascaded away', function () {
    $ada = Identity::savedPerson('Ada');
    $tag = tagRow('Lead', 'lead');
    DB::table('contact_tags')->insert($tag);
    DB::table('contact_tag_assignments')->insert(['person_id' => $ada->id->value, 'tag_id' => $tag['id'], 'assigned_by_account_id' => null, 'assigned_at' => '2026-10-01 12:00:00']);

    refuses(fn () => DB::table('contact_tags')->where('id', $tag['id'])->delete(), QueryException::class);
    expect(DB::table('contact_tags')->where('id', $tag['id'])->exists())->toBeTrue();
});

it('never lets a Person be deleted out from under CRM data about them (RESTRICT, cross-module, ADR 0021)', function (string $table) {
    $ada = Identity::savedPerson('Ada');
    $now = '2026-10-01 12:00:00';
    match ($table) {
        default => throw new InvalidArgumentException($table),
        'contact_profiles' => DB::table($table)->insert(['person_id' => $ada->id->value, 'how_we_know' => null, 'affiliation' => null, 'updated_by_account_id' => null, 'created_at' => $now, 'updated_at' => $now]),
        'contact_methods' => DB::table($table)->insert(methodRow($ada->id, 'email', 'a@example.org')),
        'contact_interactions' => DB::table($table)->insert(interactionRow($ada->id)),
        'contact_tag_assignments' => (function () use ($ada, $now) {
            $tag = tagRow('Lead', 'lead');
            DB::table('contact_tags')->insert($tag);
            DB::table('contact_tag_assignments')->insert(['person_id' => $ada->id->value, 'tag_id' => $tag['id'], 'assigned_by_account_id' => null, 'assigned_at' => $now]);
        })(),
    };

    refuses(fn () => DB::table('people')->where('id', $ada->id->value)->delete(), QueryException::class);
    expect(DB::table('people')->where('id', $ada->id->value)->exists())->toBeTrue();
})->with(['contact_profiles', 'contact_methods', 'contact_tag_assignments', 'contact_interactions']);

it('refuses CRM data about a Person that does not exist', function () {
    refuses(fn () => DB::table('contact_methods')->insert(methodRow(PersonId::generate(), 'email', 'a@example.org')), QueryException::class);
});

it('refuses a note about a Person that does not exist, but keeps who wrote it as provenance with no foreign key (ADR 0021)', function () {
    $ada = Identity::savedPerson('Ada');

    refuses(fn () => DB::table('contact_interactions')->insert(interactionRow(PersonId::generate())), QueryException::class);

    // The author is provenance: no foreign key, so a stale or unknown author id is stored rather than refused.
    DB::table('contact_interactions')->insert(interactionRow($ada->id, PersonId::generate()));
    expect(DB::table('contact_interactions')->count())->toBe(1);
});

it('has the indexes the queries rely on, by name, on both engines', function () {
    $names = function (string $table): array {
        $names = [];
        foreach (Schema::getIndexes($table) as $index) {
            $names[] = Api::string(Api::map($index)['name']);
        }

        return $names;
    };

    expect($names('contact_methods'))->toContain('contact_methods_person_kind_value_unique', 'contact_methods_one_primary_per_kind_unique', 'contact_methods_kind_search_value_index')
        ->and($names('contact_interactions'))->toContain('contact_interactions_person_occurred_index')
        ->and($names('contact_tags'))->toContain('contact_tags_name_canonical_unique')
        ->and($names('contact_tag_assignments'))->toContain('contact_tag_assignments_tag_id_index');
});

it('stores no status, Account, role or Membership column in any CRM table', function () {
    /** @var list<string> $columns */
    $columns = [];
    foreach (['contact_profiles', 'contact_methods', 'contact_tags', 'contact_tag_assignments', 'contact_interactions'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            $columns[] = Api::string($column);
        }
    }

    // Provenance (`*_by_account_id`) is allowed and has no foreign key; anything else naming a status or an owner's concept is not.
    $offenders = [];
    foreach ($columns as $column) {
        if (! str_ends_with($column, '_by_account_id') && preg_match('/status|member|volunteer|guardian|role|capab|is_in_crm|email_canonical|account/', $column) === 1) {
            $offenders[] = $column;
        }
    }
    expect($offenders)->toBe([]);
    expect(Schema::getColumnListing('contact_profiles'))->toEqualCanonicalizing(['person_id', 'how_we_know', 'affiliation', 'updated_by_account_id', 'created_at', 'updated_at']);
});
