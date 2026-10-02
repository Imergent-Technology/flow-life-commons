<?php

declare(strict_types=1);

namespace App\Modules\Crm\Infrastructure;

use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Modules\Crm\Domain\InteractionRepository;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ContactProfileRepository::class => DatabaseContactProfileRepository::class,
        ContactMethodRepository::class => DatabaseContactMethodRepository::class,
        ContactTagRepository::class => DatabaseContactTagRepository::class,
        InteractionRepository::class => DatabaseInteractionRepository::class,
    ];
}
