<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The Actor of an authenticated administration request, re-checked against current Account state. Owned here
 * because a module may not depend on another module's Http. The caller always comes from the session.
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
