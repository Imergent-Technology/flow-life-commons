<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A current-schema snapshot the recovery rehearsal refuses: a table that is missing, unexpected, not a base table or without a primary key, or a ledger that
 * is not readable. The message names tables and counts and never a value. Never a warning: an incomplete snapshot is not an empty one.
 */
final class CurrentSnapshotRefusal extends RuntimeException {}
