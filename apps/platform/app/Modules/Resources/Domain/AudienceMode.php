<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Whether a Card follows its Pack's audiences or narrows them (ADR 0037, decision 40). Explicit, never inferred from the
 * absence of rows, so losing the last narrowing row can never silently broaden a Card to its whole Pack.
 */
enum AudienceMode: string
{
    /** The Pack's set, read at projection time, so it follows later changes to the Pack. */
    case Inherit = 'inherit';

    /** An explicit, non-empty subset of the Pack's set, fixed until changed. It never grows when the Pack's set does. */
    case Narrowed = 'narrowed';
}
