<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\CreateCategory;
use Illuminate\Http\JsonResponse;

final readonly class CreateCategoryController
{
    public function __invoke(CategoryNameRequest $request, CreateCategory $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->category($use($actors->for($request), $request->name())), 201);
    }
}
