<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\MediaTypeDetector;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Infrastructure\Console\ProbeDetectionCommand;
use App\Modules\Resources\Infrastructure\Console\PruneAssetsCommand;
use Illuminate\Support\ServiceProvider;

final class ResourcesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CategoryRepository::class => DatabaseCategoryRepository::class,
        PackRepository::class => DatabasePackRepository::class,
        CardRepository::class => DatabaseCardRepository::class,
        ResourceFileStore::class => DiskResourceFileStore::class,
        MediaTypeDetector::class => FinfoMediaTypeDetector::class,
    ];

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAssetsCommand::class, ProbeDetectionCommand::class]);
        }
    }
}
