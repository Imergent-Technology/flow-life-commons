<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\MfaStatuses;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use App\Modules\Identity\Domain\PersonRepository;
use Illuminate\Console\Command;

/**
 * `identity:reset-mfa`: the operator's way to recover an Account whose second factor is gone when no other
 * administrator can do it (ADR 0024), most importantly the ONLY administrator. Its authority is that the operator
 * can run commands on the server, exactly like the administrator bootstrap (ADR 0020): it confers no new
 * authority, leaves no standing credential, and has no HTTP route.
 *
 * It removes the authenticator and every recovery code and ends the Account's sessions. It never sets a
 * password, changes a role or a status, generates a secret or prints a code: the person proves their password
 * and enrols a new authenticator at their next sign-in.
 *
 * The friction is deliberate. It refuses to run non-interactively, and NO flag overrides that, so a script
 * cannot do it by adding one. It shows exactly who the target is and what will happen, and the operator must type
 * the address back.
 */
final class ResetMfaCommand extends Command
{
    protected $signature = 'identity:reset-mfa
        {email? : The email address of the Account whose second factor is to be reset}';

    protected $description = 'Reset an Account\'s second factor (authenticator and recovery codes) so its owner can enrol again. Interactive only.';

    public function handle(AccountRepository $accounts, PersonRepository $people, MfaStatuses $statuses, ResetMultiFactor $reset): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Resetting a second factor needs an interactive terminal, and no flag overrides that. Nothing was changed.');

            return self::FAILURE;
        }

        $input = $this->argument('email');
        $input = is_string($input) && $input !== '' ? $input : $this->ask('Email address of the Account');
        if (! is_string($input) || trim($input) === '') {
            $this->error('An email address is required. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $email = EmailAddress::fromString($input);
        } catch (InvalidEmailAddress) {
            $this->error('That is not a valid email address. Nothing was changed.');

            return self::FAILURE;
        }

        $account = $accounts->findByEmail($email);
        if ($account === null) {
            $this->error("No account has the address {$email->canonical}. Nothing was changed.");

            return self::FAILURE;
        }

        $person = $people->find($account->personId);
        $mfa = $statuses->for($account->id);
        $this->newLine();
        $this->line('  Person          '.($person->displayName ?? '(unknown)'));
        $this->line("  Email           {$account->email->canonical}");
        $this->line("  Account         {$account->id->value}");
        $this->line("  Status          {$account->status->value}");
        $this->line('  Authenticator   '.($mfa->enrolled ? "on, {$mfa->recoveryCodesRemaining} recovery codes left" : 'not set up'));
        $this->newLine();
        $this->warn('This removes their authenticator and every recovery code, and signs them out everywhere.');
        $this->warn('Their password, status and roles are NOT changed. They must sign in with their password and enrol a new authenticator.');
        $this->warn('Nothing secret is shown here: they choose and prove their own new authenticator.');

        $confirmation = $this->ask("To continue, type the email address again ({$account->email->canonical})");
        if (! is_string($confirmation) || strtolower(trim($confirmation)) !== $account->email->canonical) {
            $this->error('The confirmation did not match. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $result = $reset->fromServer($account->id);
        } catch (AccountNotFound) {
            $this->error('The account no longer exists. Nothing was changed.');

            return self::FAILURE;
        }

        $this->newLine();
        if (! $result->changed) {
            $this->info('There was nothing to reset: this account has no authenticator and no recovery codes. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->info("Second factor reset. {$result->sessionsEnded} session(s) ended.");
        $this->comment('They can now sign in with their password and will be asked to set up a new authenticator. The reset is recorded as mfa.reset_from_server.');

        return self::SUCCESS;
    }
}
