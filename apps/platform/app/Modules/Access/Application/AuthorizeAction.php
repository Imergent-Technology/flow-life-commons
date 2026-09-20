<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Shared\Domain\Actor;

/**
 * What business code calls to require a capability from inside a use case: it returns
 * quietly when the Actor holds it, and throws AccessDenied when not. HTTP edges can use
 * Laravel's `can:` middleware instead; both end up in the Authorizer.
 */
final readonly class AuthorizeAction
{
    public function __construct(private Authorizer $authorizer) {}

    /**
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor, Capability $capability): void
    {
        if (! $this->authorizer->allows($actor, $capability)) {
            throw new AccessDenied;
        }
    }
}
