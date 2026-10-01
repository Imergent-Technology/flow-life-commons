<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\SetPersonTags;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class SetPersonTagsController
{
    public function __invoke(SetPersonTagsRequest $request, string $person, SetPersonTags $set, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->personTags($set($actors->for($request), PersonId::fromString($person), $request->tagIds())));
    }
}
