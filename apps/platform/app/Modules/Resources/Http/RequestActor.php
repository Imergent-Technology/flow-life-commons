<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The Actor of an authenticated administration request, re-checked against current Account state. The same shape as
 * Membership's, Crm's and Discussions', owned separately because a module may not depend on another module's Http. Every
 * route-parameter id is a SUBJECT, never the caller: the caller always comes from the authenticated session, here.
 */
final readonly class RequestActor
{
    public function __construct(private ResolveActor $resolveActor) {}

    /** @throws NoLongerAuthenticated */
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
