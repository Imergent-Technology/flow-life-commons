<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\SendManagedPasswordReset;
use App\Modules\Identity\Application\ClientContext;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sends the Account holder the normal password-reset email. The body is empty, and the response carries no token or link. */
final readonly class SendPasswordResetController
{
    public function __invoke(Request $request, string $account, SendManagedPasswordReset $send, RequestActor $actors, AccountPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->passwordReset($send(
            $actors->for($request),
            AccountId::fromString($account),
            new ClientContext($request->ip(), $request->userAgent()),
        )));
    }
}
