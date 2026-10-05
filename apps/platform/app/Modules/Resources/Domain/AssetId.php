<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Shared\Domain\UlidIdentifier;

/** The identity of one managed file a File Card owns (ADR 0037, decision 63). Its storage key is derived from it. */
final readonly class AssetId extends UlidIdentifier {}
