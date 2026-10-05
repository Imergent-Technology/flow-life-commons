<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Http\JsonResponse;

final readonly class CreateCardController
{
    public function __invoke(CreateCardRequest $request, string $pack, CreateCard $use, RequestActor $actors, ResourcesPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->card($use(
            $actors->for($request), PackId::fromString($pack), $request->type(), $request->title(), $request->content(), $request->address(), $request->summary(), $request->upload(),
        )), 201);
    }
}
