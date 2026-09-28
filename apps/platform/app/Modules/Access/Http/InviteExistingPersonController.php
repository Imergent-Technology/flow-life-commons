<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\InviteExistingPerson;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

/**
 * 201: the Account exists and its invitation is committed, whether or not the message could be sent. The answer
 * says which (`delivery.status`), and never carries the invitation secret — the same contract as
 * `InviteOperatorController`, because the response shape (a `ManagedAccount` plus a delivery status) is identical.
 */
final readonly class InviteExistingPersonController
{
    public function __invoke(
        InviteExistingPersonRequest $request,
        string $person,
        InviteExistingPerson $invite,
        RequestActor $actors,
        AccountPresenter $presenter,
    ): JsonResponse {
        $result = $invite($actors->for($request), PersonId::fromString($person), $request->email());

        return response()->json($presenter->invitation($result), 201);
    }
}
