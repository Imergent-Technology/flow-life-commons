<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * A complete resource (ADR 0037, decisions 17-23): a title, a summary, rich content, a Type that is fixed at creation, an optional
 * or required external address, for a File Card its one owned file, an audience (inherited or narrowed), a publication state, a
 * position and a revision.
 *
 * It needs no address or file to have meaning, and every Card keeps the generic fallback of title, summary, content and a safe
 * action. `revision` guards the AUTHORED fields (title, summary, content, address) against concurrent editors; publication,
 * audience, order and file replacement have their own invariants under the Pack's lock and do not move it (decision 56).
 *
 * Publishing needs a title, and by Type: text content (`basic`), an address (`external_link`) or its file (`file`). A Published
 * Card keeps meeting that, so an edit that would break it is refused by the use case rather than left Published and unusable. A
 * File Card has its file from creation and always (decision 18): it is created with one and only ever replaced, never cleared.
 */
final readonly class Card
{
    public const string NEEDS_CONTENT = 'content';

    public const string NEEDS_URI = 'uri';

    public const string NEEDS_FILE = 'file';

    private function __construct(
        public CardId $id,
        public PackId $packId,
        public int $position,
        public CardType $type,
        public string $title,
        public CardSummary $summary,
        public ContentDocument $content,
        public ?string $externalUri,
        public ?ResourceAsset $asset,
        public CardAudience $audience,
        public PublicationState $state,
        public int $revision,
        public Provenance $provenance,
    ) {}

    /**
     * A new Draft Card that inherits its Pack's audiences. A File Card is created WITH its file, and only a File Card has one.
     *
     * @throws InvalidResourceInput
     */
    public static function draft(
        CardId $id,
        PackId $packId,
        int $position,
        CardType $type,
        string $title,
        ContentDocument $content,
        ?string $externalUri,
        ?ResourceAsset $asset,
        CardSummary $summary,
        PersonId $by,
        DateTimeImmutable $now,
    ): self {
        if (($type === CardType::File) !== ($asset !== null)) {
            throw new InvalidResourceInput('file', $type === CardType::File ? 'A file card needs its file.' : 'Only a file card holds a file.');
        }

        return new self(
            $id, $packId, $position, $type,
            ResourceText::singleLine($title, 'title', 'Card', ResourceText::TITLE_MAX),
            $summary, $content, self::addressFor($type, $externalUri), $asset, CardAudience::inherit(),
            PublicationState::Draft, 1, Provenance::created($by, $now),
        );
    }

    public static function reconstitute(
        CardId $id,
        PackId $packId,
        int $position,
        CardType $type,
        string $title,
        CardSummary $summary,
        ContentDocument $content,
        ?string $externalUri,
        ?ResourceAsset $asset,
        CardAudience $audience,
        PublicationState $state,
        int $revision,
        Provenance $provenance,
    ): self {
        return new self($id, $packId, $position, $type, $title, $summary, $content, $externalUri, $asset, $audience, $state, $revision, $provenance);
    }

    /**
     * The Type's rule for the address: `external_link` requires one, `basic` may have one, a `file` Card has none, and a blank is none.
     *
     * @throws InvalidResourceInput
     */
    public static function addressFor(CardType $type, ?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            if ($type === CardType::ExternalLink) {
                throw new InvalidResourceInput('uri', 'An external link needs a web address.', InvalidResourceInput::URI);
            }

            return null;
        }
        if ($type === CardType::File) {
            throw new InvalidResourceInput('uri', 'A file card has no web address: its file is what it offers.', InvalidResourceInput::URI);
        }

        return ExternalUri::fromInput($raw);
    }

    /**
     * The authored fields changed and the revision advances.
     *
     * @throws InvalidResourceInput
     */
    public function edited(string $title, CardSummary $summary, ContentDocument $content, ?string $externalUri, PersonId $by, DateTimeImmutable $now): self
    {
        return new self(
            $this->id, $this->packId, $this->position, $this->type,
            ResourceText::singleLine($title, 'title', 'Card', ResourceText::TITLE_MAX),
            $summary, $content, self::addressFor($this->type, $externalUri), $this->asset, $this->audience,
            $this->state, $this->revision + 1, $this->provenance->touched($by, $now),
        );
    }

    public function withAudience(CardAudience $audience, PersonId $by, DateTimeImmutable $now): self
    {
        return new self($this->id, $this->packId, $this->position, $this->type, $this->title, $this->summary, $this->content, $this->externalUri, $this->asset, $audience, $this->state, $this->revision, $this->provenance->touched($by, $now));
    }

    /**
     * The File Card's file, replaced by another. Replacement is not an authored edit (decision 56): the revision does not move, and
     * the swap is decided under the Pack's lock, the later replacement winning. Only a File Card has a file to replace.
     *
     * @throws InvalidResourceInput
     */
    public function withAsset(ResourceAsset $asset, PersonId $by, DateTimeImmutable $now): self
    {
        if ($this->type !== CardType::File) {
            throw new InvalidResourceInput('file', 'Only a file card holds a file.');
        }

        return new self($this->id, $this->packId, $this->position, $this->type, $this->title, $this->summary, $this->content, $this->externalUri, $asset, $this->audience, $this->state, $this->revision, $this->provenance->touched($by, $now));
    }

    public function publishedBy(PersonId $by, DateTimeImmutable $now): self
    {
        return $this->inState(PublicationState::Published, $by, $now);
    }

    public function unpublishedBy(PersonId $by, DateTimeImmutable $now): self
    {
        return $this->inState(PublicationState::Draft, $by, $now);
    }

    public function isPublished(): bool
    {
        return $this->state === PublicationState::Published;
    }

    /**
     * What this Card lacks to be Published; empty means publishable.
     *
     * @return list<string>
     */
    public function unmetPublishRequirements(): array
    {
        return match ($this->type) {
            CardType::Basic => $this->content->plainText() === '' ? [self::NEEDS_CONTENT] : [],
            CardType::ExternalLink => $this->externalUri === null ? [self::NEEDS_URI] : [],
            CardType::File => $this->asset === null ? [self::NEEDS_FILE] : [],
        };
    }

    public function outline(): CardOutline
    {
        return new CardOutline($this->id, $this->packId, $this->position, $this->type, $this->title, $this->summary, $this->externalUri, $this->asset, $this->audience, $this->state, $this->revision, $this->provenance);
    }

    private function inState(PublicationState $state, PersonId $by, DateTimeImmutable $now): self
    {
        if ($this->state === $state) {
            return $this;
        }

        return new self($this->id, $this->packId, $this->position, $this->type, $this->title, $this->summary, $this->content, $this->externalUri, $this->asset, $this->audience, $state, $this->revision, $this->provenance->touched($by, $now));
    }
}
