<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * The breached-password check could not be completed (the service is down, slow, or answered
 * nonsense). The password has NOT been judged, so it is neither accepted nor called weak: the
 * caller should ask the user to try again shortly. Retryable; nothing was changed.
 */
final class CompromisedPasswordCheckUnavailable extends RuntimeException {}
