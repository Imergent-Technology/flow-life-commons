<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Infrastructure;

use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Discussions\Domain\DiscussionRepository;
use Illuminate\Support\ServiceProvider;

final class DiscussionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        DiscussionRepository::class => DatabaseDiscussionRepository::class,
        DiscussionMessageRepository::class => DatabaseDiscussionMessageRepository::class,
    ];
}
