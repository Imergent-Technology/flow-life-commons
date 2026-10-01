<?php

declare(strict_types=1);

use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\InvalidContactInput;
use Tests\Support\Identity;

function newTag(string $name): ContactTag
{
    return ContactTag::create(ContactTagId::generate(), $name, null, Identity::now());
}

it('keeps a tag name tidy and compares it lower-cased', function () {
    $tag = newTag("  Volunteer \t  Interest ");

    expect($tag->name)->toBe('Volunteer Interest')
        ->and($tag->canonical)->toBe('volunteer interest');
});

it('treats names differing only by case or spacing as one tag', function () {
    expect(newTag('Lead')->canonical)->toBe(newTag('  lead ')->canonical)
        ->and(newTag('Volunteer Interest')->canonical)->toBe(newTag('volunteer   interest')->canonical)
        ->and(newTag('Lead')->canonical)->not->toBe(newTag('Leads')->canonical);
});

it('accepts the starter vocabulary as ordinary names, with nothing special about them', function () {
    foreach (['Lead', 'Partner', 'Facilitator', 'Performer', 'Vendor', 'Donor', 'Volunteer Interest', 'Artist'] as $name) {
        $tag = newTag($name);
        expect($tag->name)->toBe($name);
    }
});

it('refuses an empty, over-long or control-character name', function (string $name) {
    expect(fn () => newTag($name))->toThrow(InvalidContactInput::class);
})->with(['empty' => '', 'blank' => "  \t ", 'long' => str_repeat('x', 65), 'control' => "Le\x07ad"]);

it('accepts a name of exactly 64 characters, multibyte included', function () {
    expect(newTag(str_repeat('é', 64))->name)->toBe(str_repeat('é', 64));
});

it('is renamed into a new value with the same id, creator and creation time', function () {
    $tag = newTag('Lead');

    $renamed = $tag->renamed('  Warm LEAD ');

    expect($renamed->id->equals($tag->id))->toBeTrue()
        ->and($renamed->name)->toBe('Warm LEAD')
        ->and($renamed->canonical)->toBe('warm lead')
        ->and($renamed->createdAt)->toEqual($tag->createdAt)
        ->and($tag->name)->toBe('Lead');
});

it('is a label and nothing else: no capability, role or status attribute exists on it', function () {
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(ContactTag::class))->getProperties());

    expect($properties)->toEqualCanonicalizing(['id', 'name', 'canonical', 'createdBy', 'createdAt']);
});
