<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\LoginThrottle;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Identity\Infrastructure\Auth\AccountUserProvider;
use App\Modules\Identity\Infrastructure\Auth\CacheLoginThrottle;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountInvitationRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentPersonRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Identity's ports to their implementations and registers its auth provider driver.
 * Routes are loaded from Http/routes.php by routes/api.php, like every module's.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        PersonRepository::class => EloquentPersonRepository::class,
        AccountRepository::class => EloquentAccountRepository::class,
        AccountInvitationRepository::class => EloquentAccountInvitationRepository::class,
        LoginThrottle::class => CacheLoginThrottle::class,
    ];

    public function boot(): void
    {
        // The "identity" driver named by config/auth.php.
        Auth::provider('identity', fn (Application $app): AccountUserProvider => $app->make(AccountUserProvider::class));
    }
}
