<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\AssetId;
use App\Modules\Resources\Domain\FileKind;
use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\MediaTypeDetector;
use App\Modules\Resources\Domain\OriginalFilename;
use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Domain\StorageKey;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Judges an uploaded file and, if it passes, writes it to the store under a NEW key: the first half of creating or replacing a File
 * Card's file (ADR 0037, decisions 63-65, 67). Internal to Resources. Nothing is written for a refused file, and nothing it writes is
 * referenced by anything until the caller's transaction commits a row that names it.
 *
 * In order, each a refusal that stores nothing:
 *
 * 1. Size: over `resources.assets.max_bytes` is `file_too_large`. The application decides, whatever PHP's own limits are.
 * 2. Name: sanitised for display (`OriginalFilename`); only its extension takes part in the type check.
 * 3. Type: the media type is DETECTED from the content; it and the extension must agree on one allowlisted kind (`FileKind`), or it
 *    is `file_type_not_allowed`. The client's claimed type is never read. What is stored is the kind's canonical type.
 * 4. Inspection: THE one place a malware scanner would be called, before anything is stored, when the hosting supports one
 *    (decision 67). Phase 1 has none, and claims none.
 * 5. Digest and write: SHA-256 of the bytes, then a copy into the store under a key derived from a fresh asset id. The store never
 *    overwrites, so this cannot touch a file a committed row refers to.
 */
final readonly class AssetIntake
{
    public function __construct(private MediaTypeDetector $detector, private ResourceFileStore $files) {}

    /**
     * @throws FileTooLarge
     * @throws FileTypeNotAllowed
     * @throws FileStoreFailure
     */
    public function accept(IncomingFile $file, PersonId $by, DateTimeImmutable $now): ResourceAsset
    {
        $size = is_file($file->path) ? filesize($file->path) : false;
        if ($size === false) {
            throw new FileStoreFailure('The uploaded file could not be read.');
        }
        $max = AssetLimits::maxBytes();
        if ($size > $max) {
            throw new FileTooLarge($max);
        }

        $name = OriginalFilename::fromClient($file->clientName);
        $kind = FileKind::judge($name->extension, $this->detector->detect($file->path)) ?? throw new FileTypeNotAllowed;

        // Inspection (decision 67): a scanner, when there is one, is called here, and refuses before anything is stored.

        $digest = hash_file('sha256', $file->path);
        if ($digest === false) {
            throw new FileStoreFailure('The uploaded file could not be read.');
        }
        $id = AssetId::generate();
        $key = StorageKey::for($id);
        $this->files->put($key, $file->path);

        return new ResourceAsset($id, $key, $name->value, $kind->mediaType(), $size, $digest, $by, $now);
    }
}
