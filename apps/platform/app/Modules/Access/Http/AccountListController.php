<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\ListManagedAccounts;
use Illuminate\Http\JsonResponse;

final readonly class AccountListController
{
    public function __invoke(AccountListRequest $request, ListManagedAccounts $list, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->page($list($actors->for($request), $request->search())));
    }
}
