<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * The shareable unit (ADR 0037, decisions 11-16): a title, an optional hand-written summary, a Series flag, at most one Category,
 * an audience set, a publication state and a revision. It holds Cards (a separate aggregate row set) while Draft or Published.
 *
 * A Published Pack always satisfies its publish requirements (decision 14): `unmetPublishRequirements` names what a Draft still
 * lacks, and the use cases refuse any change that would leave a Published Pack lacking one. `revision` guards the AUTHORED fields
 * (title, summary, Series, Category) against concurrent editors; publication, audiences and ordering have their own invariants
 * under locks and do not move it.
 *
 * Series is presentation only: it implies no completion, prerequisite, lock or progress.
 */
final readonly class Pack
{
    /** At most this many Cards in one Pack. */
    public const int MAX_CARDS = 100;

    /** What a Draft must have before it can be Published, and what a Published Pack must always keep. */
    public const string NEEDS_CATEGORY = 'category';

    public const string NEEDS_AUDIENCE = 'audience';

    public const string NEEDS_PUBLISHED_CARD = 'published_card';

    private function __construct(
        public PackId $id,
        public ?CategoryId $categoryId,
        public int $position,
        public string $title,
        public ?string $summary,
        public bool $isSeries,
        public AudienceSet $audiences,
        public PublicationState $state,
        public int $revision,
        public Provenance $provenance,
    ) {}

    /** @throws InvalidResourceInput */
    public static function draft(PackId $id, ?CategoryId $categoryId, int $position, string $title, ?string $summary, bool $isSeries, PersonId $by, DateTimeImmutable $now): self
    {
        return new self(
            $id, $categoryId, $position,
            ResourceText::singleLine($title, 'title', 'Pack', ResourceText::TITLE_MAX),
            ResourceText::optionalLine($summary, 'summary', 'Pack', ResourceText::SUMMARY_MAX),
            $isSeries, AudienceSet::none(), PublicationState::Draft, 1, Provenance::created($by, $now),
        );
    }

    public static function reconstitute(
        PackId $id,
        ?CategoryId $categoryId,
        int $position,
        string $title,
        ?string $summary,
        bool $isSeries,
        AudienceSet $audiences,
        PublicationState $state,
        int $revision,
        Provenance $provenance,
    ): self {
        return new self($id, $categoryId, $position, $title, $summary, $isSeries, $audiences, $state, $revision, $provenance);
    }

    /**
     * The authored fields changed, and the revision advances. `$position` is the place in the (possibly new) Category.
     *
     * @throws InvalidResourceInput
     */
    public function edited(string $title, ?string $summary, bool $isSeries, ?CategoryId $categoryId, int $position, PersonId $by, DateTimeImmutable $now): self
    {
        return new self(
            $this->id, $categoryId, $position,
            ResourceText::singleLine($title, 'title', 'Pack', ResourceText::TITLE_MAX),
            ResourceText::optionalLine($summary, 'summary', 'Pack', ResourceText::SUMMARY_MAX),
            $isSeries, $this->audiences, $this->state, $this->revision + 1, $this->provenance->touched($by, $now),
        );
    }

    public function withAudiences(AudienceSet $audiences, PersonId $by, DateTimeImmutable $now): self
    {
        return new self($this->id, $this->categoryId, $this->position, $this->title, $this->summary, $this->isSeries, $audiences, $this->state, $this->revision, $this->provenance->touched($by, $now));
    }

    public function publishedBy(PersonId $by, DateTimeImmutable $now): self
    {
        return $this->inState(PublicationState::Published, $by, $now);
    }

    public function unpublishedBy(PersonId $by, DateTimeImmutable $now): self
    {
        return $this->inState(PublicationState::Draft, $by, $now);
    }

    /** Ordering is not editing: provenance and revision are untouched. */
    public function atPosition(int $position): self
    {
        return new self($this->id, $this->categoryId, $position, $this->title, $this->summary, $this->isSeries, $this->audiences, $this->state, $this->revision, $this->provenance);
    }

    public function isPublished(): bool
    {
        return $this->state === PublicationState::Published;
    }

    /**
     * What this Pack lacks to be Published, in a fixed order; empty means publishable.
     *
     * @return list<string>
     */
    public function unmetPublishRequirements(int $publishedCards): array
    {
        $unmet = [];
        if ($this->categoryId === null) {
            $unmet[] = self::NEEDS_CATEGORY;
        }
        if ($this->audiences->isEmpty()) {
            $unmet[] = self::NEEDS_AUDIENCE;
        }
        if ($publishedCards < 1) {
            $unmet[] = self::NEEDS_PUBLISHED_CARD;
        }

        return $unmet;
    }

    private function inState(PublicationState $state, PersonId $by, DateTimeImmutable $now): self
    {
        if ($this->state === $state) {
            return $this;
        }

        return new self($this->id, $this->categoryId, $this->position, $this->title, $this->summary, $this->isSeries, $this->audiences, $state, $this->revision, $this->provenance->touched($by, $now));
    }
}
