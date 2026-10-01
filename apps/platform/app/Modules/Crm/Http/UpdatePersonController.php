<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\UpdatePerson;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class UpdatePersonController
{
    public function __invoke(UpdatePersonRequest $request, string $person, UpdatePerson $update, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->update(
            $update($actors->for($request), PersonId::fromString($person), $request->displayName(), $request->changes()),
        ));
    }
}
