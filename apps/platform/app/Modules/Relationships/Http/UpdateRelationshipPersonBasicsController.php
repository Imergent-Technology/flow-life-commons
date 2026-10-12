<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\UpdateRelationshipPersonBasics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PATCH .../basics. Generated only for a type whose definition enables `people.edit_basics` (ADR 0038, P5, Part H). */
final readonly class UpdateRelationshipPersonBasicsController
{
    public function __invoke(Request $request, UpdateRelationshipPersonBasics $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        $view = $use(
            $actors->for($request),
            $routed->type($request),
            $routed->person($request),
            RelationshipInput::instance($request->input('relationship_id')),
            RelationshipInput::bodyWithout($request, 'relationship_id'),
        );

        return response()->json($presenter->management($view));
    }
}
