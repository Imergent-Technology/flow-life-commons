<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\Actor;

/**
 * What the platform can say about the signed-in human at this phase: identity only.
 * There are deliberately no roles or capabilities here; Access does not exist yet, and
 * nothing is invented in its place.
 */
final readonly class CurrentAccount
{
    public function __construct(
        public Actor $actor,
        public string $email,
        public string $displayName,
    ) {}
}
