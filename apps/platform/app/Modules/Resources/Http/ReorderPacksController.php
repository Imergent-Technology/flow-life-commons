<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ReorderPacks;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class ReorderPacksController
{
    public function __invoke(OrderRequest $request, string $category, ReorderPacks $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->packList($use($actors->for($request), CategoryId::fromString($category), array_map(PackId::fromString(...), $request->ids()))), 200);
    }
}
