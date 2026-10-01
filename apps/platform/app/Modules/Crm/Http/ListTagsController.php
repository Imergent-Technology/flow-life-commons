<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\ListTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ListTagsController
{
    public function __invoke(Request $request, ListTags $list, RequestActor $actors, CrmPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->tags($list($actors->for($request))));
    }
}
