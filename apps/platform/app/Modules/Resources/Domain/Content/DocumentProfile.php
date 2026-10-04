<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Content;

use App\Modules\Resources\Domain\ExternalUri;
use App\Modules\Resources\Domain\InvalidResourceInput;

/**
 * Resources document profile v1 (ADR 0037, decision 26): the allowlist a Card's rich content is validated against, and the
 * security boundary for it. The server never trusts the editor, a client sanitizer or the shape of what it was sent: it parses
 * the submitted document into this allowlist or refuses it with `invalid_content`, naming the path of the first offence and
 * never echoing a value. It does not strip silently, because the Console's editor is configured to this same profile, so an
 * out-of-profile document is a defect or an attack and quietly discarding part of what a Guardian wrote would hide both.
 *
 * What it accepts (and nothing else):
 *   Blocks   paragraph; heading (level 2, 3 or 4); bulletList; orderedList (start); listItem; blockquote; horizontalRule;
 *            codeBlock (plain text); table, tableRow, tableHeader, tableCell (colspan and rowspan 1-20; no nested table).
 *   Inline   text and hardBreak.
 *   Marks    bold, italic, underline, code, and link (an href that passes ExternalUri, with mailto allowed).
 *   Limits   256 KiB of canonical JSON, nesting depth 24; control characters only as a tab, and a line feed only in a codeBlock.
 *
 * Attributes the configured editor always emits at one fixed default (a link's `target`, `rel` and `class`, a codeBlock's
 * `language`, an orderedList's `type`, a cell's `colwidth`) are accepted at that default and NOT stored: how a thing renders
 * is the renderer's decision, never the document's. Any other attribute, node, mark or key is refused. Returns the document in
 * canonical form: fixed key order, fixed mark order, defaults dropped.
 *
 * Plain PHP, pure and deterministic. HTML is never an input, an output or a stored form (decision 25), and nothing here
 * needs Node or any editor code.
 */
final class DocumentProfile
{
    public const string FORMAT = 'prosemirror';

    public const int VERSION = 1;

    public const int MAX_BYTES = 262144;

    public const int MAX_DEPTH = 24;

    private const int MAX_SPAN = 20;

    private const array BLOCKS = ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'horizontalRule', 'codeBlock', 'table'];

    /** The fixed default values of the attributes that are accepted but not stored, per node or mark type. */
    private const array DROPPED_DEFAULTS = [
        'codeBlock' => ['language' => [null]],
        'orderedList' => ['type' => [null]],
        'tableHeader' => ['colwidth' => [null]],
        'tableCell' => ['colwidth' => [null]],
        'link' => ['target' => [null, '_blank'], 'rel' => [null, 'noopener noreferrer nofollow', 'noopener noreferrer'], 'class' => [null]],
    ];

    private const array MARK_ORDER = ['bold', 'italic', 'underline', 'code', 'link'];

    /**
     * @param  array<mixed>  $document
     * @return array<string, mixed> the canonical document
     *
     * @throws InvalidResourceInput
     */
    public static function canonical(array $document): array
    {
        if (self::isList($document) || ($document['type'] ?? null) !== 'doc') {
            throw self::refuse('content', 'must be a document (type "doc")');
        }
        self::onlyKeys($document, ['type', 'content'], 'content');

        $children = self::children($document, 'content', 'doc', 1);

        return ['type' => 'doc', 'content' => $children];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function node(array $node, string $path, string $parent, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw self::refuse($path, 'is nested too deeply');
        }
        if (self::isList($node)) {
            throw self::refuse($path, 'must be an object');
        }
        $type = $node['type'] ?? null;
        if (! is_string($type)) {
            throw self::refuse($path, 'has no type');
        }

        return match ($type) {
            'paragraph' => self::textBlock($node, $path, 'paragraph', $parent, [], $depth),
            'heading' => self::textBlock($node, $path, 'heading', $parent, ['level' => self::level($node, $path)], $depth),
            'bulletList' => self::container($node, $path, 'bulletList', $parent, [], $depth),
            'orderedList' => self::container($node, $path, 'orderedList', $parent, self::orderedStart($node, $path), $depth),
            'blockquote' => self::container($node, $path, 'blockquote', $parent, [], $depth),
            'listItem' => self::container($node, $path, 'listItem', $parent, [], $depth),
            'horizontalRule' => self::leaf($node, $path, 'horizontalRule', $parent),
            'codeBlock' => self::codeBlock($node, $path, $parent),
            'table' => self::container($node, $path, 'table', $parent, [], $depth),
            'tableRow' => self::container($node, $path, 'tableRow', $parent, [], $depth),
            'tableHeader' => self::container($node, $path, 'tableHeader', $parent, self::spans($node, $path, 'tableHeader'), $depth),
            'tableCell' => self::container($node, $path, 'tableCell', $parent, self::spans($node, $path, 'tableCell'), $depth),
            'text' => self::text($node, $path, $parent),
            'hardBreak' => self::hardBreak($node, $path, $parent),
            default => throw self::refuse($path, 'has a node type the profile does not allow'),
        };
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, int>  $attrs
     * @return array<string, mixed>
     */
    private static function textBlock(array $node, string $path, string $type, string $parent, array $attrs, int $depth): array
    {
        self::place($type, $parent, $path);
        self::onlyKeys($node, ['type', 'attrs', 'content'], $path);
        if ($type === 'paragraph') {
            self::noAttributes($node, $path, $type); // a heading's `level` was handled by the caller; a paragraph has none
        }
        $out = ['type' => $type];
        if ($attrs !== []) {
            $out['attrs'] = $attrs;
        }
        $children = self::children($node, $path, $type, $depth + 1);
        if ($children !== []) {
            $out['content'] = $children;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, int>  $attrs
     * @return array<string, mixed>
     */
    private static function container(array $node, string $path, string $type, string $parent, array $attrs, int $depth): array
    {
        self::place($type, $parent, $path);
        self::onlyKeys($node, ['type', 'attrs', 'content'], $path);
        if (! in_array($type, ['orderedList', 'tableHeader', 'tableCell'], true)) {
            self::noAttributes($node, $path, $type); // those three read their own attributes; every other container has none
        }
        $children = self::children($node, $path, $type, $depth + 1);
        if ($children === []) {
            throw self::refuse($path, 'must not be empty');
        }
        if ($type === 'listItem' && ($children[0]['type'] ?? null) !== 'paragraph') {
            throw self::refuse($path.'.content[0]', 'a list item starts with a paragraph');
        }
        $out = ['type' => $type];
        if ($attrs !== []) {
            $out['attrs'] = $attrs;
        }
        $out['content'] = $children;

        return $out;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function leaf(array $node, string $path, string $type, string $parent): array
    {
        self::place($type, $parent, $path);
        self::onlyKeys($node, ['type', 'attrs'], $path);
        self::noAttributes($node, $path, $type);

        return ['type' => $type];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function codeBlock(array $node, string $path, string $parent): array
    {
        self::place('codeBlock', $parent, $path);
        self::onlyKeys($node, ['type', 'attrs', 'content'], $path);
        self::noAttributes($node, $path, 'codeBlock');
        $children = [];
        foreach (self::list($node, 'content', $path) as $i => $child) {
            $at = $path.'.content['.$i.']';
            if (! is_array($child)) {
                throw self::refuse($at, 'must be an object');
            }
            if (($child['type'] ?? null) !== 'text' || isset($child['marks'])) {
                throw self::refuse($at, 'code holds plain text only');
            }
            $children[] = self::text($child, $at, 'codeBlock');
        }
        $out = ['type' => 'codeBlock'];
        if ($children !== []) {
            $out['content'] = $children;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function hardBreak(array $node, string $path, string $parent): array
    {
        self::place('hardBreak', $parent, $path);
        self::onlyKeys($node, ['type', 'attrs'], $path);
        self::noAttributes($node, $path, 'hardBreak');

        return ['type' => 'hardBreak'];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function text(array $node, string $path, string $parent): array
    {
        self::place('text', $parent, $path);
        self::onlyKeys($node, ['type', 'text', 'marks'], $path);
        $text = $node['text'] ?? null;
        if (! is_string($text) || $text === '') {
            throw self::refuse($path, 'holds no text');
        }
        // A tab is ordinary; a line feed is a line break only inside code (elsewhere a hardBreak says it). Nothing else is a control
        // character, a line or a paragraph separator. Invalid UTF-8 makes the pattern fail, which is refusal too.
        $allowed = $parent === 'codeBlock' ? ["\t", "\n"] : ["\t"];
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', str_replace($allowed, '', $text)) !== 0) {
            throw self::refuse($path, 'contains a character the profile does not allow');
        }

        $out = ['type' => 'text', 'text' => $text];
        $marks = self::marks($node, $path);
        if ($marks !== []) {
            $out['marks'] = $marks;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function marks(array $node, string $path): array
    {
        $found = [];
        foreach (self::list($node, 'marks', $path) as $i => $mark) {
            $at = $path.'.marks['.$i.']';
            if (! is_array($mark) || self::isList($mark) || ! is_string($mark['type'] ?? null)) {
                throw self::refuse($at, 'is not a mark');
            }
            $type = $mark['type'];
            if (! in_array($type, self::MARK_ORDER, true)) {
                throw self::refuse($at, 'has a mark type the profile does not allow');
            }
            if (isset($found[$type])) {
                throw self::refuse($at, 'repeats a mark');
            }
            self::onlyKeys($mark, ['type', 'attrs'], $at);
            $found[$type] = $type === 'link' ? ['type' => 'link', 'attrs' => ['href' => self::href($mark, $at)]] : ['type' => $type];
            if ($type !== 'link') {
                self::noAttributes($mark, $at, $type);
            }
        }

        $ordered = [];
        foreach (self::MARK_ORDER as $type) {
            if (isset($found[$type])) {
                $ordered[] = $found[$type];
            }
        }

        return $ordered;
    }

    /** @param  array<mixed>  $mark */
    private static function href(array $mark, string $path): string
    {
        $attrs = self::attributes($mark, $path.'.attrs');
        $href = $attrs['href'] ?? null;
        $uri = is_string($href) ? ExternalUri::normalise($href, allowMailto: true) : null;
        if ($uri === null) {
            throw self::refuse($path.'.attrs.href', 'is not an allowed link address');
        }
        self::droppedDefaults($attrs, 'link', $path.'.attrs', ['href']);

        return $uri;
    }

    /** @param  array<mixed>  $node */
    private static function level(array $node, string $path): int
    {
        $attrs = self::attributes($node, $path.'.attrs');
        $level = $attrs['level'] ?? null;
        if (! is_int($level) || $level < 2 || $level > 4) {
            throw self::refuse($path.'.attrs.level', 'must be 2, 3 or 4');
        }
        self::droppedDefaults($attrs, 'heading', $path.'.attrs', ['level']);

        return $level;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, int>
     */
    private static function orderedStart(array $node, string $path): array
    {
        $attrs = self::attributes($node, $path.'.attrs');
        $start = $attrs['start'] ?? 1;
        if (! is_int($start) || $start < 1 || $start > 1_000_000) {
            throw self::refuse($path.'.attrs.start', 'must be a positive whole number');
        }
        self::droppedDefaults($attrs, 'orderedList', $path.'.attrs', ['start']);

        return $start === 1 ? [] : ['start' => $start];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, int>
     */
    private static function spans(array $node, string $path, string $type): array
    {
        $attrs = self::attributes($node, $path.'.attrs');
        $out = [];
        foreach (['colspan', 'rowspan'] as $name) {
            $value = $attrs[$name] ?? 1;
            if (! is_int($value) || $value < 1 || $value > self::MAX_SPAN) {
                throw self::refuse($path.'.attrs.'.$name, 'must be a whole number from 1 to '.self::MAX_SPAN);
            }
            if ($value !== 1) {
                $out[$name] = $value;
            }
        }
        self::droppedDefaults($attrs, $type, $path.'.attrs', ['colspan', 'rowspan']);

        return $out;
    }

    /**
     * The children of a node, validated against what its parent type may hold.
     *
     * @param  array<mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function children(array $node, string $path, string $parent, int $depth): array
    {
        $out = [];
        foreach (self::list($node, 'content', $path) as $i => $child) {
            $at = $path.'.content['.$i.']';
            if (! is_array($child)) {
                throw self::refuse($at, 'must be an object');
            }
            $out[] = self::node($child, $at, $parent, $depth);
        }

        return $out;
    }

    /** Whether a node of `$type` may sit directly inside `$parent`: the whole of the profile's structure. */
    private static function place(string $type, string $parent, string $path): void
    {
        $allowed = match ($parent) {
            'doc', 'blockquote', 'listItem' => self::BLOCKS,
            'tableHeader', 'tableCell' => array_values(array_diff(self::BLOCKS, ['table'])),
            'bulletList', 'orderedList' => ['listItem'],
            'table' => ['tableRow'],
            'tableRow' => ['tableHeader', 'tableCell'],
            'paragraph', 'heading' => ['text', 'hardBreak'],
            'codeBlock' => ['text'],
            default => [],
        };
        if (! in_array($type, $allowed, true)) {
            throw self::refuse($path, 'is not allowed here');
        }
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function attributes(array $node, string $path): array
    {
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs) || ($attrs !== [] && self::isList($attrs))) {
            throw self::refuse($path, 'must be an object');
        }

        return $attrs;
    }

    /** @param  array<mixed>  $node */
    private static function noAttributes(array $node, string $path, string $type): void
    {
        $attrs = self::attributes($node, $path.'.attrs');
        self::droppedDefaults($attrs, $type, $path.'.attrs', []);
    }

    /**
     * Refuses every attribute that is neither one the caller handled nor one of the type's accepted-and-dropped defaults, and
     * a default attribute holding anything but its default.
     *
     * @param  array<mixed>  $attrs
     * @param  list<string>  $handled
     */
    private static function droppedDefaults(array $attrs, string $type, string $path, array $handled): void
    {
        $defaults = self::DROPPED_DEFAULTS[$type] ?? [];
        foreach ($attrs as $name => $value) {
            if (in_array($name, $handled, true)) {
                continue;
            }
            if (! is_string($name) || ! array_key_exists($name, $defaults)) {
                throw self::refuse($path, 'has an attribute the profile does not allow');
            }
            if (! in_array($value, $defaults[$name], true)) {
                throw self::refuse($path.'.'.$name, 'is not the editor\'s default');
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $keys
     */
    private static function onlyKeys(array $node, array $keys, string $path): void
    {
        foreach (array_keys($node) as $key) {
            if (! in_array($key, $keys, true)) {
                throw self::refuse($path, 'has a key the profile does not allow');
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @return list<mixed>
     */
    private static function list(array $node, string $key, string $path): array
    {
        if (! array_key_exists($key, $node)) {
            return [];
        }
        $value = $node[$key];
        if (! is_array($value) || ($value !== [] && ! self::isList($value))) {
            throw self::refuse($path.'.'.$key, 'must be a list');
        }

        return array_values($value);
    }

    /** @param  array<mixed>  $value */
    private static function isList(array $value): bool
    {
        return $value !== [] && array_is_list($value);
    }

    private static function refuse(string $path, string $why): InvalidResourceInput
    {
        return new InvalidResourceInput('content', "The content is not in the allowed format: {$path} {$why}.", InvalidResourceInput::CONTENT);
    }
}
