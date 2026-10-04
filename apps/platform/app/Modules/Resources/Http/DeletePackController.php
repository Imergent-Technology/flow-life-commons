<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\DeletePack;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DeletePackController
{
    public function __invoke(Request $request, string $pack, DeletePack $use, RequestActor $actors): JsonResponse
    {
        $use($actors->for($request), PackId::fromString($pack));

        return response()->json(null, 204);
    }
}
