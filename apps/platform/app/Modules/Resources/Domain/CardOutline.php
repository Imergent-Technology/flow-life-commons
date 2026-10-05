<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * A Card without its content: everything the library, a Pack's contents list and the projection rule need, so listing never
 * loads a document of up to 256 KiB per Card. `Card::outline()` and the repository both produce it. A File Card's outline carries
 * its file's metadata (never its bytes).
 */
final readonly class CardOutline
{
    public function __construct(
        public CardId $id,
        public PackId $packId,
        public int $position,
        public CardType $type,
        public string $title,
        public CardSummary $summary,
        public ?string $externalUri,
        public ?ResourceAsset $asset,
        public CardAudience $audience,
        public PublicationState $state,
        public int $revision,
        public Provenance $provenance,
    ) {}

    public function isPublished(): bool
    {
        return $this->state === PublicationState::Published;
    }
}
