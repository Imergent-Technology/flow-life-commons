<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\AssetId;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardSummary;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\FileKind;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\OriginalFilename;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Domain\StorageKey;
use App\Shared\Domain\PersonId;

/*
 * The file policy in plain PHP (ADR 0037, decisions 63-64): which (extension, detected type) pairs are a kind, how a client's
 * filename is made safe to keep and show, and what a storage key may look like. What libmagic actually reports for real bytes is
 * pinned separately (tests/Feature/Modules/Resources/ResourcesFileDetectionTest.php).
 */

it('allows exactly the ten Phase 1 kinds, each with its canonical type, and only PDF and raster images inline', function () {
    $kinds = [];
    foreach (FileKind::cases() as $kind) {
        $kinds[$kind->value] = [$kind->extensions(), $kind->mediaType(), $kind->opensInline()];
    }

    expect($kinds)->toBe([
        'pdf' => [['pdf'], 'application/pdf', true],
        'png' => [['png'], 'image/png', true],
        'jpeg' => [['jpg', 'jpeg'], 'image/jpeg', true],
        'webp' => [['webp'], 'image/webp', true],
        'gif' => [['gif'], 'image/gif', true],
        'text' => [['txt'], 'text/plain', false],
        'csv' => [['csv'], 'text/csv', false],
        'docx' => [['docx'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'xlsx' => [['xlsx'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'pptx' => [['pptx'], 'application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
    ]);
});

it('accepts a file only when its extension and its DETECTED type agree on one kind', function (string $extension, string $detected, ?FileKind $kind) {
    expect(FileKind::judge($extension, $detected))->toBe($kind);
})->with([
    'pdf' => ['pdf', 'application/pdf', FileKind::Pdf],
    'upper-case extension' => ['PDF', 'application/pdf', FileKind::Pdf],
    'jpg' => ['jpg', 'image/jpeg', FileKind::Jpeg],
    'jpeg' => ['jpeg', 'image/jpeg', FileKind::Jpeg],
    'semicolon csv (libmagic: text/plain)' => ['csv', 'text/plain', FileKind::Csv],
    'comma text (libmagic: text/csv)' => ['txt', 'text/csv', FileKind::Text],
    'docx' => ['docx', FileKind::DOCX_TYPE, FileKind::Docx],
    // Disagreement is a refusal, whichever way round.
    'a PDF named .png' => ['png', 'application/pdf', null],
    'a PNG named .pdf' => ['pdf', 'image/png', null],
    'PHP named .pdf' => ['pdf', 'text/x-php', null],
    'HTML named .txt' => ['txt', 'text/html', null],
    'a docx named .xlsx' => ['xlsx', FileKind::DOCX_TYPE, null],
    'text named .pdf' => ['pdf', 'text/plain', null],
    // Never on the list, whatever the extension.
    'svg' => ['svg', 'image/svg+xml', null],
    'svg named .png' => ['png', 'image/svg+xml', null],
    'html' => ['html', 'text/html', null],
    'xml' => ['xml', 'text/xml', null],
    'php' => ['php', 'text/x-php', null],
    'javascript' => ['js', 'text/javascript', null],
    'shell script' => ['sh', 'text/x-shellscript', null],
    'windows executable' => ['exe', 'application/x-dosexec', null],
    'a zip' => ['zip', 'application/zip', null],
    'a zip named .docx' => ['docx', 'application/zip', null],
    'unknown bytes' => ['pdf', 'application/octet-stream', null],
    'empty' => ['txt', 'application/x-empty', null],
    'no extension' => ['', 'application/pdf', null],
]);

it('never names a type outside the allowlist as a kind, so nothing else is ever served inline', function () {
    expect(FileKind::ofMediaType('image/svg+xml'))->toBeNull()
        ->and(FileKind::ofMediaType('text/html'))->toBeNull()
        ->and(FileKind::ofMediaType('application/pdf'))->toBe(FileKind::Pdf);
});

it('keeps a filename as written where that is safe, and takes its extension lower-cased', function (string $raw, string $kept, string $extension) {
    $name = OriginalFilename::fromClient($raw);

    expect($name->value)->toBe($kept)->and($name->extension)->toBe($extension);
})->with([
    'plain' => ['Opening checklist.pdf', 'Opening checklist.pdf', 'pdf'],
    'upper-case extension' => ['MAP.PNG', 'MAP.PNG', 'png'],
    'quotes, accents, emoji and a second dot stay' => ['Café "menu" v2.final 🍰.pdf', 'Café "menu" v2.final 🍰.pdf', 'pdf'],
    'decomposed accents become one form (NFC)' => ["Cafe\u{301}.txt", "Caf\u{e9}.txt", 'txt'],
    'whitespace runs collapse, ends trimmed' => ["  two   spaces\tand a tab .csv  ", 'two spaces and a tab .csv', 'csv'],
    'trailing dots dropped' => ['report.pdf...', 'report.pdf', 'pdf'],
    'no extension' => ['README', 'README', ''],
    'dot-only extension is none' => ['archive.', 'archive', ''],
    'hidden-file style keeps its extension' => ['.pdf', '.pdf', 'pdf'],
    'a non-alphanumeric extension is none' => ['x.p-d-f', 'x.p-d-f', ''],
]);

it('drops every directory part, whatever the separator, so a name is never a path', function (string $raw, string $kept) {
    expect(OriginalFilename::fromClient($raw)->value)->toBe($kept);
})->with([
    'unix traversal' => ['../../etc/passwd', 'passwd'],
    'windows path' => ['C:\\Users\\ed\\Desktop\\report.pdf', 'report.pdf'],
    'mixed separators' => ['a/b\\c/../d.txt', 'd.txt'],
    'trailing separator leaves nothing' => ['folder/', ''],
    'dot-dot alone' => ['..', ''],
]);

it('removes control characters, CR and LF, and the bidirectional overrides that disguise an extension', function (string $raw, string $kept, string $extension) {
    $name = OriginalFilename::fromClient($raw);

    expect($name->value)->toBe($kept)->and($name->extension)->toBe($extension)
        ->and(preg_match('/[\x00-\x1F\x7F]/', $name->value))->toBe(0);
})->with([
    'header injection' => ["evil.pdf\r\nSet-Cookie: x=1", 'evil.pdf Set-Cookie: x=1', ''],
    'NUL' => ["report\0.pdf", 'report.pdf', 'pdf'],
    'right-to-left override' => ["invoice\u{202E}fdp.exe", 'invoicefdp.exe', 'exe'],
    'isolates' => ["a\u{2066}b\u{2069}.txt", 'ab.txt', 'txt'],
    'line separator' => ["a\u{2028}b.csv", 'a b.csv', 'csv'],
    'C1 control' => ["a\u{90}b.txt", 'ab.txt', 'txt'],
    'next-line is whitespace' => ["a\u{85}b.txt", 'a b.txt', 'txt'],
]);

it('keeps joiners and direction marks, which are ordinary text', function () {
    expect(OriginalFilename::fromClient("\u{200F}שלום\u{200D}.txt")->value)->toBe("\u{200F}שלום\u{200D}.txt");
});

it('replaces invalid UTF-8 rather than guessing at it', function () {
    $name = OriginalFilename::fromClient("bad\xC3\x28name.pdf");

    expect(mb_check_encoding($name->value, 'UTF-8'))->toBeTrue()->and($name->extension)->toBe('pdf');
});

it('bounds a name at 255 characters, cutting the base so the extension survives', function () {
    $name = OriginalFilename::fromClient(str_repeat('é', 400).'.PDF');

    expect(mb_strlen($name->value))->toBe(255)
        ->and(str_ends_with($name->value, '.PDF'))->toBeTrue()
        ->and($name->extension)->toBe('pdf')
        ->and(mb_strlen(OriginalFilename::fromClient(str_repeat('a', 300))->value))->toBe(255);
});

it('derives a storage key from the asset id alone, in the one shape the store writes', function () {
    $id = AssetId::generate();
    $key = StorageKey::for($id);

    expect($key->value)->toBe($id->value)
        ->and(StorageKey::tryFromName($key->value)?->value)->toBe($key->value)
        ->and(StorageKey::fromStored($key->value)->equals($key))->toBeTrue();
});

it('recognises no other name as a storage key: no path, extension, case, dotfile or traversal', function (string $name) {
    expect(StorageKey::tryFromName($name))->toBeNull()
        ->and(fn () => StorageKey::fromStored($name))->toThrow(InvalidArgumentException::class);
})->with([
    'upper case' => ['01J9ZZZZZZZZZZZZZZZZZZZZZZ'],
    'with extension' => ['01j9zzzzzzzzzzzzzzzzzzzzzz.pdf'],
    'in a directory' => ['a/01j9zzzzzzzzzzzzzzzzzzzzzz'],
    'traversal' => ['../01j9zzzzzzzzzzzzzzzzzzzzzz'],
    'dotfile' => ['.gitignore'],
    'too short' => ['01j9zzzzzzzzzzzzzzzzzzzzz'],
    'excluded letter' => ['01j9zzzzzzzzzzzzzzzzzzzzzi'],
    'first character out of range' => ['81j9zzzzzzzzzzzzzzzzzzzzzz'],
    'trailing newline' => ["01j9zzzzzzzzzzzzzzzzzzzzzz\n"],
]);

it('holds the File Card invariant in the domain itself: a File Card has a file, nothing else has one, and only a File Card\'s can be replaced', function () {
    $asset = new ResourceAsset(AssetId::generate(), StorageKey::for(AssetId::generate()), 'a.pdf', 'application/pdf', 10, str_repeat('a', 64), PersonId::generate(), new DateTimeImmutable);
    $draft = fn (CardType $type, ?ResourceAsset $file): Card => Card::draft(
        CardId::generate(), PackId::generate(), 1, $type, 'Title', ContentDocument::empty(), null, $file, CardSummary::custom('x'), PersonId::generate(), new DateTimeImmutable,
    );

    expect(fn () => $draft(CardType::File, null))->toThrow(InvalidResourceInput::class)
        ->and(fn () => $draft(CardType::Basic, $asset))->toThrow(InvalidResourceInput::class)
        ->and(fn () => $draft(CardType::Basic, null)->withAsset($asset, PersonId::generate(), new DateTimeImmutable))->toThrow(InvalidResourceInput::class)
        ->and($draft(CardType::File, $asset)->unmetPublishRequirements())->toBe([])
        ->and(fn () => Card::addressFor(CardType::File, 'https://example.org'))->toThrow(InvalidResourceInput::class);
});
