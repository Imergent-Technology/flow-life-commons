<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\Discussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessage;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Modules\Discussions\Domain\InvalidDiscussionInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Starts a discussion: the header and its opening message (sequence 1, authored by the caller), in one transaction.
 * Needs `discussions.participate`. The author is always the caller's Person; no request field can name another. Writes
 * nothing to the security audit trail: discussion history is business data (ADR 0035).
 */
final readonly class StartDiscussion
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionMessageRepository $messages,
        private DiscussionViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws InvalidDiscussionInput
     */
    public function __invoke(Actor $actor, string $title, string $body): DiscussionView
    {
        ($this->authorize)($actor, Capability::ParticipateInDiscussions);

        $now = DateTimeImmutable::createFromInterface(now());
        $discussion = Discussion::start(DiscussionId::generate(), $title, $now);
        $opening = DiscussionMessage::post(DiscussionMessageId::generate(), $discussion->id, 1, $actor->personId, $body, $now);

        $this->database->transaction(function () use ($discussion, $opening): void {
            $this->discussions->add($discussion);
            $this->messages->add($opening);
        });

        return $this->views->ofDiscussions([$discussion])[0];
    }
}
