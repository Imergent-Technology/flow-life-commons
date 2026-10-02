<?php

declare(strict_types=1);

use App\Modules\Discussions\Http\EditMessageController;
use App\Modules\Discussions\Http\ListDiscussionsController;
use App\Modules\Discussions\Http\ListMessagesController;
use App\Modules\Discussions\Http\RemoveMessageController;
use App\Modules\Discussions\Http\ReopenDiscussionController;
use App\Modules\Discussions\Http\ReplyToDiscussionController;
use App\Modules\Discussions\Http\ResolveDiscussionController;
use App\Modules\Discussions\Http\RetitleDiscussionController;
use App\Modules\Discussions\Http\ShowDiscussionController;
use App\Modules\Discussions\Http\StartDiscussionController;
use Illuminate\Support\Facades\Route;

/*
 * Guardian Discussions (ADR 0035), under /api/v1/admin.
 *
 * Three independent layers, as in Crm, Access and Membership:
 *
 * - `stateful`, `auth:web` and `can:console.access` for the whole surface.
 * - `can:<capability>` per operation, and the use case checks it again. Reads need `discussions.view`; every change needs
 *   `discussions.participate`, and the use case then checks OWNERSHIP where the change is to someone's own words (edit and
 *   remove a message, retitle a discussion): the capability never means changing another Person's content.
 * - NO `security.verified`. Taking part in a discussion grants and removes no authority, so a fresh password and second
 *   factor for it would only make it unusable (ADR 0035, as ADR 0034 decided for CRM). The administration route-table test
 *   names this capability, and Crm's, as the only two exemptions, each pinned to its own module's routes.
 *
 * The author is always the signed-in caller: no request field names an author, an editor or a resolver.
 */
Route::middleware(['stateful', 'auth:web', 'can:console.access'])->prefix('admin')->group(function (): void {
    // Exactly what `Str::isUlid` accepts, in the lowercase form ids are held in (see Membership's routes).
    $id = '[0-7][0-9a-hjkmnp-tv-z]{25}';

    Route::middleware('can:discussions.view')->group(function () use ($id): void {
        Route::get('discussions', ListDiscussionsController::class)->name('api.v1.admin.discussions.index');
        Route::get('discussions/{discussion}', ShowDiscussionController::class)->where('discussion', $id)->name('api.v1.admin.discussions.show');
        Route::get('discussions/{discussion}/messages', ListMessagesController::class)->where('discussion', $id)->name('api.v1.admin.discussions.messages.index');
    });

    Route::middleware('can:discussions.participate')->group(function () use ($id): void {
        Route::post('discussions', StartDiscussionController::class)->name('api.v1.admin.discussions.store');
        Route::patch('discussions/{discussion}', RetitleDiscussionController::class)->where('discussion', $id)->name('api.v1.admin.discussions.update');
        Route::post('discussions/{discussion}/resolve', ResolveDiscussionController::class)->where('discussion', $id)->name('api.v1.admin.discussions.resolve');
        Route::post('discussions/{discussion}/reopen', ReopenDiscussionController::class)->where('discussion', $id)->name('api.v1.admin.discussions.reopen');
        Route::post('discussions/{discussion}/messages', ReplyToDiscussionController::class)->where('discussion', $id)->name('api.v1.admin.discussions.messages.store');
        Route::patch('discussions/{discussion}/messages/{message}', EditMessageController::class)->where('discussion', $id)->where('message', $id)->name('api.v1.admin.discussions.messages.update');
        Route::delete('discussions/{discussion}/messages/{message}', RemoveMessageController::class)->where('discussion', $id)->where('message', $id)->name('api.v1.admin.discussions.messages.destroy');
    });
});
