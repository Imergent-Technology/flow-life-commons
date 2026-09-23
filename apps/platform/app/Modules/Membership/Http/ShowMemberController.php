<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Membership\Application\GetMembershipRecord;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ShowMemberController
{
    public function __invoke(
        Request $request,
        string $person,
        GetMembershipRecord $get,
        RequestActor $actors,
        FindPeople $findPeople,
        MembershipPresenter $presenter,
    ): JsonResponse {
        $record = $get($actors->for($request), PersonId::fromString($person));

        // Phase-1 semantics (Package 5): a bare Person who has never held a grant is not yet a
        // membership record. `GetMembershipRecord` itself already refused an unknown Person.
        if ($record->grants === []) {
            throw new MembershipRecordNotFound;
        }

        $people = $findPeople([$record->personId]);
        $summary = $people[$record->personId->value] ?? null;
        assert($summary instanceof PersonSummary);

        return response()->json($presenter->record($record, $summary));
    }
}
