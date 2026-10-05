<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Card;

/**
 * One Card with its content, for management (Drafts included), and who created and last edited it. For a File Card, also who uploaded
 * its file and whether the store holds the file's bytes now (`fileAvailable`): a file on record that is missing from the store (after
 * a partial restore, say) is shown to management rather than discovered by a viewer (ADR 0037, decision 66). Both are null for other
 * Types.
 */
final readonly class ManagedCardView
{
    public function __construct(
        public Card $card,
        public ResourcePerson $createdBy,
        public ResourcePerson $updatedBy,
        public ?ResourcePerson $uploadedBy = null,
        public ?bool $fileAvailable = null,
    ) {}
}
