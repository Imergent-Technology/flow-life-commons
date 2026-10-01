<?php

declare(strict_types=1);

use App\Modules\Identity\Application\FindPeopleNamed;
use App\Modules\Identity\Application\PeopleDirectory;
use App\Modules\Identity\Application\PeoplePage;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\RenamePerson;
use App\Modules\Identity\Application\SearchPeople;
use App\Modules\Identity\Domain\PersonRepository;
use Tests\Support\SourceScan;

/*
 * The boundaries of Identity's People ports (ADR 0034): SearchPeople and RenamePerson, the seams a later `Crm`
 * module composes. What can be read off the code without running it; the behaviour is in
 * tests/Feature/Modules/Identity/{SearchPeople,RenamePerson}Test.php.
 *
 * The WP1 phase rule "no Crm module yet" ended with CRM Work Package 2, which created the module; Crm's own
 * boundaries are in CrmBoundariesTest. The rules here are durable.
 *
 * One subject per arch expectation (tests/Architecture/README.md). Every source scan has a positive control.
 */

$identity = 'App\\Modules\\Identity';

// --- Dependency direction ----------------------------------------------------------------------------------------

arch('Identity does not depend on Crm', function () use ($identity) {
    // CRM depends on Identity, never back.
    expect($identity)->not->toUse('App\\Modules\\Crm');
});

arch('Identity does not depend on Membership', function () use ($identity) {
    expect($identity)->not->toUse('App\\Modules\\Membership');
});

arch('Identity does not depend on Access: it cannot ask what a caller may do, so the People ports authorize nothing', function () use ($identity) {
    expect("{$identity}\\Application\\SearchPeople")->not->toUse('App\\Modules\\Access')
        ->and("{$identity}\\Application\\RenamePerson")->not->toUse('App\\Modules\\Access');
});

// --- A later module can use the ports without Identity's Domain -------------------------------------------------

/**
 * Every class named by the parameters, return types and properties of a class's public surface, as a caller sees it.
 * A use case's constructor is the container's business, not the caller's, so it is skipped unless the caller builds
 * the class itself (`$callerConstructs`: the query and the result).
 *
 * @param  class-string  $class
 * @return list<string>
 */
function publicSurfaceTypes(string $class, bool $callerConstructs = true): array
{
    $types = [];
    $collect = function (?ReflectionType $type) use (&$types): void {
        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $part) {
            if ($part instanceof ReflectionNamedType && ! $part->isBuiltin()) {
                $types[] = $part->getName();
            }
        }
    };

    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() && ! $callerConstructs) {
            continue;
        }
        $collect($method->getReturnType());
        foreach ($method->getParameters() as $parameter) {
            $collect($parameter->getType());
        }
    }
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $collect($property->getType());
    }

    return array_values(array_unique($types));
}

it('exposes no Identity Domain type through SearchPeople, RenamePerson and their query and result types', function () {
    $leaks = [];
    $callerBuilt = [PeopleQuery::class => true, PeoplePage::class => true, SearchPeople::class => false, FindPeopleNamed::class => false, PeopleDirectory::class => false, RenamePerson::class => false];
    foreach ($callerBuilt as $class => $callerConstructs) {
        foreach (publicSurfaceTypes($class, $callerConstructs) as $type) {
            if (str_starts_with($type, 'App\\Modules\\Identity\\Domain\\')) {
                $leaks[] = "{$class} exposes {$type}";
            }
        }
    }

    expect($leaks)->toBe([], 'A caller in another module must be able to use these ports without ever naming (or receiving) an Identity Domain type.');

    // Positive controls: the same scan catches a port that does leak one, and a constructor that a caller builds.
    expect(publicSurfaceTypes(PersonRepository::class))->toContain('App\\Modules\\Identity\\Domain\\Person')
        ->and(publicSurfaceTypes(RenamePerson::class))->toContain(PersonRepository::class)
        ->and(publicSurfaceTypes(RenamePerson::class, false))->not->toContain(PersonRepository::class);
});

// --- The disclosure boundary -------------------------------------------------------------------------------------

/**
 * String literals in the source (table and column names among them) that name Account data.
 *
 * @return list<string>
 */
function accountDataLiterals(string $source): array
{
    return array_values(array_filter(
        SourceScan::stringLiterals($source),
        static fn (string $literal): bool => preg_match('/account|email|invitation|totp|recovery|session|role_assignment/i', $literal) === 1,
    ));
}

it('reads nothing but `people` in the People directory: no Account column can be matched or returned', function () {
    $path = SourceScan::root().'/app/Modules/Identity/Infrastructure/Persistence/DatabasePeopleDirectory.php';

    expect(accountDataLiterals(SourceScan::read($path)))->toBe([]);

    // Positive control: the same scan finds Account data in the Account directory, which is allowed to read it.
    $accounts = SourceScan::root().'/app/Modules/Identity/Infrastructure/Persistence/DatabaseAccountDirectory.php';
    expect(accountDataLiterals(SourceScan::read($accounts)))->not->toBe([]);
});

/**
 * Every Eloquent persistence record in Identity's Infrastructure except the Person's own: Account, invitation and
 * factor storage among them, and any added later without editing this test.
 *
 * @return list<class-string>
 */
function nonPersonRecords(): array
{
    $records = [];
    foreach (glob(SourceScan::root().'/app/Modules/Identity/Infrastructure/Persistence/*Record.php') ?: [] as $path) {
        $name = basename($path, '.php');
        if ($name !== 'PersonRecord') {
            $records[] = 'App\\Modules\\Identity\\Infrastructure\\Persistence\\'.$name;
        }
    }

    return $records; // @phpstan-ignore return.type
}

arch('the People directory uses no Account, invitation or factor persistence model', function () {
    expect(nonPersonRecords())->toContain('App\\Modules\\Identity\\Infrastructure\\Persistence\\AccountRecord') // positive control: the list is real
        ->and('App\\Modules\\Identity\\Infrastructure\\Persistence\\DatabasePeopleDirectory')->not->toUse(nonPersonRecords());
});

arch('the People directory depends only on the People ports, the id type and the query builder', function () {
    // An allowlist, so a new dependency on anything Account-shaped is a decision, not an accident. It is a short list
    // of what the class is FOR; adding a genuinely harmless collaborator means adding a line here.
    expect('App\\Modules\\Identity\\Infrastructure\\Persistence\\DatabasePeopleDirectory')->toOnlyUse([
        'App\\Modules\\Identity\\Application\\PeopleDirectory',
        'App\\Modules\\Identity\\Application\\PeoplePage',
        'App\\Modules\\Identity\\Application\\PeopleQuery',
        'App\\Modules\\Identity\\Application\\PersonSummary',
        'App\\Modules\\Identity\\Infrastructure\\Persistence\\LikeFragment',
        'App\\Shared\\Domain\\PersonId',
        'Illuminate\\Database\\ConnectionInterface',
        'Illuminate\\Database\\Query\\Builder',
    ]);
});

arch('the exact-name lookup is used by Crm\'s Application layer and Identity, and by nothing else', function () {
    expect(FindPeopleNamed::class)->toOnlyBeUsedIn(['App\\Modules\\Crm\\Application', 'App\\Modules\\Identity']);
});

it('gives the People directory no way to ask about Accounts', function () {
    $applicationTypes = array_merge(
        publicSurfaceTypes(PeopleDirectory::class),
        publicSurfaceTypes(PeopleQuery::class),
        publicSurfaceTypes(PeoplePage::class),
        publicSurfaceTypes(FindPeopleNamed::class),
    );

    expect(array_filter($applicationTypes, fn (string $t): bool => preg_match('/Account|Managed/', $t) === 1))->toBe([]);
});

// --- No step-up, no authorization inside Identity ----------------------------------------------------------------

const AUTHORITY_GATE = '/SecurityProof|VerifySecurityAccess|RequireRecentSecurityVerification|security\.verified|Authorizer|AuthorizeAction|Capability|hasCapability|Gate::/';

it('does not gate renaming or searching on recent verification or on a capability', function () {
    $offenders = [];
    foreach (['SearchPeople', 'FindPeopleNamed', 'RenamePerson', 'PeopleQuery', 'PeoplePage', 'PeopleDirectory'] as $name) {
        $code = SourceScan::code(SourceScan::read(SourceScan::root()."/app/Modules/Identity/Application/{$name}.php"));
        if (preg_match(AUTHORITY_GATE, $code) === 1) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([], 'Renaming is not a step-up operation (ADR 0034) and Identity cannot authorize; the calling use case does both.');

    // Positive control: the pattern catches each way of adding such a gate.
    foreach (['VerifySecurityAccess $verify', "middleware('security.verified')", '$this->authorize(Capability::X)', 'Gate::allows()'] as $planted) {
        expect(preg_match(AUTHORITY_GATE, $planted))->toBe(1);
    }
});

it('takes only a repository, the audit seam and a connection to rename', function () {
    $constructor = (new ReflectionClass(RenamePerson::class))->getConstructor();
    $types = array_map(fn (ReflectionParameter $p): string => (string) $p->getType(), $constructor?->getParameters() ?? []);

    expect($types)->toBe([
        PersonRepository::class,
        'App\\Modules\\Audit\\Application\\RecordSecurityEvent',
        'Illuminate\\Database\\ConnectionInterface',
    ]);
});
