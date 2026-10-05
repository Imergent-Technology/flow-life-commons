<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * The one managed file a File Card owns (ADR 0037, decisions 62-63): its id, where its bytes are (a key derived from the id), the
 * sanitised name it was uploaded under, the media type DETECTED from its content and allowed, its size, a SHA-256 digest of its
 * bytes, and who uploaded it and when (a Person id: provenance, no foreign key, never authority).
 *
 * It belongs to exactly one Card and is never shared, reused, versioned or kept after it is replaced or its Card is deleted. The
 * business model refers to the asset, never to a path.
 */
final readonly class ResourceAsset
{
    public function __construct(
        public AssetId $id,
        public StorageKey $storageKey,
        public string $originalFilename,
        public string $mediaType,
        public int $byteSize,
        public string $sha256,
        public PersonId $uploadedBy,
        public DateTimeImmutable $uploadedAt,
    ) {}

    /** Whether this asset may be served `inline` when asked: only an allowlisted PDF or raster image (decision 66). */
    public function opensInline(): bool
    {
        return FileKind::ofMediaType($this->mediaType)?->opensInline() ?? false;
    }
}
