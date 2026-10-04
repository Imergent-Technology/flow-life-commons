<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ListCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ListCategoriesController
{
    public function __invoke(Request $request, ListCategories $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->categories($use($actors->for($request))), 200);
    }
}
