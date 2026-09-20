<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/** What the first operation's pause hook observed about the competing worker. */
final class RaceState
{
    public ?Process $worker = null;

    public bool $blocked = false;
}
