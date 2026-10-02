<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\EditOwnMessage;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use Illuminate\Http\JsonResponse;

final readonly class EditMessageController
{
    public function __invoke(MessageBodyRequest $request, string $discussion, string $message, EditOwnMessage $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->message($use($actors->for($request), DiscussionId::fromString($discussion), DiscussionMessageId::fromString($message), $request->body())), 200);
    }
}
