<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use InvalidArgumentException;

/**
 * Where an asset's bytes live in the Resources file store (ADR 0037, decision 63): derived from the asset's ULID ALONE, so it has
 * no user-supplied path component, no extension, no directory and nothing a filename could smuggle in. It is an implementation
 * detail of storage, never an authorization: every download is resolved through a Card the caller may see, and the key never
 * leaves the server (no response, no event, no filename carries it).
 *
 * The shape is exactly the lowercase ULID form, so `tryFromName` doubles as the test for "a file this store wrote": anything else
 * found in the store's directory (a stray file, a directory, a dotfile) is not Resources' and is never touched by the prune.
 */
final readonly class StorageKey
{
    private const string PATTERN = '/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/';

    private function __construct(public string $value) {}

    public static function for(AssetId $id): self
    {
        return new self($id->value);
    }

    /** A key read back from the database, which must have the shape this class writes. */
    public static function fromStored(string $value): self
    {
        return self::tryFromName($value) ?? throw new InvalidArgumentException('That is not a Resources storage key.');
    }

    /** The key a stored object's name is, or null when the name is not one this store would have written. */
    public static function tryFromName(string $name): ?self
    {
        return preg_match(self::PATTERN, $name) === 1 ? new self($name) : null;
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
