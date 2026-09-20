<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

use InvalidArgumentException;

/**
 * A caller tried to record something the audit trail must never hold. This is a
 * programming error, so it fails the operation loudly rather than dropping the event.
 */
final class InvalidSecurityEvent extends InvalidArgumentException {}
