<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\ActiveAccountQuery;

/**
 * A read-only picture of who administers the platform, for the operator's bootstrap command. It
 * takes no locks and decides nothing: BootstrapAdministrator re-checks inside its transaction.
 */
final readonly class AdministratorOverview
{
    public function __construct(
        private RoleAssignmentRepository $assignments,
        private ActiveAccountQuery $accounts,
    ) {}

    public function __invoke(): AdministratorSummary
    {
        $holders = $this->assignments->holdersOf(Role::PlatformAdministrator->value);

        return new AdministratorSummary(count($holders), count($this->accounts->activePersonIds($holders)));
    }
}
