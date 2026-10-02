<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\RemoveInteraction;
use App\Modules\Crm\Domain\InteractionId;
use App\Shared\Domain\PersonId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class RemoveInteractionController
{
    public function __invoke(Request $request, string $person, string $interaction, RemoveInteraction $remove, RequestActor $actors): Response
    {
        $remove($actors->for($request), PersonId::fromString($person), InteractionId::fromString($interaction));

        return response()->noContent();
    }
}
