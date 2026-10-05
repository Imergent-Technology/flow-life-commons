<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\AssetLimits;
use App\Modules\Resources\Application\FileTooLarge;
use App\Modules\Resources\Application\IncomingFile;
use Illuminate\Http\UploadedFile;

/**
 * How a Resources request reads its one uploaded file, the `file` part of a multipart form (ADR 0037, decision 64).
 *
 * - A file PHP refused for its own size limits (`UPLOAD_ERR_INI_SIZE`, `UPLOAD_ERR_FORM_SIZE`) is answered as the application's
 *   `413 file_too_large`, not as a failed upload, so the answer for a too-large file is the same whichever limit it met first.
 * - Anything else that is not one successfully uploaded file (absent, a plain field, several parts named `file`, a partial or failed
 *   upload) is the `file` validation rule's 422.
 * - Only the temporary path and the client's filename go further. The client's `Content-Type` for the part is never read.
 */
final class UploadedFileInput
{
    /** @throws FileTooLarge */
    public static function refuseOversized(mixed $file): void
    {
        if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new FileTooLarge(AssetLimits::maxBytes());
        }
    }

    public static function incoming(mixed $file): ?IncomingFile
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }
        $path = $file->getRealPath();

        return new IncomingFile(is_string($path) ? $path : $file->getPathname(), $file->getClientOriginalName());
    }
}
