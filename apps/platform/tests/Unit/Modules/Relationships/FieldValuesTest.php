<?php

declare(strict_types=1);

use App\Modules\Relationships\Application\RelationshipTime;
use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\InvalidRelationshipField;
use App\Modules\Relationships\Domain\RelationshipField;
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
