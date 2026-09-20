<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

/** The email address or display name given for an invitation is not acceptable. */
final class InvalidInvitationDetails extends InvalidArgumentException {}
