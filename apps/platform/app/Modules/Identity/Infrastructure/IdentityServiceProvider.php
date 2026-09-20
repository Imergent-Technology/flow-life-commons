<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountInvitationRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentPersonRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Identity's Domain ports to their Eloquent implementations. It deliberately
 * registers nothing about authentication: no auth provider, guard or routes yet.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        PersonRepository::class => EloquentPersonRepository::class,
        AccountRepository::class => EloquentAccountRepository::class,
        AccountInvitationRepository::class => EloquentAccountInvitationRepository::class,
    ];
}
