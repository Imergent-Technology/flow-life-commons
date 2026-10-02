<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\StartDiscussion;
use Illuminate\Http\JsonResponse;

final readonly class StartDiscussionController
{
    public function __invoke(StartDiscussionRequest $request, StartDiscussion $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->discussion($use($actors->for($request), $request->title(), $request->body())), 201);
    }
}
