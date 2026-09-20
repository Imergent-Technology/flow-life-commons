<?php

declare(strict_types=1);

use App\Modules\Identity\Http\AcceptInvitationController;
use App\Modules\Identity\Http\AuthenticatorConfirmController;
use App\Modules\Identity\Http\AuthenticatorController;
use App\Modules\Identity\Http\ChangePasswordController;
use App\Modules\Identity\Http\ForgotPasswordController;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\MeController;
use App\Modules\Identity\Http\MfaChallengeController;
use App\Modules\Identity\Http\MfaEnrollmentConfirmController;
use App\Modules\Identity\Http\MfaEnrollmentController;
use App\Modules\Identity\Http\RecoveryCodesController;
use App\Modules\Identity\Http\ResetPasswordController;
use App\Modules\Identity\Http\SecurityVerificationController;
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
    // Authenticated, so on the session surface with CSRF. Any signed-in Account may change its own
    // password: authentication is the whole requirement, plus the current password in the body.
    Route::post('password/change', ChangePasswordController::class)->middleware('auth:web')->name('api.v1.password.change');

    /*
     * Multi-factor authentication (ADR 0023).
     *
     * The first three finish a sign-in whose PASSWORD was proved: they are on the session surface (cookie and
     * CSRF apply) but deliberately NOT behind `auth:web`, because what they need is the pending sign-in held in
     * the session, which is not authentication. They are not stateless bearer endpoints, and they take no
     * identity from the client.
     */
    Route::post('mfa/challenge', MfaChallengeController::class)->name('api.v1.mfa.challenge');
    Route::post('mfa/enrollment', MfaEnrollmentController::class)->name('api.v1.mfa.enrollment');
    Route::post('mfa/enrollment/confirm', MfaEnrollmentConfirmController::class)->name('api.v1.mfa.enrollment.confirm');

    // Authenticated. Each asks for fresh proof in its own body (current password AND a second factor); the
    // session alone never exposes or changes a credential.
    Route::middleware('auth:web')->group(function (): void {
        Route::post('mfa/recovery-codes', RecoveryCodesController::class)->name('api.v1.mfa.recovery-codes');
        Route::post('mfa/authenticator', AuthenticatorController::class)->name('api.v1.mfa.authenticator');
        Route::post('mfa/authenticator/confirm', AuthenticatorConfirmController::class)->name('api.v1.mfa.authenticator.confirm');
        Route::post('security/verify', SecurityVerificationController::class)->name('api.v1.security.verify');
    });
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
