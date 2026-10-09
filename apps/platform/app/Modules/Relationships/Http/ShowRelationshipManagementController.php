<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ReadRelationshipManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowRelationshipManagementController
{
    public function __invoke(Request $request, ReadRelationshipManagement $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->management($use($actors->for($request), $routed->type($request), $routed->person($request))));
    }
}
