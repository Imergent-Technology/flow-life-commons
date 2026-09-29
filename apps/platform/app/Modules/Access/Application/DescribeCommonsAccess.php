<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\PersonNotFound;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/**
 * Whether a Person has a Commons Account yet (ADR 0032, Work Package 5), and so whether the existing-Person
 * invitation (`InviteExistingPerson`) may still be issued for them. Access's own bounded fact, composed into
 * the Console's Member detail page alongside — never inside — Membership's own response: Membership discloses
 * nothing about Accounts at all (`MembershipDisclosureTest`, Work Package 7), and this stays a wholly separate
 * read, under its own route, so that stays true.
 *
 * Authorizes on the same capability that governs actually inviting (`identity.invitations.issue`): the one
 * reason this exists is to answer "may I invite this Person", so there is no reason to let anyone see the
 * answer who could not act on it. Fails closed: an unknown Person is refused (`PersonNotFound`, 404), never
 * silently reported as "not invited yet".
 */
final readonly class DescribeCommonsAccess
{
    public function __construct(
        private AuthorizeAction $authorize,
        private FindPeople $findPeople,
        private AccountDirectory $accounts,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PersonNotFound
     */
    public function __invoke(Actor $actor, PersonId $personId): CommonsAccessView
    {
        ($this->authorize)($actor, Capability::IssueInvitations);

        $people = ($this->findPeople)([$personId]);
        if (! isset($people[$personId->value])) {
            throw new PersonNotFound;
        }

        return CommonsAccessView::of(CommonsAccessState::of($this->accounts->findByPersonId($personId)));
    }
}
