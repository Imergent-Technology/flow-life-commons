<?php

declare(strict_types=1);

use App\Modules\Access\Infrastructure\AccessServiceProvider;
use App\Modules\Audit\Infrastructure\AuditServiceProvider;
use App\Modules\Crm\Infrastructure\CrmServiceProvider;
use App\Modules\Discussions\Infrastructure\DiscussionsServiceProvider;
use App\Modules\Identity\Infrastructure\IdentityServiceProvider;
use App\Modules\Membership\Infrastructure\MembershipServiceProvider;
use App\Modules\Relationships\Infrastructure\RelationshipsServiceProvider;
use App\Modules\Release\Infrastructure\ReleaseServiceProvider;
use App\Modules\Resources\Infrastructure\ResourcesServiceProvider;
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
    // Depends on Access\Application, Identity\Application and Audit\Application (ADR 0037), so it registers after all three.
    ResourcesServiceProvider::class,
    // Depends on Access\Application, Identity\Application and Audit\Application (ADR 0038). The CRM edge waits for WP3.
    RelationshipsServiceProvider::class,
    SecurityServiceProvider::class,
    ReleaseServiceProvider::class,
];
