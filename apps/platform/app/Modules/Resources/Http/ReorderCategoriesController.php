<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ReorderCategories;
use App\Modules\Resources\Domain\CategoryId;
use Illuminate\Http\JsonResponse;

final readonly class ReorderCategoriesController
{
    public function __invoke(OrderRequest $request, ReorderCategories $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->categories($use($actors->for($request), array_map(CategoryId::fromString(...), $request->ids()))), 200);
    }
}
