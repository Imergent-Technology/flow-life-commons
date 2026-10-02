<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use Illuminate\Http\JsonResponse;

final readonly class ReplyToDiscussionController
{
    public function __invoke(MessageBodyRequest $request, string $discussion, ReplyToDiscussion $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->message($use($actors->for($request), DiscussionId::fromString($discussion), $request->body())), 201);
    }
}
