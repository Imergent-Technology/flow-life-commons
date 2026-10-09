<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\UpdateRelationshipFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class UpdateRelationshipFieldsController
{
    public function __invoke(Request $request, UpdateRelationshipFields $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        $view = $use(
            $actors->for($request),
            $routed->type($request),
            $routed->person($request),
            RelationshipInput::instance($request->input('relationship_id')),
            RelationshipInput::wholeNumber($request->input('revision')) ?? -1,
            RelationshipInput::fields($request),
        );

        return response()->json($presenter->management($view));
    }
}
