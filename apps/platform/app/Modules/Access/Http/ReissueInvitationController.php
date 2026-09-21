<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\ReissueOperatorInvitation;
use App\Modules\Identity\Application\ClientContext;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ReissueInvitationController
{
    public function __invoke(Request $request, string $account, ReissueOperatorInvitation $reissue, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->invitation($reissue(
            $actors->for($request),
            AccountId::fromString($account),
            new ClientContext($request->ip(), $request->userAgent()),
        )));
    }
}
