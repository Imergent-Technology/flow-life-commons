<?php

declare(strict_types=1);

namespace App\Modules\Release\Infrastructure;

use App\Modules\Release\Infrastructure\Console\ShowReleaseCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Release module: which release this deployment is running (ADR 0027).
 *
 * Operational rather than a business module, like Health and Security: it owns no table, no entity
 * and no lifecycle, no other module depends on it, and it has no HTTP surface at all — deliberately,
 * because release identity stays off the public API and is answered over SSH instead.
 *
 * One command, and its whole job is reading a file the build wrote.
 */
final class ReleaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ShowReleaseCommand::class]);
        }
    }
}
