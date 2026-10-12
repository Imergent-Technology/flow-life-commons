<?php

declare(strict_types=1);

use App\Modules\Resources\Application\AudienceEligibility;
use App\Modules\Resources\Application\BrowseDeliveredResources;
use App\Modules\Resources\Application\BrowseLibrary;
use App\Modules\Resources\Application\LibraryFilter;
use App\Modules\Resources\Application\PreviewPack;
use App\Modules\Resources\Application\ReadLibraryPack;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\PackRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Audiences;
use Tests\Support\Resources;

/*
 * The audience catalog after ADR 0038, R1: `volunteer` is added with no schema change, an unknown key fails closed on every path that
 * could read it, and preview speaks every catalog audience. Runs on MariaDB and PostgreSQL.
 */

it('stores and returns the Volunteer audience with no schema change, beside the existing keys', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'For volunteers');
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Volunteer, Audience::Guardian]);

    expect(Resources::strings(DB::table('resource_pack_audiences')->where('pack_id', $pack->pack->id->value)->orderBy('audience')->pluck('audience')))->toBe(['guardian', 'volunteer'])
        ->and(app(PackRepository::class)->find($pack->pack->id)?->audiences->values())->toBe(['guardian', 'volunteer']);
});

it('drops a stored audience key the catalog does not know, and confers no eligibility on it', function () {
    $by = Resources::editor();
    $pack = Resources::published($by, [Audience::Guardian], 1, 'Known and unknown');
    $only = Resources::published($by, [Audience::Guardian], 1, 'Only unknown');
    DB::table('resource_pack_audiences')->insert([['pack_id' => $pack->pack->id->value, 'audience' => 'partner']]);
    DB::table('resource_pack_audiences')->where('pack_id', $only->pack->id->value)->update(['audience' => 'partner']);
    $everyone = Access::actorFor(Audiences::reader(['guardian', 'volunteer', 'member'], email: 'every.audience@example.org'));

    // Dropped on read, wherever it is read: the repository, the privileged viewer and a reader.
    expect(app(PackRepository::class)->find($pack->pack->id)?->audiences->values())->toBe(['guardian'])
        ->and(app(ReadLibraryPack::class)($by, $pack->pack->id)->pack->audiences->values())->toBe(['guardian'])
        ->and(app(ReadLibraryPack::class)($by, $only->pack->id)->pack->audiences->values())->toBe([])
        // A Pack whose only key is unknown reaches no reader, however many audiences they hold; the privileged viewer still reads it.
        ->and(array_map(fn ($c): string => $c->packs[0]->pack->title, app(BrowseDeliveredResources::class)($everyone, null, null)))->toBe(['Known and unknown'])
        ->and(count(app(BrowseLibrary::class)($by, new LibraryFilter)))->toBe(2);
});

it('drops an unknown key in a Card\'s narrowing the same way, so a narrowed Card is never wider than the catalog', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian, Audience::Member], 1, 'Narrowed');
    $card = Resources::outline($live, 0)->card;
    DB::table('resource_cards')->where('id', $card->id->value)->update(['audience_mode' => 'narrowed']);
    DB::table('resource_card_audiences')->insert([['card_id' => $card->id->value, 'audience' => 'partner']]);

    // A narrowed Card whose every key is unknown reaches nobody.
    $everyone = Access::actorFor(Audiences::reader(['guardian', 'volunteer', 'member'], email: 'every.audience@example.org'));

    expect(app(BrowseDeliveredResources::class)($everyone, null, null))->toBe([])
        ->and(app(ReadLibraryPack::class)($by, $live->pack->id)->cards[0]->audiences->values())->toBe([]);
});

it('emits catalog members only from eligibility, and nothing for a type or key the catalog lacks', function () {
    $reader = Audiences::reader(['guardian', 'volunteer', 'member'], email: 'catalog.only@example.org');
    $set = app(AudienceEligibility::class)(Access::actorFor($reader));

    expect($set->values())->toBe(['guardian', 'member', 'volunteer']);
    foreach ($set->audiences as $audience) {
        expect(Audience::tryFrom($audience->value))->toBe($audience);
    }
});

it('previews as every catalog audience, the Volunteer one included, and refuses any other', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Volunteer], 2, 'Volunteers');

    foreach (Audience::cases() as $audience) {
        $preview = app(PreviewPack::class)($by, $live->pack->id, $audience);
        expect($preview->audience)->toBe($audience)
            ->and($preview->audienceTargeted)->toBe($audience === Audience::Volunteer)
            ->and($preview->visible())->toBe($audience === Audience::Volunteer);
    }
});
