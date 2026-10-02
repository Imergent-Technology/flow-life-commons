<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\RecordInteraction;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class RecordInteractionController
{
    public function __invoke(RecordInteractionRequest $request, string $person, RecordInteraction $record, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->interaction($record($actors->for($request), PersonId::fromString($person), $request->newInteraction())), 201);
    }
}
