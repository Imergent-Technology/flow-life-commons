<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\GetPersonRecord;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowPersonController
{
    public function __invoke(Request $request, string $person, GetPersonRecord $get, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->record($get($actors->for($request), PersonId::fromString($person))));
    }
}
