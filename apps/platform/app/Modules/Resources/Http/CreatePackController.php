<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Domain\CategoryId;
use Illuminate\Http\JsonResponse;

final readonly class CreatePackController
{
    public function __invoke(CreatePackRequest $request, CreatePack $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        $category = $request->category();

        return response()->json($presenter->pack($use($actors->for($request), $request->title(), $request->summary(), $request->isSeries(), $category === null ? null : CategoryId::fromString($category))), 201);
    }
}
