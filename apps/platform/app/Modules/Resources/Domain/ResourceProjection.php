<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * THE rule for what a viewer sees (ADR 0037, decisions 45-46). The library, a single Pack, search, file download and preview
 * all go through it, so there is no second place that could disagree about who may see a Card.
 *
 * A Card is visible to a viewer when its Pack is Published, the Card is Published, and its EFFECTIVE audience set (its Pack's
 * when it inherits, its own set within the Pack's when narrowed) shares an audience with the viewer's. A Pack is visible when it
 * has at least one visible Card, and a Category is listed when it has at least one visible Pack. An invisible Card is not there:
 * callers number the visible Cards 1..n among themselves, so a stored position, count or slot never reaches a viewer.
 *
 * Preview is the same rule with one thing set aside, the Pack's own publication (`$asIfPublished`): everything else, the Cards'
 * publication, the audiences and the narrowing, is exactly delivery's.
 */
final class ResourceProjection
{
    /**
     * @param  list<CardOutline>  $outlines  every Card of the Pack, in any order
     * @return list<CardOutline> the visible ones, in order
     */
    public static function visibleCards(Pack $pack, array $outlines, AudienceSet $viewer, bool $asIfPublished = false): array
    {
        if (! $asIfPublished && ! $pack->isPublished()) {
            return [];
        }

        $visible = array_values(array_filter(
            $outlines,
            static fn (CardOutline $card): bool => $card->isPublished() && $card->audience->effective($pack->audiences)->intersects($viewer),
        ));
        usort($visible, static fn (CardOutline $a, CardOutline $b): int => [$a->position, $a->id->value] <=> [$b->position, $b->id->value]);

        return $visible;
    }
}
