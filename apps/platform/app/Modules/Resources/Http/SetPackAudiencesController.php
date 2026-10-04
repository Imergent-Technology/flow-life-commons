<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class SetPackAudiencesController
{
    public function __invoke(AudiencesRequest $request, string $pack, SetPackAudiences $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->pack($use($actors->for($request), PackId::fromString($pack), $request->audiences())), 200);
    }
}
