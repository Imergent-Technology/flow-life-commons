<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Domain\ResourceFileStore;
use Psr\Log\LoggerInterface;

/**
 * Opens a File Card's file for a download the caller has ALREADY been authorized for. Internal to Resources: it decides nothing about
 * who may see what (the download use cases do that, through management or the projection), only how a file on record is served.
 *
 * A row whose bytes are missing (after a partial restore, say) is `asset_unavailable`, logged by asset id, never a 500.
 */
final readonly class OpenAsset
{
    public function __construct(private ResourceFileStore $files, private LoggerInterface $log) {}

    /** @throws AssetUnavailable */
    public function __invoke(ResourceAsset $asset): FileDownload
    {
        $stored = $this->files->open($asset->storageKey);
        if ($stored === null) {
            $this->log->warning('A Resources file on record is missing from the store (asset_unavailable).', ['asset_id' => $asset->id->value]);

            throw new AssetUnavailable;
        }

        return new FileDownload($asset->originalFilename, $asset->mediaType, $stored->size, $stored->stream, $asset->opensInline());
    }
}
