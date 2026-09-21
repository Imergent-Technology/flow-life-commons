<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\ManagedAccount;

/** An Account as an operator administers it: Identity's facts, and the assignments Access holds for its Person. */
final readonly class AccountView
{
    /** @param  list<RoleAssignmentView>  $assignments */
    public function __construct(
        public ManagedAccount $account,
        public array $assignments,
    ) {}
}
