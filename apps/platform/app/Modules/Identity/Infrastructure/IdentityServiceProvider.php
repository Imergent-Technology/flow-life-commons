<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\AccountDeactivationGuard;
use App\Modules\Identity\Application\AccountSessions;
use App\Modules\Identity\Application\ActiveAccountQuery;
use App\Modules\Identity\Application\AttemptThrottle;
use App\Modules\Identity\Application\CompromisedPasswords;
use App\Modules\Identity\Application\CredentialMarker;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\EffectiveCapabilities;
use App\Modules\Identity\Application\InvitationNotifier;
use App\Modules\Identity\Application\LoginThrottle;
use App\Modules\Identity\Application\MultiFactorPolicy;
use App\Modules\Identity\Application\PasswordResetNotifier;
use App\Modules\Identity\Application\PasswordResetTokens;
use App\Modules\Identity\Application\TotpAuthenticator;
use App\Modules\Identity\Application\TotpSecretCipher;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Modules\Identity\Infrastructure\Auth\AccountResetTokenRepository;
use App\Modules\Identity\Infrastructure\Auth\AccountUserProvider;
use App\Modules\Identity\Infrastructure\Auth\CacheAttemptThrottle;
use App\Modules\Identity\Infrastructure\Auth\CacheLoginThrottle;
use App\Modules\Identity\Infrastructure\Auth\LaravelPasswordResetTokens;
use App\Modules\Identity\Infrastructure\Console\ResetMfaCommand;
use App\Modules\Identity\Infrastructure\Mail\MailInvitationNotifier;
use App\Modules\Identity\Infrastructure\Mail\MailPasswordResetNotifier;
use App\Modules\Identity\Infrastructure\Mfa\AlwaysRequireMultiFactor;
use App\Modules\Identity\Infrastructure\Mfa\HmacCredentialMarker;
use App\Modules\Identity\Infrastructure\Mfa\LaravelTotpSecretCipher;
use App\Modules\Identity\Infrastructure\Mfa\OtphpTotpAuthenticator;
use App\Modules\Identity\Infrastructure\Password\NoCompromisedPasswordCheck;
use App\Modules\Identity\Infrastructure\Password\PwnedPasswordsRange;
use App\Modules\Identity\Infrastructure\Persistence\DatabaseAccountSessions;
use App\Modules\Identity\Infrastructure\Persistence\DatabaseActiveAccountQuery;
use App\Modules\Identity\Infrastructure\Persistence\DatabaseRecoveryCodeRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountInvitationRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentPersonRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentTotpFactorRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Binds Identity's ports to their implementations and registers its auth provider driver.
 * Routes are loaded from Http/routes.php by routes/api.php, like every module's.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        PersonRepository::class => EloquentPersonRepository::class,
        AccountRepository::class => EloquentAccountRepository::class,
        AccountInvitationRepository::class => EloquentAccountInvitationRepository::class,
        LoginThrottle::class => CacheLoginThrottle::class,
        AttemptThrottle::class => CacheAttemptThrottle::class,
        PasswordResetNotifier::class => MailPasswordResetNotifier::class,
        InvitationNotifier::class => MailInvitationNotifier::class,
        ActiveAccountQuery::class => DatabaseActiveAccountQuery::class,
        AccountSessions::class => DatabaseAccountSessions::class,
        // A default that grants nothing. The Access module registers its own over this.
        EffectiveCapabilities::class => NoEffectiveCapabilities::class,
        // Multi-factor authentication (ADR 0023). The policy default FAILS CLOSED (everyone is asked for a
        // second factor); Access registers the real one over it.
        MultiFactorPolicy::class => AlwaysRequireMultiFactor::class,
        TotpFactorRepository::class => EloquentTotpFactorRepository::class,
        RecoveryCodeRepository::class => DatabaseRecoveryCodeRepository::class,
        TotpSecretCipher::class => LaravelTotpSecretCipher::class,
        CredentialMarker::class => HmacCredentialMarker::class,
    ];

    public function register(): void
    {
        // The guard chain: whatever other modules have tagged as an AccountDeactivationGuard.
        // Identity names none of them; with none registered the chain is simply empty.
        $this->app->when(DisableAccount::class)->needs('$guards')->giveTagged(AccountDeactivationGuard::TAG);

        // Laravel's database token repository over `password_reset_tokens`, configured from
        // config/auth.php exactly as its own broker would be: the same table, expiry and throttle.
        $this->app->bind(PasswordResetTokens::class, function (Application $app): PasswordResetTokens {
            $config = $app->make('config');
            $key = $config->string('app.key');
            if (str_starts_with($key, 'base64:')) {
                $key = (string) base64_decode(substr($key, 7), true);
            }
            $expiresInSeconds = $config->integer('auth.passwords.accounts.expire') * 60;

            return new LaravelPasswordResetTokens(
                new AccountResetTokenRepository(
                    $app->make(ConnectionInterface::class), $app->make(Hasher::class),
                    $config->string('auth.passwords.accounts.table'), $key,
                    $expiresInSeconds, $config->integer('auth.passwords.accounts.throttle'),
                ),
                $expiresInSeconds,
            );
        });

        $this->app->bind(TotpAuthenticator::class, fn (Application $app): TotpAuthenticator => new OtphpTotpAuthenticator(
            $app->make('config')->string('identity.mfa.issuer'),
        ));

        $this->app->bind(CompromisedPasswords::class, function (Application $app): CompromisedPasswords {
            $driver = $app->make('config')->get('identity.password.compromised_check.driver');

            return match ($driver) {
                'pwned_passwords' => $app->make(PwnedPasswordsRange::class),
                // The one way to switch the breach check off, and and only where there are no real users to protect.
                'none' => $app->environment('local', 'testing')
                    ? new NoCompromisedPasswordCheck
                    : throw new LogicException('The breached-password check may only be disabled in local and testing environments.'),
                default => throw new LogicException('Unknown breached-password check driver.'),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ResetMfaCommand::class]);
        }

        // Identity's own message templates (the password-recovery and invitation emails), namespaced `identity::`.
        $this->loadViewsFrom(__DIR__.'/Mail/views', 'identity');

        // The "identity" driver named by config/auth.php.
        Auth::provider('identity', fn (Application $app): AccountUserProvider => $app->make(AccountUserProvider::class));
    }
}
