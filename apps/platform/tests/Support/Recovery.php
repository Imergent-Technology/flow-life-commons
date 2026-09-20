<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Application\PasswordResetTokens;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Infrastructure\Mail\PasswordResetMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;

use function Pest\Laravel\postJson;

/** Helpers for the password-recovery tests. */
final class Recovery
{
    public const string FORGOT = '/api/v1/password/forgot';

    public const string RESET = '/api/v1/password/reset';

    /** @return TestResponse<JsonResponse> */
    public static function forgot(string $email): TestResponse
    {
        return postJson(self::FORGOT, ['email' => $email]);
    }

    /** @return TestResponse<JsonResponse> */
    public static function reset(string $email, string $token, string $password, ?string $confirmation = null): TestResponse
    {
        return postJson(self::RESET, ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password]);
    }

    /** A live reset token for the Account, issued directly (as if it had been requested and emailed). */
    public static function tokenFor(Account $account): string
    {
        return app(PasswordResetTokens::class)->issue($account)->revealToken();
    }

    /** @return list<PasswordResetMail> the recovery messages sent so far (needs Mail::fake()) */
    public static function sent(): array
    {
        return array_values(array_filter(Mail::sent(PasswordResetMail::class)->all(), fn (mixed $mail): bool => $mail instanceof PasswordResetMail));
    }

    /** The message as its recipient reads it. */
    public static function body(PasswordResetMail $mail): string
    {
        return $mail->render();
    }

    /** @return array{token: string, email: string} what the emailed link carries in its fragment */
    public static function linkFrom(PasswordResetMail $mail): array
    {
        if (preg_match('/#token=([^&\s]+)&email=(\S+)/', self::body($mail), $found) !== 1) {
            throw new RuntimeException('The message has no reset link.');
        }

        return ['token' => rawurldecode($found[1]), 'email' => rawurldecode($found[2])];
    }
}
