<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\LogOut;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class LogoutController
{
    /**
     * Idempotent: with no authenticated session it records nothing and still answers 204,
     * so a client whose session has already lapsed can call it safely. (A request that fails
     * CSRF validation never gets here; the Console recovers by refreshing its token.)
     */
    public function __invoke(Request $request, LogOut $logOut, ConsoleSession $session): Response
    {
        $logOut($session->accountId($request), new ClientContext($request->ip(), $request->userAgent()));
        $session->terminate($request);

        return response()->noContent();
    }
}
