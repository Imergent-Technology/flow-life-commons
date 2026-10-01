<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\RemoveContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Shared\Domain\PersonId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class RemoveContactMethodController
{
    public function __invoke(Request $request, string $person, string $method, RemoveContactMethod $remove, RequestActor $actors): Response
    {
        $remove($actors->for($request), PersonId::fromString($person), ContactMethodId::fromString($method));

        return response()->noContent();
    }
}
