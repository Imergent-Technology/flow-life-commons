<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Content;

use App\Modules\Resources\Domain\InvalidResourceInput;
use JsonException;
use RuntimeException;

/**
 * A Card's rich content as the platform keeps it (ADR 0037, decisions 25-26): the canonical JSON text of a document that the
 * Resources document profile accepted, plus the format and version that name that profile. This is the ONLY representation of
 * content: HTML is never stored and is never trusted, so every rendering is derived from this and every change to it passes
 * through `fromArray`, the server's authoritative validation.
 *
 * A stored document is trusted on the way out of the database (it was validated on the way in, and a profile change that is not
 * additive needs an explicit migration, decision 32), so `fromStored` checks the format and version it knows and nothing more.
 */
final readonly class ContentDocument
{
    private const string EMPTY = '{"type":"doc","content":[]}';

    private function __construct(public string $format, public int $version, public string $json) {}

    public static function empty(): self
    {
        return new self(DocumentProfile::FORMAT, DocumentProfile::VERSION, self::EMPTY);
    }

    /**
     * Validates a submitted document against the profile and returns its canonical form.
     *
     * @param  array<mixed>  $document
     *
     * @throws InvalidResourceInput
     */
    public static function fromArray(array $document): self
    {
        $canonical = DocumentProfile::canonical($document);

        try {
            $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidResourceInput('content', 'The content is not in the allowed format: it cannot be stored.', InvalidResourceInput::CONTENT);
        }
        if (strlen($json) > DocumentProfile::MAX_BYTES) {
            throw new InvalidResourceInput('content', 'The content is too long: it is limited to '.(DocumentProfile::MAX_BYTES / 1024).' KiB.', InvalidResourceInput::CONTENT);
        }

        return new self(DocumentProfile::FORMAT, DocumentProfile::VERSION, $json);
    }

    public static function fromStored(string $format, int $version, string $json): self
    {
        if ($format !== DocumentProfile::FORMAT || $version !== DocumentProfile::VERSION) {
            throw new RuntimeException('A stored Card holds content in a format or version this code does not know.');
        }

        return new self($format, $version, $json);
    }

    /**
     * The document as a value to hand to a JSON encoder: objects stay objects (an empty `attrs` is never turned into a list).
     */
    public function decoded(): mixed
    {
        return json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The words of the document in reading order, with the end of a block, a list item, a table cell and a hard break each
     * a space, whitespace collapsed and the ends trimmed. What a derived summary is made of, and what "has text" means for
     * publishing: built from text nodes, never from rendered output, so no markup can reach it.
     */
    public function plainText(): string
    {
        $decoded = json_decode($this->json, true, 512, JSON_THROW_ON_ERROR);
        $text = is_array($decoded) ? self::textOf($decoded) : '';

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    }

    /** @param  array<mixed>  $node */
    private static function textOf(array $node): string
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if ($type === 'hardBreak') {
            return ' ';
        }
        $text = '';
        $children = $node['content'] ?? [];
        foreach (is_array($children) ? $children : [] as $child) {
            if (is_array($child)) {
                $text .= self::textOf($child);
            }
        }

        // The end of any block-level node is a word boundary; `doc` itself needs none.
        return $type === 'doc' ? $text : $text.' ';
    }
}
