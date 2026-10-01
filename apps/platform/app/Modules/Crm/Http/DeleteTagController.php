<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\DeleteTag;
use App\Modules\Crm\Domain\ContactTagId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class DeleteTagController
{
    public function __invoke(Request $request, string $tag, DeleteTag $delete, RequestActor $actors): Response
    {
        $delete($actors->for($request), ContactTagId::fromString($tag));

        return response()->noContent();
    }
}
