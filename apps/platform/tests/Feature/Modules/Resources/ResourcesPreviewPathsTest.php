<?php

declare(strict_types=1);

use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\Audience;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;

/*
 * Preview is management's (ADR 0038, R3): it accepts every catalog audience, and a File Card it shows is opened from the MANAGEMENT file
 * route, so a `resources.manage`-only previewer can open a file the preview shows (a Published File Card of a Draft Pack; a Draft Card is never part of a preview) without ever holding `resources.view`.
 */

beforeEach(function () {
    ResourceFiles::fake();
});

/** @return array{Console, string, string} a signed-in manager, the Draft Pack and its Published File Card */
function draftWithFile(): array
{
    [$console] = Resources::signedInGuardian('mona.manager@example.org', 'Mona Manager');
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    $pack = Resources::pack($by, 'Volunteer map', Resources::category($by, 'Maps')->category->id);
    $file = ResourceFiles::publishedCard($by, $pack, 'The map', 'map.pdf');
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Volunteer]);

    return [$console, $pack->pack->id->value, $file->card->id->value];
}

it('opens a file previewed from a Draft Pack from the management route for a manager who holds resources.manage and not resources.view', function () {
    [$console, $pack, $card] = draftWithFile();
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => $ability === 'resources.view' ? false : null);

    $preview = Api::map($console->get("/api/v1/admin/resources/packs/{$pack}/preview?audience=volunteer")->assertOk()->json());
    $previewed = Api::rows(Api::map($preview['pack'])['cards'])[0];
    $path = Api::string(Api::map($previewed['file'])['download_path']);

    expect($preview['pack_state'])->toBe('draft')
        ->and($preview['visible'])->toBeTrue()
        ->and(array_keys(Api::map($previewed['file'])))->toBe(['name', 'media_type', 'byte_size', 'download_path'])
        ->and($path)->toBe("/api/v1/admin/resources/packs/{$pack}/cards/{$card}/file");

    // The path the preview offers opens, for this manager, with no viewing authority...
    expect($console->get($path)->assertOk()->streamedContent())->toBe(ResourceFiles::pdf());
    // ...and the privileged library's route for the same Card is refused them, which is why the preview must not point there.
    $console->get("/api/v1/admin/resource-library/packs/{$pack}/cards/{$card}/file")->assertForbidden();
});

it('points a preview of every catalog audience at the management file route, never the library or my-resources', function (Audience $audience) {
    [$console, $pack, $card] = draftWithFile();
    // Card audiences inherit, so the Volunteer Pack's Card is visible to Volunteer only; the others preview as empty.
    $preview = Api::map($console->get("/api/v1/admin/resources/packs/{$pack}/preview?audience={$audience->value}")->assertOk()->json());
    $text = (string) json_encode($preview);

    expect($text)->not->toContain('resource-library')->and($text)->not->toContain('my-resources');
    if ($audience === Audience::Volunteer) {
        expect($text)->toContain(str_replace('/', '\/', "/api/v1/admin/resources/packs/{$pack}/cards/{$card}/file"));
    } else {
        expect($preview['visible'])->toBeFalse()->and($preview['pack'])->toBeNull();
    }
})->with(Audience::cases());

it('refuses an audience outside the catalog on preview with a 422 naming the field', function () {
    [$console, $pack] = draftWithFile();

    foreach (['partner', 'vendor', 'artist', 'Volunteer', 'volunteers', 'guardian,member', ''] as $audience) {
        $console->get("/api/v1/admin/resources/packs/{$pack}/preview?audience=".urlencode($audience))->assertStatus(422)->assertJsonValidationErrors('audience');
    }
    $console->get("/api/v1/admin/resources/packs/{$pack}/preview")->assertStatus(422);
});
