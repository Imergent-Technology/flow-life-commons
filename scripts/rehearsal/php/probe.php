<?php

declare(strict_types=1);

// G6 WP3c fixed isolation probe, run inside the disposable runner by `restore-rehearsal.sh probe`. Takes no arguments and prints one
// `probe:<name>=ok` or `probe:<name>=FAIL` line per check; the wrapper requires every line to be ok. Writes nothing that persists.

function report(string $name, bool $ok): void
{
    echo "probe:$name=".($ok ? 'ok' : 'FAIL')."\n";
}

function try_write(string $path): bool
{
    return @file_put_contents($path, 'x') !== false;
}

report('php-version', PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3);
// The process's own uid (getmyuid() is the script file's owner, not the process).
report('user', preg_match('/^Uid:\s+33\s+33\s/m', (string) file_get_contents('/proc/self/status')) === 1);

// No route out: an internal network has no gateway, and its resolver does not forward external names.
report('network-external', @fsockopen('1.1.1.1', 443, $errno, $errstr, 3) === false);
report('dns-external', gethostbyname('example.com') === 'example.com');

report('root-fs', ! try_write('/etc/flc-probe'));
report('source-readonly', ! try_write('/var/www/html/harness/.flc-probe'));
report('tmp-writable', try_write('/tmp/flc-probe'));

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
        (string) getenv('DB_USERNAME'),
        (string) getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    report('db-app', $pdo->query('SELECT CURRENT_USER()')->fetchColumn() === getenv('DB_USERNAME').'@%');
    $databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    sort($databases);
    report('databases', $databases === ['flc_rehearsal_app', 'information_schema']);
    try {
        $pdo->query('SELECT token FROM flc_rehearsal_identity.run_token');
        report('sentinel-denied', false);
    } catch (PDOException) {
        report('sentinel-denied', true);
    }
} catch (Throwable) {
    report('db-app', false);
    report('databases', false);
    report('sentinel-denied', false);
}
