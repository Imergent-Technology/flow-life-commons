<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\ReplaceCardFile;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class ReplaceCardFileController
{
    public function __invoke(ReplaceCardFileRequest $request, string $pack, string $card, ReplaceCardFile $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->card($use($actors->for($request), PackId::fromString($pack), CardId::fromString($card), $request->upload())), 200);
    }
}
