<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Discussions\Domain\Discussion;

/** A discussion's header with its creator and, if resolved, who resolved it. */
final readonly class DiscussionView
{
    public function __construct(
        public Discussion $discussion,
        public ?DiscussionPerson $creator,
        public ?DiscussionPerson $resolvedBy,
    ) {}
}
