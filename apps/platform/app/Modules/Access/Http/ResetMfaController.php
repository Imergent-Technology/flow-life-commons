<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\ResetManagedMfa;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resets ANOTHER account's second factor. The body is empty: there is nothing to submit, and nothing secret to return. */
final readonly class ResetMfaController
{
    public function __invoke(Request $request, string $account, ResetManagedMfa $reset, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->account($reset($actors->for($request), AccountId::fromString($account))));
    }
}
