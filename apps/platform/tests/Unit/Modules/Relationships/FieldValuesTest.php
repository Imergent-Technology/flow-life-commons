<?php

declare(strict_types=1);

use App\Modules\Relationships\Application\RelationshipTime;
use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\InvalidRelationshipField;
use App\Modules\Relationships\Domain\RelationshipField;
use App\Modules\Relationships\Domain\UnknownRelationshipField;
use Carbon\Carbon;

it('accepts today at UTC+14 and refuses the next calendar day', function () {
    $recognizedOn = new RelationshipField(
        'recognized_on', 'date', null, 'today', false, 'view', 'Guardian since', null, 1, null, [],
    );

    Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    try {
        $noon = RelationshipTime::now();
        expect(FieldValues::latestDate($noon))->toBe('2026-10-10')
            ->and(FieldValues::canonical($recognizedOn, '2026-10-10', $noon))->toBe('2026-10-10');
        expect(fn () => FieldValues::canonical($recognizedOn, '2026-10-11', $noon))
            ->toThrow(InvalidRelationshipField::class);

        Carbon::setTestNow(Carbon::parse('2026-10-09 09:59:59', 'UTC'));
        $before = RelationshipTime::now();
        expect(FieldValues::latestDate($before))->toBe('2026-10-09');
        expect(fn () => FieldValues::canonical($recognizedOn, '2026-10-10', $before))
            ->toThrow(InvalidRelationshipField::class);

        Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00', 'UTC'));
        $atBoundary = RelationshipTime::now();
        expect(FieldValues::latestDate($atBoundary))->toBe('2026-10-10')
            ->and(FieldValues::canonical($recognizedOn, '2026-10-10', $atBoundary))->toBe('2026-10-10');
    } finally {
        Carbon::setTestNow();
    }
});

/**
 * The field map a type declares, as `FieldValues::parse` and `missingRequired` now take it (WP4: the Domain no longer
 * sees the Application-level definition, only its `array<string, RelationshipField>`).
 *
 * @return array<string, RelationshipField>
 */
function relationshipFieldMap(): array
{
    return [
        'recognized_on' => new RelationshipField('recognized_on', 'date', null, 'today', true, 'view', 'Guardian since', null, 1, null, []),
        'note' => new RelationshipField('note', 'text', 20, null, false, 'manage', 'Note', null, 2, null, []),
        'consented' => new RelationshipField('consented', 'boolean', null, null, false, 'manage', 'Consented', null, 3, null, []),
        'pronoun' => new RelationshipField('pronoun', 'choice', null, null, false, 'view', 'Pronoun', null, 4, null, ['she', 'he']),
    ];
}

it('parses only the keys sent, to canonical text, and null clears', function () {
    $now = new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC'));

    expect(FieldValues::parse(relationshipFieldMap(), [
        'recognized_on' => '2026-10-09', 'note' => '  hello  ', 'consented' => true, 'pronoun' => 'she',
    ], $now))->toBe(['recognized_on' => '2026-10-09', 'note' => 'hello', 'consented' => 'true', 'pronoun' => 'she'])
        ->and(FieldValues::parse(relationshipFieldMap(), ['note' => null], $now))->toBe(['note' => null])
        ->and(FieldValues::parse(relationshipFieldMap(), [], $now))->toBe([]);
});

it('refuses a key the type does not declare, and a key that is not a string', function (array $input, string $named) {
    $now = new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC'));

    try {
        FieldValues::parse(relationshipFieldMap(), $input, $now);
        $thrown = null;
    } catch (UnknownRelationshipField $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(UnknownRelationshipField::class)
        ->and($thrown?->field)->toBe($named);
})->with([
    'an undeclared key' => [['surname' => 'x'], 'surname'],
    'an undeclared key beside a declared one' => [['note' => 'ok', 'surname' => 'x'], 'surname'],
    'a numeric key' => [[0 => 'x'], ''],
]);

it('refuses an invalid value for a declared field, naming the field', function (string $key, mixed $value) {
    $now = new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC'));

    try {
        FieldValues::parse(relationshipFieldMap(), [$key => $value], $now);
        $thrown = null;
    } catch (InvalidRelationshipField $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidRelationshipField::class);
})->with([
    'a date after the UTC+14 bound' => ['recognized_on', '2026-10-11'],
    'text beyond its length' => ['note', str_repeat('x', 21)],
    'a boolean sent as text' => ['consented', 'true'],
    'a choice outside its options' => ['pronoun', 'they'],
]);

it('reports the first required field the write leaves empty, from the field map alone', function () {
    expect(FieldValues::missingRequired(relationshipFieldMap(), []))->toBe('recognized_on')
        ->and(FieldValues::missingRequired(relationshipFieldMap(), ['recognized_on' => null]))->toBe('recognized_on')
        ->and(FieldValues::missingRequired(relationshipFieldMap(), ['recognized_on' => '2026-10-09']))->toBeNull()
        ->and(FieldValues::missingRequired([], []))->toBeNull();
});
