<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Verifies an email and password and decides whether that Account may sign in. It does
 * NOT create the session: that is the transport's job, and it happens only after this
 * has returned and its transaction has committed.
 *
 * - Lookup is by canonical email only (ADR 0015).
 * - Only an active Account with a credential may authenticate. Invited, disabled, unknown
 *   and wrong-password all return the same undifferentiated failure.
 * - A password check runs on EVERY path, against a throwaway hash when there is no
 *   eligible Account, so response time does not reveal whether the address exists.
 * - The success event and the `last_login_at` update share one transaction (ADR 0019).
 *   Failures are not thrown from inside a transaction, so their events are kept.
 * - The plain password is used once and never stored, logged or recorded.
 */
final class AuthenticateAccount
{
    /** A hash of a random string at the application's own cost, for the no-such-account path. */
    private static ?string $decoyHash = null;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PersonRepository $people,
        private readonly LoginThrottle $throttle,
        private readonly AuthenticationAudit $audit,
        private readonly Hasher $hasher,
        private readonly ConnectionInterface $database,
        private readonly EffectiveCapabilities $capabilities,
    ) {}

    public function __invoke(EmailAddress $email, string $password, ClientContext $client): AuthenticationResult
    {
        $block = $this->throttle->block($client->ip, $email);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited($email, $block, $client);
            }

            return AuthenticationResult::throttled($block->retryAfterSeconds);
        }

        $this->throttle->recordAttempt($client->ip);

        $account = $this->accounts->findByEmail($email);
        $eligible = $account !== null && $account->canAuthenticate() ? $account : null;
        $passwordMatches = $this->hasher->check($password, $eligible->passwordHash ?? $this->decoyHash());

        if ($eligible === null || ! $passwordMatches) {
            $this->throttle->recordFailure($email);
            $this->audit->failed($this->reasonFor($account), $email, $account, $client);

            return AuthenticationResult::failed();
        }

        $current = $this->database->transaction(fn (): CurrentAccount => $this->signIn($eligible, $client));
        $this->throttle->clearFailures($email);

        // Read after the sign-in commits, from current state; never stored in the session.
        return AuthenticationResult::authenticated(new CurrentAccount(
            $current->actor, $current->email, $current->displayName, $this->capabilities->for($current->actor),
        ));
    }

    private function signIn(Account $account, ClientContext $client): CurrentAccount
    {
        $person = $this->people->find($account->personId)
            ?? throw new LogicException('An account exists without its person.');
        $actor = Actor::user($account->id, $account->personId);

        $this->accounts->save($account->recordLogin(DateTimeImmutable::createFromInterface(now())));
        $this->audit->succeeded($actor, $client);

        return new CurrentAccount($actor, $account->email->value, $person->displayName);
    }

    private function reasonFor(?Account $account): FailureReason
    {
        return match (true) {
            $account === null => FailureReason::UnknownAccount,
            ! $account->canAuthenticate() => FailureReason::AccountNotActive,
            default => FailureReason::WrongPassword,
        };
    }

    private function decoyHash(): string
    {
        return self::$decoyHash ??= $this->hasher->make(Str::random(40));
    }
}
