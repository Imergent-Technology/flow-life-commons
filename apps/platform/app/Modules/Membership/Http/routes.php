<?php

declare(strict_types=1);

use App\Modules\Membership\Http\GrantMembershipController;
use App\Modules\Membership\Http\ListMembersController;
use App\Modules\Membership\Http\RegisterMemberController;
use App\Modules\Membership\Http\RevokeMembershipGrantController;
use App\Modules\Membership\Http\ShowMemberController;
use Illuminate\Support\Facades\Route;

/*
 * Operator administration of membership records (ADR 0028, Work Package 5), under /api/v1/admin.
 * The same three independent layers as Access\Http\routes.php:
 *
 * - `stateful`, `auth:web` and `can:console.access` for the whole surface.
 * - `can:<capability>` per operation, OUTSIDE `security.verified`, so a caller without the
 *   capability is refused before being asked to prove anything. The use case checks it again.
 * - `security.verified` on every mutation. Reads need the capability, not a fresh proof.
 *
 * A test walks this table and fails a route that is missing any of them.
 *
 * The Console concept is "Members"; the backend truth is a Person with membership grant history
 * (ADR 0028). There is no stored Member aggregate, so `/members` addresses that composed view, and
 * `/membership-grants/{grant}/revoke` addresses the one thing revocation actually targets: a grant,
 * not the Person, since membership itself is derived and has no "end membership" mutation of its own.
 */
Route::middleware(['stateful', 'auth:web', 'can:console.access'])->prefix('admin')->group(function (): void {
    // Exactly what `Str::isUlid` accepts, in the lowercase form ids are held in: the first character 0-7 and no i, l, o
    // or u. A looser `[0-9a-z]{26}` lets a malformed id through to the value object, which throws (a 500) instead of
    // the route simply not matching (a 404).
    $person = '[0-7][0-9a-hjkmnp-tv-z]{25}';
    $grant = '[0-7][0-9a-hjkmnp-tv-z]{25}';

    Route::middleware('can:membership.records.view')->group(function () use ($person): void {
        Route::get('members', ListMembersController::class)->name('api.v1.admin.members.index');
        Route::get('members/{person}', ShowMemberController::class)->where('person', $person)->name('api.v1.admin.members.show');
    });

    Route::middleware('can:membership.records.manage')->group(function () use ($person, $grant): void {
        Route::post('members', RegisterMemberController::class)->middleware('security.verified')->name('api.v1.admin.members.store');
        Route::post('members/{person}/grants', GrantMembershipController::class)->where('person', $person)->middleware('security.verified')->name('api.v1.admin.members.grants.store');
        Route::post('membership-grants/{grant}/revoke', RevokeMembershipGrantController::class)->where('grant', $grant)->middleware('security.verified')->name('api.v1.admin.membership-grants.revoke');
    });
});
