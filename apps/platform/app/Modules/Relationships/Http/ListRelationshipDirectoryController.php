<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ListRelationshipDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ListRelationshipDirectoryController
{
    public function __invoke(Request $request, ListRelationshipDirectory $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        $status = $request->query('status');
        $query = $request->query('q');
        $page = RelationshipInput::wholeNumber($request->query('page', 1)) ?? 0;
        $perPage = RelationshipInput::wholeNumber($request->query('per_page', 25)) ?? 0;

        return response()->json($presenter->directory($use(
            $actors->for($request),
            $routed->type($request),
            is_string($status) && $status !== '' ? $status : null,
            is_string($query) && trim($query) !== '' ? $query : null,
            $page,
            $perPage,
        )));
    }
}
