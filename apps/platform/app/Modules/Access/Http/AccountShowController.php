<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\ShowManagedAccount;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AccountShowController
{
    public function __invoke(Request $request, string $account, ShowManagedAccount $show, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->account($show($actors->for($request), AccountId::fromString($account))));
    }
}
