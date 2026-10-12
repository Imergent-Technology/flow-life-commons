<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;

/**
 * Policy C (ADR 0038, R11, R14): the file of a File Card, when the Card is VISIBLE to this Person: the same lookup a delivered Pack
 * uses (`AudienceDelivery`), so a file can be had exactly when its Card can be seen. Nothing else authorizes it: not the Card existing,
 * not the asset existing, not being signed in, and never a storage key, which no request names.
 *
 * Every reason the file is not theirs (a missing, Draft or unpublished Pack; a Draft, hidden, narrowed-away or missing Card; a Card in
 * another Pack; a visible Card that has no file) is the same `ResourcePackNotFound`, with nothing to tell which. Only once the Card is
 * known visible may the answer be `asset_unavailable`, for a file on record whose bytes are missing.
 */
final readonly class DownloadDeliveredFile
{
    public function __construct(private AudienceDelivery $delivery, private OpenAsset $open) {}

    /**
     * @throws ResourcePackNotFound
     * @throws AssetUnavailable
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $card): FileDownload
    {
        [, , $visible] = $this->delivery->visible($actor, $pack);
        $matches = array_values(array_filter($visible, static fn (CardOutline $o): bool => $o->id->equals($card)));
        $asset = ($matches[0] ?? null)->asset ?? throw new ResourcePackNotFound;

        return ($this->open)($asset);
    }
}
