<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\EditInteraction;
use App\Modules\Crm\Domain\InteractionId;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class EditInteractionController
{
    public function __invoke(EditInteractionRequest $request, string $person, string $interaction, EditInteraction $edit, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->interaction(
            $edit($actors->for($request), PersonId::fromString($person), InteractionId::fromString($interaction), $request->changes()),
        ));
    }
}
