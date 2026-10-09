<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ReadRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowRelationshipController
{
    public function __invoke(Request $request, ReadRelationship $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->record($use($actors->for($request), $routed->type($request), $routed->person($request))));
    }
}
