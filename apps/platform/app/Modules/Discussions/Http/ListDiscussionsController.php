<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\PageDiscussions;
use Illuminate\Http\JsonResponse;

final readonly class ListDiscussionsController
{
    public function __invoke(ListDiscussionsRequest $request, PageDiscussions $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->discussions($use($actors->for($request), $request->state(), $request->text(), $request->page(), $request->perPage())), 200);
    }
}
