<?php

declare(strict_types=1);

namespace App\Modules\Membership\Infrastructure;

use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\ServiceProvider;

final class MembershipServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        MembershipGrantRepository::class => DatabaseMembershipGrantRepository::class,
    ];
}
