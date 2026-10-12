<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Identity\Application\ResolveActor;
use App\Modules\Membership\Application\GetCurrentMembership;
use App\Modules\Relationships\Application\QualifyingRelationships;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceSet;
use App\Shared\Domain\Actor;

/**
 * The audiences a signed-in Person is eligible for RIGHT NOW (ADR 0038, R10): the ONLY producer of policy C's audience set. It knows
 * nothing of the Console. Its input is an `Actor`, never a Person id a caller chose, and it takes no audience, mode or Person from anyone.
 *
 * **Identity is re-resolved first** (AR9), exactly as the `Authorizer` does: Identity's `ResolveActor` is asked for the Actor's Account,
 * and unless that still yields a current Actor for the SAME Person the set is EMPTY. So a captured or stale `Actor` (a disabled Account,
 * one that can no longer authenticate, a mismatched Person) can never carry eligibility, on any surface, whether or not that surface
 * also checks a capability. Only then is the RESOLVED Person's own state read.
 *
 * Resources owns the audiences; the owners of the facts say who belongs to them. The mapping lives here, in one place:
 *
 * - `guardian`: Relationships' `QualifyingRelationships` includes `guardian` (an Active Guardian relationship).
 * - `volunteer`: the same read includes `volunteer` (an Active Volunteer relationship).
 * - `member`: Membership's `GetCurrentMembership` says the membership is active (Membership's own rule, unchanged).
 *
 * The set is their union (OR; no audience implies another). It is NEVER derived from a role, a capability (`resources.view`,
 * `resources.manage`, `console.access`), Console admission, a relationship's descriptive fields or tags, and a relationship type with no
 * audience here (the test-only `apprentice`, a future Partner) adds nothing: fail closed. Nothing is cached; every call reads current state.
 */
final readonly class AudienceEligibility
{
    /** Relationship type key => the audience it makes a Person eligible for. */
    private const array RELATIONSHIP_AUDIENCES = [
        'guardian' => Audience::Guardian,
        'volunteer' => Audience::Volunteer,
    ];

    public function __construct(
        private ResolveActor $resolveActor,
        private QualifyingRelationships $relationships,
        private GetCurrentMembership $membership,
    ) {}

    public function __invoke(Actor $actor): AudienceSet
    {
        $current = ($this->resolveActor)($actor->accountId);
        if ($current === null || ! $current->personId->equals($actor->personId)) {
            return AudienceSet::none();
        }
        $person = $current->personId;

        $audiences = [];
        foreach (($this->relationships)($person) as $type) {
            $audience = self::RELATIONSHIP_AUDIENCES[$type->key] ?? null;
            if ($audience !== null) {
                $audiences[] = $audience;
            }
        }
        if (($this->membership)($person)->active) {
            $audiences[] = Audience::Member;
        }

        return AudienceSet::fromList($audiences);
    }
}
