<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;

/**
 * Policy A (ADR 0038, R5-R7): one Pack with every Card at its latest saved content, Drafts included. Needs `resources.view` alone.
 * A Pack that does not exist is `ResourcePackNotFound`; a Draft Pack exists, and is read.
 */
final readonly class ReadLibraryPack
{
    public function __construct(private AuthorizeAction $authorize, private ViewerLibrary $library) {}

    /**
     * @throws AccessDenied
     * @throws ResourcePackNotFound
     */
    public function __invoke(Actor $actor, PackId $id): ViewerPack
    {
        ($this->authorize)($actor, Capability::ViewResources);

        return $this->library->pack($id);
    }
}
