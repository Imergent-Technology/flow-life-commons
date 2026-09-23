<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The Actor of an authenticated administration request, re-checked against current Account state
 * (an Account that can no longer sign in resolves to nothing). The same shape as
 * `Access\Http\RequestActor`, owned separately here because a module may not reach into another
 * module's Http (ModuleBoundariesTest): every route-parameter Person or Grant id in this module is
 * a SUBJECT, never the caller — the caller's identity always comes from the authenticated session,
 * through this class alone.
 */
final readonly class RequestActor
{
    public function __construct(private ResolveActor $resolveActor) {}

    /**
     * @throws NoLongerAuthenticated
     */
    public function for(Request $request): Actor
    {
        $user = $request->user();
        $identifier = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        try {
            $actor = is_string($identifier) ? ($this->resolveActor)(AccountId::fromString($identifier)) : null;
        } catch (InvalidArgumentException) {
            $actor = null;
        }

        return $actor ?? throw new NoLongerAuthenticated;
    }
}
