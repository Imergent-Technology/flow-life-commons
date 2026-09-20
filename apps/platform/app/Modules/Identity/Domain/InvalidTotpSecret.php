<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

final class InvalidTotpSecret extends InvalidArgumentException {}
