<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\InvalidResourceInput;

/*
 * The backend side of the shared example documents (tests/Fixtures/resource-content/README.md; ADR 0037, decisions 26 and 28):
 * every valid document is accepted and stored in its canonical form, and every invalid one is refused, with the path of the
 * first offence, never stripped. The Console's suite (WP2) must run the same files.
 */

/** @return list<array{string, array<string, mixed>}> */
function contentFixtures(string $kind): array
{
    $files = glob(dirname(__DIR__, 3).'/Fixtures/resource-content/'.$kind.'/*.json') ?: [];
    sort($files);
    $fixtures = [];
    foreach ($files as $file) {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        /** @var array<string, mixed> $decoded */
        $fixtures[] = [basename($file, '.json'), $decoded];
    }

    return $fixtures;
}

/** @return array<mixed> */
function fixtureDocument(mixed $value): array
{
    assert(is_array($value));

    return $value;
}

it('has a real corpus, so the loops below cannot pass by finding nothing', function () {
    expect(count(contentFixtures('valid')))->toBeGreaterThanOrEqual(14)
        ->and(count(contentFixtures('invalid')))->toBeGreaterThanOrEqual(40);
});

it('accepts every valid fixture and stores exactly its canonical form', function () {
    foreach (contentFixtures('valid') as [$name, $fixture]) {
        $stored = ContentDocument::fromArray(fixtureDocument($fixture['document']));

        $expected = $fixture['canonical'] ?? $fixture['document'];
        expect($stored->decoded())->toEqual(json_decode(json_encode($expected, JSON_THROW_ON_ERROR)), "valid/{$name}: stored form")
            ->and($stored->plainText())->toBe($fixture['text'], "valid/{$name}: plain text")
            ->and($stored->format)->toBe('prosemirror')
            ->and($stored->version)->toBe(1);

        // Idempotent: the canonical form is a fixed point of validation, so storing it again changes nothing.
        $again = ContentDocument::fromArray(fixtureDocument(json_decode($stored->json, true, 512, JSON_THROW_ON_ERROR)));
        expect($again->json)->toBe($stored->json, "valid/{$name}: canonical form is stable");
    }
});

it('refuses every invalid fixture with invalid_content, naming where, and never strips it', function () {
    foreach (contentFixtures('invalid') as [$name, $fixture]) {
        $document = fixtureDocument($fixture['document']);
        assert(is_string($fixture['path']));
        $path = $fixture['path'];

        try {
            ContentDocument::fromArray($document);
            $refused = null;
        } catch (InvalidResourceInput $e) {
            $refused = $e;
        }

        expect($refused)->not->toBeNull("invalid/{$name} was accepted");
        assert($refused instanceof InvalidResourceInput);
        expect($refused->problem)->toBe('invalid_content', "invalid/{$name}")
            ->and($refused->field)->toBe('content')
            ->and(str_contains($refused->getMessage(), $path.' '))->toBeTrue("invalid/{$name}: the message names where ({$path})");
    }
});

it('never echoes what it refused: the message names a path and a reason, not the value', function () {
    foreach (contentFixtures('invalid') as [$name, $fixture]) {
        try {
            ContentDocument::fromArray(fixtureDocument($fixture['document']));
        } catch (InvalidResourceInput $e) {
            foreach (['alert(1)', 'javascript', 'onclick', '<script', 'PHNjcmlwdD4', 'danger', 'user:pass'] as $secret) {
                expect($e->getMessage())->not->toContain($secret, "invalid/{$name} echoes {$secret}");
            }
        }
    }
});
