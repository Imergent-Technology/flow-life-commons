<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\IdentityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
];
