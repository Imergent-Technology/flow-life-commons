<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\DescribeCommonsAccess;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /admin/people/{person}/commons-access`: whether this Person has a Commons Account yet, for the Console's
 * Member detail page to compose alongside (never inside) the Membership record it already shows. A read, so no
 * `security.verified`: nothing here can be acted on without the mutation it might lead to (`InviteExistingPerson`)
 * asking for fresh proof on its own.
 */
final readonly class ShowCommonsAccessController
{
    public function __invoke(
        Request $request,
        string $person,
        DescribeCommonsAccess $describe,
        RequestActor $actors,
        AccountPresenter $presenter,
    ): JsonResponse {
        $view = $describe($actors->for($request), PersonId::fromString($person));

        return response()->json($presenter->commonsAccess($view));
    }
}
