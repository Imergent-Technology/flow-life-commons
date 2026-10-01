<?php

declare(strict_types=1);

use App\Modules\Crm\Http\AddContactMethodController;
use App\Modules\Crm\Http\CreateTagController;
use App\Modules\Crm\Http\DeleteTagController;
use App\Modules\Crm\Http\ListPeopleController;
use App\Modules\Crm\Http\ListTagsController;
use App\Modules\Crm\Http\RegisterContactController;
use App\Modules\Crm\Http\RemoveContactMethodController;
use App\Modules\Crm\Http\RenameTagController;
use App\Modules\Crm\Http\SetPersonTagsController;
use App\Modules\Crm\Http\ShowPersonController;
use App\Modules\Crm\Http\UpdateContactMethodController;
use App\Modules\Crm\Http\UpdatePersonController;
use Illuminate\Support\Facades\Route;

/*
 * The People directory and what CRM holds about each Person (ADR 0034), under /api/v1/admin. The product word is
 * "People", so the resource is `people`; the module is called Crm because that is what it is. Access already keeps
 * two Person-keyed routes under /admin/people/{person} (the Commons invitation and Commons access); every module owns
 * its own routes, and the paths do not overlap.
 *
 * Three independent layers, as in Access and Membership:
 *
 * - `stateful`, `auth:web` and `can:console.access` for the whole surface.
 * - `can:<capability>` per operation, and the use case checks it again.
 * - NO `security.verified`. This is the one administration surface where mutations do not ask for a fresh password
 *   and second factor, on purpose: nothing here grants or removes authority (ADR 0034). Editing a note about how we
 *   know someone, a phone number, a tag or a typo in a name is routine maintenance, and making it a step-up would
 *   only make it unusable. A test names this exemption, so it cannot spread to another surface by accident.
 *
 * Mutating responses return the resource that was written, never a re-read of everything CRM holds, so a caller who
 * may manage but not view learns nothing more from writing than from not writing.
 */
Route::middleware(['stateful', 'auth:web', 'can:console.access'])->prefix('admin')->group(function (): void {
    // Exactly what `Str::isUlid` accepts, in the lowercase form ids are held in (see Membership's routes).
    $id = '[0-7][0-9a-hjkmnp-tv-z]{25}';

    Route::middleware('can:crm.people.view')->group(function () use ($id): void {
        Route::get('people', ListPeopleController::class)->name('api.v1.admin.people.index');
        Route::get('people/{person}', ShowPersonController::class)->where('person', $id)->name('api.v1.admin.people.show');
        Route::get('contact-tags', ListTagsController::class)->name('api.v1.admin.contact-tags.index');
    });

    Route::middleware('can:crm.people.manage')->group(function () use ($id): void {
        Route::post('people', RegisterContactController::class)->name('api.v1.admin.people.store');
        Route::patch('people/{person}', UpdatePersonController::class)->where('person', $id)->name('api.v1.admin.people.update');
        Route::post('people/{person}/contact-methods', AddContactMethodController::class)->where('person', $id)->name('api.v1.admin.people.contact-methods.store');
        Route::patch('people/{person}/contact-methods/{method}', UpdateContactMethodController::class)->where('person', $id)->where('method', $id)->name('api.v1.admin.people.contact-methods.update');
        Route::delete('people/{person}/contact-methods/{method}', RemoveContactMethodController::class)->where('person', $id)->where('method', $id)->name('api.v1.admin.people.contact-methods.destroy');
        Route::put('people/{person}/tags', SetPersonTagsController::class)->where('person', $id)->name('api.v1.admin.people.tags.update');
        Route::post('contact-tags', CreateTagController::class)->name('api.v1.admin.contact-tags.store');
        Route::patch('contact-tags/{tag}', RenameTagController::class)->where('tag', $id)->name('api.v1.admin.contact-tags.update');
        Route::delete('contact-tags/{tag}', DeleteTagController::class)->where('tag', $id)->name('api.v1.admin.contact-tags.destroy');
    });
});
