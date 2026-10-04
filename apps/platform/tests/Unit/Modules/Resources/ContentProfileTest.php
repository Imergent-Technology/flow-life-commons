<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\Content\DocumentProfile;
use App\Modules\Resources\Domain\InvalidResourceInput;

/*
 * The limits and invariants of the document profile that are not a fixture file (ADR 0037, decisions 25-27). The accepted and
 * refused SHAPES are the shared corpus (ContentFixturesTest); this is what needs code to state.
 */

/** @return array<string, mixed> */
function paragraphDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

it('refuses a document over 256 KiB of canonical JSON, and accepts one just under', function () {
    $near = str_repeat('a', DocumentProfile::MAX_BYTES - 200);
    expect(strlen(ContentDocument::fromArray(paragraphDoc($near))->json))->toBeLessThanOrEqual(DocumentProfile::MAX_BYTES);

    $over = fn () => ContentDocument::fromArray(paragraphDoc(str_repeat('a', DocumentProfile::MAX_BYTES)));
    expect($over)->toThrow(InvalidResourceInput::class, 'too long');
});

it('refuses nesting deeper than 24 levels, and accepts the deepest allowed', function () {
    $nest = function (int $levels): array {
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]];
        for ($i = 0; $i < $levels; $i++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        return ['type' => 'doc', 'content' => [$node]];
    };

    // doc is depth 0; each blockquote adds one; the paragraph and its text add two more.
    $deepest = DocumentProfile::MAX_DEPTH - 2;
    expect(fn () => ContentDocument::fromArray($nest($deepest)))->not->toThrow(InvalidResourceInput::class)
        ->and(fn () => ContentDocument::fromArray($nest($deepest + 1)))->toThrow(InvalidResourceInput::class, 'nested too deeply');
});

it('keeps whitespace inside text nodes exactly as sent: it is content', function () {
    $stored = ContentDocument::fromArray(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => ' leading and trailing '],
        ['type' => 'text', 'text' => "\ttabbed", 'marks' => [['type' => 'bold']]],
    ]]]]);

    $decoded = json_decode($stored->json, true);
    assert(is_array($decoded) && is_array($decoded['content']) && is_array($decoded['content'][0]) && is_array($decoded['content'][0]['content']));
    $inline = $decoded['content'][0]['content'];
    assert(is_array($inline[0]) && is_array($inline[1]));

    expect($inline[0]['text'])->toBe(' leading and trailing ')
        ->and($inline[1]['text'])->toBe("\ttabbed");
});

it('stores a canonical, deterministic text: the same document always gives the same bytes, whatever key order it came in', function () {
    $a = ContentDocument::fromArray(['content' => [['content' => [['text' => 'x', 'type' => 'text']], 'type' => 'paragraph']], 'type' => 'doc']);
    $b = ContentDocument::fromArray(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]]);

    expect($a->json)->toBe($b->json)
        ->and($a->json)->toBe('{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"x"}]}]}');
});

it('does not escape unicode or slashes in what it stores, so the text is the text', function () {
    expect(ContentDocument::fromArray(paragraphDoc('日本語 / café'))->json)->toContain('日本語 / café');
});

it('holds an empty document as the canonical empty document', function () {
    expect(ContentDocument::empty()->json)->toBe('{"type":"doc","content":[]}')
        ->and(ContentDocument::fromArray(['type' => 'doc'])->json)->toBe('{"type":"doc","content":[]}')
        ->and(ContentDocument::empty()->plainText())->toBe('');
});

it('refuses a list where an object is required, and an object where a list is', function () {
    expect(fn () => ContentDocument::fromArray([['type' => 'doc']]))->toThrow(InvalidResourceInput::class)
        ->and(fn () => ContentDocument::fromArray(['type' => 'doc', 'content' => ['type' => 'paragraph']]))->toThrow(InvalidResourceInput::class, 'must be a list');
});

it('does not know about HTML at all: a document is never rendered, parsed or produced as markup', function () {
    // The profile is plain PHP over arrays; this pins that nothing in it reaches for an HTML or DOM library.
    $source = '';
    foreach (glob(dirname(__DIR__, 4).'/app/Modules/Resources/Domain/Content/*.php') ?: [] as $file) {
        $source .= (string) file_get_contents($file);
    }

    expect($source)->not->toMatch('/\b(DOMDocument|HTMLPurifier|html_entity_decode|htmlspecialchars|strip_tags|loadHTML|Masterminds|HtmlSanitizer)\b/');
});

it('is a pure function: validating the same input twice gives the same result and throws the same refusal', function () {
    $bad = ['type' => 'doc', 'content' => [['type' => 'script']]];
    $messages = [];
    for ($i = 0; $i < 2; $i++) {
        try {
            ContentDocument::fromArray($bad);
        } catch (InvalidResourceInput $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages)->toHaveCount(2)->and($messages[0])->toBe($messages[1]);
});

/**
 * @param  list<array<string, mixed>>  $blocks
 * @return array<string, mixed> a one-cell table whose cell holds `$blocks`
 */
function tableHolding(array $blocks, string $cell = 'tableCell'): array
{
    return ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => $cell, 'content' => $blocks]]]]];
}

it('refuses a table anywhere below a table cell or header, at any depth, and keeps ordinary tables and cell content', function () {
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]];
    $inner = tableHolding([$paragraph]);
    $store = fn (array $blocks) => ContentDocument::fromArray(['type' => 'doc', 'content' => $blocks]);

    // Refused: directly in a cell, behind a blockquote, behind a list, and deeper still (ancestry, not the parent).
    $nested = [
        'directly' => tableHolding([$paragraph, $inner]),
        'behind a blockquote' => tableHolding([['type' => 'blockquote', 'content' => [$inner]]]),
        'behind a list' => tableHolding([['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph, $inner]]]]]),
        'behind an ordered list in a header' => tableHolding([['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [$paragraph, ['type' => 'blockquote', 'content' => [$inner]]]]]]], 'tableHeader'),
    ];
    foreach ($nested as $where => $table) {
        expect(fn () => $store([$table]))->toThrow(InvalidResourceInput::class, 'is not allowed here');
        $thrown = null;
        try {
            $store([$table]);
        } catch (InvalidResourceInput $e) {
            $thrown = $e;
        }
        expect($thrown?->problem)->toBe('invalid_content', $where);
    }

    // Kept: a table at the top level, in a quote or a list outside any cell, two tables side by side, and a cell holding
    // quotes, lists and code (everything but a table).
    $quote = ['type' => 'blockquote', 'content' => [$inner]];
    $list = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph, $inner]]]];
    $richCell = tableHolding([
        $paragraph, ['type' => 'blockquote', 'content' => [$paragraph]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph, ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph]]]]]]]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'code']]],
    ]);
    foreach ([[$inner], [$quote], [$list], [$inner, $inner], [$richCell]] as $blocks) {
        expect(fn () => $store($blocks))->not->toThrow(InvalidResourceInput::class);
    }
});
