<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/**
 * The whole-sibling-list contract of every reorder (ADR 0037, decision 9): the client states the COMPLETE ordered list of ids of
 * one sibling set, and it must be exactly the set that is there now, no more, no fewer, none twice. Anything else means someone
 * added, moved or deleted a sibling since the client looked, and is refused `order_mismatch` rather than guessed at.
 */
final class SiblingOrder
{
    /**
     * @param  list<string>  $current  the ids that are there now
     * @param  list<string>  $submitted  the ids the client sent, in its order
     *
     * @throws OrderMismatch
     */
    public static function assertSameSet(array $current, array $submitted): void
    {
        $a = $current;
        $b = $submitted;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new OrderMismatch;
        }
    }
}
