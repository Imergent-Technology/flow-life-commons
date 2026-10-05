<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\FileKind;
use RuntimeException;
use ZipArchive;

/**
 * Small, real examples of every allowed kind and of what must be refused, with what the server must make of each (ADR 0037, decision
 * 64). They are the bytes whose `fileinfo` detection was measured in development and pinned by the tests, and what
 * `resources:assets:probe` runs on a host to show its `fileinfo` agrees. Nothing here is written anywhere but a temporary file.
 */
final class DetectionSamples
{
    public const string PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public const string GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public const string JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    public const string WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

    /**
     * name => [filename, bytes, the kind it must be accepted as, or null when it must be refused].
     *
     * @return array<string, array{string, string, FileKind|null}>
     */
    public static function all(): array
    {
        return [
            'PDF' => ['a.pdf', self::PDF, FileKind::Pdf],
            'PNG' => ['a.png', self::decode(self::PNG), FileKind::Png],
            'JPEG' => ['a.jpg', self::decode(self::JPEG), FileKind::Jpeg],
            'WebP' => ['a.webp', self::decode(self::WEBP), FileKind::Webp],
            'GIF' => ['a.gif', self::decode(self::GIF), FileKind::Gif],
            'text' => ['a.txt', "Hello, this is a plain text file.\nSecond line.\n", FileKind::Text],
            'CSV, comma' => ['a.csv', "name,email,count\nAda,ada@example.org,3\nGrace,grace@example.org,5\n", FileKind::Csv],
            'CSV, semicolon' => ['a.csv', "name;email\nAda;ada@example.org\n", FileKind::Csv],
            'DOCX' => ['a.docx', self::office('word/document.xml', true), FileKind::Docx],
            'DOCX, other entry order' => ['a.docx', self::office('word/document.xml', false), FileKind::Docx],
            'XLSX' => ['a.xlsx', self::office('xl/workbook.xml', true), FileKind::Xlsx],
            'PPTX' => ['a.pptx', self::office('ppt/presentation.xml', true), FileKind::Pptx],
            'HTML named .txt' => ['a.txt', "Some notes\n<script>alert(1)</script>\n", null],
            'SVG named .png' => ['a.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', null],
            'PHP named .pdf' => ['a.pdf', "<?php echo 1;\n", null],
            'executable named .pdf' => ['a.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff".str_repeat("\x00", 50), null],
            'zip named .docx' => ['a.docx', self::zip(['a.txt' => 'hello']), null],
            'empty .txt' => ['a.txt', '', null],
        ];
    }

    /** An Office Open XML package with one main part, `[Content_Types].xml` first (Microsoft's order) or last. */
    public static function office(string $mainPart, bool $officeOrder): string
    {
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';

        return self::zip($officeOrder
            ? ['[Content_Types].xml' => $types, '_rels/.rels' => $rels, $mainPart => '<x/>']
            : ['_rels/.rels' => $rels, $mainPart => '<x/>', '[Content_Types].xml' => $types]);
    }

    /** @param  array<string, string>  $entries */
    public static function zip(array $entries): string
    {
        $path = self::temporary('');
        unlink($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('could not build a sample package');
        }
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** A temporary file holding `$bytes`; the caller removes it. */
    public static function temporary(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'flc-sample-');
        if ($path === false) {
            throw new RuntimeException('no temporary directory');
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function decode(string $base64): string
    {
        $bytes = base64_decode($base64, true);

        return $bytes === false ? throw new RuntimeException('bad sample') : $bytes;
    }
}
