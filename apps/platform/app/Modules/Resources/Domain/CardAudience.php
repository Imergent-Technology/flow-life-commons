<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Whom a Card targets relative to its Pack (ADR 0037, decisions 40-41): the Pack's own set, or an explicit non-empty SUBSET of
 * it. A Card is never broader than its Pack. The subset rule is checked when a narrowing is made (`narrowedWithin`) and again
 * at every projection (`effective`, an intersection), so even a broken invariant could not widen what a viewer sees.
 */
final readonly class CardAudience
{
    private function __construct(public AudienceMode $mode, public AudienceSet $set) {}

    public static function inherit(): self
    {
        return new self(AudienceMode::Inherit, AudienceSet::none());
    }

    /** @throws InvalidResourceInput `card_audience_not_subset` unless `$set` is non-empty and within `$pack` */
    public static function narrowedWithin(AudienceSet $set, AudienceSet $pack): self
    {
        if ($set->isEmpty() || ! $set->isSubsetOf($pack)) {
            throw new InvalidResourceInput('audiences', 'A Card can only narrow its Pack\'s audiences: choose at least one, all of them among the Pack\'s.', 'card_audience_not_subset');
        }

        return new self(AudienceMode::Narrowed, $set);
    }

    public static function reconstitute(AudienceMode $mode, AudienceSet $set): self
    {
        return new self($mode, $mode === AudienceMode::Narrowed ? $set : AudienceSet::none());
    }

    /** Who can see this Card, given its Pack's audiences. */
    public function effective(AudienceSet $pack): AudienceSet
    {
        return $this->mode === AudienceMode::Inherit ? $pack : $this->set->intersect($pack);
    }
}
