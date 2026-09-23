<?php

declare(strict_types=1);

namespace App\Modules\Membership\Domain;

use DomainException;

/** A membership grant's interval is not a positive span: endsAt is not strictly after startsAt. */
final class InvalidMembershipTerm extends DomainException {}
