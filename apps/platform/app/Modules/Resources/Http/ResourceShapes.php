<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\ResourceAsset;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The PURE pieces every Resources response is built from, shared by the three presenters and carrying no authority of their own (ADR 0038,
 * R5): how a Category reference, a document, a file description and a time are written. Which fields a reader receives is each
 * presenter's decision, and a presenter's field list is the contract for its policy.
 */
final class ResourceShapes
{
    /** @return array{id: string, name: string}|null */
    public static function categoryRef(?Category $category): ?array
    {
        return $category === null ? null : ['id' => $category->id->value, 'name' => $category->name];
    }

    /** @return array{format: string, version: int, document: mixed} */
    public static function content(Card $card): array
    {
        return ['format' => $card->content->format, 'version' => $card->content->version, 'document' => $card->content->decoded()];
    }

    /** @return array{name: string, media_type: string, byte_size: int} */
    public static function fileSummary(ResourceAsset $asset): array
    {
        return ['name' => $asset->originalFilename, 'media_type' => $asset->mediaType, 'byte_size' => $asset->byteSize];
    }

    /** The API path (no host) of a Card's file on one of the file routes. */
    public static function path(string $route, PackId $pack, CardId $card): string
    {
        return route($route, ['pack' => $pack->value, 'card' => $card->value], false);
    }

    public static function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
