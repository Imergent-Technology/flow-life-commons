<?php

declare(strict_types=1);

use App\Modules\Access\Infrastructure\AccessServiceProvider;
use App\Modules\Audit\Infrastructure\AuditServiceProvider;
use App\Modules\Identity\Infrastructure\IdentityServiceProvider;
use App\Modules\Security\Infrastructure\SecurityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    AccessServiceProvider::class,
    SecurityServiceProvider::class,
];
