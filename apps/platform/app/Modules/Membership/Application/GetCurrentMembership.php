<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipState;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * A Person's OWN membership state and grant history, for the self-service `GET /my/membership`
 * (ADR 0032). Unlike `GetMembershipRecord`, this authorizes nothing and checks that no Person id
 * beyond the caller's own is ever accepted: reaching this Application method at all already means
 * the caller is asking about themselves — the HTTP layer resolves the Person from the authenticated
 * session, never from a request parameter, so there is nothing here to authorize.
 *
 * `/my/` is authenticated self-service, not evidence of active membership (docs/architecture/
 * member-access.md): every outcome — active, lapsed, or never a member at all — is a normal,
 * successful answer, never a refusal. The Person given is always assumed to exist: it is the
 * signed-in Account's own, which Identity already guarantees by the Account/Person relationship.
 */
final readonly class GetCurrentMembership
{
    public function __construct(private MembershipGrantRepository $grants) {}

    public function __invoke(PersonId $person, ?DateTimeImmutable $at = null): MembershipRecord
    {
        $grants = $this->grants->forPerson($person);
        $state = MembershipState::at($grants, $at ?? DateTimeImmutable::createFromInterface(now()));

        return new MembershipRecord($person, $state->active, $state->currentAccessEndsAt, $state->openEnded, $grants);
    }
}
