<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Role;
use App\Modules\Discussions\Application\DiscussionView;
use App\Modules\Discussions\Application\MessageView;
use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Application\StartDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\Actor;

/** Builders shared by the Discussions tests: everything goes through the real use cases, as an Actor who may participate. */
final class Discussions
{
    /**
     * An Actor who may view and participate in discussions: a Guardian, since Discussions access is an accepted owner
     * decision (ADR 0035). Idempotent within a test: the same address returns the same Person.
     */
    public static function participant(string $email = 'dee.participant@example.org', string $name = 'Dee Participant'): Actor
    {
        $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString($email));
        if ($account === null) {
            $account = Identity::savedActiveAccount($email, name: $name);
            Access::grant($account, Role::GuardianFull);
        }

        return Access::actorFor($account);
    }

    /** @return array{Console, Account} a Guardian signed in through the real two steps, named so they collide with no fixture Person */
    public static function signedInGuardian(string $email = 'gina.guardian@example.org', string $name = 'Gina Guardian'): array
    {
        return Crm::signedInGuardian($email, $name);
    }

    public static function start(Actor $by, string $title = 'A topic', string $body = 'The opening words'): DiscussionView
    {
        return app(StartDiscussion::class)($by, $title, $body);
    }

    public static function reply(Actor $by, DiscussionView|DiscussionId $in, string $body = 'A reply'): MessageView
    {
        return app(ReplyToDiscussion::class)($by, $in instanceof DiscussionView ? $in->discussion->id : $in, $body);
    }
}
