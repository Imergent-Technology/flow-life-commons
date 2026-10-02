<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\ListInteractions;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class ListInteractionsController
{
    public function __invoke(ListInteractionsRequest $request, string $person, ListInteractions $list, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->interactions(
            $list($actors->for($request), PersonId::fromString($person), $request->page(), $request->perPage()),
        ));
    }
}
