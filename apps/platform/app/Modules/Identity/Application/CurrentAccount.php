<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\Actor;

/**
 * What the platform can say about the signed-in human: who they are, and what they may
 * currently do.
 *
 * `capabilities` are identifiers, never role names: the client learns what it may do, not
 * which organisational label produced it. They are derived fresh on every request and are
 * not stored in the session. They are for presentation only; the server enforces.
 */
final readonly class CurrentAccount
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public Actor $actor,
        public string $email,
        public string $displayName,
        public array $capabilities = [],
    ) {}
}
