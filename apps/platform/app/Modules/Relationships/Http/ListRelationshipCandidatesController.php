<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ListRelationshipCandidates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ListRelationshipCandidatesController
{
    public function __invoke(Request $request, ListRelationshipCandidates $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        $query = $request->query('q');

        return response()->json($presenter->candidates($use(
            $actors->for($request),
            $routed->type($request),
            is_string($query) ? $query : '',
        )));
    }
}
