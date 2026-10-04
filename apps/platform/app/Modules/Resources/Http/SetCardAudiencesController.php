<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class SetCardAudiencesController
{
    public function __invoke(CardAudiencesRequest $request, string $pack, string $card, SetCardAudiences $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->card($use($actors->for($request), PackId::fromString($pack), CardId::fromString($card), $request->mode(), $request->audiences())), 200);
    }
}
