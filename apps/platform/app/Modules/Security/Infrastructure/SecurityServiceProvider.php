<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure;

use App\Modules\Security\Infrastructure\Console\ShowSecurityHeadersCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Security module: the platform's browser security policy (ADR 0026) and nothing else. It is
 * operational rather than a business module, like Health: it owns no table, no entity and no
 * lifecycle, and no other module depends on it.
 *
 * The middleware itself is registered in bootstrap/app.php, with the rest of the global stack.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ShowSecurityHeadersCommand::class]);
        }
    }
}
