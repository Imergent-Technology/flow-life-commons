<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\RenameTag;
use App\Modules\Crm\Domain\ContactTagId;
use Illuminate\Http\JsonResponse;

final readonly class RenameTagController
{
    public function __invoke(TagRequest $request, string $tag, RenameTag $rename, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->tag($rename($actors->for($request), ContactTagId::fromString($tag), $request->name())));
    }
}
