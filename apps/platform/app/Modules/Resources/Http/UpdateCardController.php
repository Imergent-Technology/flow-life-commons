<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class UpdateCardController
{
    public function __invoke(UpdateCardRequest $request, string $pack, string $card, UpdateCard $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->card($use($actors->for($request), PackId::fromString($pack), CardId::fromString($card), $request->revision(), $request->changes())), 200);
    }
}
