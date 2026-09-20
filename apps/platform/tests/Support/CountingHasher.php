<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;

/** Delegates to the real hasher and counts, so a test can prove how much hashing work a path did. */
final class CountingHasher implements Hasher
{
    public int $makes = 0;

    public int $checks = 0;

    public function __construct(private readonly Hasher $inner) {}

    /** @return array<array-key, mixed> */
    public function info($hashedValue)
    {
        return $this->inner->info($hashedValue);
    }

    /** @param  array<string, mixed>  $options */
    public function make($value, array $options = [])
    {
        $this->makes++;

        return $this->inner->make($value, $options);
    }

    /** @param  array<string, mixed>  $options */
    public function check($value, $hashedValue, array $options = [])
    {
        $this->checks++;

        return $this->inner->check($value, $hashedValue, $options);
    }

    /** @param  array<string, mixed>  $options */
    public function needsRehash($hashedValue, array $options = [])
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}
