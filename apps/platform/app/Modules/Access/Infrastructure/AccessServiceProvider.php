<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Application\AuthorizerEffectiveCapabilities;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\LastAdministratorDeactivationGuard;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Access\Infrastructure\Console\CreateAdministratorCommand;
use App\Modules\Identity\Application\AccountDeactivationGuard;
use App\Modules\Identity\Application\EffectiveCapabilities;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Access's port and registers what Access offers other modules.
 *
 * Access depends on Identity, never the reverse: Identity's EffectiveCapabilities port is
 * implemented here and registered here (bootstrap/providers.php loads this after
 * Identity's, so this binding is the one that wins).
 */
final class AccessServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        RoleAssignmentRepository::class => DatabaseRoleAssignmentRepository::class,
        EffectiveCapabilities::class => AuthorizerEffectiveCapabilities::class,
    ];

    public function register(): void
    {
        // Join Identity's deactivation guard chain (ADR 0020). Identity owns the port and never
        // names this class; the tag is how it finds whoever has registered.
        $this->app->tag([LastAdministratorDeactivationGuard::class], AccountDeactivationGuard::TAG);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateAdministratorCommand::class]);
        }

        // One Gate ability per capability, derived from the enum: the Gate is not a second
        // catalog. Each delegates to Access. A guest never reaches the callback (its user
        // parameter is not nullable), so guests are denied.
        foreach (Capability::cases() as $capability) {
            Gate::define(
                $capability->value,
                fn (Authenticatable $user): bool => $this->app->make(CapabilityGate::class)->allows($user, $capability),
            );
        }
    }
}
