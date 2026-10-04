<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\PageManagedPacks;
use Illuminate\Http\JsonResponse;

final readonly class ListManagedPacksController
{
    public function __invoke(ListManagedPacksRequest $request, PageManagedPacks $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->packs($use($actors->for($request), $request->filter(), $request->page(), $request->perPage())), 200);
    }
}
