<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\UpdatePack;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class UpdatePackController
{
    public function __invoke(UpdatePackRequest $request, string $pack, UpdatePack $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->pack($use($actors->for($request), PackId::fromString($pack), $request->revision(), $request->changes())), 200);
    }
}
