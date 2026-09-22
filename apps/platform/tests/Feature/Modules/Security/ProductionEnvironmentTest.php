<?php

declare(strict_types=1);

use App\Modules\Security\Application\ProductionEnvironment;
use App\Modules\Security\Application\ProductionReadiness;
use Dotenv\Dotenv;

/*
 * The production environment template, `apps/platform/.env.production.example`, and the contract it
 * shares with `security:production-check`.
 *
 * The failure this file exists for is DRIFT: the template says one thing and the check expects another,
 * and the first person to find out is the one deploying. So the strongest test here does not compare
 * two lists — it loads the template as the environment, fills in only the four values an operator is
 * told to supply, rebuilds the configuration exactly as Laravel would on the host, and runs the real
 * check against it. If the check starts requiring something the template never sets, or the template
 * sets something the check refuses, that test fails.
 *
 * Nothing here touches a real host, a real database or the network.
 */

/** @return array<string, string|null> the template, parsed as dotenv parses it */
function productionTemplate(): array
{
    return Dotenv::parse((string) file_get_contents(ProductionEnvironment::templatePath()));
}

/** The template's text, with commentary removed, for asserting what is ASSIGNED rather than explained. */
function productionTemplateAssignments(): string
{
    return implode("\n", array_filter(
        explode("\n", (string) file_get_contents(ProductionEnvironment::templatePath())),
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#') && trim($line) !== '',
    ));
}

/** Config files underEnvironment() rebuilds from disk, and must therefore also restore. */
const UNDER_ENVIRONMENT_CONFIG_FILES = ['app', 'cache', 'cors', 'database', 'hashing', 'identity', 'mail', 'queue', 'session'];

/**
 * Run $callback with the process environment replaced by $values ALONE, then put everything back —
 * $_ENV, $_SERVER, putenv() state AND the rebuilt config() values, whatever happened inside.
 *
 * "Alone" is the point. The test suite's own environment comes from phpunit.xml, which sets exactly
 * the development values the template must not rely on (`none` for the breach checker, a 4-round
 * bcrypt cost, a 0 ms reset floor). Every key it sets is cleared first, so a value only passes because
 * the template set it or the application's own default is correct — which is precisely the production
 * situation, where nothing but `shared/.env` exists.
 *
 * EVERY mutation this function makes is inside the try, and every one is undone in the finally,
 * regardless of where an exception is thrown — including one thrown while saving or clearing the
 * environment, not only from $callback. Two things are restored, not one: the earlier version of this
 * helper restored $_ENV/$_SERVER/putenv() but never the config() values it rebuilt from disk, so a
 * production-shaped config (a 12-round bcrypt cost, `pwned_passwords`, `database` cache and queue…)
 * leaked into every test that ran afterward in the same process — PHPUnit does not fork a process per
 * test, so that contamination was silent and order-dependent rather than a failure at the call site
 * where it happened.
 *
 * @param  array<string, string>  $values
 */
function underEnvironment(array $values, Closure $callback): mixed
{
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    $injected = [];
    foreach ($phpunit !== false ? ($phpunit->xpath('//php/env') ?: []) : [] as $node) {
        $injected[] = (string) $node['name'];
    }
    $keys = array_values(array_unique([...$injected, ...ProductionEnvironment::FORBIDDEN_KEYS, ...array_keys($values)]));

    $savedEnv = [];
    $savedConfig = [];
    try {
        foreach ($keys as $key) {
            $savedEnv[$key] = [
                array_key_exists($key, $_ENV) ? $_ENV[$key] : null, array_key_exists($key, $_ENV),
                array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null, array_key_exists($key, $_SERVER),
                getenv($key),
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }

        // Rebuilt from the config files themselves, so every env() default is the application's own.
        // The PRE-rebuild value of each file is captured here, not assumed to be config()'s current
        // state at exit: a nested call, or a test that already mutated config() before calling this,
        // would otherwise be restored to the wrong thing.
        foreach (UNDER_ENVIRONMENT_CONFIG_FILES as $file) {
            $savedConfig[$file] = config($file);
            config([$file => require config_path("$file.php")]);
        }

        return $callback();
    } finally {
        foreach ($savedConfig as $file => $value) {
            config([$file => $value]);
        }
        foreach ($savedEnv as $key => [$env, $hadEnv, $server, $hadServer, $put]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($hadEnv) {
                $_ENV[$key] = $env;
            }
            if ($hadServer) {
                $_SERVER[$key] = $server;
            }
            putenv($put === false ? $key : "$key=$put");
        }
    }
}

/**
 * The four values an operator supplies, as the template's own header instructs. Never real.
 *
 * @return array<string, string>
 */
function operatorSupplies(): array
{
    return [
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_DATABASE' => 'acct_commons',
        'DB_USERNAME' => 'acct_commons',
        'DB_PASSWORD' => 'an-operator-supplied-password-not-a-real-one',
    ];
}

/**
 * An environment file parsed as dotenv parses it, with the operator's four values filled in.
 *
 * @return array<string, string>
 */
function filledIn(string $file): array
{
    $values = [];
    foreach (Dotenv::parse((string) file_get_contents($file)) as $key => $value) {
        $values[$key] = (string) $value;
    }

    return array_merge($values, operatorSupplies());
}

/** @return list<string> the name and detail of every required check that fails */
function failingChecks(): array
{
    $failures = [];
    foreach (app(ProductionReadiness::class)->checks() as $check) {
        if (! $check->passed) {
            $failures[] = $check->name;
        }
    }

    return $failures;
}

describe('the template', function () {
    it('exists where the deployment runbook tells the operator to find it', function () {
        expect(ProductionEnvironment::templatePath())->toBe(base_path('.env.production.example'))
            ->and(is_file(ProductionEnvironment::templatePath()))->toBeTrue();
    });

    it('states every required production setting explicitly', function () {
        $template = productionTemplate();

        foreach (ProductionEnvironment::REQUIRED_KEYS as $key) {
            expect(array_key_exists($key, $template))->toBeTrue("the template does not state {$key}");
        }
    });

    it('leaves every operator-supplied value blank, so no secret is ever in it', function () {
        $template = productionTemplate();

        foreach (ProductionEnvironment::OPERATOR_SUPPLIED as $key) {
            expect($template[$key] ?? null)->toBe('', "{$key} must be blank in the template: it is a secret or a per-host fact");
        }
    });

    it('never assigns a development-only override, or a setting this application does not read', function () {
        $assignments = productionTemplateAssignments();

        foreach (ProductionEnvironment::FORBIDDEN_KEYS as $key) {
            expect($assignments)->not->toMatch('/^\s*'.$key.'\s*=/m', "the template assigns {$key}");
        }
    });

    it('holds no credential, key or development secret anywhere, comments included', function () {
        $raw = (string) file_get_contents(ProductionEnvironment::templatePath());

        foreach (ProductionEnvironment::DEVELOPMENT_SECRETS as $secret) {
            expect($raw)->not->toContain($secret);
        }
        expect($raw)->not->toMatch('/base64:[A-Za-z0-9+\/]{20,}/')   // an APP_KEY
            ->and($raw)->not->toMatch('/-----BEGIN [A-Z ]*PRIVATE KEY-----/')
            // [ \t] and not \s after the `=`: \s would cross the newline and read the NEXT line as a value.
            ->and($raw)->not->toMatch('/^[ \t]*(DB|MAIL|REDIS|AWS)_[A-Z_]*PASSWORD[ \t]*=[ \t]*[^\s#]/m');
    });

    it('is a production file, not the development one with a new name', function () {
        $template = productionTemplate();

        expect($template['APP_ENV'])->toBe('production')
            ->and($template['APP_DEBUG'])->toBe('false')
            ->and($template['APP_URL'])->toStartWith('https://')
            ->and($template['APP_MAINTENANCE_DRIVER'])->toBe('file')
            ->and((int) $template['BCRYPT_ROUNDS'])->toBeGreaterThanOrEqual(10)
            ->and($template['SESSION_DRIVER'])->toBe('database')
            ->and($template['CORS_ALLOWED_ORIGINS'])->toBe('')
            ->and($template['LOG_LEVEL'])->not->toBe('debug')
            ->and($template['DB_HOST'])->not->toBe('mariadb');   // the compose service name
    });

    it('needs nothing the hosting account does not run', function () {
        $template = productionTemplate();

        expect($template['CACHE_STORE'])->toBe('database')
            ->and($template['QUEUE_CONNECTION'])->toBe('database')
            ->and(productionTemplateAssignments())->not->toContain('REDIS_');
    });

    it('does not configure outbound mail, and cannot be read as approving PHP mail()', function () {
        // Mail is OPEN pending an organizational decision (production readiness, section 5). `log` sends
        // nothing; `sendmail` would be the unapproved transport; an SMTP host here would be an invented one.
        $template = productionTemplate();

        expect($template['MAIL_MAILER'])->toBe('log')
            ->and(productionTemplateAssignments())->not->toMatch('/^\s*MAIL_(HOST|PORT|USERNAME|PASSWORD|SCHEME)\s*=/m')
            ->and((string) file_get_contents(ProductionEnvironment::templatePath()))
            ->toContain('OPEN AND DEFERRED')
            ->toContain('NOT approved');
    });

    it('does not offer proxy trust as a setting', function () {
        // Commons is direct to origin and trusts no proxy. That is a decision in bootstrap/app.php, not
        // a variable, and the template says so rather than leaving a knob that silently does nothing.
        expect(productionTemplateAssignments())->not->toMatch('/PROX(Y|IES)/i');
    });
});

describe('the template against the real check', function () {
    it('passes security:production-check with nothing added but the four operator values', function () {
        /** @var list<string> $failed */
        $failed = underEnvironment(filledIn(ProductionEnvironment::templatePath()), failingChecks(...));

        expect($failed)->toBe([], "the production template, filled in as instructed, fails the production check:\n".implode("\n", $failed));
    });

    it('leaves exactly one item deliberately open, and it is mail', function () {
        $open = underEnvironment(filledIn(ProductionEnvironment::templatePath()), function (): array {
            $names = [];
            foreach (app(ProductionReadiness::class)->deferred() as $item) {
                if (! $item->passed) {
                    $names[] = $item->name;
                }
            }

            return $names;
        });

        expect($open)->toBe(['outbound mail is authenticated and its deliverability is verified']);
    });

    it('fails the check if the operator forgets any one of the four values', function () {
        foreach (array_keys(operatorSupplies()) as $forgotten) {
            $values = filledIn(ProductionEnvironment::templatePath());
            $values[$forgotten] = '';

            expect(underEnvironment($values, failingChecks(...)))->not->toBe([], "leaving {$forgotten} blank was not noticed");
        }
    });

    it('fails the check when the development file is copied instead', function () {
        // The specific accident the template exists to make harder. The development file is parsed and
        // applied exactly as the template is, and the check must refuse it.
        expect(underEnvironment(filledIn(base_path('.env.example')), failingChecks(...)))
            ->toContain('APP_ENV is production')
            ->toContain('APP_DEBUG is off')
            ->toContain('the breached-password check is the real one')
            ->toContain('the login rate limit is not the raised development one');
    });

    it('restores the test environment afterwards, whatever happened inside', function () {
        $before = getenv('IDENTITY_COMPROMISED_PASSWORD_CHECK');

        try {
            underEnvironment(['APP_ENV' => 'production'], static function (): never {
                throw new RuntimeException('inside');
            });
        } catch (RuntimeException) {
        }

        expect(getenv('IDENTITY_COMPROMISED_PASSWORD_CHECK'))->toBe($before);
    });

    it('restores config(), not only the environment, whatever happened inside', function () {
        // Regression: an earlier version of this helper rebuilt config() from disk but never restored
        // it, so a production-shaped config LEAKED into every test that ran afterward in the same
        // process — silent, and dependent on test order, because nothing failed at the call site where
        // it happened. bcrypt cost is a clean witness: the suite runs at 4 (phpunit.xml) and production
        // is 12, so a leak here is unmistakable and not a coincidence of some other default.
        $before = config('hashing.bcrypt.rounds');
        expect($before)->not->toBe(12, 'the test suite is expected to run at a different cost than production');

        underEnvironment(filledIn(ProductionEnvironment::templatePath()), fn () => config('hashing.bcrypt.rounds'));

        expect(config('hashing.bcrypt.rounds'))->toBe($before);

        try {
            underEnvironment(filledIn(ProductionEnvironment::templatePath()), static function (): never {
                throw new RuntimeException('inside');
            });
        } catch (RuntimeException) {
        }

        expect(config('hashing.bcrypt.rounds'))->toBe($before, 'a throw inside the callback must not leave the production config() in place either');
    });
});

describe('where the contract lives', function () {
    it('keeps the real production file out of the repository and out of every release artifact', function () {
        // The template is documentation. The real file lives only at shared/.env on the host; the
        // release validator refuses any `.env*` in an artifact (scripts/release/artifact.php), and the
        // release allowlist never copies a top-level platform dotfile.
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        expect($gitignore)->toContain('.env')
            ->and($gitignore)->toContain('.env.production');
    });

    it('trusts no reverse proxy, in code, where the decision actually lives', function () {
        // Direct to origin (trust boundaries). Every per-address rate limit and audit row depends on the
        // client address being real; a wildcard here would let any caller claim any address.
        $bootstrap = php_strip_whitespace(base_path('bootstrap/app.php'));

        expect($bootstrap)->not->toContain('trustProxies(')
            ->and($bootstrap)->not->toContain('TrustProxies::at')
            ->and($bootstrap)->not->toContain("'*'");
    });
});
