<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\PruneTransientState;
use Illuminate\Console\Command;

/**
 * `identity:prune-expired`: the scheduled sweep that keeps Identity's transient tables from growing
 * without limit (see PruneTransientState for what it does and does not touch).
 *
 * Production runs it from Laravel's scheduler (routes/console.php), which one system cron entry drives.
 * It is safe to run by hand at any time, concurrently with itself, and repeatedly: everything it does is
 * a conditional delete of rows that are already unusable.
 *
 * Unlike `identity:reset-mfa`, there is nothing to confirm: it changes no one's access, removes no
 * credential and ends no usable session, so it runs non-interactively by design.
 */
final class PruneTransientStateCommand extends Command
{
    protected $signature = 'identity:prune-expired';

    protected $description = 'Remove expired transient Identity state: idle sessions, expired password-reset tokens and unproved authenticator secrets.';

    public function handle(PruneTransientState $prune): int
    {
        $pruned = $prune();

        $this->line(sprintf(
            'Pruned %d idle session(s), %d expired password-reset token(s), %d unproved authenticator secret(s).',
            $pruned->sessions, $pruned->passwordResetTokens, $pruned->pendingAuthenticators,
        ));

        return self::SUCCESS;
    }
}
