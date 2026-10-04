<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\RenameCategory;
use App\Modules\Resources\Domain\CategoryId;
use Illuminate\Http\JsonResponse;

final readonly class RenameCategoryController
{
    public function __invoke(CategoryNameRequest $request, string $category, RenameCategory $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->category($use($actors->for($request), CategoryId::fromString($category), $request->name())), 200);
    }
}
