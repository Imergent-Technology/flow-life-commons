<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\EnableManagedAccount;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class EnableAccountController
{
    public function __invoke(Request $request, string $account, EnableManagedAccount $enable, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->account($enable($actors->for($request), AccountId::fromString($account))));
    }
}
