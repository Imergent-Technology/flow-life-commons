<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * How a Card presents and integrates (ADR 0037, decision 18), fixed when the Card is created. WP1 implements the first two.
 * The third approved type, `file`, arrives with managed files (WP3) as one more case: `type` is a string column, so nothing
 * stored needs to change for it, and every Card keeps the generic fallback of title, summary, content and a safe action.
 */
enum CardType: string
{
    /** The rich content is the resource. A URI is optional and is shown as a related link. */
    case Basic = 'basic';

    /** A link, with the Card's title, summary and content as ordinary fields. A URI is required. */
    case ExternalLink = 'external_link';
}
