<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\ManagedAccount;

/** An Account as an operator administers it: Identity's facts, and the roles Access holds for its Person. */
final readonly class AccountView
{
    /**
     * @param  list<RoleAssignmentView>  $assignments  independent assignments; these are what revoke removes
     * @param  list<SourcedRoleGrantView>  $sourcedGrants  relationship-managed grants; they have no revoke action here
     */
    public function __construct(
        public ManagedAccount $account,
        public array $assignments,
        public array $sourcedGrants,
    ) {}
}
