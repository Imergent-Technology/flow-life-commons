<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\GetCurrentAccount;
use App\Shared\Domain\AccountId;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class MeController
{
    public function __invoke(
        Request $request,
        GetCurrentAccount $currentAccount,
        ConsoleSession $session,
        CurrentAccountPresenter $presenter,
        Config $config,
    ): JsonResponse {
        $user = $request->user();
        $accountId = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
        $authenticatedAt = $session->authenticatedAt($request);

        try {
            $current = is_string($accountId) && $authenticatedAt !== null
                ? $currentAccount(AccountId::fromString($accountId))
                : null;
        } catch (InvalidArgumentException) {
            $current = null;
        }

        if ($current === null || $authenticatedAt === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json($presenter->present($current, $authenticatedAt, $config->integer('identity.session.absolute_lifetime_minutes')));
    }
}
