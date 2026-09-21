<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\RevokeRoleFromAccount;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class RevokeRoleController
{
    public function __invoke(Request $request, string $account, string $key, RevokeRoleFromAccount $revoke, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->account($revoke($actors->for($request), AccountId::fromString($account), $key)));
    }
}
