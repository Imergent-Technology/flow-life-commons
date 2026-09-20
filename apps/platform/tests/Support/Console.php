<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\withUnencryptedCookies;

/**
 * A stand-in for the Guardian Console's browser, for feature tests of session
 * authentication.
 *
 * It behaves like a browser where it matters:
 * - it keeps a cookie jar and sends back exactly what the server issued (encrypted values
 *   untouched), honouring expiry;
 * - it echoes the XSRF-TOKEN cookie as X-XSRF-TOKEN, as the Console's HTTP client will;
 * - every request starts from a clean session and guard, like a fresh PHP process. Without
 *   that, the test kernel would carry one request's in-memory session into the next and
 *   could pass for the wrong reason.
 *
 * And it makes CSRF real. Laravel skips CSRF entirely under PHPUnit (it checks the app
 * environment is "testing"), so the environment is switched for the duration.
 */
final class Console
{
    public const string SESSION_COOKIE = '__Host-flowlife-session';

    public const string XSRF_COOKIE = 'XSRF-TOKEN';

    public const string API = '/api/v1';

    /** @var array<string, Cookie> */
    private array $jar = [];

    public function __construct(private readonly ?string $ip = null)
    {
        app()->instance('env', 'local'); // anything but "testing": see above
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function get(string $path, array $headers = []): TestResponse
    {
        return $this->send('GET', $path, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function post(string $path, array $data = [], array $headers = [], bool $withXsrfHeader = true): TestResponse
    {
        return $this->send('POST', $path, $data, $headers, $withXsrfHeader);
    }

    /**
     * Gets a session and CSRF cookie the way the Console does before signing in.
     *
     * @return TestResponse<Response>
     */
    public function bootstrap(): TestResponse
    {
        return $this->get(self::API.'/me');
    }

    /**
     * Signs in the way the Console will: get a token cookie first, then POST with it.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function login(string $email, string $password, array $headers = []): TestResponse
    {
        if (! isset($this->jar[self::XSRF_COOKIE])) {
            $this->bootstrap();
        }

        return $this->post(self::API.'/login', ['email' => $email, 'password' => $password], $headers);
    }

    /**
     * Finishes a sign-in whose password was proved: an authenticator code for `$secret` (a fresh one; the platform
     * accepts each time step once), or a recovery code.
     *
     * @return TestResponse<Response>
     */
    public function challenge(string $secret): TestResponse
    {
        return $this->post(self::API.'/mfa/challenge', ['code' => Totp::next($secret)]);
    }

    /** @return TestResponse<Response> */
    public function challengeWithRecoveryCode(string $code): TestResponse
    {
        return $this->post(self::API.'/mfa/challenge', ['recovery_code' => $code]);
    }

    /**
     * Signs in as an Account that has an authenticator: password, then a code. Returns the challenge response
     * (the one that establishes the session).
     *
     * @return TestResponse<Response>
     */
    public function loginWithMfa(string $email, string $password, string $secret = Totp::SECRET): TestResponse
    {
        $this->login($email, $password)->assertStatus(202)->assertJson(['next' => 'challenge']);

        return $this->challenge($secret);
    }

    /** @return TestResponse<Response> */
    public function logout(): TestResponse
    {
        return $this->post(self::API.'/logout');
    }

    /** @return TestResponse<Response> */
    public function me(): TestResponse
    {
        return $this->get(self::API.'/me');
    }

    /** The cookie the server last issued under this name, if the browser would still hold it. */
    public function cookie(string $name): ?Cookie
    {
        return $this->jar[$name] ?? null;
    }

    /** The current CSRF token, decrypted from the XSRF-TOKEN cookie. */
    public function csrfToken(): string
    {
        $cookie = $this->cookie(self::XSRF_COOKIE) ?? throw new RuntimeException('No XSRF-TOKEN cookie yet.');

        $decrypted = app('encrypter')->decrypt((string) $cookie->getValue(), false);
        assert(is_string($decrypted));

        return CookieValuePrefix::remove($decrypted);
    }

    /** @return array<string, string> raw cookie values as a browser would resend them */
    public function cookieValues(): array
    {
        return array_map(fn (Cookie $c): string => (string) $c->getValue(), $this->jar);
    }

    /**
     * Changes the stored session of the (single) live session row, as if the server had written it.
     *
     * @param  callable(Store): void  $mutate
     */
    public function tamperSession(callable $mutate): void
    {
        $ids = DB::table('sessions')->pluck('id')->all();
        if (count($ids) !== 1) {
            throw new RuntimeException('Expected exactly one session row to tamper with, found '.count($ids).'.');
        }

        $store = app('session')->driver();
        assert($store instanceof Store && is_string($ids[0]));
        $store->setId($ids[0]);
        $store->start();
        $mutate($store);
        $store->save();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app()->forgetInstance('auth.driver');
    }

    /**
     * A Console holding exactly these raw cookie values, as a browser replaying stolen or stale cookies would.
     *
     * @param  array<string, string>  $values
     */
    public static function replaying(array $values): self
    {
        $console = new self;
        foreach ($values as $name => $value) {
            $console->jar[$name] = new Cookie($name, $value);
        }

        return $console;
    }

    /**
     * Moves the clock forward by $seconds while a signed-in user stays active: a request
     * is made every 25 minutes along the way, so Laravel's 30-minute INACTIVITY rule is
     * satisfied throughout and only the absolute lifetime can end the session. No request
     * is made after the final hop, which is at most 25 minutes.
     */
    public function advanceWhileActive(int $seconds): void
    {
        $remaining = $seconds;
        while ($remaining > 25 * 60) {
            self::advance(25 * 60);
            $this->me()->assertOk();
            $remaining -= 25 * 60;
        }
        self::advance($remaining);
    }

    /** Moves the clock forward, for sliding-inactivity and absolute-lifetime tests. */
    public static function advance(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data, array $headers, bool $withXsrfHeader = true): TestResponse
    {
        // A fresh "process": drop the session drivers, the `session.store` and `auth.driver`
        // singletons (the guard captures the first, and the DB session handler asks the
        // second which account is signed in; stale ones would give wrong answers), and
        // the cached guards.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app()->forgetInstance('auth.driver');
        app('auth')->forgetGuards();

        if ($withXsrfHeader && isset($this->jar[self::XSRF_COOKIE]) && ! isset($headers['X-XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = (string) $this->jar[self::XSRF_COOKIE]->getValue();
        }
        $server = $this->ip === null ? [] : ['REMOTE_ADDR' => $this->ip];

        // withCredentials(): Laravel's json() helper sends NO cookies without it, which
        // would silently start a new session on every request.
        $response = withUnencryptedCookies($this->cookieValues())
            ->withCredentials()
            ->withServerVariables($server)
            ->json($method, $path, $data, $headers);

        $this->absorb($response);

        return $response;
    }

    /** @param  TestResponse<Response>  $response */
    private function absorb(TestResponse $response): void
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $gone = $cookie->getValue() === null || $cookie->getValue() === ''
                || ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < Carbon::now()->getTimestamp());

            if ($gone) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = $cookie;
            }
        }
    }
}
