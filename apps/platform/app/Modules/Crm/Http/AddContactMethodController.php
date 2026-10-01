<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\AddContactMethod;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class AddContactMethodController
{
    public function __invoke(AddContactMethodRequest $request, string $person, AddContactMethod $add, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->method($add($actors->for($request), PersonId::fromString($person), $request->newMethod())), 201);
    }
}
