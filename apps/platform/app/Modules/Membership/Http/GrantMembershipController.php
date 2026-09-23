<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Application\GrantMembershipAccess;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

/**
 * Grants an additional membership term to an EXISTING Person. Returns the grant just created,
 * standing alone, not the Person's whole history: re-reading the full record would need
 * `membership.records.view`, which this route (guarded by `.manage` alone) does not require.
 */
final readonly class GrantMembershipController
{
    public function __invoke(
        GrantMembershipRequest $request,
        string $person,
        GrantMembershipAccess $grant,
        RequestActor $actors,
        MembershipPresenter $presenter,
    ): JsonResponse {
        $created = $grant(
            $actors->for($request),
            PersonId::fromString($person),
            $request->startsAt(),
            $request->endsAt(),
            $request->source(),
            $request->sourceReference(),
        );

        return response()->json($presenter->grant($created), 201);
    }
}
