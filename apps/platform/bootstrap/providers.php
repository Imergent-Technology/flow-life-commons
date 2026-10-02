<?php

declare(strict_types=1);

use App\Modules\Access\Infrastructure\AccessServiceProvider;
use App\Modules\Audit\Infrastructure\AuditServiceProvider;
use App\Modules\Crm\Infrastructure\CrmServiceProvider;
use App\Modules\Discussions\Infrastructure\DiscussionsServiceProvider;
use App\Modules\Identity\Infrastructure\IdentityServiceProvider;
use App\Modules\Membership\Infrastructure\MembershipServiceProvider;
use App\Modules\Release\Infrastructure\ReleaseServiceProvider;
use App\Modules\Security\Infrastructure\SecurityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    AccessServiceProvider::class,
    // Depends on Access\Application and Identity\Application (ADR 0028), so it registers after both.
    MembershipServiceProvider::class,
    // Depends on Access\Application and Identity\Application (ADR 0034), so it registers after both.
    CrmServiceProvider::class,
    // Depends on Access\Application and Identity\Application (ADR 0035), so it registers after both.
    DiscussionsServiceProvider::class,
    SecurityServiceProvider::class,
    ReleaseServiceProvider::class,
];
