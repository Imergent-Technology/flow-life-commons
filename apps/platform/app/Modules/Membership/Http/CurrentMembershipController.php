<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Application\GetCurrentMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /my/membership`: an authenticated Account's own membership state (ADR 0032). Authentication
 * is the whole requirement — no capability, no `console.access`, and no way to ask about anyone
 * else: the Person comes only from `RequestActor`, resolved from the session, never from the
 * request. A `200` with `active: false` is as normal an answer as `active: true`; reaching this
 * endpoint is never itself evidence of membership (docs/architecture/member-access.md).
 */
final readonly class CurrentMembershipController
{
    public function __invoke(
        Request $request,
        RequestActor $actors,
        GetCurrentMembership $membership,
        CurrentMembershipPresenter $presenter,
    ): JsonResponse {
        $record = $membership($actors->for($request)->personId);

        return response()->json($presenter->present($record));
    }
}
