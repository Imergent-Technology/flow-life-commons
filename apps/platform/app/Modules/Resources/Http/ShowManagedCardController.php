<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\GetManagedCard;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowManagedCardController
{
    public function __invoke(Request $request, string $pack, string $card, GetManagedCard $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->card($use($actors->for($request), PackId::fromString($pack), CardId::fromString($card))), 200);
    }
}
