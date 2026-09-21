<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\DisableManagedAccount;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DisableAccountController
{
    public function __invoke(Request $request, string $account, DisableManagedAccount $disable, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->account($disable($actors->for($request), AccountId::fromString($account))));
    }
}
