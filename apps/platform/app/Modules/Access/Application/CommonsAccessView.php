<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * What an operator sees about a Person's Commons access, and the one thing they might do about it. `canInvite` is
 * derived, not an independent fact to keep in sync: it is true exactly when `state` is `NotInvited` (WP1's
 * `InviteExistingPerson` itself refuses on any other state), computed once here so nothing else has to repeat
 * that rule.
 */
final readonly class CommonsAccessView
{
    public function __construct(
        public CommonsAccessState $state,
        public bool $canInvite,
    ) {}

    public static function of(CommonsAccessState $state): self
    {
        return new self($state, $state === CommonsAccessState::NotInvited);
    }
}
