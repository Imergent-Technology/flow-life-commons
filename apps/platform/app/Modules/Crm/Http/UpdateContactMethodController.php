<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class UpdateContactMethodController
{
    public function __invoke(UpdateContactMethodRequest $request, string $person, string $method, UpdateContactMethod $update, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->method(
            $update($actors->for($request), PersonId::fromString($person), ContactMethodId::fromString($method), $request->changes()),
        ));
    }
}
