<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** A Resources file state, archive or request the recovery rehearsal refuses. Never a warning: nothing is repaired, moved or written past it. */
final class ResourceFileRefusal extends RuntimeException {}
