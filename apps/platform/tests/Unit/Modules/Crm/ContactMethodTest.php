<?php

declare(strict_types=1);

use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\PersonId;
use PHPUnit\Framework\Assert;
use Tests\Support\Identity;

function newMethod(ContactMethodKind $kind, string $value, ?string $label = null, bool $primary = false): ContactMethod
{
    return ContactMethod::create(ContactMethodId::generate(), PersonId::generate(), $kind, $value, $label, $primary, Identity::now());
}

// --- Email -----------------------------------------------------------------------------------------------------------

it('keeps an email as entered (trimmed) and searches it lower-cased', function () {
    $method = newMethod(ContactMethodKind::Email, "  Ada.Lovelace+Work@Example.ORG \n");

    expect($method->value)->toBe('Ada.Lovelace+Work@Example.ORG')
        ->and($method->searchValue)->toBe('ada.lovelace+work@example.org');
});

it('applies no provider-specific alias rules: plus-addresses and dots are different mailboxes', function () {
    $plain = newMethod(ContactMethodKind::Email, 'ada@gmail.com');

    expect(newMethod(ContactMethodKind::Email, 'ada+news@gmail.com')->searchValue)->not->toBe($plain->searchValue)
        ->and(newMethod(ContactMethodKind::Email, 'a.da@gmail.com')->searchValue)->not->toBe($plain->searchValue)
        ->and(newMethod(ContactMethodKind::Email, 'ada@googlemail.com')->searchValue)->not->toBe($plain->searchValue);
});

it('lower-cases a non-ASCII email without touching anything else', function () {
    expect(newMethod(ContactMethodKind::Email, 'José@Example.org')->searchValue)->toBe('josé@example.org');
});

it('refuses what is not plausibly an email', function (string $value) {
    expect(fn () => newMethod(ContactMethodKind::Email, $value))->toThrow(InvalidContactInput::class);
})->with(['empty' => '', 'blank' => '   ', 'no at' => 'ada.example.org', 'two ats' => 'a@b@c.org', 'inner space' => 'ada @example.org', 'no local part' => '@example.org', 'no domain' => 'ada@', 'newline' => "ada@example.org\nbcc@x.org", 'too long' => 'a@'.str_repeat('x', 255)]);

// --- Phone -----------------------------------------------------------------------------------------------------------

it('keeps a phone number as entered and searches it by its digits', function () {
    $method = newMethod(ContactMethodKind::Phone, '  (555)   010-0100 ');

    expect($method->value)->toBe('(555) 010-0100')
        ->and($method->searchValue)->toBe('5550100100');
});

it('keeps a leading plus in the search form and nothing else', function () {
    expect(newMethod(ContactMethodKind::Phone, '+44 20 7946 0958')->searchValue)->toBe('+442079460958')
        ->and(newMethod(ContactMethodKind::Phone, '020 7946 0958')->searchValue)->toBe('02079460958');
});

it('claims no global phone identity: country codes are not inferred and extensions simply add digits', function () {
    $national = newMethod(ContactMethodKind::Phone, '555 010 0100');
    $international = newMethod(ContactMethodKind::Phone, '+1 555 010 0100');
    $withExtension = newMethod(ContactMethodKind::Phone, '555 010 0100 x22');

    expect($international->searchValue)->not->toBe($national->searchValue) // no country code inferred, so these two are NOT matched
        ->and($withExtension->searchValue)->toBe('555010010022');          // the documented limitation
});

it('refuses a phone number with fewer than three digits, or that is absurdly long', function (string $value) {
    expect(fn () => newMethod(ContactMethodKind::Phone, $value))->toThrow(InvalidContactInput::class);
})->with(['empty' => '', 'two digits' => '12', 'no digits' => 'call me', 'too long' => str_repeat('1', 65), 'control' => "555\x00 0100"]);

// --- Label and changes ---------------------------------------------------------------------------------------------------

it('trims a label and treats a blank one as none', function () {
    expect(newMethod(ContactMethodKind::Email, 'a@b.org', '  work ')->label)->toBe('work')
        ->and(newMethod(ContactMethodKind::Email, 'a@b.org', '   ')->label)->toBeNull()
        ->and(newMethod(ContactMethodKind::Email, 'a@b.org', null)->label)->toBeNull();
});

it('refuses a label over 64 characters', function () {
    expect(fn () => newMethod(ContactMethodKind::Email, 'a@b.org', str_repeat('x', 65)))->toThrow(InvalidContactInput::class);
});

it('names the field a refusal is about, and never echoes the value', function () {
    try {
        newMethod(ContactMethodKind::Email, 'secret-looking-nonsense');
    } catch (InvalidContactInput $e) {
        expect($e->field)->toBe('value')->and($e->getMessage())->not->toContain('secret-looking-nonsense');

        return;
    }
    Assert::fail('expected a refusal');
});

it('changes into a new value, keeping the id, kind, person and creation time', function () {
    $method = newMethod(ContactMethodKind::Email, 'old@example.org', 'work', true);
    $later = new DateTimeImmutable('2026-10-02 08:00:00', new DateTimeZone('UTC'));

    $changed = $method->withValue('New@Example.org', $later)->withLabel(null, $later)->withPrimary(false, $later);

    expect($changed->id->equals($method->id))->toBeTrue()
        ->and($changed->personId->equals($method->personId))->toBeTrue()
        ->and($changed->kind)->toBe(ContactMethodKind::Email)
        ->and($changed->value)->toBe('New@Example.org')
        ->and($changed->searchValue)->toBe('new@example.org')
        ->and($changed->label)->toBeNull()
        ->and($changed->isPrimary)->toBeFalse()
        ->and($changed->createdAt)->toEqual($method->createdAt)
        ->and($changed->updatedAt)->toEqual($later)
        ->and($method->value)->toBe('old@example.org'); // immutable
});
