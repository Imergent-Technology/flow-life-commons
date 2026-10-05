<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * How a Card presents and integrates (ADR 0037, decision 18), fixed when the Card is created. Every Card keeps the generic
 * fallback of title, summary, content and a safe action, whatever its Type. The set is closed: there are exactly these three.
 */
enum CardType: string
{
    /** The rich content is the resource. A URI is optional and is shown as a related link. */
    case Basic = 'basic';

    /** A link, with the Card's title, summary and content as ordinary fields. A URI is required. */
    case ExternalLink = 'external_link';

    /**
     * One managed file the Card owns (decision 62), required at creation and always; the content describes it. No URI. The file
     * arrives with the Card (a multipart creation) and is replaced, never removed, so a File Card never exists without one.
     */
    case File = 'file';
}
