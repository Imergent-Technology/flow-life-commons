<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AccountNotDisabled;
use App\Modules\Access\Application\LastAdministratorRequired;
use App\Modules\Access\Application\MfaNotEnrolled;
use App\Modules\Access\Application\UnknownPerson;
use App\Modules\Access\Application\UnknownRole;
use App\Modules\Access\Http\AdministrationProblems;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\CompromisedPasswordCheckUnavailable;
use App\Modules\Identity\Application\CurrentPasswordIncorrect;
use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvalidInvitationDetails;
use App\Modules\Identity\Application\InvitationNotIssuable;
use App\Modules\Identity\Application\InvitationRejected;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\PasswordRejected;
use App\Modules\Identity\Application\ResetRejected;
use App\Modules\Identity\Application\SecondFactorRejected;
use App\Modules\Identity\Application\SelfMfaResetProhibited;
use App\Modules\Identity\Application\TooManyAttempts;
use App\Modules\Identity\Http\CredentialProblems;
use App\Modules\Identity\Http\EnforceAbsoluteSessionLifetime;
use App\Modules\Identity\Http\EnforceSecondFactorWhereDue;
use App\Modules\Identity\Http\EnforceSecurityGeneration;
use App\Modules\Identity\Http\MfaProblems;
use App\Modules\Identity\Http\RequireRecentSecurityVerification;
use App\Modules\Membership\Application\GrantAlreadyRevoked;
use App\Modules\Membership\Application\GrantNotFound;
use App\Modules\Membership\Application\UnknownPerson as UnknownMembershipPerson;
use App\Modules\Membership\Domain\InvalidMembershipTerm as InvalidMembershipGrantTerm;
use App\Modules\Membership\Http\MembershipProblems;
use App\Modules\Membership\Http\MembershipRecordNotFound;
use App\Modules\Security\Http\ApplyBrowserSecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // The application API is versioned in the URL; module routes are
        // loaded by routes/api.php under /api/v1.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        /*
         * The liveness probe, /up. The framework's own `health:` route is deliberately NOT used.
         *
         * Its page loads a web font from fonts.bunny.net and Tailwind from cdn.jsdelivr.net — two
         * third-party origins, one of them executing a live script — on the same origin as the
         * privileged Console, and with APP_DEBUG off it prints the failing exception's MESSAGE on a
         * public page, which for a database fault is a connection string's worth of detail.
         *
         * This answers the question a probe actually asks, in eleven bytes, and says nothing else:
         * `up` with 200, `down` with 503. The `DiagnosingHealth` event is still dispatched, so the
         * framework's extension point survives; a listener that throws makes the probe fail without
         * telling the caller why. Operational detail belongs behind authentication, not here, and the
         * application-level check (/api/v1/health) stays as coarse as it already was.
         */
        then: function (): void {
            Route::get('/up', function (): Response {
                try {
                    Event::dispatch(new DiagnosingHealth);
                } catch (Throwable $e) {
                    report($e);

                    return response('down', 503)->header('Content-Type', 'text/plain');
                }

                return response('up')->header('Content-Type', 'text/plain');
            })->name('up');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The browser security policy (ADR 0026) goes on EVERY response this application produces,
        // including error pages and /up, so there is no route that can be added without it. In
        // production it covers the API half of the origin; the Console's static files are Apache's,
        // under the same policy generated from the same configuration (`php artisan security:headers`).
        //
        // PREPENDED to the global stack, not `use()`d: `use()` REPLACES the stack, which would quietly
        // drop CORS handling, trusted hosts, maintenance mode and the input sanitisers. Prepending puts
        // it outermost, so it also dresses a response produced by anything further in, including the
        // framework's own exception rendering.
        $middleware->prepend(ApplyBrowserSecurityHeaders::class);

        // Cookie-authenticated Guardian Console flows are a deliberate opt-in (ADR 0016),
        // not something the whole API inherits. The API stays stateless, so public
        // endpoints and later service clients never get a session, a cookie or a CSRF
        // check. Routes join it with ->middleware('stateful').
        //
        // PreventRequestForgery is Laravel 13's name for what the ADR calls
        // ValidateCsrfToken (that class is now a deprecated alias of it).
        $middleware->group('stateful', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
            EnforceAbsoluteSessionLifetime::class,
            EnforceSecondFactorWhereDue::class,
            EnforceSecurityGeneration::class,
        ]);

        // The step-up seam (ADR 0023): `->middleware('security.verified')`, after `auth:web`. Modules use the
        // alias, never the class, so a sensitive route in another module does not import Identity's Http.
        $middleware->alias(['security.verified' => RequireRecentSecurityVerification::class]);

        /*
         * Host and proxy handling (ADR 0016, ADR 0026).
         *
         * TRUSTED HOSTS: exactly the host in APP_URL, and `subdomains: false`. Laravel's default is
         * "this host and every subdomain of it", which is precisely the thing this deployment must not
         * do: WordPress and other Flow Life hosts live beside the Console under the same apex and are
         * deliberately lower-trust (ADR 0004). A request arriving with someone else's Host header is
         * refused rather than used to generate a password-reset or invitation link pointing at it.
         * The middleware excuses itself in `local` and under unit tests, so development and the suite
         * are unaffected; production and staging enforce it.
         *
         * TRUSTED PROXIES: none, deliberately, by not configuring any. Production is cPanel/Apache
         * serving PHP on the same host, with nothing in front adding `X-Forwarded-*`, so trusting
         * those headers would buy nothing and would let a caller claim any source address — which is
         * what the per-address rate limits and the audit trail are keyed on. `trustProxies('*')` is
         * the reflex to avoid here.
         *
         * The development gateway IS a proxy, so in development every request appears to come from
         * Caddy. That is the safe direction (no client can spoof an address), it is why the
         * development login limit is raised for the browser suite, and it is recorded rather than
         * papered over. If a CDN or reverse proxy is ever put in front of production, its addresses
         * must be listed here explicitly — never a wildcard — and the change reviewed on its own.
         */
        $middleware->trustHosts(at: function (): array {
            $url = config('app.url');
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            return is_string($host) && $host !== '' ? [$host] : [];
        }, subdomains: false);

        // This is an API: an unauthenticated request gets a 401, never a redirect to a
        // login page. (Without this, `auth` looks up a `login` route that does not exist.)
        $middleware->redirectGuestsTo(fn (): ?string => null);

        // Laravel reorders route middleware by a priority list, and would otherwise hoist
        // `auth` above CSRF protection and the absolute-lifetime check. That breaks two
        // things: an unauthenticated request would be refused before it is ever issued its
        // XSRF-TOKEN cookie (so the Console could never obtain a token before signing in),
        // and an expired session could authenticate. The order must be:
        //   session -> CSRF -> absolute lifetime -> second factor due -> security generation -> authentication
        // Each is inserted directly before authentication, CSRF first. The priority list
        // names the AuthenticatesRequests interface, not the Authenticate class, so that
        // is what to anchor on (anchoring on the class silently appends to the end).
        // A test pins the resulting order.
        //
        // The security-generation check (ADR 0025) is last of the four, immediately before
        // authentication: it is the one that decides whether this session's authentication still
        // stands at all, and nothing between it and the guard may re-establish authority.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: PreventRequestForgery::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceAbsoluteSessionLifetime::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceSecondFactorWhereDue::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceSecurityGeneration::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Access's Application layer may not use Laravel's HTTP or auth machinery, so it
        // throws its own AccessDenied; this is the edge that turns it into a 403.
        $exceptions->render(fn (AccessDenied $e, Request $request) => response()->json(['message' => $e->getMessage()], 403));
        // Operator administration (ADR 0024): each refusal has a stable `code`, so the Console never parses a sentence.
        $exceptions->render(fn (AccountNotFound $e) => AdministrationProblems::notFound());
        $exceptions->render(fn (UnknownPerson $e) => AdministrationProblems::notFound());
        $exceptions->render(fn (LastAdministratorRequired $e) => AdministrationProblems::conflict('last_administrator_required', $e->getMessage()));
        $exceptions->render(fn (AccountNotDisabled $e) => AdministrationProblems::conflict('account_not_disabled', $e->getMessage()));
        $exceptions->render(fn (EmailAlreadyInUse $e) => AdministrationProblems::conflict('email_already_in_use', 'An account already uses that email address.'));
        $exceptions->render(fn (InvitationNotIssuable $e) => AdministrationProblems::conflict('invitation_not_issuable', $e->getMessage()));
        $exceptions->render(fn (MfaNotEnrolled $e) => AdministrationProblems::conflict('mfa_not_enrolled', $e->getMessage()));
        $exceptions->render(fn (UnknownRole $e) => AdministrationProblems::invalid('unknown_role', 'key', $e->getMessage()));
        $exceptions->render(fn (SelfMfaResetProhibited $e) => AdministrationProblems::invalid('self_mfa_reset_prohibited', 'account', $e->getMessage()));
        $exceptions->render(fn (InvalidInvitationDetails $e) => AdministrationProblems::invalidDetails($e));
        // The same for Identity's credential endpoints: Application throws, this is the wire format.
        $exceptions->render(fn (PasswordRejected $e) => CredentialProblems::passwordRejected($e));
        $exceptions->render(fn (CompromisedPasswordCheckUnavailable $e) => CredentialProblems::checkUnavailable());
        $exceptions->render(fn (InvitationRejected $e) => CredentialProblems::invitationRejected());
        $exceptions->render(fn (ResetRejected $e) => CredentialProblems::resetRejected());
        $exceptions->render(fn (CurrentPasswordIncorrect $e) => CredentialProblems::currentPasswordIncorrect());
        $exceptions->render(fn (SecondFactorRejected $e) => MfaProblems::rejected($e));
        $exceptions->render(fn (NoLongerAuthenticated $e) => response()->json(['message' => 'Unauthenticated.'], 401));
        $exceptions->render(fn (TooManyAttempts $e) => CredentialProblems::tooManyAttempts($e));
        // Operator administration of membership records (ADR 0028, Work Package 5).
        $exceptions->render(fn (UnknownMembershipPerson $e) => MembershipProblems::personNotFound());
        $exceptions->render(fn (MembershipRecordNotFound $e) => MembershipProblems::recordNotFound());
        $exceptions->render(fn (GrantNotFound $e) => MembershipProblems::grantNotFound());
        $exceptions->render(fn (GrantAlreadyRevoked $e) => MembershipProblems::grantAlreadyRevoked());
        $exceptions->render(fn (InvalidMembershipGrantTerm $e) => MembershipProblems::invalidMembershipTerm($e));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();
