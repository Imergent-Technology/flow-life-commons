<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\RetitleOwnDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use Illuminate\Http\JsonResponse;

final readonly class RetitleDiscussionController
{
    public function __invoke(RetitleDiscussionRequest $request, string $discussion, RetitleOwnDiscussion $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->discussion($use($actors->for($request), DiscussionId::fromString($discussion), $request->title())), 200);
    }
}
