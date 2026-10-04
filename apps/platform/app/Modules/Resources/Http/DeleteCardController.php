<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DeleteCardController
{
    public function __invoke(Request $request, string $pack, string $card, DeleteCard $use, RequestActor $actors): JsonResponse
    {
        $use($actors->for($request), PackId::fromString($pack), CardId::fromString($card));

        return response()->json(null, 204);
    }
}
