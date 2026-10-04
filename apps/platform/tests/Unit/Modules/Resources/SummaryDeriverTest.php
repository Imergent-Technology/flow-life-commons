<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\CardSummary;
use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\Content\SummaryDeriver;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\SummaryMode;

/** @return ContentDocument a document of one paragraph per argument */
function summaryDoc(string ...$paragraphs): ContentDocument
{
    return ContentDocument::fromArray(['type' => 'doc', 'content' => array_map(
        static fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        array_values($paragraphs),
    )]);
}

it('derives the plain text of the content, with blocks, list items, cells and hard breaks as spaces, whitespace collapsed', function () {
    $doc = ContentDocument::fromArray(['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "a  b\t c"], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'd']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]]],
        ]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'c1']]]]],
            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'c2']]]]],
        ]]]],
    ]]);

    expect(SummaryDeriver::derive($doc, 200))->toBe('Title a b c d one two c1 c2');
});

it('is built from text nodes, never from markup: a link, bold and a code block contribute only their words', function () {
    $doc = ContentDocument::fromArray(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' here', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/secret?x=1']]]]]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => '<b>not markup</b>']]],
    ]]);

    $summary = SummaryDeriver::derive($doc, 200);
    expect($summary)->toBe('see here <b>not markup</b>')->and($summary)->not->toContain('example.org');
});

it('returns text that fits whole, with no ellipsis', function () {
    expect(SummaryDeriver::derive(summaryDoc('Exactly short'), 200))->toBe('Exactly short')
        ->and(SummaryDeriver::derive(ContentDocument::fromArray(['type' => 'doc', 'content' => [['type' => 'paragraph']]]), 200))->toBe('')
        ->and(SummaryDeriver::derive(ContentDocument::empty(), 200))->toBe('');
});

it('cuts at a word boundary and ends in an ellipsis when text is dropped', function () {
    $text = 'The quick brown fox jumps over the lazy dog and keeps on running far away';

    expect(SummaryDeriver::derive(summaryDoc($text), 28))->toBe('The quick brown fox jumps…')
        ->and(mb_strlen(SummaryDeriver::derive(summaryDoc($text), 28)))->toBeLessThanOrEqual(29);
});

it('keeps the whole word when the cut falls exactly at its end', function () {
    // "aaaa bbbb cccc dddd eeee" is 24 characters; a cut at 19 falls right after "dddd", the next character being a space.
    expect(SummaryDeriver::derive(summaryDoc('aaaa bbbb cccc dddd eeee'), 20))->toBe('aaaa bbbb cccc dddd…');
});

it('cuts a long unbroken word hard rather than backing off to almost nothing', function () {
    $text = 'x '.str_repeat('y', 80);

    expect(SummaryDeriver::derive(summaryDoc($text), 40))->toBe('x '.str_repeat('y', 38).'…');
});

it('counts characters, not bytes, so non-ASCII text is cut cleanly and stays valid UTF-8', function () {
    $summary = SummaryDeriver::derive(summaryDoc(str_repeat('日本語 ', 40)), 30);

    expect(mb_check_encoding($summary, 'UTF-8'))->toBeTrue()->and(mb_strlen($summary))->toBeLessThanOrEqual(31);
});

it('is deterministic and bounds the allowance it is asked for to what a summary column holds', function () {
    $doc = summaryDoc(str_repeat('word ', 200));

    expect(SummaryDeriver::derive($doc, 100))->toBe(SummaryDeriver::derive($doc, 100))
        ->and(mb_strlen(SummaryDeriver::derive($doc, 5000)))->toBeLessThanOrEqual(SummaryDeriver::MAX_LENGTH + 1)
        ->and(mb_strlen(SummaryDeriver::derive($doc, 1)))->toBeGreaterThan(1) // the floor: a one-character summary is not useful
        ->and(mb_strlen(SummaryDeriver::derive($doc, 5000)))->toBeLessThanOrEqual(300);
});

it('lets a derived summary follow the content and a custom one stay as written', function () {
    $before = summaryDoc('First words');
    $after = summaryDoc('Entirely different words');

    $derived = CardSummary::derived($before, 200);
    $custom = CardSummary::custom('Written by a person');

    expect($derived->mode)->toBe(SummaryMode::Derived)
        ->and($derived->afterContentChange($after, 200)->text)->toBe('Entirely different words')
        ->and($custom->mode)->toBe(SummaryMode::Custom)
        ->and($custom->afterContentChange($after, 200)->text)->toBe('Written by a person')
        ->and($custom->afterContentChange($after, 200)->mode)->toBe(SummaryMode::Custom);
});

it('refuses a blank, multi-line, control-character or over-long custom summary', function (string $text) {
    expect(fn () => CardSummary::custom($text))->toThrow(InvalidResourceInput::class);
})->with(['blank' => '   ', 'multi-line' => "two\nlines", 'control' => "bell\x07", 'too long' => str_repeat('a', 301)]);

it('trims a custom summary and accepts exactly 300 characters', function () {
    expect(CardSummary::custom('  Padded  ')->text)->toBe('Padded')
        ->and(mb_strlen(CardSummary::custom(str_repeat('a', 300))->text))->toBe(300);
});
