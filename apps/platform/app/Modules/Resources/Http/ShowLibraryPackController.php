<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ReadLibraryPack;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowLibraryPackController
{
    public function __invoke(Request $request, string $pack, ReadLibraryPack $use, RequestActor $actors, ViewerPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->pack($use($actors->for($request), PackId::fromString($pack))), 200);
    }
}
