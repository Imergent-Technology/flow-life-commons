<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\Content\SummaryDeriver;

/**
 * A Card's summary and where it came from (ADR 0037, decisions 34-37). `derived` follows the content: every content change
 * recomputes it. `custom` is the editor's own words and no content change ever overwrites it. Going back to automatic is an
 * explicit act that recomputes at once. Plain text, one line, at most 300 characters either way.
 */
final readonly class CardSummary
{
    private function __construct(public SummaryMode $mode, public string $text) {}

    public static function derived(ContentDocument $content, int $length): self
    {
        return new self(SummaryMode::Derived, SummaryDeriver::derive($content, $length));
    }

    /** @throws InvalidResourceInput */
    public static function custom(string $text): self
    {
        $text = ResourceText::trimmed($text);
        if ($text === '') {
            throw new InvalidResourceInput('summary', 'Write a summary, or use the automatic one.');
        }

        return new self(SummaryMode::Custom, ResourceText::singleLine($text, 'summary', 'Card', ResourceText::SUMMARY_MAX));
    }

    public static function reconstitute(SummaryMode $mode, string $text): self
    {
        return new self($mode, $text);
    }

    /** The content changed: a derived summary follows it, a custom one stays as written. */
    public function afterContentChange(ContentDocument $content, int $length): self
    {
        return $this->mode === SummaryMode::Derived ? self::derived($content, $length) : $this;
    }
}
