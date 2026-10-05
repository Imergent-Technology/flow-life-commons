<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/**
 * The Card may be seen and has a file on record, but the store does not hold its bytes (after a partial restore, say), or a
 * management caller asked for the file of a Card that has none (`404 asset_unavailable`, ADR 0037 decision 66). Never a 500. On
 * the delivery route it is answered only after the Card was found visible, so it discloses nothing the viewer could not see.
 */
final class AssetUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That file is not available.');
    }
}
