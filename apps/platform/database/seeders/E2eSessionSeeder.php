<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEVELOPMENT AND TESTING ONLY. Gives the browser journeys that are NOT about signing in a legitimately authenticated
 * session to start from, without each of them spending a password-and-second-factor login from the public rate budget
 * (`./flow test e2e` runs this after E2eAccountSeeder and before it clears the cache).
 *
 * "Legitimately" is the point. Nothing here writes a session row or invents a cookie: it drives the platform's own
 * `POST /login` and `POST /mfa/challenge` in-process, through the real HTTP kernel, with a known account's password and one
 * of its known RECOVERY CODES (single-use, so any number of sessions can be minted with no authenticator time-step to wait
 * for), and keeps the cookies the server answered with. Each session is therefore exactly what a browser signing in would
 * hold: established with a second factor, freshly verified, rotated on establishment, 30 minutes of inactivity and 12 hours
 * absolute.
 *
 * A session can be minted as if its last password-and-second-factor proof were older (`stale`), by moving the clock back for the
 * sign-in and then restoring the session's activity time: the platform then judges it exactly as it would a real one whose
 * 15 minutes have run out, with no waiting and no test-only branch in the platform.
 *
 * It refuses to run outside the local and testing environments, is not part of DatabaseSeeder, and is referenced by nothing
 * in the application (an architecture test says so). The cookies go to a git-ignored file that only the e2e runner reads.
 */
final class E2eSessionSeeder extends Seeder
{
    /** Where the cookies are written, relative to storage/. `./flow test e2e` hands the file to the browser suite. */
    public const string FILE = 'app/private/e2e-sessions.json';

    private const string HOST = 'commons.flowlife.localhost';

    private const string SESSION_COOKIE = '__Host-flowlife-session';

    private const string XSRF_COOKIE = 'XSRF-TOKEN';

    /** The recovery code each session signs in with: the LAST of a fixture's ten, which no journey uses interactively. */
    private const int RECOVERY_INDEX = 9;

    /**
     * name => [email, password, recovery-code tag (see E2eAccountSeeder::recoveryCodes), minutes since the last proof].
     *
     * @var array<string, array{string, string, string, int}>
     */
    private const array SESSIONS = [
        'admin-read' => [E2eAccountSeeder::ADMIN_READ_EMAIL, E2eAccountSeeder::ADMIN_READ_PASSWORD, 'H', 0],
        'admin-stale' => [E2eAccountSeeder::ADMIN_STALE_EMAIL, E2eAccountSeeder::ADMIN_STALE_PASSWORD, 'J', 16],
        'admin-story' => [E2eAccountSeeder::ADMIN_STORY_EMAIL, E2eAccountSeeder::ADMIN_STORY_PASSWORD, 'N', 0],
        'admin-recover' => [E2eAccountSeeder::ADMIN_RECOVER_EMAIL, E2eAccountSeeder::ADMIN_RECOVER_PASSWORD, 'Q', 0],
        'plain-guardian' => [E2eAccountSeeder::PLAIN_GUARDIAN_EMAIL, E2eAccountSeeder::PLAIN_GUARDIAN_PASSWORD, 'S', 0],
    ];

    /** @var array<string, string> the cookie jar of the sign-in in progress: name => value as the server sent it */
    private array $jar = [];

    public function run(): void
    {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The e2e session fixtures may only be minted in a local or testing environment.');
        }

        $minted = [];
        foreach (self::SESSIONS as $name => [$email, $password, $tag, $minutesSinceProof]) {
            $minted[$name] = $this->mint($email, $password, E2eAccountSeeder::recoveryCodes($tag)[self::RECOVERY_INDEX], $minutesSinceProof);
        }

        File::ensureDirectoryExists(dirname(storage_path(self::FILE)));
        File::put(storage_path(self::FILE), json_encode($minted, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return array{session: string, xsrf: string} */
    private function mint(string $email, string $password, string $recoveryCode, int $minutesSinceProof): array
    {
        $this->clearJar();
        if ($minutesSinceProof > 0) {
            Carbon::setTestNow(Carbon::now()->subMinutes($minutesSinceProof));
        }

        try {
            $this->send('GET', '/api/v1/me', expected: 401); // a session and a request-forgery token, as the Console gets first
            $this->send('POST', '/api/v1/login', ['email' => $email, 'password' => $password], expected: 202);
            $this->send('POST', '/api/v1/mfa/challenge', ['recovery_code' => $recoveryCode], expected: 200);
        } finally {
            Carbon::setTestNow();
        }

        $session = $this->jar[self::SESSION_COOKIE] ?? throw new RuntimeException("No session was established for {$email}.");
        $xsrf = $this->jar[self::XSRF_COOKIE] ?? throw new RuntimeException("No request-forgery token was issued for {$email}.");
        $encoded = ['session' => rawurlencode($session), 'xsrf' => rawurlencode($xsrf)]; // as `Set-Cookie` carries them, which is what a browser stores

        // It must be a session that works, whatever its proof's age: readable, and never merely a pending sign-in.
        $this->send('GET', '/api/v1/me', expected: 200);

        if ($minutesSinceProof > 0) {
            // The clock was moved back for the sign-in, so the session's activity time is in the past too. Restore it: what
            // is old is the last PROOF, not the session, which must stay alive long enough to be used.
            DB::table('sessions')->where('id', $this->sessionId($session))->update(['last_activity' => time()]);
        }

        return $encoded;
    }

    /**
     * One in-process request through the real kernel, keeping the cookies the server answers with (as a browser would). The
     * previous request's session state is dropped first, so it is a fresh "process", as in production.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data = [], ?int $expected = null): Response
    {
        $app = $this->container;
        $app->make('session')->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app->make('auth')->forgetGuards();

        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (isset($this->jar[self::XSRF_COOKIE])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->jar[self::XSRF_COOKIE];
        }

        $request = Request::create('http://'.self::HOST.$path, $method, [], $this->jar, [], $server, $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $response = $app->make(Kernel::class)->handle($request);

        foreach ($response->headers->getCookies() as $cookie) {
            $cookie->getValue() === null || $cookie->getValue() === '' ? $this->forget($cookie) : $this->jar[$cookie->getName()] = (string) $cookie->getValue();
        }
        if ($expected !== null && $response->getStatusCode() !== $expected) {
            throw new RuntimeException("{$method} {$path} answered {$response->getStatusCode()}, expected {$expected}.");
        }

        return $response;
    }

    /** Starts a new sign-in with no cookies at all. (Its own method, so static analysis does not assume the jar stays empty.) */
    private function clearJar(): void
    {
        $this->jar = [];
    }

    private function forget(Cookie $cookie): void
    {
        unset($this->jar[$cookie->getName()]);
    }

    private function sessionId(string $cookieValue): string
    {
        $decrypted = $this->container->make('encrypter')->decrypt($cookieValue, false);
        assert(is_string($decrypted));

        return CookieValuePrefix::remove($decrypted);
    }
}
