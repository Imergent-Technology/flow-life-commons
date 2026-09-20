<?php

declare(strict_types=1);

use App\Modules\Identity\Http\AcceptInvitationController;
use App\Modules\Identity\Http\ForgotPasswordController;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\MeController;
use App\Modules\Identity\Http\ResetPasswordController;
use Illuminate\Support\Facades\Route;

/*
 * Guardian Console session authentication (ADR 0016), under /api/v1.
 *
 * The `stateful` group (bootstrap/app.php) is what makes these cookie-authenticated:
 * cookies, the database session, CSRF protection and the absolute session lifetime.
 * The rest of the API stays stateless, so the public health endpoint and, later,
 * service clients never receive a session or need a CSRF token.
 */
Route::middleware('stateful')->group(function (): void {
    Route::post('login', LoginController::class)->name('api.v1.login');
    Route::post('logout', LogoutController::class)->name('api.v1.logout');
    Route::get('me', MeController::class)->middleware('auth:web')->name('api.v1.me');
});

/*
 * Credential lifecycle, public endpoints. Deliberately OUTSIDE the `stateful` group: the caller has
 * no session and no account to forge a request as, so no cookie, session row or CSRF token is
 * involved, and the secret they present travels in the request BODY (never a URL, so routine access
 * logs do not capture it).
 */
Route::post('invitations/accept', AcceptInvitationController::class)->name('api.v1.invitations.accept');
Route::post('password/forgot', ForgotPasswordController::class)->name('api.v1.password.forgot');
Route::post('password/reset', ResetPasswordController::class)->name('api.v1.password.reset');
