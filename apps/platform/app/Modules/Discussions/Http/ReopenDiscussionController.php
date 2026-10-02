<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\ReopenDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ReopenDiscussionController
{
    public function __invoke(Request $request, string $discussion, ReopenDiscussion $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->discussion($use($actors->for($request), DiscussionId::fromString($discussion))), 200);
    }
}
