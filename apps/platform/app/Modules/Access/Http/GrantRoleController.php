<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\GrantRoleToAccount;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;

final readonly class GrantRoleController
{
    public function __invoke(GrantRoleRequest $request, string $account, GrantRoleToAccount $grant, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->account($grant($actors->for($request), AccountId::fromString($account), $request->key())));
    }
}
