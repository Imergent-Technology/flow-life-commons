<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\IncomingFile;
use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Infrastructure\DetectionSamples;
use App\Modules\Resources\Infrastructure\DiskResourceFileStore;
use App\Shared\Domain\Actor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use ZipArchive;

/**
 * Files for the Resources tests (WP3). Every sample is REAL content of its kind, small enough to read: these are the bytes whose
 * `fileinfo` detection was measured for ADR 0037 decision 64, so a test that uploads "a PDF" uploads something libmagic calls a PDF.
 *
 * The store is the real `DiskResourceFileStore` over a faked `resources` disk: the same configuration as production (private, `throw`,
 * links skipped), rooted in a per-run test directory that the fake empties first. The concurrency worker is pointed at the same
 * directory through RESOURCES_TEST_DISK_ROOT, so both processes of a race share one store.
 */
final class ResourceFiles
{
    public const string PDF = DetectionSamples::PDF;

    /** The faked store's root: what the real disk would be, under the test run's own directory. */
    public static function fake(): string
    {
        $config = config()->array('filesystems.disks.resources');
        Storage::fake(DiskResourceFileStore::DISK, $config);
        $root = Storage::disk(DiskResourceFileStore::DISK)->path('');

        return rtrim($root, '/');
    }

    /** @return list<string> the names of every file in the store, sorted */
    public static function stored(): array
    {
        $names = Storage::disk(DiskResourceFileStore::DISK)->files('');
        sort($names);

        return $names;
    }

    public static function storedBytes(string $key): string
    {
        return Storage::disk(DiskResourceFileStore::DISK)->get($key) ?? throw new LogicException("no stored file {$key}");
    }

    public static function pdf(string $marker = ''): string
    {
        return self::PDF.($marker === '' ? '' : "% {$marker}\n");
    }

    public static function png(): string
    {
        return self::decode(DetectionSamples::PNG);
    }

    public static function gif(): string
    {
        return self::decode(DetectionSamples::GIF);
    }

    public static function jpeg(): string
    {
        return self::decode(DetectionSamples::JPEG);
    }

    public static function webp(): string
    {
        return self::decode(DetectionSamples::WEBP);
    }

    /**
     * An Office Open XML package with the given main part (`word/document.xml`, `xl/workbook.xml`, `ppt/presentation.xml`). By default
     * in Microsoft Office's entry order, `[Content_Types].xml` first, which libmagic recognises; `$officeOrder: false` puts it last,
     * as other producers may, which libmagic reports as a plain zip.
     */
    public static function office(string $mainPart, bool $officeOrder = true): string
    {
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
        $entries = $officeOrder
            ? ['[Content_Types].xml' => $types, '_rels/.rels' => $rels, $mainPart => '<x/>']
            : ['_rels/.rels' => $rels, $mainPart => '<x/>', '[Content_Types].xml' => $types];

        return self::zip($entries);
    }

    /** @param  array<string, string>  $entries */
    public static function zip(array $entries): string
    {
        $path = self::temporary('');
        unlink($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('could not build a zip');
        }
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** An upload as Laravel hands it to a request; the client's claimed type is whatever is given, and must never matter. */
    public static function upload(string $name, string $bytes, string $claimedType = 'application/octet-stream', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(self::temporary($bytes), $name, $claimedType, $error, true);
    }

    /** An upload as the use cases receive it. */
    public static function incoming(string $name, string $bytes): IncomingFile
    {
        return new IncomingFile(self::temporary($bytes), $name);
    }

    /** A Draft File Card holding `$bytes` as `$name`, through the real use case. */
    public static function card(Actor $by, PackId|ManagedPackView $pack, string $title = 'A file', string $name = 'guide.pdf', ?string $bytes = null): ManagedCardView
    {
        return app(CreateCard::class)($by, Resources::id($pack), CardType::File, $title, Resources::doc('About this file'), null, null, self::incoming($name, $bytes ?? self::pdf()));
    }

    public static function publishedCard(Actor $by, PackId|ManagedPackView $pack, string $title = 'A file', string $name = 'guide.pdf', ?string $bytes = null): ManagedCardView
    {
        $card = self::card($by, $pack, $title, $name, $bytes);

        return app(PublishCard::class)($by, Resources::id($pack), $card->card->id);
    }

    public static function asset(ManagedCardView $card): ResourceAsset
    {
        return $card->card->asset ?? throw new LogicException('that Card has no file');
    }

    private static function temporary(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'flc-upload-');
        if ($path === false) {
            throw new RuntimeException('no temporary file');
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function decode(string $base64): string
    {
        $bytes = base64_decode($base64, true);

        return $bytes === false ? throw new LogicException('bad sample') : $bytes;
    }
}
