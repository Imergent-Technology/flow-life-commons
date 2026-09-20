<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use DomainException;

/** An Account was asked to do something its current status forbids, or was built inconsistently. */
final class InvalidAccountState extends DomainException {}
