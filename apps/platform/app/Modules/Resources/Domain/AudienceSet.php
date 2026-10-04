<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * A set of audiences with positive OR semantics (ADR 0037, decision 39): a viewer qualifies by satisfying ANY of them. There
 * is no deny, exception or precedence, so a set can only ever widen who qualifies. Duplicates collapse and the order is the
 * catalog's, so two sets with the same members are equal however they were built.
 */
final readonly class AudienceSet
{
    /** @var list<Audience> */
    public array $audiences;

    /** @param  list<Audience>  $audiences */
    private function __construct(array $audiences)
    {
        $kept = [];
        foreach (Audience::cases() as $case) {
            if (in_array($case, $audiences, true)) {
                $kept[] = $case;
            }
        }
        $this->audiences = $kept;
    }

    public static function of(Audience ...$audiences): self
    {
        return new self(array_values($audiences));
    }

    /** @param  list<Audience>  $audiences */
    public static function fromList(array $audiences): self
    {
        return new self($audiences);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->audiences === [];
    }

    public function contains(Audience $audience): bool
    {
        return in_array($audience, $this->audiences, true);
    }

    /** Whether the two share at least one audience: the whole of "a viewer qualifies". */
    public function intersects(self $other): bool
    {
        foreach ($this->audiences as $audience) {
            if ($other->contains($audience)) {
                return true;
            }
        }

        return false;
    }

    public function intersect(self $other): self
    {
        return new self(array_values(array_filter($this->audiences, $other->contains(...))));
    }

    /** Every member of this set is in $other. The empty set is a subset of anything. */
    public function isSubsetOf(self $other): bool
    {
        foreach ($this->audiences as $audience) {
            if (! $other->contains($audience)) {
                return false;
            }
        }

        return true;
    }

    public function equals(self $other): bool
    {
        return $this->audiences === $other->audiences;
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_map(static fn (Audience $a): string => $a->value, $this->audiences);
    }
}
