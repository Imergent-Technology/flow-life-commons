<?php

declare(strict_types=1);

namespace App\Modules\Security\Application;

/**
 * One production-readiness check and its result.
 *
 * `detail` explains what is wrong AND why it matters, because the person reading it is deploying
 * something and needs to decide whether to stop. "APP_DEBUG is on" is a fact; "every error would
 * return a stack trace to whoever triggered it" is the reason to care.
 */
final readonly class ReadinessCheck
{
    private function __construct(
        public string $name,
        public bool $passed,
        public string $detail,
    ) {}

    public static function assert(string $name, bool $passed, string $detail): self
    {
        return new self($name, $passed, $passed ? '' : $detail);
    }
}
