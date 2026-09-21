<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\EnrollTotpFixture;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Modules\Identity\Http\ConsoleActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** Builders and helpers shared by the multi-factor tests. */
final class Mfa
{
    /**
     * Enrols an authenticator for the Account (through the development fixture: a real enrolment needs a
     * live proof, and is what the feature tests of it do). Returns the secret and raw recovery codes.
     *
     * @return array{secret: string, codes: list<string>}
     */
    public static function enroll(Account $account, string $secret = Totp::SECRET): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = RecoveryCode::generate()->formatted();
        }
        app(EnrollTotpFixture::class)($account->id, $secret, $codes);

        return ['secret' => $secret, 'codes' => $codes];
    }

    public static function isEnrolled(Account $account): bool
    {
        return app(TotpFactorRepository::class)->findByAccount($account->id)?->isActive() === true;
    }

    public static function remainingCodes(Account $account): int
    {
        return app(RecoveryCodeRepository::class)->remaining($account->id);
    }

    /**
     * The raw stored TOTP row, for asserting what is (not) at rest.
     *
     * @return object{secret_ciphertext: ?string, pending_secret_ciphertext: ?string, enrolled_at: ?string, last_used_step: ?int}|null
     */
    public static function factorRow(Account $account): ?object
    {
        /** @var object{secret_ciphertext: ?string, pending_secret_ciphertext: ?string, enrolled_at: ?string, last_used_step: ?int}|null */
        return DB::table('account_totp_factors')->where('account_id', $account->id->value)->first();
    }

    /** Asserts that none of the needles appears in the haystack. */
    public static function assertAbsent(string $haystack, string ...$needles): void
    {
        foreach ($needles as $needle) {
            expect($haystack)->not->toContain($needle);
        }
    }

    /** A JSON field that must be text, as a string (a test helper: response fields are `mixed`). */
    public static function text(mixed $value): string
    {
        assert(is_string($value));

        return $value;
    }

    /**
     * A JSON field that must be a list of text.
     *
     * @return list<string>
     */
    public static function texts(mixed $value): array
    {
        assert(is_array($value));

        return array_values(array_map(static fn (mixed $v): string => self::text($v), $value));
    }

    /** An active Account whose Person holds the Console role, with no second factor yet. */
    public static function guardian(string $email = 'ada@example.org'): Account
    {
        $account = Identity::savedActiveAccount($email);
        Access::grant($account, Role::Guardian);

        return $account;
    }

    /**
     * A Console signed in as an enrolled Account, through the real two steps.
     *
     * @return array{Console, Account, array{secret: string, codes: list<string>}}
     */
    public static function signedIn(string $email = 'ada@example.org', string $secret = Totp::SECRET): array
    {
        $account = self::guardian($email);
        $factor = self::enroll($account, $secret);
        $console = new Console;
        $console->loginWithMfa($email, Identity::PASSWORD, $secret)->assertOk();

        return [$console, $account, $factor];
    }

    /**
     * An operator: an active Account holding the administrator role, with an authenticator, signed in through the
     * real two steps. Their session was established WITH a second factor, so it is freshly verified.
     *
     * @return array{Console, Account, array{secret: string, codes: list<string>}}
     */
    public static function signedInAdmin(string $email = 'admin@example.org', string $secret = Totp::SECRET): array
    {
        $account = Access::admin($email);
        $factor = self::enroll($account, $secret);
        $console = new Console;
        $console->loginWithMfa($email, Identity::PASSWORD, $secret)->assertOk();

        return [$console, $account, $factor];
    }

    /** The whole text of every recorded security event, for asserting what must never be in it. */
    public static function auditText(): string
    {
        return json_encode(DB::table('security_events')->get()->all(), JSON_THROW_ON_ERROR);
    }

    /** TEST-ONLY routes for the step-up seam and for observing an Actor's provenance. */
    public static function registerProbeRoutes(): void
    {
        Route::middleware(['stateful', 'auth:web', 'security.verified'])
            ->get('/api/v1/zz/sensitive', fn () => response()->json(['ok' => true]));
        Route::middleware(['stateful', 'auth:web'])
            ->get('/api/v1/zz/actor', function (Request $request, ConsoleActor $actors) {
                return response()->json(['via' => $actors->for($request)?->authenticatedVia->value]);
            });
        Route::middleware(['stateful', 'auth:web', 'can:console.access'])
            ->get('/api/v1/zz/console', fn () => response()->json(['ok' => true]));
    }
}
