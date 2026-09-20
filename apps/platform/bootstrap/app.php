<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Identity\Http\EnforceAbsoluteSessionLifetime;
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
        ]);

        // This is an API: an unauthenticated request gets a 401, never a redirect to a
        // login page. (Without this, `auth` looks up a `login` route that does not exist.)
        $middleware->redirectGuestsTo(fn (): ?string => null);

        // Laravel reorders route middleware by a priority list, and would otherwise hoist
        // `auth` above CSRF protection and the absolute-lifetime check. That breaks two
        // things: an unauthenticated request would be refused before it is ever issued its
        // XSRF-TOKEN cookie (so the Console could never obtain a token before signing in),
        // and an expired session could authenticate. The order must be:
        //   session -> CSRF -> absolute lifetime -> authentication
        // Each is inserted directly before authentication, CSRF first. The priority list
        // names the AuthenticatesRequests interface, not the Authenticate class, so that
        // is what to anchor on (anchoring on the class silently appends to the end).
        // A test pins the resulting order.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: PreventRequestForgery::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceAbsoluteSessionLifetime::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Access's Application layer may not use Laravel's HTTP or auth machinery, so it
        // throws its own AccessDenied; this is the edge that turns it into a 403.
        $exceptions->render(fn (AccessDenied $e, Request $request) => response()->json(['message' => $e->getMessage()], 403));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();
