<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\FileKind;
use App\Modules\Resources\Domain\MediaTypeDetector;
use finfo;
use ZipArchive;

/**
 * Detects a file's media type from its bytes with PHP's `fileinfo` (libmagic), which the production host has (verified 2026-09-21,
 * and checked by `security:production-check`). The client's claimed type plays no part.
 *
 * One refinement, measured rather than assumed: libmagic recognises an Office Open XML package (DOCX, XLSX, PPTX) only when its zip
 * entries come in the order Microsoft Office writes them, `[Content_Types].xml` first, and reports any other well-formed package as
 * plain `application/zip` (measured with libmagic 5.43, PHP 8.3.33). Whether a real document is accepted would then depend on which
 * program saved it. So when libmagic says `application/zip`, the package's own directory is read (ZipArchive, read-only; nothing is
 * extracted or decompressed): it is that Office type only if it declares its content types (`[Content_Types].xml`) and holds exactly
 * one Office main part (`word/document.xml`, `xl/workbook.xml` or `ppt/presentation.xml`). Any other zip stays `application/zip`,
 * which the allowlist refuses: archives are never accepted.
 */
final class FinfoMediaTypeDetector implements MediaTypeDetector
{
    private const array OFFICE_MAIN_PARTS = [
        'word/document.xml' => FileKind::DOCX_TYPE,
        'xl/workbook.xml' => FileKind::XLSX_TYPE,
        'ppt/presentation.xml' => FileKind::PPTX_TYPE,
    ];

    public function detect(string $path): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $type = is_string($detected) && $detected !== '' ? strtolower($detected) : 'application/octet-stream';

        return $type === 'application/zip' ? ($this->officePackage($path) ?? $type) : $type;
    }

    private function officePackage(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return null;
            }
            $found = array_values(array_filter(
                self::OFFICE_MAIN_PARTS,
                static fn (string $part): bool => $zip->locateName($part) !== false,
                ARRAY_FILTER_USE_KEY,
            ));

            return count($found) === 1 ? $found[0] : null;
        } finally {
            $zip->close();
        }
    }
}
