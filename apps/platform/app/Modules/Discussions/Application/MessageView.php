<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Discussions\Domain\DiscussionMessage;

/** A message with the people it names. A removed message carries no text, so there is none to show. */
final readonly class MessageView
{
    public function __construct(
        public DiscussionMessage $message,
        public DiscussionPerson $author,
        public ?DiscussionPerson $editedBy,
    ) {}
}
