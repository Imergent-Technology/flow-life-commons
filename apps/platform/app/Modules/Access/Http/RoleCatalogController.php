<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\ListRoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The system roles, from Access, as data. The Console renders them and defines none of its own. */
final readonly class RoleCatalogController
{
    public function __invoke(Request $request, ListRoleCatalog $catalog, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->catalog($catalog($actors->for($request))));
    }
}
