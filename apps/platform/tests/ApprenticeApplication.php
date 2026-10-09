<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Relationships\Application\RelationshipCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\ApprenticeCatalogSource;

/**
 * Boots with the catalog replaced before routes load, so the third type is registered through the real seam.
 */
class ApprenticeApplication extends TestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        assert($app instanceof Application);
        $app->booting(function () use ($app): void {
            $app->singleton(RelationshipCatalog::class, fn (): RelationshipCatalog => RelationshipCatalog::load([
                new ApprenticeCatalogSource,
            ]));
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
