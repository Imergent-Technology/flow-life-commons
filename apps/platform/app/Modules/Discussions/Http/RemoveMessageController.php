<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Application\RemoveOwnMessage;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class RemoveMessageController
{
    public function __invoke(Request $request, string $discussion, string $message, RemoveOwnMessage $use, RequestActor $actors, DiscussionsPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->message($use($actors->for($request), DiscussionId::fromString($discussion), DiscussionMessageId::fromString($message))), 200);
    }
}
