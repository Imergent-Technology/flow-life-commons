<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

/** One page of a discussion's messages, oldest first, and enough to page through the rest. */
final readonly class MessagePage
{
    /** @param  list<MessageView>  $messages */
    public function __construct(
        public array $messages,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
