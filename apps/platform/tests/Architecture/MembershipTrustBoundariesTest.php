<?php

declare(strict_types=1);

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\RegisterPerson;
use App\Modules\Identity\Application\ResolveActor;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Application\RegisterPersonWithMembershipAccess;
use App\Modules\Membership\Domain\MembershipGrant;
use App\Shared\Domain\Actor;
use App\Shared\Domain\AuthenticationMethod;
use Tests\Support\SourceScan;

/*
 * The Membership Foundation's trust boundary (Work Package 7), as source structure. The runtime proofs (routes, guards,
 * forged requests, capability layers) are the feature tests under tests/Feature/Modules/Membership; these are what can
 * be read off the code without running it.
 *
 * PHASE BOUNDARY, NOT A PERMANENT RULE. Several rules here pin that the ONLY caller identity the platform can produce
 * is a signed-in human's Console session: one Actor shape, one authentication method family, one place that mints an
 * Actor. That is true today because service clients and delegated-person authentication (ADR 0018) are an accepted
 * direction that is deliberately not built yet. When ADR 0018 is implemented these rules are EXPECTED to fail, and must
 * be revised deliberately — with the new Actor shape and its minting site named here — not deleted. What does not
 * change then is the durable rule: a client-asserted identity (a person_id, a WordPress user) is never proof of who
 * is acting.
 *
 * One subject per arch expectation (tests/Architecture/README.md). Every source scan has a positive control.
 */

$membership = 'App\\Modules\\Membership';

// --- Who can be a caller at all ---------------------------------------------------------------------------------

/** A regex for the only two ways the language offers to make an Actor. */
const ACTOR_MINTING = '/\bActor::user\s*\(|\bnew\s+\\\\?(?:App\\\\Shared\\\\Domain\\\\)?Actor\s*\(/';

it('mints an Actor only in Identity\'s Application layer, from a resolved Account', function () {
    $offenders = [];
    $minters = [];
    foreach (SourceScan::phpFiles(['app', 'bootstrap', 'config', 'routes', 'database']) as $path) {
        $relative = SourceScan::relative($path);
        if ($relative === 'app/Shared/Domain/Actor.php') {
            continue; // its own named constructor
        }
        if (preg_match(ACTOR_MINTING, SourceScan::code(SourceScan::read($path))) === 1) {
            $minters[] = $relative;
            if (! str_starts_with($relative, 'app/Modules/Identity/Application/')) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'Only Identity\'s Application layer may mint an Actor: anywhere else, a request could become a caller without authenticating.');

    // Positive controls: the scan finds the real minting sites, and the pattern catches a planted one in any form.
    expect($minters)->toContain('app/Modules/Identity/Application/ResolveActor.php');
    foreach (['<?php $a = Actor::user($id, $person);', '<?php $a = new Actor($id, $p, $m);', '<?php $a = new \\App\\Shared\\Domain\\Actor($i, $p, $m);'] as $planted) {
        expect(preg_match(ACTOR_MINTING, SourceScan::code($planted)))->toBe(1);
    }
    // ...and a comment that merely names it is not code.
    expect(preg_match(ACTOR_MINTING, SourceScan::code('<?php // Actor::user($id, $person) is minted elsewhere')))->toBe(0);
});

it('has exactly one Actor shape: a signed-in human (ADR 0018 service and delegated Actors are not built)', function () {
    $actor = new ReflectionClass(Actor::class);
    $constructor = $actor->getConstructor();
    $factories = array_values(array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        array_filter($actor->getMethods(ReflectionMethod::IS_STATIC), fn (ReflectionMethod $m): bool => $m->isPublic()),
    ));
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), $actor->getProperties());

    expect($constructor?->isPrivate())->toBeTrue()
        ->and($factories)->toBe(['user'])
        // An account and a person: nothing that could name a client, a delegating application or a WordPress user.
        ->and($properties)->toBe(['accountId', 'personId', 'authenticatedVia'])
        ->and(array_map(fn (AuthenticationMethod $m): string => $m->value, AuthenticationMethod::cases()))
        ->toBe(['session', 'session_second_factor']);
});

it('takes the caller of a Membership request from the authenticated session only, never from request data', function () {
    // Anything a client sends — body, query, headers, cookies it wrote, the route — is a SUBJECT or noise, never the caller.
    $readsRequestData = '/->(?:input|header|headers|query|post|json|all|only|except|get|string|integer|boolean|route|cookie|cookies|server|bearerToken|getUser|getPassword)\s*\(|->(?:headers|query|request|cookies|server|attributes)\b|\$_(?:GET|POST|REQUEST|SERVER|COOKIE)\b/';
    $path = SourceScan::root().'/app/Modules/Membership/Http/RequestActor.php';
    $code = SourceScan::code(SourceScan::read($path));

    expect(preg_match($readsRequestData, $code))->toBe(0, 'Membership\\Http\\RequestActor must read nothing from the request but its authenticated user.')
        ->and($code)->toContain('$request->user()')
        ->and($code)->toContain('resolveActor');

    // Positive controls: each way a caller id could be smuggled in is caught.
    foreach ([
        '$id = $request->input(\'actor_account_id\');',
        '$id = $request->header(\'X-Account-Id\');',
        '$id = $request->headers->get(\'X-Person-Id\');',
        '$id = $request->bearerToken();',
        '$id = $request->route(\'person\');',
        '$id = $_SERVER[\'HTTP_X_WORDPRESS_USER\'];',
    ] as $planted) {
        expect(preg_match($readsRequestData, SourceScan::code("<?php {$planted}")))->toBe(1, $planted);
    }
});

it('resolves a Membership caller in exactly one place', function () {
    $users = [];
    foreach (SourceScan::phpFiles(['app/Modules/Membership']) as $path) {
        if (str_contains(SourceScan::code(SourceScan::read($path)), 'ResolveActor')) {
            $users[] = SourceScan::relative($path);
        }
    }

    expect($users)->toBe(['app/Modules/Membership/Http/RequestActor.php']);
    expect(class_exists(ResolveActor::class))->toBeTrue(); // the name scanned for is the real class
});

it('gives every Membership controller its caller through RequestActor, and none mints or accepts one otherwise', function () {
    $controllers = array_values(array_filter(
        SourceScan::phpFiles(['app/Modules/Membership/Http']),
        fn (string $path): bool => str_ends_with($path, 'Controller.php'),
    ));
    $problems = [];
    foreach ($controllers as $path) {
        $code = SourceScan::code(SourceScan::read($path));
        if (! str_contains($code, '$actors->for($request)')) {
            $problems[] = SourceScan::relative($path).' does not take its caller from RequestActor';
        }
        if (preg_match('/\bActor\b/', str_replace('RequestActor', '', $code)) === 1) {
            $problems[] = SourceScan::relative($path).' handles an Actor itself';
        }
    }

    expect($problems)->toBe([])->and($controllers)->toHaveCount(5);
});

// --- What Membership may and may not become ----------------------------------------------------------------------

arch('Membership never authenticates, hashes a credential or touches a session: identity stays Identity\'s', function () use ($membership) {
    expect($membership)->not->toUse([
        'Illuminate\\Auth',
        'Illuminate\\Session',
        'Illuminate\\Contracts\\Hashing',
        'Illuminate\\Contracts\\Session',
        'Illuminate\\Contracts\\Auth\\Guard',
        'Illuminate\\Contracts\\Auth\\StatefulGuard',
        'Illuminate\\Support\\Facades\\Auth',
        'Illuminate\\Support\\Facades\\Hash',
        'Illuminate\\Support\\Facades\\Session',
        'Illuminate\\Support\\Facades\\Cookie',
        'Illuminate\\Support\\Facades\\Password',
        'App\\Modules\\Identity\\Application\\AuthenticateAccount',
        'App\\Modules\\Identity\\Application\\InviteAccount',
    ]);
});

arch('Membership creates a Person only through Identity\'s own use case', function () {
    expect(RegisterPersonWithMembershipAccess::class)->toUse(RegisterPerson::class);
    // ...and nothing but that orchestration may: RegisterPerson does not authorize its caller.
    expect(RegisterPerson::class)->toOnlyBeUsedIn([RegisterPersonWithMembershipAccess::class, 'App\\Modules\\Identity']);
});

arch('Membership composes a display name only through Identity\'s Application read port', function () {
    expect(FindPeople::class)->toOnlyBeUsedIn(['App\\Modules\\Membership\\Http', 'App\\Modules\\Identity']);
});

it('keeps Membership out of Identity\'s, Access\'s and Audit\'s tables', function () {
    $foreign = ['people', 'accounts', 'account_invitations', 'sessions', 'account_totp_factors', 'account_recovery_codes', 'password_reset_tokens', 'role_assignments', 'security_events'];
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Membership']) as $path) {
        foreach (array_intersect(SourceScan::stringLiterals(SourceScan::read($path)), $foreign) as $table) {
            $offenders[] = SourceScan::relative($path)." names '{$table}'";
        }
    }

    expect($offenders)->toBe([]);

    // Positive control: the same literal scan sees the tables where they legitimately are named.
    expect(SourceScan::stringLiterals(SourceScan::read(SourceScan::root().'/app/Modules/Identity/Infrastructure/Persistence/DatabaseAccountDirectory.php')))
        ->toContain('accounts as a');
    expect(SourceScan::stringLiterals(SourceScan::read(SourceScan::root().'/app/Modules/Membership/Infrastructure/DatabaseMembershipGrantRepository.php')))
        ->toContain('membership_grants');
});

arch('Only Identity and Access record security events: Membership grants and revocations are not security events in Phase 1', function () {
    // ADR 0019's seam is for identity- and access-relevant occurrences. This pins the Phase-1 fact that no Membership
    // action writes there, directly or by a module it does not already depend on. A future general business audit or
    // history for Membership is a separate decision, not forbidden by this.
    expect(RecordSecurityEvent::class)->toOnlyBeUsedIn(['App\\Modules\\Identity', 'App\\Modules\\Access', 'App\\Modules\\Audit']);
});

arch('Membership reaches no external service: no provider (Luma, Zeffy) is called from anywhere in it', function () use ($membership) {
    // Stricter than ModuleBoundariesTest, which allows an HTTP client in Infrastructure: Membership has no Infrastructure
    // adapter to a provider, and ADR 0029 keeps payment facts out of it.
    expect($membership)->not->toUse(['Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client', 'GuzzleHttp', 'Psr\\Http\\Client', 'Symfony\\Contracts\\HttpClient']);
});

it('opens no network connection from Membership by any lower-level means either', function () {
    $network = '/\b(?:curl_\w+|fsockopen|pfsockopen|stream_socket_client|socket_connect|file_get_contents\s*\(\s*[\'"]https?:)|[\'"]https?:\/\//i';
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Membership']) as $path) {
        if (preg_match($network, SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([]);
    foreach (['<?php curl_init($u);', '<?php $x = file_get_contents("https://lu.ma/x");', '<?php $url = \'https://api.zeffy.com\';'] as $planted) {
        expect(preg_match($network, SourceScan::code($planted)))->toBe(1, $planted);
    }
});

it('holds no payment fact in a grant or a record: provenance is source and an opaque reference, nothing more (ADR 0029)', function () {
    $names = fn (ReflectionClass $class): array => array_map(fn (ReflectionProperty $p): string => $p->getName(), $class->getProperties());

    // Exact, so a new field is a deliberate change here rather than a substring a scan happens to miss.
    expect($names(new ReflectionClass(MembershipGrant::class)))->toBe(['id', 'personId', 'startsAt', 'endsAt', 'source', 'sourceReference', 'grantedByAccountId', 'revokedAt', 'revokedByAccountId', 'createdAt'])
        ->and($names(new ReflectionClass(MembershipRecord::class)))->toBe(['personId', 'active', 'currentAccessEndsAt', 'openEnded', 'grants']);
});

it('treats a source reference as opaque: nothing in Membership branches on it or on which source it came from', function () {
    // It is carried and returned, never parsed, split, decoded or matched (a luma_legacy reference is not a Luma id the
    // platform understands). The only code that may look at `source` is the enum itself and the persistence round trip.
    $interprets = '/sourceReference\s*(?:\)|,)?\s*(?:->|\[)|(?:explode|preg_\w+|parse_url|json_decode|str_starts_with|str_contains|substr|strtok|base64_decode)\s*\([^;]*source(?:_r|R)eference|===?\s*MembershipGrantSource::|MembershipGrantSource::\w+\s*===?|match\s*\(\s*\$\w+->source\b/';
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Membership']) as $path) {
        if (preg_match($interprets, SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([]);
    foreach ([
        '<?php $parts = explode(":", $grant->sourceReference);',
        '<?php if ($grant->source === MembershipGrantSource::LumaLegacy) { lookup(); }',
        '<?php $id = json_decode($request->source_reference);',
        '<?php $r = match ($grant->source) { default => 1 };',
    ] as $planted) {
        expect(preg_match($interprets, SourceScan::code($planted)))->toBe(1, $planted);
    }
});

// --- WordPress, from the platform's side ---------------------------------------------------------------------------

it('reads no WordPress or client-asserted identity anywhere in the platform (the durable rule of ADR 0004 and 0018)', function () {
    // A key- or header-shaped literal like this in platform code would mean it looks for a claim WordPress (or any
    // client) makes about who is acting. Only identifier-shaped literals are examined: prose that names WordPress (an
    // error message explaining why a cookie must be host-only) is not a claim, and comments are not literals at all.
    $claim = '/^(?:x-)?(?:wordpress|wp)[-_]|wordpress[-_]?user|^x-(?:user|person|account|actor|forwarded-user)(?:-id)?$/i';
    $identifierShaped = fn (string $literal): bool => preg_match('/^[\w.-]+$/', $literal) === 1;
    $offenders = [];
    foreach (SourceScan::phpFiles(['app', 'bootstrap', 'config', 'routes']) as $path) {
        foreach (array_filter(SourceScan::stringLiterals(SourceScan::read($path)), $identifierShaped) as $literal) {
            if (preg_match($claim, $literal) === 1) {
                $offenders[] = SourceScan::relative($path).": '{$literal}'";
            }
        }
    }

    expect($offenders)->toBe([]);
    foreach (['X-WordPress-User', 'wordpress_user_id', 'X-Person-Id', 'X-Account-Id', 'X-Forwarded-User', 'wp_user'] as $planted) {
        expect($identifierShaped($planted) && preg_match($claim, $planted) === 1)->toBeTrue($planted);
    }
    // Not claims about the caller: the platform's own subject identifiers, and Laravel's `sessions.user_id` column, which
    // the session guard writes from an authenticated Account and never reads from a request.
    foreach (['person_id', 'account_id', 'granted_by_account_id', 'user_id'] as $fine) {
        expect(preg_match($claim, $fine))->toBe(0, $fine);
    }
    expect($identifierShaped('A Domain would send this cookie to WordPress'))->toBeFalse();
});
