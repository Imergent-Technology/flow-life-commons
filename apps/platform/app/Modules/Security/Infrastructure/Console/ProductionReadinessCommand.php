<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Console;

use App\Modules\Security\Application\ProductionReadiness;
use App\Modules\Security\Application\ReadinessCheck;
use Illuminate\Console\Command;

/**
 * `security:production-check`: reads the configuration this deployment is actually running under and
 * says whether it is safe to serve real people. `./flow doctor --production` runs it inside the
 * platform container; on the production host it is `php artisan security:production-check`.
 *
 * It is READ-ONLY and takes no destructive action, so it is safe to run on a live host at any time.
 *
 * It reports in three parts, and the separation is the point:
 *
 *   CHECKS          configuration this deployment is running under. A failure here is unsafe to serve.
 *   DELIBERATELY    decisions that have been deferred on purpose and documented as deferred. Reported
 *   OPEN            every time so they cannot be forgotten, and never failing the command, because a
 *                   check that always fails is one people stop reading.
 *   NOT CHECKED     what no amount of configuration reading can establish. A green result says the
 *                   application is configured correctly and says nothing whatever about whether the
 *                   hosting account can serve it. Conflating the two is how a checklist becomes false
 *                   comfort.
 *
 * No check prints a secret. Several read one — APP_KEY, the database password — and report only what
 * is wrong with it, because this is run on a live host and its output gets pasted into tickets.
 */
final class ProductionReadinessCommand extends Command
{
    protected $signature = 'security:production-check';

    protected $description = 'Check this deployment\'s configuration against what production requires (read-only).';

    /** What no amount of configuration reading can establish. Each is a person's job, on the real host. */
    private const array OWNER_VERIFICATIONS = [
        'HTTPS is active on the subdomain (AutoSSL or equivalent). Without it the __Host- cookie cannot be issued at all.',
        'The subdomain has its OWN document root, pointing at the platform\'s public/ directory and shared with nothing.',
        '.htaccess overrides are honoured (AllowOverride) and mod_rewrite is on.',
        'mod_headers is enabled. Without it the Console\'s static files ship with NO security headers while the API keeps them.',
        'The WEB SERVER\'s PHP (not the CLI this command ran under) is 8.3 with the same extensions.',
        'A cron entry runs `php artisan schedule:run` every minute. Nothing else drives scheduled maintenance.',
        'Outbound HTTPS to api.pwnedpasswords.com works, or no password can be accepted.',
        'Outbound mail works: an invitation or reset that cannot be sent leaves the person unable to proceed.',
        'The database is reachable with production credentials, and backed up together with APP_KEY.',
        'Repository and application-private files (.env, storage/, vendor/) are not reachable over the web.',
    ];

    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->checks();
        $failed = array_values(array_filter($checks, static fn (ReadinessCheck $c): bool => ! $c->passed));

        $this->newLine();
        $this->line('<options=bold>Configuration this deployment is running under</>');
        foreach ($checks as $check) {
            $this->line($check->passed ? "  <fg=green>✓</> {$check->name}" : "  <fg=red>✗</> {$check->name}");
            if (! $check->passed && $check->detail !== '') {
                $this->line("      {$check->detail}");
            }
        }

        $deferred = $readiness->deferred();
        if ($deferred !== []) {
            $this->newLine();
            $this->line('<options=bold>Deliberately open: decided to defer, and not a reason to stop</>');
            foreach ($deferred as $item) {
                $this->line($item->passed ? "  <fg=green>✓</> {$item->name}" : "  <fg=yellow>—</> {$item->name}");
                if (! $item->passed && $item->detail !== '') {
                    $this->line("      {$item->detail}");
                }
            }
        }

        $this->newLine();
        $this->line('<options=bold>Not checked here: verify these on the hosting account itself</>');
        foreach (self::OWNER_VERIFICATIONS as $item) {
            $this->line("  <fg=yellow>?</> {$item}");
        }
        // Informational, never a check: see ProductionReadiness::exposePhp() for why the CLI's own
        // reading is not acted on here, and confirm the web-facing fact (no X-Powered-By reaching a
        // client) is what was actually verified on the hosting account.
        $this->line("  <fg=yellow>?</> This CLI process's expose_php reads '{$readiness->exposePhp()}'.");
        $this->line('      That is not the web server\'s PHP. Confirm no X-Powered-By header reaches a real client.');

        $this->newLine();
        if ($failed !== []) {
            $this->error(count($failed).' configuration problem(s). Do not serve real people until they are fixed.');

            return self::FAILURE;
        }

        $open = count(array_filter($deferred, static fn (ReadinessCheck $c): bool => ! $c->passed));
        $this->info('Configuration is as production requires. '
            .($open > 0 ? $open.' item(s) remain deliberately open, and the ' : 'The ')
            .'owner verifications above are still outstanding.');

        return self::SUCCESS;
    }
}
