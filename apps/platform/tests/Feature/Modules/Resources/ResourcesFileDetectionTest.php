<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\FileKind;
use App\Modules\Resources\Domain\MediaTypeDetector;
use App\Modules\Resources\Infrastructure\DetectionSamples;
use App\Modules\Resources\Infrastructure\FinfoMediaTypeDetector;
use Illuminate\Testing\PendingCommand;
use Tests\Support\ResourceFiles;

use function Pest\Laravel\artisan;

/*
 * What PHP's `fileinfo` actually reports for real bytes, pinned (ADR 0037, decision 64: "measures what fileinfo reports for each allowed
 * type ... and pins it"). Measured in development on PHP 8.3.33 with its bundled libmagic 5.43; the production host runs PHP 8.3.33 too,
 * but whether its fileinfo is the same build is an operator check (the runbook gives the probe). If a PHP upgrade changes an answer,
 * this test is where it shows, before a user's upload is refused or, worse, accepted.
 *
 * The detector is the real one; nothing here is mocked.
 */

function detectedType(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'flc-detect-');
    file_put_contents($path, $bytes);
    try {
        return (new FinfoMediaTypeDetector)->detect($path);
    } finally {
        unlink($path);
    }
}

it('runs on the libmagic the measurements were taken with', function () {
    ob_start();
    (new ReflectionExtension('fileinfo'))->info();
    $info = strip_tags((string) ob_get_clean());

    expect($info)->toMatch('/libmagic\s*=>\s*5\d\d/');
});

it('detects every allowed kind as the type the allowlist names for it', function (string $bytes, string $expected) {
    expect(detectedType($bytes))->toBe($expected);
})->with([
    'PDF' => [ResourceFiles::pdf(), 'application/pdf'],
    'PNG' => [ResourceFiles::png(), 'image/png'],
    'JPEG' => [ResourceFiles::jpeg(), 'image/jpeg'],
    'WebP' => [ResourceFiles::webp(), 'image/webp'],
    'GIF' => [ResourceFiles::gif(), 'image/gif'],
    'plain text' => ["Hello, this is a plain text file.\nSecond line.\n", 'text/plain'],
    'UTF-8 text' => ["Caf\u{e9} na\u{ef}ve \u{2014} r\u{e9}sum\u{e9}\n", 'text/plain'],
    'comma CSV' => ["name,email,count\nAda,ada@example.org,3\nGrace,grace@example.org,5\n", 'text/csv'],
    'semicolon CSV' => ["name;email\nAda;ada@example.org\n", 'text/plain'],
    'DOCX, Office entry order' => [ResourceFiles::office('word/document.xml'), FileKind::DOCX_TYPE],
    'XLSX, Office entry order' => [ResourceFiles::office('xl/workbook.xml'), FileKind::XLSX_TYPE],
    'PPTX, Office entry order' => [ResourceFiles::office('ppt/presentation.xml'), FileKind::PPTX_TYPE],
]);

it('recognises an Office package whatever order its entries are in, by reading its directory', function (string $part, string $type) {
    // libmagic alone calls these plain zips (measured); the package's declared content types and main part decide.
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer(ResourceFiles::office($part, officeOrder: false)))->toBe('application/zip')
        ->and(detectedType(ResourceFiles::office($part, officeOrder: false)))->toBe($type);
})->with([
    'docx' => ['word/document.xml', FileKind::DOCX_TYPE],
    'xlsx' => ['xl/workbook.xml', FileKind::XLSX_TYPE],
    'pptx' => ['ppt/presentation.xml', FileKind::PPTX_TYPE],
]);

it('never turns a zip libmagic calls a zip into an Office document unless the package says it is one', function (array $entries) {
    /** @var array<string, string> $entries */
    $bytes = ResourceFiles::zip($entries);

    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes))->toBe('application/zip') // the refinement applies only here
        ->and(detectedType($bytes))->toBe('application/zip');
})->with([
    'an ordinary archive' => [['a.txt' => 'hello']],
    'a main part but no declared content types' => [['b.txt' => 'x', 'word/document.xml' => '<x/>']],
    'declared types but no main part' => [['b.txt' => 'x', '[Content_Types].xml' => '<Types/>']],
    'two main parts' => [['_rels/.rels' => '<x/>', 'word/document.xml' => '<x/>', 'xl/workbook.xml' => '<x/>', '[Content_Types].xml' => '<Types/>']],
]);

it('records libmagic\'s own leniency: it already calls some loosely built zips Office documents, which the refinement does not change', function () {
    // Measured, not designed: a zip whose FIRST entry is a main part is a DOCX to libmagic, and one with two main parts is an XLSX. Such a
    // file is accepted only under that kind's extension, stored and served as that type, as an attachment: a mislabelled archive, never
    // active content.
    expect(detectedType(ResourceFiles::zip(['word/document.xml' => '<x/>', 'b.txt' => 'x'])))->toBe(FileKind::DOCX_TYPE)
        ->and(detectedType(ResourceFiles::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<x/>', 'xl/workbook.xml' => '<x/>'])))->toBe(FileKind::XLSX_TYPE);
});

it('detects active, executable and archive content as something the allowlist never accepts', function (string $bytes, string $expected) {
    $detected = detectedType($bytes);

    expect($detected)->toBe($expected);
    foreach (FileKind::cases() as $kind) {
        expect(in_array($detected, $kind->detectedTypes(), true))->toBeFalse("{$expected} would pass as {$kind->value}");
    }
})->with([
    'HTML' => ["<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n", 'text/html'],
    'a script tag after a line of text' => ["Some notes\n<script>alert(1)</script>\n", 'text/html'],
    'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
    'SVG with an XML declaration' => ["<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"/>", 'image/svg+xml'],
    'XML' => ['<?xml version="1.0"?><root/>', 'text/xml'],
    'PHP' => ["<?php echo 'hi';\n", 'text/x-php'],
    'shell script' => ["#!/bin/sh\necho hi\n", 'text/x-shellscript'],
    'Windows executable' => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff".str_repeat("\x00", 50), 'application/x-dosexec'],
    'ELF header alone' => ["\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 56), 'application/octet-stream'],
    'zip' => [ResourceFiles::zip(['a.txt' => 'hello']), 'application/zip'],
    'OpenDocument' => [ResourceFiles::zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<x/>']), 'application/vnd.oasis.opendocument.text'],
    'empty' => ['', 'application/x-empty'],
]);

it('records what the type check cannot see: script that libmagic reads as text, and a polyglot, are served only as their stored type', function () {
    // Not refusals, and not claimed to be. JavaScript without markup is "text/plain"; GIF bytes followed by PHP are "image/gif". Both are
    // safe as stored because a Resources file is never executed, never in the web root, has no extension on disk, and is served with
    // its stored type, `nosniff`, and as an attachment unless it is a PDF or raster image asked for inline (ADR 0037, decision 67).
    expect(detectedType("function x(){ return 1; }\nalert(1);\n"))->toBe('text/plain')
        ->and(detectedType("GIF89a<?php echo 'hi'; ?>"))->toBe('image/gif');
});

it('gives operators a read-only probe that decides every example as development does, so the production host can be measured', function () {
    $probe = artisan('resources:assets:probe');
    assert($probe instanceof PendingCommand);
    $probe->expectsOutputToContain('libmagic 5')
        ->expectsOutputToContain('Every example is decided as in development.')
        ->doesntExpectOutputToContain('✗')
        ->assertSuccessful()
        ->run();

    // Its examples are the allowlist: every kind appears, each accepted as itself, and every refusal is one.
    $accepted = [];
    foreach (DetectionSamples::all() as [, , $kind]) {
        if ($kind !== null) {
            $accepted[] = $kind->value;
        }
    }
    expect(array_values(array_unique($accepted)))->toEqualCanonicalizing(array_map(fn (FileKind $k): string => $k->value, FileKind::cases()));
});

it('fails the probe when this host would decide an example differently', function () {
    app()->instance(MediaTypeDetector::class, new class implements MediaTypeDetector
    {
        public function detect(string $path): string
        {
            return 'application/zip'; // a host whose fileinfo knows no Office documents, say
        }
    });

    $probe = artisan('resources:assets:probe');
    assert($probe instanceof PendingCommand);
    $probe->expectsOutputToContain('decided differently')->assertFailed()->run();
});
