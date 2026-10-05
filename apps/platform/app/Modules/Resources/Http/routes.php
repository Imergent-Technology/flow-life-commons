<?php

declare(strict_types=1);

use App\Modules\Resources\Http\BrowseLibraryController;
use App\Modules\Resources\Http\CreateCardController;
use App\Modules\Resources\Http\CreateCategoryController;
use App\Modules\Resources\Http\CreatePackController;
use App\Modules\Resources\Http\DeleteCardController;
use App\Modules\Resources\Http\DeleteCategoryController;
use App\Modules\Resources\Http\DeletePackController;
use App\Modules\Resources\Http\DownloadLibraryFileController;
use App\Modules\Resources\Http\DownloadManagedFileController;
use App\Modules\Resources\Http\ListCategoriesController;
use App\Modules\Resources\Http\ListManagedPacksController;
use App\Modules\Resources\Http\PreviewPackController;
use App\Modules\Resources\Http\PublishCardController;
use App\Modules\Resources\Http\PublishPackController;
use App\Modules\Resources\Http\RenameCategoryController;
use App\Modules\Resources\Http\ReorderCardsController;
use App\Modules\Resources\Http\ReorderCategoriesController;
use App\Modules\Resources\Http\ReorderPacksController;
use App\Modules\Resources\Http\ReplaceCardFileController;
use App\Modules\Resources\Http\SetCardAudiencesController;
use App\Modules\Resources\Http\SetPackAudiencesController;
use App\Modules\Resources\Http\ShowLibraryPackController;
use App\Modules\Resources\Http\ShowManagedCardController;
use App\Modules\Resources\Http\ShowManagedPackController;
use App\Modules\Resources\Http\UnpublishCardController;
use App\Modules\Resources\Http\UnpublishPackController;
use App\Modules\Resources\Http\UpdateCardController;
use App\Modules\Resources\Http\UpdatePackController;
use Illuminate\Support\Facades\Route;

/*
 * Resources (ADR 0037), under /api/v1/admin. Management and delivery are separate prefixes with separate capabilities and
 * separate projections, so each can be pinned to its capability by a route-table test:
 *
 * - /admin/resources/*          MANAGEMENT. `resources.manage`. Drafts, revisions, audiences, order, provenance.
 * - /admin/resource-library/*   DELIVERY to the Guardian Console. `resources.view`. Only what the viewer may have.
 *
 * Three independent layers, as in Crm, Discussions and Membership:
 *
 * - `stateful`, `auth:web` and `can:console.access` for the whole surface.
 * - `can:<capability>` per operation, and the use case checks it again.
 * - Routine management asks for NO recent verification (the administration route-table test names `resources.manage`, pinned
 *   to this module, beside Crm's and Discussions' capabilities). The ONE exception is permanent deletion: the two DELETE routes
 *   for a Pack and a Card also carry `security.verified`, after the capability, so a caller without the capability is never asked
 *   to prove anything and a stolen session cannot destroy content. A Resources route-table test pins exactly those two. The
 *   exemption is NOT generic: it covers this module's `resources.manage` routes only, and deleting an EMPTY Category (it loses a
 *   name, not content) is routine.
 *
 * The actor is always the signed-in caller: no request field names a creator, an editor or an uploader.
 *
 * Files (WP3) follow the same split. A File Card's file is replaced and downloaded (Drafts included) under management, and downloaded
 * by a viewer under delivery only for a Card the projection shows them. Both are routes on a Card: no route takes an asset id or a
 * storage key, and nothing of the store is reachable any other way. Replacing a file is routine (no recent verification).
 */
Route::middleware(['stateful', 'auth:web', 'can:console.access'])->prefix('admin')->group(function (): void {
    // Exactly what `Str::isUlid` accepts, in the lowercase form ids are held in (see Membership's routes).
    $id = '[0-7][0-9a-hjkmnp-tv-z]{25}';

    Route::middleware('can:resources.manage')->prefix('resources')->group(function () use ($id): void {
        Route::get('categories', ListCategoriesController::class)->name('api.v1.admin.resources.categories.index');
        Route::post('categories', CreateCategoryController::class)->name('api.v1.admin.resources.categories.store');
        Route::put('categories/order', ReorderCategoriesController::class)->name('api.v1.admin.resources.categories.order');
        Route::patch('categories/{category}', RenameCategoryController::class)->where('category', $id)->name('api.v1.admin.resources.categories.update');
        Route::delete('categories/{category}', DeleteCategoryController::class)->where('category', $id)->name('api.v1.admin.resources.categories.destroy');
        Route::put('categories/{category}/pack-order', ReorderPacksController::class)->where('category', $id)->name('api.v1.admin.resources.categories.pack-order');

        Route::get('packs', ListManagedPacksController::class)->name('api.v1.admin.resources.packs.index');
        Route::post('packs', CreatePackController::class)->name('api.v1.admin.resources.packs.store');
        Route::get('packs/{pack}', ShowManagedPackController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.show');
        Route::patch('packs/{pack}', UpdatePackController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.update');
        Route::put('packs/{pack}/audiences', SetPackAudiencesController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.audiences');
        Route::post('packs/{pack}/publish', PublishPackController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.publish');
        Route::post('packs/{pack}/unpublish', UnpublishPackController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.unpublish');
        Route::get('packs/{pack}/preview', PreviewPackController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.preview');
        Route::put('packs/{pack}/card-order', ReorderCardsController::class)->where('pack', $id)->name('api.v1.admin.resources.packs.card-order');
        // Permanent deletion: the capability above, THEN recent verification (a refused caller is never asked to prove anything).
        Route::delete('packs/{pack}', DeletePackController::class)->where('pack', $id)->middleware('security.verified')->name('api.v1.admin.resources.packs.destroy');

        Route::post('packs/{pack}/cards', CreateCardController::class)->where('pack', $id)->name('api.v1.admin.resources.cards.store');
        Route::get('packs/{pack}/cards/{card}', ShowManagedCardController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.show');
        Route::patch('packs/{pack}/cards/{card}', UpdateCardController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.update');
        Route::put('packs/{pack}/cards/{card}/audiences', SetCardAudiencesController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.audiences');
        Route::post('packs/{pack}/cards/{card}/publish', PublishCardController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.publish');
        Route::post('packs/{pack}/cards/{card}/unpublish', UnpublishCardController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.unpublish');
        Route::delete('packs/{pack}/cards/{card}', DeleteCardController::class)->where('pack', $id)->where('card', $id)->middleware('security.verified')->name('api.v1.admin.resources.cards.destroy');
        Route::post('packs/{pack}/cards/{card}/file', ReplaceCardFileController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.file.replace');
        Route::get('packs/{pack}/cards/{card}/file', DownloadManagedFileController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resources.cards.file');
    });

    Route::middleware('can:resources.view')->prefix('resource-library')->group(function () use ($id): void {
        Route::get('/', BrowseLibraryController::class)->name('api.v1.admin.resource-library.index');
        Route::get('packs/{pack}', ShowLibraryPackController::class)->where('pack', $id)->name('api.v1.admin.resource-library.show');
        Route::get('packs/{pack}/cards/{card}/file', DownloadLibraryFileController::class)->where('pack', $id)->where('card', $id)->name('api.v1.admin.resource-library.cards.file');
    });
});
