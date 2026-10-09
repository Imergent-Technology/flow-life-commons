<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ChangeRelationshipStatusController
{
    public function __invoke(Request $request, ChangeRelationshipStatus $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        $status = $request->input('status');
        $view = $use(
            $actors->for($request),
            $routed->type($request),
            $routed->person($request),
            RelationshipInput::instance($request->input('relationship_id')),
            RelationshipInput::wholeNumber($request->input('revision')) ?? -1,
            is_string($status) ? $status : '',
            $request->exists('default_role'),
        );

        return response()->json($presenter->management($view));
    }
}
