<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The audiences Resources can target (ADR 0037, decision 38; ADR 0038, R1): a code-owned catalog, stored as these strings.
 * Resources owns WHICH audiences a Pack or Card targets and never the facts that put a Person in one (ADR 0036): Guardian and
 * Volunteer eligibility is the Person's Active relationship (Relationships), Member eligibility is Membership's; none of it is
 * stored or derived here, and `AudienceEligibility` is the one place that asks. A key outside this catalog confers nothing
 * anywhere. Declaration order is the catalog order the audience sets keep.
 */
enum Audience: string
{
    case Guardian = 'guardian';
    case Member = 'member';
    case Volunteer = 'volunteer';
}
