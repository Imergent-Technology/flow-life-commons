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
use App\Modules\Identity\Http\MfaProblems;
use App\Modules\Identity\Http\RequireRecentSecurityVerification;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // The application API is versioned in the URL; module routes are
        // loaded by routes/api.php under /api/v1.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // Framework liveness probe (/up). The application health endpoint is /api/v1/health.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        ]);

        // The step-up seam (ADR 0023): `->middleware('security.verified')`, after `auth:web`. Modules use the
        // alias, never the class, so a sensitive route in another module does not import Identity's Http.
        $middleware->alias(['security.verified' => RequireRecentSecurityVerification::class]);

        // This is an API: an unauthenticated request gets a 401, never a redirect to a
        // login page. (Without this, `auth` looks up a `login` route that does not exist.)
        $middleware->redirectGuestsTo(fn (): ?string => null);

        // Laravel reorders route middleware by a priority list, and would otherwise hoist
        // `auth` above CSRF protection and the absolute-lifetime check. That breaks two
        // things: an unauthenticated request would be refused before it is ever issued its
        // XSRF-TOKEN cookie (so the Console could never obtain a token before signing in),
        // and an expired session could authenticate. The order must be:
        //   session -> CSRF -> absolute lifetime -> second factor due -> authentication
        // Each is inserted directly before authentication, CSRF first. The priority list
        // names the AuthenticatesRequests interface, not the Authenticate class, so that
        // is what to anchor on (anchoring on the class silently appends to the end).
        // A test pins the resulting order.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: PreventRequestForgery::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceAbsoluteSessionLifetime::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceSecondFactorWhereDue::class);
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

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();
