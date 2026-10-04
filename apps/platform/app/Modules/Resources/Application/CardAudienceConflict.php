<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardId;
use RuntimeException;

/**
 * The new Pack audiences would leave some narrowed Cards wider than their Pack (ADR 0037, decision 41). Nothing is adjusted
 * automatically: an automatic intersection could leave a Card visible to no one without anyone having decided that.
 */
final class CardAudienceConflict extends RuntimeException
{
    /** @param  list<CardId>  $cards */
    public function __construct(public readonly array $cards)
    {
        parent::__construct('Some Cards narrow this Pack\'s audiences to ones it would no longer have. Change those Cards first.');
    }
}
