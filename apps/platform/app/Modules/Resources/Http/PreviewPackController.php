<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\PreviewPack;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class PreviewPackController
{
    public function __invoke(PreviewPackRequest $request, string $pack, PreviewPack $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->preview($use($actors->for($request), PackId::fromString($pack), $request->audience())), 200);
    }
}
