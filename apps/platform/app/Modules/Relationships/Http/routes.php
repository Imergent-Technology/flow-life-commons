<?php

declare(strict_types=1);

use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Http\ChangeRelationshipStatusController;
use App\Modules\Relationships\Http\DeleteRelationshipController;
use App\Modules\Relationships\Http\EstablishRelationshipController;
use App\Modules\Relationships\Http\ListRelationshipCandidatesController;
use App\Modules\Relationships\Http\ListRelationshipDirectoryController;
use App\Modules\Relationships\Http\ListRelationshipTypesController;
use App\Modules\Relationships\Http\ShowRelationshipController;
use App\Modules\Relationships\Http\ShowRelationshipManagementController;
use App\Modules\Relationships\Http\UpdateRelationshipFieldsController;
use Illuminate\Support\Facades\Route;

/*
 * Organizational relationships (ADR 0038), under /api/v1/admin.
 *
 * Routes are generated from the catalog, one concrete slug and one concrete capability each.
 * The type is the route's, never the body's. `candidates` is a literal, and `{person}` is a ULID,
 * so the lookup cannot be captured as a Person id.
 *
 * Recent verification follows A5, from each definition's `verification` list:
 * Guardian intake, status and deletion; every type's deletion; never a field edit.
 * `can:guardians.manage` and `can:volunteers.manage` are step-up exemptions pinned to this module,
 * so a route under them may omit `security.verified`. The routes that still carry it are the table.
 *
 * `GET relationship-types` carries only Console admission (A6). No default-role route and no basics
 * route: those are WP2B and WP3.
 */

$catalog = app(RelationshipCatalog::class);
$person = '[0-7][0-9a-hjkmnp-tv-z]{25}';

Route::middleware(['stateful', 'auth:web', 'can:console.access'])->prefix('admin')->group(function () use ($catalog, $person): void {
    Route::get('relationship-types', ListRelationshipTypesController::class)->name('api.v1.admin.relationship-types');

    foreach ($catalog->all() as $definition) {
        $slug = $definition->slug;
        $view = 'can:'.$definition->viewCapability->value;
        $manage = 'can:'.$definition->manageCapability->value;
        $type = $definition->type->key;
        $verified = fn (string $operation): array => $definition->verifies($operation) ? ['security.verified'] : [];

        Route::middleware($view)->group(function () use ($slug, $person, $type): void {
            Route::get("relationships/{$slug}", ListRelationshipDirectoryController::class)
                ->defaults('relationship_type', $type)
                ->name("api.v1.admin.relationships.{$slug}.index");
            Route::get("relationships/{$slug}/{person}", ShowRelationshipController::class)
                ->where('person', $person)
                ->defaults('relationship_type', $type)
                ->name("api.v1.admin.relationships.{$slug}.show");
        });

        Route::middleware($manage)->group(function () use ($slug, $person, $type, $definition, $verified): void {
            Route::get("relationships/{$slug}/{person}/management", ShowRelationshipManagementController::class)
                ->where('person', $person)
                ->defaults('relationship_type', $type)
                ->name("api.v1.admin.relationships.{$slug}.management");
            if ($definition->hasFeature('people.lookup')) {
                Route::get("relationships/{$slug}/candidates", ListRelationshipCandidatesController::class)
                    ->defaults('relationship_type', $type)
                    ->name("api.v1.admin.relationships.{$slug}.candidates");
            }
            Route::post("relationships/{$slug}", EstablishRelationshipController::class)
                ->defaults('relationship_type', $type)
                ->middleware($verified('intake'))
                ->name("api.v1.admin.relationships.{$slug}.store");
            Route::put("relationships/{$slug}/{person}/status", ChangeRelationshipStatusController::class)
                ->where('person', $person)
                ->defaults('relationship_type', $type)
                ->middleware($verified('status'))
                ->name("api.v1.admin.relationships.{$slug}.status");
            Route::patch("relationships/{$slug}/{person}/fields", UpdateRelationshipFieldsController::class)
                ->where('person', $person)
                ->defaults('relationship_type', $type)
                ->name("api.v1.admin.relationships.{$slug}.fields");
            if ($definition->deletion) {
                Route::delete("relationships/{$slug}/{person}", DeleteRelationshipController::class)
                    ->where('person', $person)
                    ->defaults('relationship_type', $type)
                    ->middleware($verified('delete'))
                    ->name("api.v1.admin.relationships.{$slug}.destroy");
            }
        });
    }
});
