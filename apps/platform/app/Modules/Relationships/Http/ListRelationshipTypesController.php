<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\DescribeRelationshipTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ListRelationshipTypesController
{
    public function __invoke(Request $request, DescribeRelationshipTypes $use, RequestActor $actors, RelationshipsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->types($use($actors->for($request))));
    }
}
