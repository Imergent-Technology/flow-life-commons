<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\GetDeliveredPack;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowMyResourcePackController
{
    public function __invoke(Request $request, string $pack, GetDeliveredPack $use, RequestActor $actors, DeliveryPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->pack($use($actors->for($request), PackId::fromString($pack)), 'api.v1.admin.my-resources.cards.file'), 200);
    }
}
