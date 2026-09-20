<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * A stored TOTP secret cannot be decrypted. This is a deployment fault (the application key changed
 * without its previous value being kept, or a row was altered), not a wrong code, so it is deliberately
 * NOT turned into "the code was wrong": it is loud.
 */
final class TotpSecretUnreadable extends RuntimeException {}
