<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Infrastructure;

use App\Modules\Relationships\Application\DefinitionSource;
use App\Modules\Relationships\Application\NoRelationshipGrants;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipDependents;
use App\Modules\Relationships\Application\RelationshipGrantWithdrawal;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Infrastructure\Console\CheckRelationshipsCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class RelationshipsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        RelationshipRepository::class => DatabaseRelationshipRepository::class,
        RelationshipGrantWithdrawal::class => NoRelationshipGrants::class,
    ];

    public function register(): void
    {
        $this->app->tag(DirectoryDefinitionSource::class, RelationshipCatalog::SOURCE);
        $this->app->singleton(RelationshipCatalog::class, function (Application $app): RelationshipCatalog {
            /** @var iterable<DefinitionSource> $sources */
            $sources = $app->tagged(RelationshipCatalog::SOURCE);

            return RelationshipCatalog::load($sources);
        });
        $this->app->when(RelationshipDependents::class)
            ->needs('$dependents')
            ->giveTagged('relationships.dependents');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckRelationshipsCommand::class]);
        }
    }
}
