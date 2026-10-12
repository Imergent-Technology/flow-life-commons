<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Crm\Domain\ContactTagRepository;
use Tests\Support\SourceScan;

/*
 * The Crm module's boundaries (ADR 0034), as source structure. The runtime proofs (capability layers, disclosure, races,
 * the HTTP table) are the feature tests under tests/Feature/Modules/Crm and tests/Concurrency/CrmRaceTest.php.
 *
 * CRM enriches an Identity Person. It may use Identity's and Access's Application layers, Shared primitives and its own
 * persistence; it may not reach Identity's tables or Domain, Account storage, Membership, Audit or WordPress; and nothing
 * else may depend on it. The generic rules in ModuleBoundariesTest and the module graph in AccessBoundariesTest already
 * cover the module because modules are discovered from disk; these are the CRM-specific, positive statements.
 *
 * One subject per arch expectation (README.md). Every source scan has a positive control.
 */

$crm = 'App\\Modules\\Crm';

// --- What Crm may reach ------------------------------------------------------------------------------------------

arch('Crm uses Identity only through its Application layer', function () use ($crm) {
    expect($crm)->not->toUse(['App\\Modules\\Identity\\Domain', 'App\\Modules\\Identity\\Infrastructure', 'App\\Modules\\Identity\\Http']);
});

arch('Crm uses Access only through its Application layer', function () use ($crm) {
    expect($crm)->not->toUse(['App\\Modules\\Access\\Domain', 'App\\Modules\\Access\\Infrastructure', 'App\\Modules\\Access\\Http']);
});

arch('Crm does not depend on Membership', function () use ($crm) {
    expect($crm)->not->toUse('App\\Modules\\Membership');
});

arch('Crm has no Audit dependency of its own: its history is business history, not security events', function () use ($crm) {
    // Identity records person.renamed when CRM calls RenamePerson; calling a module does not make Crm depend on what it calls.
    expect($crm)->not->toUse('App\\Modules\\Audit');
});

arch('Crm has no WordPress dependency', function () use ($crm) {
    expect($crm)->not->toUse(['WP_', 'wp_']);
});

foreach (['Domain', 'Application', 'Infrastructure'] as $layer) {
    arch("Crm {$layer} does not own session, authentication or security concerns", function () use ($crm, $layer) {
        // Only Http (RequestActor) reads who is signed in, exactly as in Membership and Access; every use case receives an Actor.
        expect("{$crm}\\{$layer}")->not->toUse([
            'Illuminate\\Support\\Facades\\Auth', 'Illuminate\\Support\\Facades\\Session', 'Illuminate\\Support\\Facades\\Cookie',
            'Illuminate\\Support\\Facades\\Hash', 'Illuminate\\Contracts\\Auth', 'Illuminate\\Auth', 'Illuminate\\Session',
            'App\\Modules\\Identity\\Application\\AuthenticateAccount', 'App\\Modules\\Identity\\Application\\VerifySecurityAccess',
            'App\\Modules\\Identity\\Application\\SecurityProof', 'App\\Modules\\Identity\\Application\\InviteAccount',
        ]);
    });
}

arch('Crm Http asks for no recent verification: it does not even know the step-up exists', function () use ($crm) {
    expect("{$crm}\\Http")->not->toUse(['App\\Modules\\Identity\\Http', 'App\\Modules\\Identity\\Application\\VerifySecurityAccess']);
});

// --- Who may reach Crm ---------------------------------------------------------------------------------------------

arch('Identity does not depend on Crm', function () {
    expect('App\\Modules\\Identity')->not->toUse('App\\Modules\\Crm');
});

arch('Access does not depend on Crm', function () {
    // Access defines the CRM capabilities as plain enum cases; it names no Crm type.
    expect('App\\Modules\\Access')->not->toUse('App\\Modules\\Crm');
});

arch('Membership does not depend on Crm, though the People screens will later compose both', function () {
    expect('App\\Modules\\Membership')->not->toUse('App\\Modules\\Crm');
});

arch('Audit does not depend on Crm', function () {
    expect('App\\Modules\\Audit')->not->toUse('App\\Modules\\Crm');
});

arch('nothing outside Crm uses Crm, but the development-only demo seeder and Relationships\' use of the delegated seam', function () use ($crm) {
    // `Database\Seeders\CrmDemoSeeder`: opt-in, refused outside local and testing, and referenced by nothing in the application
    // (CrmDemoSeederTest pins each). It writes through Crm's own use cases (its one direct write is repairing provenance that names
    // a Person who no longer exists). Relationships' Application layer is the other: it may use `Crm\Application\Delegated`, and ONLY
    // that, from the named classes below (ADR 0038, P1). The scans after this one pin both of those restrictions.
    expect($crm)->toOnlyBeUsedIn([$crm, 'Database\\Seeders\\CrmDemoSeeder', 'App\\Modules\\Relationships\\Application']);
});

arch('Crm does not depend on Relationships: it does not know which relationship, or which capability, asked', function () use ($crm) {
    expect($crm)->not->toUse('App\\Modules\\Relationships');
});

/**
 * The delegated services (ADR 0038, P1) and the ONLY classes allowed to name each one. A class that names one is a caller:
 * its authorization and scope check are what make the call safe, so a new caller is a deliberate change to this table.
 *
 * @return array<string, list<string>> delegated class => the Relationships Application classes that may use it
 */
function delegatedCallers(): array
{
    return [
        'ReadPersonBasics' => ['RelationshipPresentation'],
        'PeopleMatchingContact' => ['ListRelationshipDirectory'],
        'FindPersonCandidates' => ['ListRelationshipCandidates'],
        'RegisterMinimalPerson' => ['EstablishRelationship'],
        'UpdatePersonBasics' => ['UpdateRelationshipPersonBasics'],
    ];
}

it('lets only Relationships\' named Application classes call each delegated service', function () {
    $callers = [];
    foreach (SourceScan::phpFiles(['app', 'database']) as $path) {
        $relative = SourceScan::relative($path);
        if (str_starts_with($relative, 'app/Modules/Crm/Application/Delegated/')) {
            continue;
        }
        $code = SourceScan::code(SourceScan::read($path));
        foreach (array_keys(delegatedCallers()) as $service) {
            if (preg_match('/Crm\\\\Application\\\\Delegated\\\\'.$service.'\\b/', $code) === 1) {
                $callers[$service][] = $relative;
            }
        }
    }

    foreach (delegatedCallers() as $service => $allowed) {
        $expected = array_map(fn (string $class): string => "app/Modules/Relationships/Application/{$class}.php", $allowed);
        expect($callers[$service] ?? [])->toBe($expected, "{$service} may be called only from ".implode(', ', $allowed));
    }
});

it('lets Relationships name nothing of Crm but its delegated Application services', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Relationships']) as $path) {
        preg_match_all('/App\\\\Modules\\\\Crm\\\\([A-Za-z\\\\]+)/', SourceScan::code(SourceScan::read($path)), $matches);
        foreach ($matches[1] as $name) {
            if (! str_starts_with($name, 'Application\\Delegated\\')) {
                $offenders[] = SourceScan::relative($path).' names Crm\\'.$name;
            }
        }
    }

    // Positive control: the scan reads a Crm Domain name and a non-delegated Application name as offenders.
    preg_match_all('/App\\\\Modules\\\\Crm\\\\([A-Za-z\\\\]+)/', SourceScan::code('<?php use App\\Modules\\Crm\\Domain\\ContactMethod; use App\\Modules\\Crm\\Application\\AddContactMethod;'), $control);

    expect($offenders)->toBe([])
        ->and($control[1])->toBe(['Domain\\ContactMethod', 'Application\\AddContactMethod']);
});

it('keeps the delegated services free of authorization, capabilities and any relationship vocabulary', function () {
    // They authorize nothing and know nothing of relationships: their caller decided both (ADR 0038, P1).
    $forbidden = '/Capability|AuthorizeAction|Authorizer|App\\\\Modules\\\\Access|App\\\\Modules\\\\Relationships|guardian|volunteer|relationship/i';
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Crm/Application/Delegated']) as $path) {
        if (preg_match($forbidden, SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([])
        ->and(preg_match($forbidden, 'Capability::ManagePeople'))->toBe(1)
        ->and(preg_match($forbidden, 'the Volunteer type'))->toBe(1);
});

it('puts no HTTP class in reach of the delegated services', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app']) as $path) {
        $relative = SourceScan::relative($path);
        if (preg_match('~app/Modules/\w+/Http/~', $relative) !== 1) {
            continue;
        }
        if (str_contains(SourceScan::code(SourceScan::read($path)), 'Crm\\Application\\Delegated')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('lets PersonRegistration be used only by RegisterContact and the delegated RegisterMinimalPerson', function () {
    $users = [];
    foreach (SourceScan::phpFiles(['app', 'database']) as $path) {
        $relative = SourceScan::relative($path);
        if ($relative === 'app/Modules/Crm/Application/PersonRegistration.php') {
            continue;
        }
        if (preg_match('/\bPersonRegistration\b/', SourceScan::code(SourceScan::read($path))) === 1) {
            $users[] = $relative;
        }
    }
    sort($users);

    expect($users)->toBe([
        'app/Modules/Crm/Application/Delegated/RegisterMinimalPerson.php',
        'app/Modules/Crm/Application/RegisterContact.php',
    ]);
});

// --- Layers -------------------------------------------------------------------------------------------------------

arch('Crm Domain is plain PHP: no framework, no other layer', function () use ($crm) {
    expect("{$crm}\\Domain")->not->toUse(['Illuminate', "{$crm}\\Application", "{$crm}\\Infrastructure", "{$crm}\\Http"]);
});

arch('Crm uses no Eloquent model: persistence is query-builder code in Infrastructure that can write only what it provides', function () use ($crm) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($crm);
});

arch('Crm Application does not depend on its Infrastructure or Http', function () use ($crm) {
    expect("{$crm}\\Application")->not->toUse(["{$crm}\\Infrastructure", "{$crm}\\Http"]);
});

arch('Crm Http does not reach into Infrastructure', function () use ($crm) {
    expect("{$crm}\\Http")->not->toUse("{$crm}\\Infrastructure");
});

arch('Crm authorizes only through AuthorizeAction, never by inspecting capabilities or roles itself', function () use ($crm) {
    expect($crm)->not->toUse([Authorizer::class, 'App\\Modules\\Access\\Application\\Role']);
});

// --- Tables: CRM reads and writes its own, and nothing of Identity's ---------------------------------------------------

/**
 * Every table the platform's migrations create that is not CRM's own: the persistence CRM must never name. Read from
 * the migrations themselves, so a table added by a later module is protected without editing this test.
 *
 * @return list<string>
 */
function foreignTables(): array
{
    $tables = [];
    foreach (glob(SourceScan::root().'/database/migrations/*.php') ?: [] as $migration) {
        preg_match_all('/Schema::create\(\s*[\'"]([a-z_]+)[\'"]/', SourceScan::read($migration), $matches);
        foreach ($matches[1] as $table) {
            if (! str_starts_with($table, 'contact_')) {
                $tables[] = $table;
            }
        }
    }

    return array_values(array_unique($tables));
}

/**
 * The string literals in this source that name one of `$tables`, however they reach the database: as a bare literal
 * (`->table('people')`, `const TABLE = 'people'`, an `'accounts as a'` alias, an array of names) or inside raw SQL
 * (`from accounts`, `join role_assignments`, a subquery in a `whereRaw`). Read from the tokenizer, so a comment that
 * names a table to explain why CRM never touches it trips nothing. Not a SQL parser: it recognises the table positions
 * (`from`, `join`, `into`, `update`, `table`) and a literal that IS a table name.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function foreignTableLiterals(string $source, array $tables): array
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

it('names no table that is not CRM\'s own: it never queries or writes people, Accounts, roles, Membership or the audit trail', function () {
    $tables = foreignTables();

    // The protected set is real and complete enough to mean something: Identity's, Access's, Membership's and Audit's.
    expect($tables)->toContain('people', 'accounts', 'account_invitations', 'role_assignments', 'membership_grants', 'security_events', 'sessions')
        ->and($tables)->not->toContain('contact_methods', 'contact_profiles', 'contact_tags', 'contact_tag_assignments', 'contact_interactions');

    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Crm/Domain', 'app/Modules/Crm/Application', 'app/Modules/Crm/Infrastructure']) as $path) {
        foreach (foreignTableLiterals(SourceScan::read($path), $tables) as $literal) {
            $offenders[] = SourceScan::relative($path).": {$literal}";
        }
    }

    expect($offenders)->toBe([]);

    // Positive controls: the scan finds the real table where it IS named, and every form a planted offender could take.
    expect(foreignTableLiterals(SourceScan::read(SourceScan::root().'/app/Modules/Identity/Infrastructure/Persistence/DatabasePeopleDirectory.php'), $tables))->toContain('people')
        ->and(foreignTableLiterals("<?php \$db->table('people')->update(['display_name' => 'x']);", $tables))->toBe(['people'])
        ->and(foreignTableLiterals("<?php private const string TABLE = 'people'; \$db->table(self::TABLE)->get();", $tables))->toBe(['people'])
        ->and(foreignTableLiterals("<?php \$db->table('accounts as a')->get();", $tables))->toBe(['accounts as a'])
        ->and(foreignTableLiterals("<?php \$db->table(self::T.' as a')->join('membership_grants as g', 'g.person_id', '=', 'a.person_id');", $tables))->toBe(['membership_grants as g'])
        ->and(foreignTableLiterals("<?php \$db->select('select email from accounts where id = ?', [\$id]);", $tables))->toHaveCount(1)
        ->and(foreignTableLiterals("<?php \$q->whereRaw('person_id in (select person_id from role_assignments)');", $tables))->toHaveCount(1)
        ->and(foreignTableLiterals('<?php $db->statement("delete from security_events where id = {$id}");', $tables))->toHaveCount(1)
        ->and(foreignTableLiterals('<?php $db->statement("update people set display_name = ?");', $tables))->toHaveCount(1)
        // ...and does not trip on what is not a table reference: CRM's own tables, an alias, a comment, a word in prose.
        ->and(foreignTableLiterals("<?php \$db->table('contact_methods')->get(); \$db->selectRaw('count(*) as people'); echo 'no such people'; // table('people')", $tables))->toBe([]);
});

it('names only the five tables CRM owns', function () {
    $tables = [];
    foreach (SourceScan::phpFiles(['app/Modules/Crm/Infrastructure']) as $path) {
        foreach (SourceScan::stringLiterals(SourceScan::read($path)) as $literal) {
            if (preg_match('/^contact_[a-z_]+( as \w+)?$/', $literal) === 1) {
                $tables[] = trim(explode(' as ', $literal)[0]);
            }
        }
    }

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['contact_profiles', 'contact_methods', 'contact_tags', 'contact_tag_assignments', 'contact_interactions']);
});

// --- Tags are labels ----------------------------------------------------------------------------------------------

arch('a tag is known only inside Crm: nothing else can read a tag name to decide anything', function () {
    expect([ContactTag::class, ContactTagRepository::class])->each->toOnlyBeUsedIn('App\\Modules\\Crm');
});

it('gives the tag code no path to authorization, roles, Membership or Volunteer status', function () {
    $offenders = [];
    foreach (['Domain/ContactTag.php', 'Domain/ContactTagId.php', 'Domain/ContactTagRepository.php', 'Infrastructure/DatabaseContactTagRepository.php', 'Application/CreateTag.php', 'Application/RenameTag.php', 'Application/DeleteTag.php', 'Application/ListTags.php', 'Application/SetPersonTags.php', 'Application/TagWithCount.php'] as $file) {
        $code = SourceScan::code(SourceScan::read(SourceScan::root().'/app/Modules/Crm/'.$file));
        // The only capabilities tag code may name are CRM's own two; anything else, a role, Membership or an Authorizer, is a tag meaning something.
        preg_match_all('/Capability::(\w+)/', $code, $capabilities);
        foreach ($capabilities[1] as $case) {
            if (! in_array($case, ['ViewPeople', 'ManagePeople'], true)) {
                $offenders[] = "{$file}: Capability::{$case}";
            }
        }
        if (preg_match('/\bRole\b|Authorizer|Membership|Volunteer|Guardian|role_assignments/', $code) === 1) {
            $offenders[] = "{$file}: names a role, Membership or an Authorizer";
        }
    }

    expect($offenders)->toBe([]);

    // Positive controls: the scan catches each way a tag could be given meaning.
    foreach (['Capability::ConsoleAccess', 'Capability::AssignRoles'] as $planted) {
        preg_match_all('/Capability::(\w+)/', $planted, $m);
        expect(in_array($m[1][0], ['ViewPeople', 'ManagePeople'], true))->toBeFalse();
    }
    foreach (['use App\\Modules\\Access\\Application\\Role;', '$this->authorizer', 'MembershipGrant', 'if ($tag->name === "Volunteer")', 'Role::GuardianFull'] as $planted) {
        expect(preg_match('/\bRole\b|Authorizer|Membership|Volunteer|Guardian|role_assignments/i', $planted))->toBe(1);
    }
});

it('stores a tag with a name and its provenance and nothing that could carry authority', function () {
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(ContactTag::class))->getProperties());

    expect($properties)->toEqualCanonicalizing(['id', 'name', 'canonical', 'createdBy', 'createdAt']);
});

// --- Which capability each use case asks for ---------------------------------------------------------------------------

/**
 * The capability cases a use case's code names, read off the source with comments removed.
 *
 * @return list<string>
 */
function capabilitiesNamedBy(string $source): array
{
    preg_match_all('/Capability::(\w+)/', SourceScan::code($source), $matches);

    return array_values(array_unique($matches[1]));
}

it('has every read ask for crm.people.view alone and every change ask for crm.people.manage alone', function () {
    // The role catalog gives a Guardian BOTH capabilities, so a behavioural test cannot tell a use case that asks for the
    // wrong one from one that asks for the right one. The structure can: this is the whole of each use case's authorization.
    $expected = [
        'PagePeopleDirectory' => 'ViewPeople', 'GetPersonRecord' => 'ViewPeople', 'ListTags' => 'ViewPeople',
        'RegisterContact' => 'ManagePeople', 'UpdatePerson' => 'ManagePeople', 'AddContactMethod' => 'ManagePeople',
        'UpdateContactMethod' => 'ManagePeople', 'RemoveContactMethod' => 'ManagePeople', 'SetPersonTags' => 'ManagePeople',
        'CreateTag' => 'ManagePeople', 'RenameTag' => 'ManagePeople', 'DeleteTag' => 'ManagePeople',
        'ListInteractions' => 'ViewPeople', 'RecordInteraction' => 'ManagePeople', 'EditInteraction' => 'ManagePeople', 'RemoveInteraction' => 'ManagePeople',
    ];

    $actual = [];
    foreach (array_keys($expected) as $useCase) {
        $actual[$useCase] = capabilitiesNamedBy(SourceScan::read(SourceScan::root()."/app/Modules/Crm/Application/{$useCase}.php"));
    }

    foreach ($expected as $useCase => $capability) {
        expect($actual[$useCase])->toBe([$capability], "{$useCase} must ask for {$capability} and nothing else");
    }

    // Every Application class in Crm that is a use case (it takes an Actor) is listed above: a new one cannot skip the check.
    // The two carve-outs authorize nothing by design: `PersonRegistration` is the shared registration implementation its two
    // callers authorize before calling, and the delegated services (a different namespace, pinned by their own tests above)
    // are authorized by Relationships. Both are pinned to their callers.
    foreach (SourceScan::phpFiles(['app/Modules/Crm/Application']) as $path) {
        $name = basename($path, '.php');
        if (str_contains($path, '/Delegated/') || $name === 'PersonRegistration') {
            continue;
        }
        if (str_contains(SourceScan::code(SourceScan::read($path)), 'Actor $actor')) {
            expect(array_key_exists($name, $expected))->toBeTrue("{$name} takes an Actor but is not in the capability table");
        }
    }

    // Positive controls: the scan reads each shape of mistake.
    expect(capabilitiesNamedBy('<?php ($this->authorize)($actor, Capability::ViewPeople);'))->toBe(['ViewPeople'])
        ->and(capabilitiesNamedBy("<?php // Capability::ManagePeople\n(\$this->authorize)(\$actor, Capability::ViewPeople);"))->toBe(['ViewPeople'])
        ->and(capabilitiesNamedBy('<?php Capability::ViewPeople; Capability::ManagePeople;'))->toBe(['ViewPeople', 'ManagePeople'])
        ->and(capabilitiesNamedBy('<?php // no authorization at all'))->toBe([]);
});
