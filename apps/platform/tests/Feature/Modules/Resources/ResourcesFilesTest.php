<?php

declare(strict_types=1);

use App\Modules\Resources\Application\AssetLimits;
use App\Modules\Resources\Application\CardNotFound;
use App\Modules\Resources\Application\CardNotPublishable;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Application\DeletePack;
use App\Modules\Resources\Application\FileTooLarge;
use App\Modules\Resources\Application\FileTypeNotAllowed;
use App\Modules\Resources\Application\GetManagedCard;
use App\Modules\Resources\Application\PackNotFound;
use App\Modules\Resources\Application\PruneOrphanedAssets;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\ReplaceCardFile;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Domain\AssetId;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\FileKind;
use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PublicationState;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Modules\Resources\Domain\StorageKey;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Faults;
use Tests\Support\FaultyFileStore;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;
use Tests\Support\ResourcesPauses;

use function Pest\Laravel\artisan;

/*
 * Managed files (ADR 0037, decisions 18, 23, 55, 58-68; WP3), through the real use cases and the real store over a faked private
 * disk. What is proved: a File Card is created WITH its file and never exists without one; only an allowlisted file whose name and
 * detected content agree is accepted, and a refused one writes nothing; a file is written before its row commits and removed only
 * after its row's removal commits, so no failure can leave a row naming a file that is not there; replacement keeps the Card and its
 * old file until the swap commits; deletion keeps its audit shape and counts files truly; the prune removes only what nothing refers
 * to. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    ResourceFiles::fake();
});

/** @return array{Actor, PackId} an editor and a Published Pack (guardian) holding one Published File Card */
function publishedFilePack(string $name = 'guide.pdf', ?string $bytes = null): array
{
    $by = Resources::editor();
    $category = Resources::category($by, 'Files')->category->id;
    $pack = Resources::pack($by, 'Handbook', $category);
    ResourceFiles::publishedCard($by, $pack, 'The guide', $name, $bytes);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
    app(PublishPack::class)($by, $pack->pack->id);

    return [$by, $pack->pack->id];
}

/** @return array<string, mixed> */
function fileEventContext(stdClass $event): array
{
    $context = json_decode(Resources::str($event->context), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($context));

    /** @var array<string, mixed> $context */
    return $context;
}

/** The id of the one Card there is. */
function onlyCardId(): CardId
{
    expect(DB::table('resource_cards')->count())->toBe(1);

    return CardId::fromString(Resources::str(DB::table('resource_cards')->value('id')));
}

/** Makes a stored file look `$hours` old, for the prune's grace period. */
function ageStored(string $key, int $hours): void
{
    touch(Storage::disk('resources')->path($key), time() - $hours * 3600);
}

// --- Creation -----------------------------------------------------------------------------------------------------------------

it('creates a Draft File Card WITH its file: the bytes in the private store under a key derived from the asset id, the metadata on record', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $bytes = ResourceFiles::pdf('opening');

    $card = ResourceFiles::card($by, $pack, 'Opening guide', 'Opening guide.pdf', $bytes);
    $asset = ResourceFiles::asset($card);

    expect($card->card->type)->toBe(CardType::File)
        ->and($card->card->state)->toBe(PublicationState::Draft)
        ->and($card->card->revision)->toBe(1)
        ->and($card->card->externalUri)->toBeNull()
        ->and($asset->storageKey->value)->toBe($asset->id->value)
        ->and($asset->originalFilename)->toBe('Opening guide.pdf')
        ->and($asset->mediaType)->toBe('application/pdf')
        ->and($asset->byteSize)->toBe(strlen($bytes))
        ->and($asset->sha256)->toBe(hash('sha256', $bytes))
        ->and($asset->uploadedBy->equals($by->personId))->toBeTrue()
        ->and(ResourceFiles::stored())->toBe([$asset->storageKey->value])
        ->and(ResourceFiles::storedBytes($asset->storageKey->value))->toBe($bytes);

    $row = Resources::row('resource_assets', $asset->id->value);
    expect(array_keys((array) $row))->toEqualCanonicalizing(['id', 'storage_key', 'original_filename', 'media_type', 'byte_size', 'sha256', 'uploaded_by_person_id', 'created_at'])
        ->and(Resources::str($row->storage_key))->toBe($asset->id->value)
        ->and(Resources::str(Resources::row('resource_cards', $card->card->id->value)->asset_id))->toBe($asset->id->value)
        // Read back, the Card is the same: nothing about the file lives anywhere but the asset row and the store.
        ->and(app(GetManagedCard::class)($by, $pack->pack->id, $card->card->id)->card->asset?->sha256)->toBe($asset->sha256);
});

it('stores the kind\'s canonical type, judged from content and extension together', function (string $name, string $bytes, string $stored) {
    $by = Resources::editor();

    expect(ResourceFiles::asset(ResourceFiles::card($by, Resources::pack($by), 'x', $name, $bytes))->mediaType)->toBe($stored);
})->with([
    'png' => ['map.PNG', ResourceFiles::png(), 'image/png'],
    'jpeg as .jpg' => ['photo.jpg', ResourceFiles::jpeg(), 'image/jpeg'],
    'webp' => ['photo.webp', ResourceFiles::webp(), 'image/webp'],
    'gif' => ['spinner.gif', ResourceFiles::gif(), 'image/gif'],
    'text' => ['notes.txt', "Some notes\n", 'text/plain'],
    'semicolon csv (detected text/plain)' => ['list.csv', "a;b\n1;2\n", 'text/csv'],
    'docx' => ['letter.docx', ResourceFiles::office('word/document.xml'), FileKind::DOCX_TYPE],
    'docx in another producer\'s entry order' => ['letter.docx', ResourceFiles::office('word/document.xml', officeOrder: false), FileKind::DOCX_TYPE],
    'xlsx' => ['sheet.xlsx', ResourceFiles::office('xl/workbook.xml'), FileKind::XLSX_TYPE],
    'pptx' => ['deck.pptx', ResourceFiles::office('ppt/presentation.xml'), FileKind::PPTX_TYPE],
]);

it('refuses a file whose type is not allowed, or whose name and content disagree, and writes nothing', function (string $name, string $bytes) {
    $by = Resources::editor();
    $pack = Resources::pack($by);

    expect(fn () => ResourceFiles::card($by, $pack, 'x', $name, $bytes))->toThrow(FileTypeNotAllowed::class)
        ->and(ResourceFiles::stored())->toBe([])
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0);
})->with([
    'HTML' => ['page.html', '<!DOCTYPE html><html><script>alert(1)</script></html>'],
    'HTML named .txt' => ['notes.txt', "Notes\n<script>alert(1)</script>\n"],
    'SVG named .png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
    'XML' => ['data.xml', '<?xml version="1.0"?><root/>'],
    'PHP named .pdf' => ['invoice.pdf', "<?php system(\$_GET['c']);\n"],
    'PHP' => ['shell.php', "<?php echo 1;\n"],
    'executable named .pdf' => ['report.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff".str_repeat("\x00", 50)],
    'a PDF named .png' => ['picture.png', ResourceFiles::pdf()],
    'a PNG named .pdf' => ['report.pdf', ResourceFiles::png()],
    'a zip' => ['archive.zip', ResourceFiles::zip(['a.txt' => 'x'])],
    'a zip named .docx' => ['letter.docx', ResourceFiles::zip(['a.txt' => 'x'])],
    'a docx named .xlsx' => ['sheet.xlsx', ResourceFiles::office('word/document.xml')],
    'an empty file' => ['empty.txt', ''],
    'no extension' => ['guide', ResourceFiles::pdf()],
    'a path that ends in a separator' => ['../../', ResourceFiles::pdf()],
    'an extension disguised by a bidi override' => ["report\u{202E}fdp.exe", ResourceFiles::pdf()],
]);

it('keeps only the sanitised last segment of the client\'s name, never a path', function () {
    $by = Resources::editor();
    $asset = ResourceFiles::asset(ResourceFiles::card($by, Resources::pack($by), 'x', "../../etc/C:\\temp\\Plan\r\n  A.pdf", ResourceFiles::pdf()));

    expect($asset->originalFilename)->toBe('Plan A.pdf')
        ->and(ResourceFiles::stored())->toBe([$asset->storageKey->value]);
});

it('refuses a file over the configured limit before writing it, and accepts one exactly at it', function () {
    config(['resources.assets.max_bytes' => AssetLimits::FLOOR]);
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $line = "abcdefghijklmnopqrstuvwxyz0123456789 the quick brown fox\n";
    $exactly = substr(str_repeat($line, (int) ceil(AssetLimits::FLOOR / strlen($line))), 0, AssetLimits::FLOOR - 1)."\n";

    expect(fn () => ResourceFiles::card($by, $pack, 'Too big', 'big.txt', $exactly.'x'))->toThrow(FileTooLarge::class)
        ->and(ResourceFiles::stored())->toBe([])
        ->and(ResourceFiles::asset(ResourceFiles::card($by, $pack, 'Just fits', 'fits.txt', $exactly))->byteSize)->toBe(AssetLimits::FLOOR);
});

it('bounds the configured limit, so a mistyped setting can neither remove it nor forbid every file', function () {
    config(['resources.assets.max_bytes' => 0]);
    expect(AssetLimits::maxBytes())->toBe(AssetLimits::FLOOR);

    config(['resources.assets.max_bytes' => PHP_INT_MAX]);
    expect(AssetLimits::maxBytes())->toBe(AssetLimits::CEILING);

    config(['resources.assets.max_bytes' => 20 * 1024 * 1024]);
    expect(AssetLimits::maxBytes())->toBe(AssetLimits::DEFAULT_MAX_BYTES);
});

it('requires a file for a File Card and refuses one on any other Type, writing nothing either way', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by)->pack->id;

    expect(fn () => app(CreateCard::class)($by, $pack, CardType::File, 'No file', null, null, null))->toThrow(InvalidResourceInput::class)
        ->and(fn () => app(CreateCard::class)($by, $pack, CardType::Basic, 'Stray file', Resources::doc('x'), null, null, ResourceFiles::incoming('a.pdf', ResourceFiles::pdf())))->toThrow(InvalidResourceInput::class)
        ->and(ResourceFiles::stored())->toBe([])
        ->and(DB::table('resource_cards')->count())->toBe(0);
});

it('refuses what would fail anyway before writing the file: a missing Pack, a blank title, bad content, an address', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by)->pack->id;
    $file = fn () => ResourceFiles::incoming('a.pdf', ResourceFiles::pdf());

    expect(fn () => app(CreateCard::class)($by, PackId::generate(), CardType::File, 'x', null, null, null, $file()))->toThrow(PackNotFound::class)
        ->and(fn () => app(CreateCard::class)($by, $pack, CardType::File, '   ', null, null, null, $file()))->toThrow(InvalidResourceInput::class)
        ->and(fn () => app(CreateCard::class)($by, $pack, CardType::File, 'x', ['type' => 'doc', 'content' => [['type' => 'image']]], null, null, $file()))->toThrow(InvalidResourceInput::class)
        ->and(fn () => app(CreateCard::class)($by, $pack, CardType::File, 'x', null, 'https://example.org', null, $file()))->toThrow(InvalidResourceInput::class)
        ->and(ResourceFiles::stored())->toBe([]);
});

it('removes the file it wrote when the creation\'s transaction fails, so nothing refers to it and nothing is left', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    ResourcesPauses::afterCard('add', fn () => throw new RuntimeException('the database went away'));

    expect(fn () => ResourceFiles::card($by, $pack))->toThrow(RuntimeException::class, 'the database went away')
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([]);
});

it('stores nothing and changes nothing when the store cannot write', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    FaultyFileStore::install()->failPut = true;

    expect(fn () => ResourceFiles::card($by, $pack))->toThrow(FileStoreFailure::class)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0);
});

it('never overwrites a stored file: a key that holds one cannot be written again', function () {
    $by = Resources::editor();
    $asset = ResourceFiles::asset(ResourceFiles::card($by, Resources::pack($by)));
    $other = ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('other'));

    expect(fn () => app(ResourceFileStore::class)->put($asset->storageKey, $other->path))->toThrow(FileStoreFailure::class)
        ->and(ResourceFiles::storedBytes($asset->storageKey->value))->toBe(ResourceFiles::pdf());
});

// --- Publication and editing ------------------------------------------------------------------------------------------------------

it('publishes a File Card that has its file, and refuses one whose file is missing from the store, naming the file', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $ok = ResourceFiles::card($by, $pack, 'Present');
    $gone = ResourceFiles::card($by, $pack, 'Gone');
    Storage::disk('resources')->delete(ResourceFiles::asset($gone)->storageKey->value);

    expect(app(PublishCard::class)($by, $pack->pack->id, $ok->card->id)->card->state)->toBe(PublicationState::Published);

    $unmet = null;
    try {
        app(PublishCard::class)($by, $pack->pack->id, $gone->card->id);
    } catch (CardNotPublishable $e) {
        $unmet = $e->unmet;
    }
    expect($unmet)->toBe(['file']);
    expect(Resources::str(Resources::row('resource_cards', $gone->card->id->value)->state))->toBe('draft');
});

it('lets a File Card\'s title and description be edited, keeping its file, and refuses it an address', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::publishedCard($by, $pack);

    $edited = app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['title' => 'Renamed', 'content' => Resources::doc('New description')]);

    expect($edited->card->asset?->id->equals(ResourceFiles::asset($card)->id))->toBeTrue()
        ->and($edited->card->revision)->toBe(2)
        ->and(fn () => app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 2, ['uri' => 'https://example.org']))->toThrow(InvalidResourceInput::class);
});

it('lets a File Card with an empty description be published: its file is what it offers', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = app(CreateCard::class)($by, $pack->pack->id, CardType::File, 'Just a file', null, null, null, ResourceFiles::incoming('a.pdf', ResourceFiles::pdf()));

    expect(app(PublishCard::class)($by, $pack->pack->id, $card->card->id)->card->isPublished())->toBeTrue();
});

it('shows management whether the file is in the store, and who uploaded it', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::card($by, $pack);

    $view = app(GetManagedCard::class)($by, $pack->pack->id, $card->card->id);
    expect($view->fileAvailable)->toBeTrue()->and($view->uploadedBy?->displayName)->toBe('Ed Editor');

    Storage::disk('resources')->delete(ResourceFiles::asset($card)->storageKey->value);
    expect(app(GetManagedCard::class)($by, $pack->pack->id, $card->card->id)->fileAvailable)->toBeFalse();
});

// --- Replacement ------------------------------------------------------------------------------------------------------------------

it('replaces the file and nothing else: same Card, state, revision, audience and position; a new asset and key; the old row gone; the old file removed after the commit', function () {
    [$by, $pack] = publishedFilePack();
    $card = app(GetManagedCard::class)($by, $pack, onlyCardId());
    app(SetCardAudiences::class)($by, $pack, $card->card->id, AudienceMode::Narrowed, [Audience::Guardian]);
    $old = ResourceFiles::asset($card);
    $store = FaultyFileStore::install();
    $level = DB::transactionLevel();
    $other = Resources::editor('ola.other@example.org', 'Ola Other');

    $replaced = app(ReplaceCardFile::class)($other, $pack, $card->card->id, ResourceFiles::incoming('Map v2.png', ResourceFiles::png()));
    $new = ResourceFiles::asset($replaced);

    expect($replaced->card->id->equals($card->card->id))->toBeTrue()
        ->and($replaced->card->state)->toBe(PublicationState::Published)
        ->and($replaced->card->revision)->toBe($card->card->revision) // decision 56: not an authored edit
        ->and($replaced->card->audience->mode)->toBe(AudienceMode::Narrowed)
        ->and($replaced->card->position)->toBe($card->card->position)
        ->and($replaced->card->title)->toBe($card->card->title)
        ->and($new->id->equals($old->id))->toBeFalse()
        ->and($new->originalFilename)->toBe('Map v2.png')
        ->and($new->mediaType)->toBe('image/png')
        ->and($new->uploadedBy->equals($other->personId))->toBeTrue()
        ->and(Resources::str(Resources::row('resource_cards', $card->card->id->value)->updated_by_person_id))->toBe($other->personId->value)
        ->and(DB::table('resource_assets')->pluck('id')->all())->toBe([$new->id->value])
        ->and(ResourceFiles::stored())->toBe([$new->storageKey->value])
        // Removed once, and only after the replacement's transaction was over.
        ->and($store->deletes)->toBe([['key' => $old->storageKey->value, 'transactionLevel' => $level]]);
});

it('keeps the old file in the store until the swap has committed: a reader in that window is never left with nothing', function () {
    [$by, $pack] = publishedFilePack();
    $cardId = onlyCardId();
    $old = ResourceFiles::asset(app(GetManagedCard::class)($by, $pack, $cardId));
    $seen = null;
    // Inside the transaction, after the rows were swapped and before the commit.
    ResourcesPauses::afterCard('replaceAsset', function () use ($old, &$seen) {
        $seen = ResourceFiles::stored();
        expect($seen)->toContain($old->storageKey->value);
    });

    $new = ResourceFiles::asset(app(ReplaceCardFile::class)($by, $pack, $cardId, ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two'))));

    expect($seen)->toEqualCanonicalizing([$old->storageKey->value, $new->storageKey->value])
        ->and(ResourceFiles::stored())->toBe([$new->storageKey->value]);
});

it('leaves the Card and its file untouched when the new file is refused or cannot be written', function () {
    [$by, $pack] = publishedFilePack();
    $cardId = onlyCardId();
    $old = ResourceFiles::asset(app(GetManagedCard::class)($by, $pack, $cardId));

    expect(fn () => app(ReplaceCardFile::class)($by, $pack, $cardId, ResourceFiles::incoming('evil.pdf', "<?php echo 1;\n")))->toThrow(FileTypeNotAllowed::class);
    FaultyFileStore::install()->failPut = true;
    expect(fn () => app(ReplaceCardFile::class)($by, $pack, $cardId, ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two'))))->toThrow(FileStoreFailure::class)
        ->and(Resources::str(Resources::row('resource_cards', $cardId->value)->asset_id))->toBe($old->id->value)
        ->and(DB::table('resource_assets')->pluck('id')->all())->toBe([$old->id->value])
        ->and(ResourceFiles::stored())->toBe([$old->storageKey->value])
        ->and(ResourceFiles::storedBytes($old->storageKey->value))->toBe(ResourceFiles::pdf());
});

it('removes the NEW file and keeps the old when the swap\'s transaction fails', function () {
    [$by, $pack] = publishedFilePack();
    $cardId = onlyCardId();
    $old = ResourceFiles::asset(app(GetManagedCard::class)($by, $pack, $cardId));
    ResourcesPauses::afterCard('replaceAsset', fn () => throw new RuntimeException('the database went away'));

    expect(fn () => app(ReplaceCardFile::class)($by, $pack, $cardId, ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two'))))->toThrow(RuntimeException::class)
        ->and(Resources::str(Resources::row('resource_cards', $cardId->value)->asset_id))->toBe($old->id->value)
        ->and(DB::table('resource_assets')->pluck('id')->all())->toBe([$old->id->value])
        ->and(ResourceFiles::stored())->toBe([$old->storageKey->value]);
});

it('leaves a correct Card when the old file cannot be removed after the commit, and the prune removes it later', function () {
    [$by, $pack] = publishedFilePack();
    $cardId = onlyCardId();
    $old = ResourceFiles::asset(app(GetManagedCard::class)($by, $pack, $cardId));
    FaultyFileStore::install()->failDelete = true;

    $new = ResourceFiles::asset(app(ReplaceCardFile::class)($by, $pack, $cardId, ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two'))));

    expect(Resources::str(Resources::row('resource_cards', $cardId->value)->asset_id))->toBe($new->id->value)
        ->and(ResourceFiles::stored())->toEqualCanonicalizing([$old->storageKey->value, $new->storageKey->value]);

    app()->forgetInstance(ResourceFileStore::class);
    ageStored($old->storageKey->value, 30);
    ageStored($new->storageKey->value, 30);
    $report = app(PruneOrphanedAssets::class)(new DateTimeImmutable, false);

    expect($report->removed)->toBe([$old->storageKey->value])
        ->and(ResourceFiles::stored())->toBe([$new->storageKey->value]);
});

it('refuses to replace the file of a Card that is not a File Card, or not in that Pack, writing nothing', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $basic = Resources::card($by, $pack);
    $file = ResourceFiles::card($by, Resources::pack($by, 'Other'));
    $incoming = fn () => ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two'));

    expect(fn () => app(ReplaceCardFile::class)($by, $pack->pack->id, $basic->card->id, $incoming()))->toThrow(InvalidResourceInput::class)
        ->and(fn () => app(ReplaceCardFile::class)($by, $pack->pack->id, $file->card->id, $incoming()))->toThrow(CardNotFound::class)
        ->and(fn () => app(ReplaceCardFile::class)($by, PackId::generate(), $file->card->id, $incoming()))->toThrow(PackNotFound::class)
        ->and(ResourceFiles::stored())->toBe([ResourceFiles::asset($file)->storageKey->value]);
});

it('does not move the revision: an edit based on the revision from before a replacement still lands', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::card($by, $pack);
    app(ReplaceCardFile::class)($by, $pack->pack->id, $card->card->id, ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two')));

    expect(app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['title' => 'Still mine'])->card->title)->toBe('Still mine');
});

it('records no security event for creating, publishing or replacing a file', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::publishedCard($by, $pack);
    app(ReplaceCardFile::class)($by, $pack->pack->id, $card->card->id, ResourceFiles::incoming('b.pdf', ResourceFiles::pdf('two')));

    expect(Resources::events())->toBe([])->and(DB::table('security_events')->count())->toBe(0);
});

// --- Deletion -----------------------------------------------------------------------------------------------------------------------

it('deletes a File Card with its asset row in one transaction and its file only after the commit, recording the event in its WP1 shape', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::card($by, $pack, 'Zorblax file', 'Zorblax-secret-name.pdf', ResourceFiles::pdf('Quuxmarker'));
    $asset = ResourceFiles::asset($card);
    $store = FaultyFileStore::install();
    $level = DB::transactionLevel();

    app(DeleteCard::class)($by, $pack->pack->id, $card->card->id);

    $events = Resources::events();
    expect(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([])
        ->and($store->deletes)->toBe([['key' => $asset->storageKey->value, 'transactionLevel' => $level]])
        ->and($events)->toHaveCount(1)
        ->and(fileEventContext($events[0]))->toBe(['card_id' => $card->card->id->value, 'pack_id' => $pack->pack->id->value, 'card_type' => 'file']);

    $recorded = json_encode(DB::table('security_events')->get()->all(), JSON_THROW_ON_ERROR);
    foreach (['Zorblax', 'secret-name', 'Quuxmarker', $asset->storageKey->value, $asset->sha256, 'application/pdf', 'pdf"'] as $leak) {
        expect(str_contains($recorded, $leak))->toBeFalse("the audit trail holds {$leak}");
    }
});

it('keeps the file with its row when the deletion rolls back because its event could not be written', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::card($by, $pack);
    $store = FaultyFileStore::install();
    Faults::auditFailsAt(1);

    expect(fn () => app(DeleteCard::class)($by, $pack->pack->id, $card->card->id))->toThrow(RuntimeException::class)
        ->and(DB::table('resource_cards')->count())->toBe(1)
        ->and(DB::table('resource_assets')->count())->toBe(1)
        ->and(ResourceFiles::stored())->toBe([ResourceFiles::asset($card)->storageKey->value])
        ->and($store->deletes)->toBe([]);
});

it('leaves the Card deleted when its file cannot be removed afterwards; the file is an orphan the prune removes', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = ResourceFiles::card($by, $pack);
    $key = ResourceFiles::asset($card)->storageKey->value;
    FaultyFileStore::install()->failDelete = true;

    app(DeleteCard::class)($by, $pack->pack->id, $card->card->id);

    expect(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(Resources::events())->toHaveCount(1)
        ->and(ResourceFiles::stored())->toBe([$key]);

    app()->forgetInstance(ResourceFileStore::class);
    ageStored($key, 30);
    expect(app(PruneOrphanedAssets::class)(new DateTimeImmutable, false)->removed)->toBe([$key])
        ->and(ResourceFiles::stored())->toBe([]);
});

it('deletes a Pack\'s File Cards with their asset rows, counts files_deleted truly, records no per-Card event, and removes the files after the commit', function () {
    $by = Resources::editor();
    $category = Resources::category($by)->category->id;
    $pack = Resources::pack($by, 'Mixed', $category);
    $one = ResourceFiles::publishedCard($by, $pack, 'One', 'one.pdf', ResourceFiles::pdf('one'));
    $two = ResourceFiles::card($by, $pack, 'Two', 'two.png', ResourceFiles::png());
    Resources::publishedCard($by, $pack, 'Words');
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    app(SetCardAudiences::class)($by, $pack->pack->id, $two->card->id, AudienceMode::Narrowed, [Audience::Member]);
    app(PublishPack::class)($by, $pack->pack->id);
    $other = ResourceFiles::card($by, Resources::pack($by, 'Untouched'));
    $store = FaultyFileStore::install();
    $level = DB::transactionLevel();

    app(DeletePack::class)($by, $pack->pack->id);

    $events = Resources::events();
    $keys = [ResourceFiles::asset($one)->storageKey->value, ResourceFiles::asset($two)->storageKey->value];
    sort($keys);
    expect($events)->toHaveCount(1)
        ->and(Resources::str($events[0]->type))->toBe('resource.pack_deleted')
        ->and(fileEventContext($events[0]))->toBe(['pack_id' => $pack->pack->id->value, 'cards_deleted' => 3, 'files_deleted' => 2])
        ->and(DB::table('resource_assets')->pluck('id')->all())->toBe([ResourceFiles::asset($other)->id->value])
        ->and(ResourceFiles::stored())->toBe([ResourceFiles::asset($other)->storageKey->value])
        ->and(array_column($store->deletes, 'key'))->toBe($keys)
        ->and(array_unique(array_column($store->deletes, 'transactionLevel')))->toBe([$level]);
});

it('counts no files for a Pack without File Cards', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    Resources::card($by, $pack);

    app(DeletePack::class)($by, $pack->pack->id);

    expect(fileEventContext(Resources::events()[0]))->toBe(['pack_id' => $pack->pack->id->value, 'cards_deleted' => 1, 'files_deleted' => 0]);
});

it('keeps every file of a Pack whose deletion rolls back', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    ResourceFiles::card($by, $pack, 'One');
    ResourceFiles::card($by, $pack, 'Two', 'two.pdf', ResourceFiles::pdf('two'));
    $store = FaultyFileStore::install();
    Faults::auditFailsAt(1);

    expect(fn () => app(DeletePack::class)($by, $pack->pack->id))->toThrow(RuntimeException::class)
        ->and(DB::table('resource_assets')->count())->toBe(2)
        ->and(ResourceFiles::stored())->toHaveCount(2)
        ->and($store->deletes)->toBe([]);
});

// --- Prune --------------------------------------------------------------------------------------------------------------------------

it('prunes only unreferenced Resources files older than the grace period, and leaves everything else alone', function () {
    $by = Resources::editor();
    $referenced = ResourceFiles::asset(ResourceFiles::card($by, Resources::pack($by)))->storageKey->value;
    $disk = Storage::disk('resources');
    $oldOrphan = StorageKey::for(AssetId::generate())->value;
    $newOrphan = StorageKey::for(AssetId::generate())->value;
    $disk->put($oldOrphan, 'orphaned bytes');
    $disk->put($newOrphan, 'an upload whose row has not committed');
    $disk->put('notes.txt', 'not ours');
    $disk->put('.hidden', 'not ours');
    $disk->put(strtoupper($oldOrphan), 'not a key this store writes');
    $disk->makeDirectory(StorageKey::for(AssetId::generate())->value);
    foreach ([$referenced, $oldOrphan, 'notes.txt', '.hidden', strtoupper($oldOrphan)] as $name) {
        ageStored($name, 48);
    }
    ageStored($newOrphan, 2);

    $report = app(PruneOrphanedAssets::class)(new DateTimeImmutable, false);

    expect($report->removed)->toBe([$oldOrphan])
        ->and($report->kept)->toBe(1)
        ->and($report->recent)->toBe([$newOrphan])
        ->and($report->ignored)->toEqualCanonicalizing(['.hidden', 'notes.txt', strtoupper($oldOrphan)])
        ->and($report->failed)->toBe([])
        ->and($report->missing)->toBe([])
        ->and($disk->exists($referenced))->toBeTrue()
        ->and($disk->exists($oldOrphan))->toBeFalse()
        ->and($disk->exists($newOrphan))->toBeTrue()
        ->and($disk->exists('notes.txt'))->toBeTrue()
        ->and($disk->directories(''))->toHaveCount(1);
});

it('never removes a referenced file, however old, and never removes anything in a dry run', function () {
    $by = Resources::editor();
    $referenced = ResourceFiles::asset(ResourceFiles::card($by, Resources::pack($by)))->storageKey->value;
    $orphan = StorageKey::for(AssetId::generate())->value;
    Storage::disk('resources')->put($orphan, 'x');
    ageStored($referenced, 24 * 365);
    ageStored($orphan, 48);

    $dry = app(PruneOrphanedAssets::class)(new DateTimeImmutable, true);
    expect($dry->removed)->toBe([$orphan])->and(ResourceFiles::stored())->toEqualCanonicalizing([$referenced, $orphan]);

    $real = app(PruneOrphanedAssets::class)(new DateTimeImmutable, false);
    $again = app(PruneOrphanedAssets::class)(new DateTimeImmutable, false);
    expect($real->removed)->toBe([$orphan])
        ->and($again->removed)->toBe([]) // idempotent
        ->and($again->kept)->toBe(1)
        ->and(ResourceFiles::stored())->toBe([$referenced]);
});

it('follows no link out of the store and removes none', function () {
    $outside = ResourceFiles::fake().'-outside.txt';
    file_put_contents($outside, 'somebody else\'s file');
    $link = StorageKey::for(AssetId::generate())->value;
    symlink($outside, Storage::disk('resources')->path($link));
    touch($outside, time() - 48 * 3600);

    $report = app(PruneOrphanedAssets::class)(new DateTimeImmutable, false);

    expect($report->removed)->toBe([])
        ->and(is_link(Storage::disk('resources')->path($link)))->toBeTrue()
        ->and(file_get_contents($outside))->toBe('somebody else\'s file');
    unlink(Storage::disk('resources')->path($link));
    unlink($outside);
});

it('reports asset rows whose file is missing, and repairs nothing', function () {
    $by = Resources::editor();
    $key = ResourceFiles::asset(ResourceFiles::card($by, Resources::pack($by)))->storageKey->value;
    Storage::disk('resources')->delete($key);

    $report = app(PruneOrphanedAssets::class)(new DateTimeImmutable, false);

    expect($report->missing)->toBe([$key])->and(DB::table('resource_assets')->count())->toBe(1);
});

it('says what it decided, in a stable order, and fails only when an orphan could not be removed', function () {
    $orphan = StorageKey::for(AssetId::generate())->value;
    Storage::disk('resources')->put($orphan, 'x');
    ageStored($orphan, 48);

    $dry = artisan('resources:assets:prune', ['--dry-run' => true]);
    assert($dry instanceof PendingCommand);
    $dry->expectsOutput('Resources file store: 1 file(s) found (dry run: nothing is removed).')
        ->expectsOutput('  orphaned, would be removed: 1')
        ->expectsOutput("    {$orphan}")
        ->assertSuccessful()
        ->run();
    expect(ResourceFiles::stored())->toBe([$orphan]);

    FaultyFileStore::install()->failDelete = true;
    $failing = artisan('resources:assets:prune');
    assert($failing instanceof PendingCommand);
    $failing->expectsOutput('  orphaned, could NOT be removed (tried again next run): 1')->assertFailed()->run();
    expect(ResourceFiles::stored())->toBe([$orphan]);
});
