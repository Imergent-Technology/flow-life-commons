<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\PagePeopleDirectory;
use Illuminate\Http\JsonResponse;

final readonly class ListPeopleController
{
    public function __invoke(ListPeopleRequest $request, PagePeopleDirectory $page, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->directory(
            $page($actors->for($request), $request->text(), $request->tag(), $request->page(), $request->perPage()),
        ));
    }
}
