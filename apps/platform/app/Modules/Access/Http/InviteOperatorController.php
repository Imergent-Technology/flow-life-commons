<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\InviteOperator;
use Illuminate\Http\JsonResponse;

/**
 * 201: the Account exists and its invitation is committed, whether or not the message could be sent. The answer says
 * which (`delivery.status`), and never carries the invitation secret.
 */
final readonly class InviteOperatorController
{
    public function __invoke(InviteOperatorRequest $request, InviteOperator $invite, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        $result = $invite($actors->for($request), $request->email(), $request->displayName(), $request->assignments());

        return response()->json($presenter->invitation($result), 201);
    }
}
