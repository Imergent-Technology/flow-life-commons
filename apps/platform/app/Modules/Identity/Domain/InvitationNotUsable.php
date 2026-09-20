<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use DomainException;

/** An invitation was expired, already accepted, or could not be issued in a usable state. */
final class InvitationNotUsable extends DomainException {}
