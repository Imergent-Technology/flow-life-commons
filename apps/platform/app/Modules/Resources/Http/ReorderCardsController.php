<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class ReorderCardsController
{
    public function __invoke(OrderRequest $request, string $pack, ReorderCards $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->pack($use($actors->for($request), PackId::fromString($pack), array_map(CardId::fromString(...), $request->ids()))), 200);
    }
}
