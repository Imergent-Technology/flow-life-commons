<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * The indexes the Identity, Access and Audit hot paths depend on.
 *
 * Phase 9 reviewed the whole schema now that the epic exists, with EXPLAIN on both engines, and added
 * NO new index: every query that runs on a request path already resolves through one, and the one that
 * does not (the operator directory's substring search) cannot use an index at all and is correct as a
 * scan at this scale. That finding is only worth anything if it stays true, so what is pinned here is
 * the set of indexes it depends on — a later migration that drops or renames one fails here rather than
 * in production — plus the one query whose plan is deliberately a scan, so the decision is visible.
 *
 * See docs/architecture/identity-and-access.md for the measured plans.
 */

/** @return list<string> the index names on a table, as the engine reports them */
function indexesOn(string $table): array
{
    $names = [];
    foreach (Schema::getIndexes($table) as $index) {
        $name = is_array($index) ? ($index['name'] ?? null) : null;
        $names[] = is_string($name) ? $name : '';
    }
    sort($names);

    return $names;
}

it('indexes every Identity lookup that happens on a request path', function () {
    expect(indexesOn('accounts'))
        ->toContain('accounts_email_canonical_unique')  // every sign-in, by canonical email only
        ->toContain('accounts_person_id_unique');       // the directory join, and one Account per Person

    expect(indexesOn('account_invitations'))
        ->toContain('account_invitations_token_hash_unique')  // accepting an invitation
        ->toContain('account_invitations_account_id_index');  // an Account's outstanding invitation

    expect(indexesOn('account_totp_factors'))
        ->toContain('account_totp_factors_account_id_unique'); // every second-factor check

    expect(indexesOn('account_recovery_codes'))
        ->toContain('account_recovery_codes_account_id_code_hash_unique'); // spending a code, and counting what is left
});

it('indexes both things the session table is asked for', function () {
    // `user_id`: ending an Account's sessions, which every security-invalidating operation does.
    // `last_activity`: the scheduled prune, which without it would scan the largest table in the schema.
    $indexes = indexesOn('sessions');

    expect($indexes)->toContain('sessions_user_id_index')
        ->and($indexes)->toContain('sessions_last_activity_index');
});

it('indexes role assignments for both directions they are read in', function () {
    // By person (what may this actor do, on every authorized request) and by role (who holds
    // administrator, which the last-administrator invariant asks before every removal).
    $indexes = indexesOn('role_assignments');

    expect($indexes)->toContain('role_assignments_person_id_role_key_unique')
        ->and($indexes)->toContain('role_assignments_role_key_index');
});

it('resolves every hot lookup through an index once the tables are not trivially small', function () {
    // Measured on a populated schema, deliberately. Both engines scan a one-row table and are RIGHT to:
    // asserting a plan on an empty one proves only that the planner can count, and it passes or fails
    // depending on what ran before. The question worth asking is what these queries do at a size where
    // the difference matters — a sign-in on every request, revoking an Account's sessions on every
    // disable and reset, and the prune after an outage.
    $now = Identity::now()->format('Y-m-d H:i:s');
    $people = $accounts = $sessions = [];
    for ($n = 0; $n < 2000; $n++) {
        $person = (string) Str::ulid();
        $account = (string) Str::ulid();
        $people[] = ['id' => $person, 'display_name' => "Person {$n}", 'created_at' => $now, 'updated_at' => $now];
        $accounts[] = [
            'id' => $account, 'person_id' => $person, 'email' => "person{$n}@example.org",
            'email_canonical' => "person{$n}@example.org", 'password_hash' => 'not-a-real-hash',
            'password_updated_at' => $now, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ];
        $sessions[] = [
            'id' => 'plan-'.$n, 'user_id' => $n < 1990 ? $account : null, 'ip_address' => '127.0.0.1',
            'user_agent' => 'plan', 'payload' => 'e30=', 'last_activity' => time() - ($n < 1990 ? 10 : 86400),
        ];
    }
    DB::table('people')->insert($people);
    DB::table('accounts')->insert($accounts);
    DB::table('sessions')->insert($sessions);
    foreach (['people', 'accounts', 'sessions'] as $table) {
        DB::statement('analyze '.(DB::getDriverName() === 'pgsql' ? $table : "table {$table}"));
    }

    // The single most frequent query in the system: every sign-in attempt, including every failed one.
    expect(planUsesIndex(DB::select('explain select * from accounts where email_canonical = ?', ['person7@example.org'])))
        ->toBeTrue('a sign-in scanned the accounts table');

    // The table with the most rows by far, and the one every disable, reset and password change deletes from.
    expect(planUsesIndex(DB::select('explain select id from sessions where user_id = ?', [$accounts[3]['id']])))
        ->toBeTrue('revoking an Account\'s sessions scanned the session table');

    // The scheduled prune, clearing a backlog: ten of two thousand rows are old enough to go.
    expect(planUsesIndex(DB::select('explain select id from sessions where last_activity < ?', [time() - 3600])))
        ->toBeTrue('the scheduled prune scanned the session table');
});

it('accepts that the operator directory search is a scan, and says why', function () {
    // The one hot-path query with no index, deliberately. It matches a substring anywhere in a
    // lower-cased email or display name, so a leading wildcard rules out an index on either, and the
    // ordering is on lower(display_name), which no plain index provides. It scans `people`.
    //
    // That is correct at this scale and only at this scale: an invite-only operator directory of tens of
    // rows. This test states the threshold rather than leaving it to be discovered — if the directory
    // ever grows into the thousands, the answer is a different search design (a normalised, indexed
    // search column, or full-text), not an index bolted onto this query.
    Access::admin('admin@example.org');
    foreach (['one', 'two', 'three'] as $name) {
        Identity::savedActiveAccount("{$name}@example.org");
    }

    expect(DB::table('accounts')->count())->toBeLessThan(1000, 'the directory is no longer small enough for a scan');

    $plan = DB::select(
        'explain select a.id from accounts a join people p on p.id = a.person_id '
        .'where lower(p.display_name) like ? order by lower(p.display_name), a.id limit 25',
        ['%one%'],
    );

    // The join into `accounts` still uses its unique index: only the filter and the ordering scan.
    expect($plan)->not->toBeEmpty();
});

/**
 * Whether a plan resolved through an index rather than reading every row.
 *
 * @param  array<array-key, mixed>  $plan
 */
function planUsesIndex(array $plan): bool
{
    foreach ($plan as $row) {
        $line = '';
        foreach ((array) $row as $value) {
            $line .= (is_scalar($value) ? (string) $value : '').' ';
        }

        // MariaDB names the index in `key` and reports `ALL` for a scan. PostgreSQL's EXPLAIN is one
        // text column, naming either "Index Scan"/"Index Only Scan" or "Seq Scan". An empty MariaDB plan
        // ("Impossible WHERE noticed after reading const tables") means the unique index answered it
        // without reading anything, which is the best case of all.
        if (str_contains($line, 'Seq Scan') || preg_match('/\bALL\b/', $line) === 1) {
            return false;
        }
    }

    return true;
}
