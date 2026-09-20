<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The Actor of an authenticated Console request, re-checked against current Account state. It records
 * whether the SESSION was established with a second factor, so an Actor's provenance is what actually
 * happened. Null when there is no session, or the Account can no longer sign in.
 */
final readonly class ConsoleActor
{
    public function __construct(private ResolveActor $resolveActor, private ConsoleSession $session) {}

    public function for(Request $request): ?Actor
    {
        $user = $request->user();
        $identifier = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        try {
            return is_string($identifier)
                ? ($this->resolveActor)(AccountId::fromString($identifier), $this->session->secondFactorVerified($request))
                : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
