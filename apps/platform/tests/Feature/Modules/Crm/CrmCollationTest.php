<?php

declare(strict_types=1);

use App\Modules\Crm\Application\DuplicateContactMethod;
use App\Modules\Crm\Application\DuplicateTag;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\PossibleDuplicate;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Domain\ContactMethodKind;
use Illuminate\Support\Facades\DB;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * Where CRM's equality depends on the database, pinned on purpose (ADR 0034, "Normalisation"). CRM's own normalisation
 * (lower-casing, whitespace, phone digits) is portable for ASCII and case. For NON-ASCII text, whether two values are
 * "the same" is the column collation's business: MariaDB's `utf8mb4_unicode_ci` folds accents and ligatures (`café` =
 * `cafe`, `straße` = `strasse`), PostgreSQL's default collation does not. Account email canonicalisation already behaves
 * this way. CRM promises no accent folding and these tests change nothing about it: they exist so the difference is known
 * rather than accidental, and a future decision to fold accents (or to make a column binary) has to move them.
 *
 * Runs on MariaDB and PostgreSQL (`./flow test backend --pgsql`): each expectation is the engine's own answer.
 */

/** Whether this engine's column collation treats accented and plain spellings as equal. */
function collationFoldsAccents(): bool
{
    return DB::connection()->getDriverName() !== 'pgsql';
}

it('keeps ASCII and case identical on both engines: "Lead" and "LEAD" are one tag, one email in two cases is one method', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::tag($by, 'Lead');
    Crm::email($by, $ada->id, 'ada@example.org');

    expect(fn () => Crm::tag($by, 'LEAD'))->toThrow(DuplicateTag::class)
        ->and(fn () => Crm::email($by, $ada->id, 'ADA@Example.ORG'))->toThrow(DuplicateContactMethod::class);
});

it('treats accented and plain tag names as one tag on MariaDB and as two on PostgreSQL', function (string $first, string $second) {
    $by = Crm::manager();
    Crm::tag($by, $first);

    if (collationFoldsAccents()) {
        expect(fn () => Crm::tag($by, $second))->toThrow(DuplicateTag::class);
        expect(DB::table('contact_tags')->count())->toBe(1);
    } else {
        Crm::tag($by, $second);
        expect(DB::table('contact_tags')->count())->toBe(2);
    }
})->with([
    'accent' => ['Café', 'Cafe'],
    'ligature' => ['Straße', 'Strasse'],
]);

it('treats accented and plain emails of one Person as one method on MariaDB and two on PostgreSQL', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::email($by, $ada->id, 'josé@example.org');

    if (collationFoldsAccents()) {
        expect(fn () => Crm::email($by, $ada->id, 'jose@example.org'))->toThrow(DuplicateContactMethod::class);
        expect(DB::table('contact_methods')->where('person_id', $ada->id->value)->count())->toBe(1);
    } else {
        Crm::email($by, $ada->id, 'jose@example.org');
        expect(DB::table('contact_methods')->where('person_id', $ada->id->value)->count())->toBe(2);
    }
});

it('gives duplicate advice across accents on MariaDB only: the advice follows the same collation', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::email($by, $ada->id, 'josé@example.org');

    $register = fn () => app(RegisterContact::class)($by, 'Someone Else', null, null, [new NewContactMethod(ContactMethodKind::Email, 'jose@example.org')], false);

    if (collationFoldsAccents()) {
        expect($register)->toThrow(PossibleDuplicate::class);
    } else {
        expect($register()->person->displayName)->toBe('Someone Else'); // advice only: no match, so nothing to confirm
    }
});
