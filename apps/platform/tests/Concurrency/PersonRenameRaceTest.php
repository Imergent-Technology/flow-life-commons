<?php

declare(strict_types=1);

use App\Modules\Identity\Application\RenamePerson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\Support\Race;

/*
 * RenamePerson under REAL concurrency, across two PHP processes and two database connections (method:
 * Tests\Support\Race). The rename reads the Person WITH a row lock, so a competing rename waits for the first to
 * commit and then decides on what was committed.
 *
 * The properties are durable ones, not a winner: no rename or audit event is lost, none is invented, and the final
 * name is one that was really committed. Which of two simultaneous renames is last is not promised.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

it('serializes two renames to different names: both are applied and recorded, and the Person is unchanged', function () {
    $person = Identity::savedPerson('Ada Lovelase');

    // The first rename holds the Person's row lock and has not committed when the second starts.
    $race = Race::against(
        fn (Closure $pause) => app(RenamePerson::class)($person->id, 'Ada Lovelace'),
        'person.renamed', 'rename_person', ['person' => $person->id->value, 'name' => 'Ada King'],
    );

    $name = DB::table('people')->where('id', $person->id->value)->value('display_name');
    expect($race['blocked'])->toBeTrue('the second rename did not wait for the first to commit')
        ->and($race['exit'])->toBe(0)
        ->and($name)->toBeIn(['Ada Lovelace', 'Ada King'])
        ->and(DB::table('people')->count())->toBe(1)
        ->and(DB::table('people')->where('id', $person->id->value)->exists())->toBeTrue()
        ->and(Identity::events('person.renamed'))->toHaveCount(2) // neither audit event was lost
        ->and(DB::table('security_events')->where('type', 'person.renamed')->where('subject_person_id', $person->id->value)->count())->toBe(2);
});

it('decides the second of two identical renames on the committed name: one change, one event', function () {
    // Both ask for the same corrected name. Only the first changes anything; the second, having waited, reads the
    // committed name and finds nothing to do. Without the row lock it would read the OLD name, "change" it to the
    // one it already is, and record a rename that never happened.
    $person = Identity::savedPerson('Ada Lovelase');

    $race = Race::against(
        fn (Closure $pause) => app(RenamePerson::class)($person->id, 'Ada Lovelace'),
        'person.renamed', 'rename_person', ['person' => $person->id->value, 'name' => 'Ada Lovelace'],
    );

    expect($race['blocked'])->toBeTrue('the second rename did not wait for the first to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Ada Lovelace')
        ->and(Identity::events('person.renamed'))->toHaveCount(1);
});
