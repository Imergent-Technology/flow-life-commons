<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\PageDiscussionMessages;
use App\Modules\Discussions\Domain\DiscussionId;
use Illuminate\Http\JsonResponse;

final readonly class ListMessagesController
{
    public function __invoke(ListMessagesRequest $request, string $discussion, PageDiscussionMessages $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->messages($use($actors->for($request), DiscussionId::fromString($discussion), $request->page(), $request->perPage())), 200);
    }
}
