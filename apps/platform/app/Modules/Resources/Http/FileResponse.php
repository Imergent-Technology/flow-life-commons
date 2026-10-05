<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\FileDownload;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A File Card's file on the wire (ADR 0037, decision 66): streamed from the store, never read into memory or written anywhere public.
 *
 * - `Content-Type` is the stored, allowlisted type, never what the uploader's browser claimed.
 * - `Content-Disposition` is `attachment` unless `inline` was asked for AND the file is a PDF or raster image. The filename is the
 *   sanitised original name, encoded per RFC 6266: an ASCII fallback (`filename=`, quoted, with anything that is not printable ASCII
 *   or could break the header replaced) and the exact name as UTF-8 (`filename*=`, percent-encoded). The sanitised name already has
 *   no control character, CR or LF, so nothing a client named a file can add a header. The storage key is never the filename.
 * - `Cache-Control: private, no-store`: authorized content stays out of every shared and local cache.
 * - `X-Content-Type-Options: nosniff`, set here as well as by the platform's security headers, which also put the production CSP
 *   (`default-src 'none'`, `frame-ancestors 'none'`) on this response as on every other.
 */
final class FileResponse
{
    public static function for(FileDownload $file, bool $inlineRequested): StreamedResponse
    {
        $disposition = $inlineRequested && $file->opensInline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        $stream = $file->stream;

        $response = new StreamedResponse(static function () use ($stream): void {
            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
            }
            fclose($stream);
        }, 200, [
            'Content-Type' => $file->mediaType,
            'Content-Length' => (string) $file->size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($disposition, $file->filename, self::fallback($file->filename)));

        return $response;
    }

    /** The ASCII-only form of a filename, for clients that ignore `filename*`. */
    public static function fallback(string $filename): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]|[%"\\\\\/]/', '_', Str::ascii($filename));
        $ascii = trim($ascii);

        return $ascii === '' ? 'download' : $ascii;
    }
}
