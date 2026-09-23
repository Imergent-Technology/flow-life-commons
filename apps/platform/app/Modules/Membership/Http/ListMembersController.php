<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Identity\Application\FindPeople;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Application\PageMembershipRecords;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;

final readonly class ListMembersController
{
    public function __invoke(
        ListMembersRequest $request,
        PageMembershipRecords $page,
        RequestActor $actors,
        FindPeople $findPeople,
        MembershipPresenter $presenter,
    ): JsonResponse {
        $result = $page($actors->for($request), $request->page(), $request->perPage());
        $people = $findPeople(array_map(fn (MembershipRecord $r): PersonId => $r->personId, $result->records));

        return response()->json($presenter->page($result, $people));
    }
}
