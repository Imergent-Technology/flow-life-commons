<?php

declare(strict_types=1);

namespace App\Modules\Membership\Domain;

/**
 * Why Commons granted membership access: provenance, never a payment fact (ADR 0029).
 *
 * Code-owned, the way Access's Capability and Role are (ADR 0017's pattern applied here):
 * adding a source is a deliberate, visible act, not a value that arrives unchecked from a
 * caller. Provider identity may appear here as provenance; provider-specific business fields
 * and semantics never enter this module's schema.
 */
enum MembershipGrantSource: string
{
    /** A Commons operator decided to grant this access. */
    case Operator = 'operator';

    /** Reconciled from the legacy Luma records during the Membership Foundation migration. */
    case LumaLegacy = 'luma_legacy';
}
