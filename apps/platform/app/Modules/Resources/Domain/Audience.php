<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The audiences Resources can target (ADR 0037, decision 38): a code-owned catalog, stored as these strings. Resources owns
 * WHICH audiences a Pack or Card targets and never the facts that put a Person in one (ADR 0036): Guardian eligibility is, for
 * now, the Console's `resources.view`; Member eligibility belongs to Membership; neither is stored or derived here.
 *
 * Volunteer is deliberately absent: it is added, as one case here and one eligibility source, only once a Volunteering domain
 * can say who is a Volunteer now. Declaration order is the catalog order the audience sets keep.
 */
enum Audience: string
{
    case Guardian = 'guardian';
    case Member = 'member';
}
