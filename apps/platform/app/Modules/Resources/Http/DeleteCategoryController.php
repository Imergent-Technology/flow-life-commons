<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\DeleteCategory;
use App\Modules\Resources\Domain\CategoryId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DeleteCategoryController
{
    public function __invoke(Request $request, string $category, DeleteCategory $use, RequestActor $actors): JsonResponse
    {
        $use($actors->for($request), CategoryId::fromString($category));

        return response()->json(null, 204);
    }
}
