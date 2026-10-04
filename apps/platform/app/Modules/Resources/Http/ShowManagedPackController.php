<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\GetManagedPack;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowManagedPackController
{
    public function __invoke(Request $request, string $pack, GetManagedPack $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->pack($use($actors->for($request), PackId::fromString($pack))), 200);
    }
}
