<?php

declare(strict_types=1);

use App\Modules\Crm\Domain\ContactProfile;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Tests\Support\Identity;

function emptyProfile(): ContactProfile
{
    return ContactProfile::reconstitute(PersonId::generate(), null, null, null, Identity::now(), Identity::now());
}

it('changes only the fields that are sent', function () {
    $by = AccountId::generate();
    $later = new DateTimeImmutable('2026-10-02 08:00:00', new DateTimeZone('UTC'));
    $profile = emptyProfile()->with(['how_we_know' => '  Met at the winter market  ', 'affiliation' => 'Dancers Guild'], $by, $later);

    expect($profile->howWeKnow)->toBe('Met at the winter market')
        ->and($profile->affiliation)->toBe('Dancers Guild')
        ->and($profile->updatedBy)->toBe($by)
        ->and($profile->updatedAt)->toEqual($later);

    $onlyAffiliation = $profile->with(['affiliation' => 'Sound crew'], $by, $later);
    expect($onlyAffiliation->howWeKnow)->toBe('Met at the winter market')   // absent: left alone
        ->and($onlyAffiliation->affiliation)->toBe('Sound crew');
});

it('clears a field set to null or blank', function () {
    $profile = emptyProfile()->with(['how_we_know' => 'x', 'affiliation' => 'y'], null, Identity::now());

    $cleared = $profile->with(['how_we_know' => null, 'affiliation' => '   '], null, Identity::now());

    expect($cleared->howWeKnow)->toBeNull()->and($cleared->affiliation)->toBeNull();
});

it('limits how we know to 2000 characters and affiliation to 255', function () {
    expect(emptyProfile()->with(['how_we_know' => str_repeat('x', 2000)], null, Identity::now())->howWeKnow)->toHaveLength(2000)
        ->and(fn () => emptyProfile()->with(['how_we_know' => str_repeat('x', 2001)], null, Identity::now()))->toThrow(InvalidContactInput::class)
        ->and(fn () => emptyProfile()->with(['affiliation' => str_repeat('x', 256)], null, Identity::now()))->toThrow(InvalidContactInput::class);
});

it('holds no status of any kind: not Member, Account, Volunteer or CRM-membership', function () {
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(ContactProfile::class))->getProperties());

    expect($properties)->toEqualCanonicalizing(['personId', 'howWeKnow', 'affiliation', 'updatedBy', 'createdAt', 'updatedAt']);
});
