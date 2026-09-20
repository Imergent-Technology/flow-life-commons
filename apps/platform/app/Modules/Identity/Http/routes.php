<?php

declare(strict_types=1);

use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\MeController;
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
