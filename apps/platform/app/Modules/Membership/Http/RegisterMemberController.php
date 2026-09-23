<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Application\RegisterPersonWithMembershipAccess;
use App\Modules\Membership\Domain\MembershipState;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Registers a new Person and grants them membership access in one atomic use case. The response
 * is built entirely from what that use case already returned — never a second Application call —
 * so this never re-checks `membership.records.view`, which the route does not require.
 */
final readonly class RegisterMemberController
{
    public function __invoke(
        RegisterMemberRequest $request,
        RegisterPersonWithMembershipAccess $register,
        RequestActor $actors,
        MembershipPresenter $presenter,
    ): JsonResponse {
        $registration = $register(
            $actors->for($request),
            $request->displayName(),
            $request->startsAt(),
            $request->endsAt(),
            $request->source(),
            $request->sourceReference(),
        );

        $state = MembershipState::at([$registration->grant], DateTimeImmutable::createFromInterface(now()));
        $record = new MembershipRecord($registration->personId, $state->active, $state->currentAccessEndsAt, $state->openEnded, [$registration->grant]);
        $summary = new PersonSummary($registration->personId, $registration->displayName);

        return response()->json($presenter->record($record, $summary), 201);
    }
}
