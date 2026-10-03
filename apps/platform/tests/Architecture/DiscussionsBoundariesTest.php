<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Discussions\Domain\Discussion;
use App\Modules\Discussions\Domain\DiscussionMessage;
use Tests\Support\SourceScan;

/*
 * The Discussions module's boundaries (ADR 0035), as source structure. The runtime proofs (capability layers, ownership,
 * disclosure, races, the HTTP table) are the feature tests under tests/Feature/Modules/Discussions and
 * tests/Concurrency/DiscussionsRaceTest.php.
 *
 * Discussions owns discussion business state. It may use Identity's and Access's Application layers, Shared primitives and
 * its own persistence; it may not reach Identity's tables or Domain, Account storage, CRM, Membership, Audit or WordPress;
 * and nothing else may depend on it. The generic rules in ModuleBoundariesTest and the module graph in AccessBoundariesTest
 * already cover the module because modules are discovered from disk; these are the Discussions-specific, positive statements.
 *
 * One subject per arch expectation (README.md). Every source scan has a positive control.
 */

$discussions = 'App\\Modules\\Discussions';

// --- What Discussions may reach ---------------------------------------------------------------------------------------

arch('Discussions uses Identity only through its Application layer', function () use ($discussions) {
    expect($discussions)->not->toUse(['App\\Modules\\Identity\\Domain', 'App\\Modules\\Identity\\Infrastructure', 'App\\Modules\\Identity\\Http']);
});

arch('Discussions uses Access only through its Application layer', function () use ($discussions) {
    expect($discussions)->not->toUse(['App\\Modules\\Access\\Domain', 'App\\Modules\\Access\\Infrastructure', 'App\\Modules\\Access\\Http']);
});

arch('Discussions does not depend on CRM: it is standalone, with no Person-attached or cross-domain discussions', function () use ($discussions) {
    expect($discussions)->not->toUse('App\\Modules\\Crm');
});

arch('Discussions does not depend on Membership', function () use ($discussions) {
    expect($discussions)->not->toUse('App\\Modules\\Membership');
});

arch('Discussions has no Audit dependency: its history is business history, not security events', function () use ($discussions) {
    expect($discussions)->not->toUse('App\\Modules\\Audit');
});

arch('Discussions has no WordPress dependency', function () use ($discussions) {
    expect($discussions)->not->toUse(['WP_', 'wp_']);
});

arch('Discussions has no notification, mail, queue, broadcast or realtime dependency: it works without any of them', function () use ($discussions) {
    expect($discussions)->not->toUse([
        'Illuminate\\Support\\Facades\\Mail', 'Illuminate\\Mail', 'Illuminate\\Notifications', 'Illuminate\\Support\\Facades\\Notification',
        'Illuminate\\Broadcasting', 'Illuminate\\Support\\Facades\\Broadcast', 'Illuminate\\Contracts\\Broadcasting',
        'Illuminate\\Queue', 'Illuminate\\Bus', 'Illuminate\\Support\\Facades\\Queue', 'Illuminate\\Support\\Facades\\Bus', 'Illuminate\\Contracts\\Queue',
        'Illuminate\\Support\\Facades\\Event', 'Illuminate\\Events',
    ]);
});

foreach (['Domain', 'Application', 'Infrastructure'] as $layer) {
    arch("Discussions {$layer} does not own session, authentication or security concerns", function () use ($discussions, $layer) {
        // Only Http (RequestActor) reads who is signed in, exactly as in Crm, Membership and Access; every use case receives an Actor.
        expect("{$discussions}\\{$layer}")->not->toUse([
            'Illuminate\\Support\\Facades\\Auth', 'Illuminate\\Support\\Facades\\Session', 'Illuminate\\Support\\Facades\\Cookie',
            'Illuminate\\Support\\Facades\\Hash', 'Illuminate\\Contracts\\Auth', 'Illuminate\\Auth', 'Illuminate\\Session',
            'App\\Modules\\Identity\\Application\\AuthenticateAccount', 'App\\Modules\\Identity\\Application\\VerifySecurityAccess',
            'App\\Modules\\Identity\\Application\\SecurityProof', 'App\\Modules\\Identity\\Application\\InviteAccount',
        ]);
    });
}

arch('Discussions Http asks for no recent verification: it does not even know the step-up exists', function () use ($discussions) {
    expect("{$discussions}\\Http")->not->toUse(['App\\Modules\\Identity\\Http', 'App\\Modules\\Identity\\Application\\VerifySecurityAccess']);
});

// --- Who may reach Discussions ---------------------------------------------------------------------------------------------

arch('Identity does not depend on Discussions', function () {
    expect('App\\Modules\\Identity')->not->toUse('App\\Modules\\Discussions');
});

arch('Access does not depend on Discussions', function () {
    // Access defines the Discussions capabilities as plain enum cases; it names no Discussions type.
    expect('App\\Modules\\Access')->not->toUse('App\\Modules\\Discussions');
});

arch('Crm does not depend on Discussions: a Person record starts no discussion', function () {
    expect('App\\Modules\\Crm')->not->toUse('App\\Modules\\Discussions');
});

arch('Membership does not depend on Discussions', function () {
    expect('App\\Modules\\Membership')->not->toUse('App\\Modules\\Discussions');
});

arch('Audit does not depend on Discussions', function () {
    expect('App\\Modules\\Audit')->not->toUse('App\\Modules\\Discussions');
});

arch('Shared does not depend on Discussions', function () {
    expect('App\\Shared')->not->toUse('App\\Modules\\Discussions');
});

arch('nothing outside Discussions uses Discussions, but the development-only demo seeder, which uses its public use cases as an operator would', function () use ($discussions) {
    // The one exception is `Database\Seeders\DiscussionsDemoSeeder`: opt-in, refused outside local and testing, and referenced by
    // nothing in the application (DiscussionsDemoSeederTest pins each). It writes through Discussions' own use cases; its only
    // direct writes are two documented provenance updates to Discussions' own tables (also pinned there).
    expect($discussions)->toOnlyBeUsedIn([$discussions, 'Database\\Seeders\\DiscussionsDemoSeeder']);
});

// --- Layers ------------------------------------------------------------------------------------------------------------

arch('Discussions Domain is plain PHP: no framework, no other layer', function () use ($discussions) {
    expect("{$discussions}\\Domain")->not->toUse(['Illuminate', "{$discussions}\\Application", "{$discussions}\\Infrastructure", "{$discussions}\\Http"]);
});

arch('Discussions uses no Eloquent model: persistence is query-builder code in Infrastructure that can write only what it provides', function () use ($discussions) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($discussions);
});

arch('Discussions Application does not depend on its Infrastructure or Http', function () use ($discussions) {
    expect("{$discussions}\\Application")->not->toUse(["{$discussions}\\Infrastructure", "{$discussions}\\Http"]);
});

arch('Discussions Http does not reach into Infrastructure', function () use ($discussions) {
    expect("{$discussions}\\Http")->not->toUse("{$discussions}\\Infrastructure");
});

arch('Discussions authorizes only through AuthorizeAction, never by inspecting capabilities or roles itself', function () use ($discussions) {
    expect($discussions)->not->toUse([Authorizer::class, 'App\\Modules\\Access\\Application\\Role']);
});

// --- Tables: Discussions reads and writes its own, and nothing of anyone else's -----------------------------------------------

/**
 * Every table the platform's migrations create that is not Discussions' own: the persistence Discussions must never name.
 * Read from the migrations themselves, so a table added by a later module is protected without editing this test.
 *
 * @return list<string>
 */
function discussionsForeignTables(): array
{
    $tables = [];
    foreach (glob(SourceScan::root().'/database/migrations/*.php') ?: [] as $migration) {
        preg_match_all('/Schema::create\(\s*[\'"]([a-z_]+)[\'"]/', SourceScan::read($migration), $matches);
        foreach ($matches[1] as $table) {
            if (! str_starts_with($table, 'discussion')) {
                $tables[] = $table;
            }
        }
    }

    return array_values(array_unique($tables));
}

/**
 * The string literals in this source that name one of `$tables`, however they reach the database: a bare literal, an
 * `'accounts as a'` alias, or inside raw SQL (`from`, `join`, `into`, `update`, `table`). Read from the tokenizer, so a comment
 * that names a table to explain why Discussions never touches it trips nothing.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function discussionsForeignTableLiterals(string $source, array $tables): array
{
    $names = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tables));
    $found = [];
    foreach (SourceScan::stringLiterals($source) as $literal) {
        if (preg_match('/^\s*(?:'.$names.')(?:\s+as\s+\w+)?\s*$/i', $literal) === 1
            || preg_match('/\b(?:from|join|into|update|table|truncate)\s+["`]?(?:'.$names.')\b/i', $literal) === 1) {
            $found[] = $literal;
        }
    }

    return $found;
}

it('names no table that is not Discussions\' own: it never queries or writes people, Accounts, roles, CRM, Membership or the audit trail', function () {
    $tables = discussionsForeignTables();

    // The protected set is real and complete enough to mean something.
    expect($tables)->toContain('people', 'accounts', 'role_assignments', 'membership_grants', 'security_events', 'sessions', 'contact_interactions')
        ->and($tables)->not->toContain('discussions', 'discussion_messages');

    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Discussions/Domain', 'app/Modules/Discussions/Application', 'app/Modules/Discussions/Infrastructure']) as $path) {
        foreach (discussionsForeignTableLiterals(SourceScan::read($path), $tables) as $literal) {
            $offenders[] = SourceScan::relative($path).": {$literal}";
        }
    }

    expect($offenders)->toBe([]);

    // Positive controls: the scan finds the real table where it IS named, and every form a planted offender could take.
    expect(discussionsForeignTableLiterals(SourceScan::read(SourceScan::root().'/app/Modules/Identity/Infrastructure/Persistence/DatabasePeopleDirectory.php'), $tables))->toContain('people')
        ->and(discussionsForeignTableLiterals("<?php \$db->table('people')->update(['display_name' => 'x']);", $tables))->toBe(['people'])
        ->and(discussionsForeignTableLiterals("<?php private const string TABLE = 'accounts'; \$db->table(self::TABLE)->get();", $tables))->toBe(['accounts'])
        ->and(discussionsForeignTableLiterals("<?php \$db->table('contact_interactions as c')->get();", $tables))->toBe(['contact_interactions as c'])
        ->and(discussionsForeignTableLiterals("<?php \$db->select('select email from accounts where id = ?', [\$id]);", $tables))->toHaveCount(1)
        ->and(discussionsForeignTableLiterals("<?php \$q->whereRaw('person_id in (select person_id from role_assignments)');", $tables))->toHaveCount(1)
        ->and(discussionsForeignTableLiterals('<?php $db->statement("insert into security_events values (1)");', $tables))->toHaveCount(1)
        // ...and does not trip on what is not a table reference: Discussions' own tables, an alias, a comment, a word in prose.
        ->and(discussionsForeignTableLiterals("<?php \$db->table('discussion_messages')->get(); \$db->selectRaw('count(*) as people'); echo 'no such people'; // table('people')", $tables))->toBe([]);
});

it('names only the two tables Discussions owns, wherever it reaches the database', function () {
    // A table is reached through a `TABLE` constant or a `->table('...')` or `DB::table('...')` call; a column name such as `discussion_id` is not a table.
    $tablesIn = function (string $source): array {
        $code = SourceScan::code($source);
        preg_match_all('/const\s+string\s+\w+\s*=\s*[\'"]([a-z_]+)[\'"]/', $code, $constants);
        preg_match_all('/(?:->|::)table\(\s*[\'"]([a-z_]+)[\'"]/', $code, $calls);

        return [...$constants[1], ...$calls[1]];
    };

    $tables = [];
    foreach (SourceScan::phpFiles(['app/Modules/Discussions/Infrastructure']) as $path) {
        array_push($tables, ...$tablesIn(SourceScan::read($path)));
    }

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['discussions', 'discussion_messages']);

    // Positive controls: both ways of naming a table are seen, and a column name is not one.
    expect($tablesIn("<?php private const string TABLE = 'widgets';"))->toBe(['widgets'])
        ->and($tablesIn("<?php \$db->table('gadgets')->get();"))->toBe(['gadgets'])
        ->and($tablesIn("<?php DB::table('facade')->get();"))->toBe(['facade'])
        ->and($tablesIn("<?php \$db->table(self::TABLE)->where('discussion_id', 1)->get(); // table('ghost')"))->toBe([]);
});

it('creates no table for a generic comment, activity, reaction, mention, notification or attachment framework', function () {
    $generic = array_values(array_filter(
        discussionsForeignTables(),
        fn (string $table): bool => preg_match('/comment|activit|reaction|mention|notification|attachment|thread|subscription|follow|watch/', $table) === 1,
    ));
    $owned = [];
    foreach (glob(SourceScan::root().'/database/migrations/*.php') ?: [] as $migration) {
        preg_match_all('/Schema::create\(\s*[\'"](discussion[a-z_]*)[\'"]/', SourceScan::read($migration), $matches);
        array_push($owned, ...$matches[1]);
    }

    expect($generic)->toBe([])->and($owned)->toEqualCanonicalizing(['discussions', 'discussion_messages']);
});

it('puts no discussion, comment or activity type in Shared', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Shared']) as $path) {
        if (preg_match('/discussion|comment|activity|thread|message/i', basename($path)) === 1 || preg_match('/\bDiscussion|DiscussionMessage|Comment\b/', SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([]);
});

// --- Phase 1 stores exactly what ADR 0035 says, and nothing a later feature would need -------------------------------------------

/**
 * @param  class-string  $class
 * @return list<string>
 */
function discussionsPropertiesOf(string $class): array
{
    return array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
}

it('gives a message exactly its authorship, text and edit and removal provenance: no parent, no official flag, no editor set, no history', function () {
    expect(discussionsPropertiesOf(DiscussionMessage::class))->toEqualCanonicalizing([
        'id', 'discussionId', 'sequence', 'authorPersonId', 'body', 'createdAt', 'editedAt', 'editedByPersonId', 'removedAt',
    ]);
});

it('gives a discussion exactly its title, state, counters and resolution: no creator copy, no audience, no priority, no assignee', function () {
    expect(discussionsPropertiesOf(Discussion::class))->toEqualCanonicalizing([
        'id', 'title', 'state', 'messageCount', 'lastActivityAt', 'resolvedAt', 'resolvedByPersonId', 'createdAt', 'updatedAt',
    ]);
});

// --- Which capability each use case asks for ---------------------------------------------------------------------------------------

/**
 * The capability cases a use case's code names, read off the source with comments removed.
 *
 * @return list<string>
 */
function discussionsCapabilitiesNamedBy(string $source): array
{
    preg_match_all('/Capability::(\w+)/', SourceScan::code($source), $matches);

    return array_values(array_unique($matches[1]));
}

it('has every read ask for discussions.view alone and every change ask for discussions.participate alone', function () {
    // The role catalog gives a Guardian BOTH capabilities, so a behavioural test cannot tell a use case that asks for the
    // wrong one from one that asks for the right one. The structure can: this is the whole of each use case's authorization.
    $expected = [
        'PageDiscussions' => 'ViewDiscussions', 'GetDiscussion' => 'ViewDiscussions', 'PageDiscussionMessages' => 'ViewDiscussions',
        'StartDiscussion' => 'ParticipateInDiscussions', 'ReplyToDiscussion' => 'ParticipateInDiscussions', 'EditOwnMessage' => 'ParticipateInDiscussions',
        'RemoveOwnMessage' => 'ParticipateInDiscussions', 'RetitleOwnDiscussion' => 'ParticipateInDiscussions',
        'ResolveDiscussion' => 'ParticipateInDiscussions', 'ReopenDiscussion' => 'ParticipateInDiscussions',
    ];

    foreach ($expected as $useCase => $capability) {
        $actual = discussionsCapabilitiesNamedBy(SourceScan::read(SourceScan::root()."/app/Modules/Discussions/Application/{$useCase}.php"));
        expect($actual)->toBe([$capability], "{$useCase} must ask for {$capability} and nothing else");
    }

    // Every Application class in Discussions that is a use case (it takes an Actor) is listed above: a new one cannot skip the check.
    foreach (SourceScan::phpFiles(['app/Modules/Discussions/Application']) as $path) {
        $name = basename($path, '.php');
        if (str_contains(SourceScan::code(SourceScan::read($path)), 'Actor $actor')) {
            expect(array_key_exists($name, $expected))->toBeTrue("{$name} takes an Actor but is not in the capability table");
        }
    }

    // Positive controls: the scan reads each shape of mistake.
    expect(discussionsCapabilitiesNamedBy('<?php ($this->authorize)($actor, Capability::ViewDiscussions);'))->toBe(['ViewDiscussions'])
        ->and(discussionsCapabilitiesNamedBy("<?php // Capability::ParticipateInDiscussions\n(\$this->authorize)(\$actor, Capability::ViewDiscussions);"))->toBe(['ViewDiscussions'])
        ->and(discussionsCapabilitiesNamedBy('<?php Capability::ViewDiscussions; Capability::ParticipateInDiscussions;'))->toBe(['ViewDiscussions', 'ParticipateInDiscussions'])
        ->and(discussionsCapabilitiesNamedBy('<?php // no authorization at all'))->toBe([]);
});

it('checks ownership in the use case for exactly the three operations that change someone\'s own words, and nowhere else', function () {
    $owned = [];
    foreach (SourceScan::phpFiles(['app/Modules/Discussions/Application']) as $path) {
        $code = SourceScan::code(SourceScan::read($path));
        if (str_contains($code, 'NotAuthor') && str_contains($code, 'throw new NotAuthor')) {
            $owned[] = basename($path, '.php');
        }
    }
    sort($owned);

    expect($owned)->toBe(['EditOwnMessage', 'RemoveOwnMessage', 'RetitleOwnDiscussion']);
});
