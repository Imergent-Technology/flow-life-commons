<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\CreateTag;
use Illuminate\Http\JsonResponse;

final readonly class CreateTagController
{
    public function __invoke(TagRequest $request, CreateTag $create, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->tag($create($actors->for($request), $request->name())), 201);
    }
}
