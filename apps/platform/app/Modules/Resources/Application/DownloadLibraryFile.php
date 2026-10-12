<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;

/**
 * Policy A (ADR 0038, R9): the file of any File Card of an existing Pack, Drafts included. Needs `resources.view` alone; it does not
 * ask what the Card's audiences are or whether it is published. A Pack that does not exist, or a Card that is not in it, is
 * `ResourcePackNotFound`; a file on record whose bytes are gone is `AssetUnavailable`. Never a signed or long-lived address.
 */
final readonly class DownloadLibraryFile
{
    public function __construct(private AuthorizeAction $authorize, private ViewerLibrary $library, private OpenAsset $open) {}

    /**
     * @throws AccessDenied
     * @throws ResourcePackNotFound
     * @throws AssetUnavailable
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $card): FileDownload
    {
        ($this->authorize)($actor, Capability::ViewResources);

        return ($this->open)($this->library->file($pack, $card));
    }
}
