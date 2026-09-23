<?php

declare(strict_types=1);

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Identity;

/*
 * Schema and persistence for `membership_grants` (ADR 0028, ADR 0029, ADR 0021). "Refused" is
 * asserted on real rows: after a refused write or delete the data must still be there.
 */

function membershipGrant(
    ?PersonId $person = null,
    ?DateTimeImmutable $startsAt = null,
    ?DateTimeImmutable $endsAt = null,
    MembershipGrantSource $source = MembershipGrantSource::Operator,
    ?string $sourceReference = null,
    ?AccountId $grantedBy = null,
): MembershipGrant {
    $starts = $startsAt ?? Identity::now();

    return MembershipGrant::grant(
        MembershipGrantId::generate(), $person ?? Identity::savedPerson()->id, $starts, $endsAt,
        $source, $sourceReference, $grantedBy, $starts,
    );
}

// --- Round trips -------------------------------------------------------------------------------

it('round-trips a bounded grant', function () {
    $person = Identity::savedPerson();
    $starts = Identity::now();
    $ends = $starts->modify('+1 year');
    $grant = membershipGrant($person->id, $starts, $ends);

    app(MembershipGrantRepository::class)->add($grant);

    $found = app(MembershipGrantRepository::class)->find($grant->id);
    expect($found)->toEqual($grant)
        ->and($found?->startsAt)->toEqual($starts)
        ->and($found?->endsAt)->toEqual($ends)
        ->and($found?->isRevoked())->toBeFalse();
});

it('round-trips an open-ended grant', function () {
    $person = Identity::savedPerson();
    $grant = membershipGrant($person->id, endsAt: null);
    app(MembershipGrantRepository::class)->add($grant);

    expect(app(MembershipGrantRepository::class)->find($grant->id)?->endsAt)->toBeNull();
});

it('round-trips source and an opaque source reference', function () {
    $person = Identity::savedPerson();
    $grant = membershipGrant($person->id, source: MembershipGrantSource::LumaLegacy, sourceReference: 'luma-4821');
    app(MembershipGrantRepository::class)->add($grant);

    $found = app(MembershipGrantRepository::class)->find($grant->id);
    expect($found?->source)->toBe(MembershipGrantSource::LumaLegacy)
        ->and($found?->sourceReference)->toBe('luma-4821');
});

it('round-trips granted-by provenance, and allows none', function () {
    $person = Identity::savedPerson();
    $operator = Identity::savedActiveAccount('operator@example.org');

    $withProvenance = membershipGrant($person->id, grantedBy: $operator->id);
    app(MembershipGrantRepository::class)->add($withProvenance);
    expect(app(MembershipGrantRepository::class)->find($withProvenance->id)?->grantedByAccountId)->toEqual($operator->id);

    $withoutProvenance = membershipGrant($person->id);
    app(MembershipGrantRepository::class)->add($withoutProvenance);
    expect(app(MembershipGrantRepository::class)->find($withoutProvenance->id)?->grantedByAccountId)->toBeNull();
});

// --- No Account required -------------------------------------------------------------------

it('grants access to a Person with no Account, and creates none', function () {
    $person = Identity::savedPerson();
    $grant = membershipGrant($person->id);
    app(MembershipGrantRepository::class)->add($grant);

    expect(DB::table('accounts')->count())->toBe(0)
        ->and(app(MembershipGrantRepository::class)->find($grant->id))->not->toBeNull();
});

// --- No uniqueness on provenance (ADR 0029) ---------------------------------------------------

it('allows two grants with the same source and source_reference: no uniqueness constraint', function () {
    $person = Identity::savedPerson();
    $first = membershipGrant($person->id, source: MembershipGrantSource::LumaLegacy, sourceReference: 'luma-100');
    $second = membershipGrant($person->id, source: MembershipGrantSource::LumaLegacy, sourceReference: 'luma-100');

    app(MembershipGrantRepository::class)->add($first);
    app(MembershipGrantRepository::class)->add($second);

    expect(DB::table('membership_grants')->where('source_reference', 'luma-100')->count())->toBe(2);
});

// --- Referential integrity (ADR 0021) -----------------------------------------------------

it('refuses a grant for a Person that does not exist', function () {
    $grant = membershipGrant(PersonId::generate());

    $error = Identity::violation(fn () => app(MembershipGrantRepository::class)->add($grant));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('membership_grants')->count())->toBe(0);
});

it('restricts deleting a Person who holds a membership grant (RESTRICT, not CASCADE)', function () {
    $person = Identity::savedPerson();
    app(MembershipGrantRepository::class)->add(membershipGrant($person->id));

    $error = Identity::violation(fn () => DB::table('people')->where('id', $person->id->value)->delete());

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('people')->count())->toBe(1)
        ->and(DB::table('membership_grants')->count())->toBe(1);
});

it('keeps granted_by_account_id as provenance with no foreign key: the Account may vanish', function () {
    $person = Identity::savedPerson();
    $vanished = AccountId::generate(); // no such Account exists
    $grant = membershipGrant($person->id, grantedBy: $vanished);

    app(MembershipGrantRepository::class)->add($grant);

    expect(app(MembershipGrantRepository::class)->find($grant->id)?->grantedByAccountId)->toEqual($vanished);
});

it('introduces no foreign keys beyond person_id -> people', function () {
    $targets = [];
    foreach (Schema::getForeignKeys('membership_grants') as $foreignKey) {
        assert(is_array($foreignKey));
        assert(is_string($foreignKey['foreign_table']) && is_array($foreignKey['columns']));
        $columns = array_map(fn (mixed $c): string => is_string($c) ? $c : '', $foreignKey['columns']);
        $targets[] = $foreignKey['foreign_table'].'('.implode(',', $columns).')';
    }

    expect($targets)->toBe(['people(person_id)']);
});

// --- forPerson / all ordering ------------------------------------------------------------------

it("lists a Person's grants oldest first", function () {
    $person = Identity::savedPerson();
    $first = membershipGrant($person->id, startsAt: Identity::now());
    $second = membershipGrant($person->id, startsAt: Identity::now()->modify('+1 year'));
    app(MembershipGrantRepository::class)->add($second);
    app(MembershipGrantRepository::class)->add($first);

    $ordered = app(MembershipGrantRepository::class)->forPerson($person->id);
    expect($ordered)->toHaveCount(2)
        ->and($ordered[0]->id->equals($first->id))->toBeTrue()
        ->and($ordered[1]->id->equals($second->id))->toBeTrue();
});

it('all() lists every grant, including a Person whose only grant is revoked', function () {
    $active = Identity::savedPerson();
    $lapsed = Identity::savedPerson();
    $operator = Identity::savedActiveAccount('operator@example.org');

    app(MembershipGrantRepository::class)->add(membershipGrant($active->id));
    $revokedGrant = membershipGrant($lapsed->id);
    app(MembershipGrantRepository::class)->add($revokedGrant);
    app(MembershipGrantRepository::class)->revoke($revokedGrant->id, $operator->id, Identity::now()->modify('+1 minute'));

    $people = array_map(fn (MembershipGrant $g): string => $g->personId->value, app(MembershipGrantRepository::class)->all());
    expect($people)->toContain($active->id->value)->toContain($lapsed->id->value);
});

// --- No delete, no generic save (structural) ----------------------------------------------

it('has no delete path on the repository: a grant is never removed', function () {
    expect(method_exists(MembershipGrantRepository::class, 'delete'))->toBeFalse();
});

it('has no generic save on the repository: the only mutation is revoke()', function () {
    expect(method_exists(MembershipGrantRepository::class, 'save'))->toBeFalse()
        ->and(method_exists(MembershipGrantRepository::class, 'update'))->toBeFalse();
});

it('keeps every immutable field unchanged after revocation, changing only the two revocation columns', function () {
    $person = Identity::savedPerson();
    $operator = Identity::savedActiveAccount('operator@example.org');
    $grant = membershipGrant($person->id, source: MembershipGrantSource::LumaLegacy, sourceReference: 'luma-1');
    app(MembershipGrantRepository::class)->add($grant);

    $revokedAt = Identity::now()->modify('+1 day');
    app(MembershipGrantRepository::class)->revoke($grant->id, $operator->id, $revokedAt);

    $found = app(MembershipGrantRepository::class)->find($grant->id);
    expect($found?->personId)->toEqual($grant->personId)
        ->and($found?->startsAt)->toEqual($grant->startsAt)
        ->and($found?->endsAt)->toEqual($grant->endsAt)
        ->and($found?->source)->toBe($grant->source)
        ->and($found?->sourceReference)->toBe($grant->sourceReference)
        ->and($found?->grantedByAccountId)->toEqual($grant->grantedByAccountId)
        ->and($found?->createdAt)->toEqual($grant->createdAt)
        ->and($found?->revokedAt)->toEqual($revokedAt)
        ->and($found?->revokedByAccountId)->toEqual($operator->id);
});

// --- Shape ---------------------------------------------------------------------------------

it('has only the frozen columns: no status, soft delete, scope or history beyond revocation', function () {
    $columns = array_map(fn (mixed $c): string => is_array($c) && is_string($c['name']) ? $c['name'] : '', Schema::getColumns('membership_grants'));

    expect($columns)->toEqualCanonicalizing([
        'id', 'person_id', 'starts_at', 'ends_at', 'source', 'source_reference',
        'granted_by_account_id', 'revoked_at', 'revoked_by_account_id', 'created_at',
    ]);
});

it('has no updated_at column: the only mutation is revocation, which stamps its own columns', function () {
    expect(Schema::hasColumn('membership_grants', 'updated_at'))->toBeFalse();
});

it('stores the source as a VARCHAR, never a database ENUM', function () {
    $type = '';
    foreach (Schema::getColumns('membership_grants') as $column) {
        assert(is_array($column));
        if ($column['name'] === 'source') {
            assert(is_string($column['type']));
            $type = $column['type'];
        }
    }

    expect($type)->not->toContain('enum');
});

it('uses fixed-width 26-character ULID keys', function () {
    $person = Identity::savedPerson();
    app(MembershipGrantRepository::class)->add(membershipGrant($person->id));

    $row = DB::table('membership_grants')->first();
    assert($row !== null);
    $id = $row->id;
    $personId = $row->person_id;
    assert(is_string($id) && is_string($personId));

    expect($id)->toHaveLength(26)->toBe(strtolower($id))
        ->and($personId)->toHaveLength(26);
});
