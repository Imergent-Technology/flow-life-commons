<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\RegisterContact;
use Illuminate\Http\JsonResponse;

/** Registers a new Person with their first CRM information. The response is built from what this one use case returned. */
final readonly class RegisterContactController
{
    public function __invoke(RegisterContactRequest $request, RegisterContact $register, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        $record = $register(
            $actors->for($request),
            $request->displayName(),
            $request->howWeKnow(),
            $request->affiliation(),
            $request->contactMethods(),
            $request->confirmDistinct(),
        );

        return response()->json($presenter->record($record), 201);
    }
}
