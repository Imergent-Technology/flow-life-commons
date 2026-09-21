<?php

declare(strict_types=1);

use App\Modules\Access\Http\AccountListController;
use App\Modules\Access\Http\AccountShowController;
use App\Modules\Access\Http\DisableAccountController;
use App\Modules\Access\Http\EnableAccountController;
use App\Modules\Access\Http\GrantRoleController;
use App\Modules\Access\Http\InviteOperatorController;
use App\Modules\Access\Http\ReissueInvitationController;
use App\Modules\Access\Http\ResetMfaController;
use App\Modules\Access\Http\RevokeRoleController;
use App\Modules\Access\Http\RoleCatalogController;
use Illuminate\Support\Facades\Route;

/*
 * Operator administration (ADR 0024), under /api/v1/admin. Owned by Access because Access may depend on Identity and
 * Identity may not depend on Access; each operation is an Access use case that authorizes FIRST, then calls Identity.
 *
 * Three layers, each independent of the others:
 *
 * - `stateful`, `auth:web` and `can:console.access` for the whole surface: it is inside the boundary where a second
 *   factor is already required (ADR 0023), whatever a future role bundles.
 * - `can:<capability>` per operation, OUTSIDE `security.verified`, so a caller without the capability is refused
 *   before being asked to prove anything. The use case checks it again, so no other caller can skip it.
 * - `security.verified` on every mutation (`403` with `verification_required: true` when the last password-and-
 *   second-factor proof on this session is older than 15 minutes). Reads need the capability, not a fresh proof.
 *
 * A test walks this table and fails a route that is missing any of them.
 */
Route::middleware(['stateful', 'auth:web', 'can:console.access'])->prefix('admin')->group(function (): void {
    $account = '[0-9a-z]{26}';

    Route::middleware('can:identity.accounts.view')->group(function () use ($account): void {
        Route::get('accounts', AccountListController::class)->name('api.v1.admin.accounts.index');
        Route::get('accounts/{account}', AccountShowController::class)->where('account', $account)->name('api.v1.admin.accounts.show');
        Route::get('roles', RoleCatalogController::class)->name('api.v1.admin.roles.index');
    });

    Route::middleware('can:identity.invitations.issue')->group(function () use ($account): void {
        Route::post('invitations', InviteOperatorController::class)->middleware('security.verified')->name('api.v1.admin.invitations.store');
        Route::post('accounts/{account}/invitation', ReissueInvitationController::class)->where('account', $account)->middleware('security.verified')->name('api.v1.admin.invitations.reissue');
    });

    Route::middleware('can:identity.accounts.manage')->group(function () use ($account): void {
        Route::post('accounts/{account}/disable', DisableAccountController::class)->where('account', $account)->middleware('security.verified')->name('api.v1.admin.accounts.disable');
        Route::post('accounts/{account}/enable', EnableAccountController::class)->where('account', $account)->middleware('security.verified')->name('api.v1.admin.accounts.enable');
    });

    Route::middleware('can:identity.mfa.recover')->group(function () use ($account): void {
        Route::post('accounts/{account}/mfa/reset', ResetMfaController::class)->where('account', $account)->middleware('security.verified')->name('api.v1.admin.mfa.reset');
    });

    Route::middleware('can:access.roles.assign')->group(function () use ($account): void {
        Route::post('accounts/{account}/assignments', GrantRoleController::class)->where('account', $account)->middleware('security.verified')->name('api.v1.admin.assignments.store');
        Route::delete('accounts/{account}/assignments/{key}', RevokeRoleController::class)->where('account', $account)->where('key', '[a-z][a-z0-9_]{0,63}')->middleware('security.verified')->name('api.v1.admin.assignments.destroy');
    });
});
