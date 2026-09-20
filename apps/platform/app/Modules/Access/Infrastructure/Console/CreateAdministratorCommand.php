<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure\Console;

use App\Modules\Access\Application\AdministratorAlreadyExists;
use App\Modules\Access\Application\AdministratorOverview;
use App\Modules\Access\Application\BootstrapAdministrator;
use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvalidInvitationDetails;
use App\Modules\Identity\Application\InvitationDetails;
use Illuminate\Console\Command;

/**
 * `identity:create-administrator`: the operator's way to create the first administrator, or a
 * recovery administrator after a total lockout (ADR 0020). Its authority is that the operator can
 * run commands on the server; there is no other check, and no HTTP route to it.
 *
 * The name is the frozen one, though the class lives in Access: it needs both modules, and only
 * Access may depend on Identity.
 *
 * - It never accepts a password. The invitee sets their own when they accept the invitation.
 * - It shows the one-time invitation token only after the transaction has committed, once, on
 *   standard output. The token is not logged, audited or stored.
 * - It refuses when an administrator assignment already exists. `--force` is not enough on its
 *   own: recovery also needs an interactive terminal and the operator typing the new address
 *   back, and a non-interactive run refuses outright, so a script cannot bypass the protection
 *   by adding a flag.
 */
final class CreateAdministratorCommand extends Command
{
    protected $signature = 'identity:create-administrator
        {email? : Email address of the administrator to invite}
        {--name= : Display name}
        {--force : Recovery: create an administrator although one already exists (interactive terminal and confirmation required)}';

    protected $description = 'Create the first (or a recovery) platform administrator and issue a one-time invitation. Sets no password.';

    public function handle(AdministratorOverview $overview, BootstrapAdministrator $bootstrap): int
    {
        $interactive = $this->input->isInteractive();

        $emailInput = $this->stringArgument('email') ?? ($interactive ? $this->ask('Email address') : null);
        $nameInput = $this->stringOption('name') ?? ($interactive ? $this->ask('Display name') : null);
        if (! is_string($emailInput) || ! is_string($nameInput) || trim($emailInput) === '' || trim($nameInput) === '') {
            $this->error('An email address and a display name are required. In a non-interactive run pass the email as the argument and --name.');

            return self::FAILURE;
        }

        try {
            $details = InvitationDetails::from($emailInput, $nameInput);
        } catch (InvalidInvitationDetails $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = $overview();
        $recovery = false;

        if ($summary->assigned > 0) {
            if (! $this->option('force')) {
                $this->error("An administrator already exists ({$summary->assigned} assigned, {$summary->active} able to sign in). Refusing.");
                $this->line('Nothing was changed. If every administrator is locked out, re-run with --force from an interactive terminal.');

                return self::FAILURE;
            }
            if (! $interactive) {
                $this->error('Recovery needs an interactive terminal. --force alone does not override the protection in a non-interactive run. Nothing was changed.');

                return self::FAILURE;
            }

            $this->warn("An administrator already exists ({$summary->assigned} assigned, {$summary->active} able to sign in).");
            $this->warn('This creates ANOTHER administrator; it does not replace or change any existing account.');
            $confirmation = $this->ask("To continue, type the email address again ({$details->canonicalEmail()})");
            if (! is_string($confirmation) || strtolower(trim($confirmation)) !== $details->canonicalEmail()) {
                $this->error('The confirmation did not match. Nothing was changed.');

                return self::FAILURE;
            }
            $recovery = true;
        } elseif ($this->option('force')) {
            $this->info('No administrator exists, so --force is not needed. Continuing as a normal bootstrap.');
        }

        try {
            $result = $bootstrap($details, $recovery);
        } catch (AdministratorAlreadyExists $e) {
            $this->error("An administrator already exists ({$e->assigned} assigned). Nothing was changed.");

            return self::FAILURE;
        } catch (EmailAlreadyInUse) {
            $this->error("The email address {$details->canonicalEmail()} already belongs to an account. Nothing was changed; an existing account is never repurposed.");

            return self::FAILURE;
        }

        // Committed. Only now is the secret shown.
        $invitation = $result->invitation;
        $this->newLine();
        $this->info($recovery ? 'Recovery administrator invited.' : 'Administrator invited.');
        $this->line("  Person       {$invitation->personId->value}");
        $this->line("  Account      {$invitation->accountId->value}  (status: invited; no password is set)");
        $this->line("  Email        {$invitation->email}");
        $this->line('  Expires      '.$invitation->expiresAt->format('Y-m-d H:i:s').' UTC');
        $this->newLine();
        $this->line('Invitation token. It is shown ONCE, is not stored, and cannot be recovered:');
        $this->newLine();
        $this->line('  '.$invitation->revealToken());
        $this->newLine();
        $this->comment('Hand the token to the administrator over a channel you trust. They accept it, and choose their password, with');
        $this->comment('POST /api/v1/invitations/accept (token, password, password_confirmation in the body). See docs/runbooks/administrator-bootstrap.md.');

        return self::SUCCESS;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
