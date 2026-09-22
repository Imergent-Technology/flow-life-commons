<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Console;

use App\Modules\Security\Application\BrowserSecurityPolicy;
use Illuminate\Console\Command;

/**
 * `security:headers`: prints the browser security policy in the form a web server needs, so the
 * deployment adapter is generated from `config/security.php` rather than transcribed from it.
 *
 * Two formats, for the two servers this project actually uses:
 *
 *   --format=apache   the `<IfModule mod_headers.c>` block for production's `public/.htaccess`
 *   --format=caddy    the `header` block for the development gateway's production-equivalent site
 *
 * A test compares both committed files against this output, so a change to the policy that is not
 * carried into the deployment adapter fails the build instead of shipping a weaker production origin.
 *
 * HSTS is emitted conditionally on HTTPS in the Apache form: the header is meaningless over plain
 * HTTP, and a server that sends it there is describing a deployment it does not have.
 */
final class ShowSecurityHeadersCommand extends Command
{
    protected $signature = 'security:headers {--format=apache : apache or caddy}';

    protected $description = 'Print the production browser security headers in web-server form (config/security.php).';

    public function handle(BrowserSecurityPolicy $policy): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['apache', 'caddy'], true)) {
            $this->error('Unknown format. Use --format=apache or --format=caddy.');

            return self::FAILURE;
        }

        $this->line(self::block($policy, $format));

        return self::SUCCESS;
    }

    /** The exact text the deployment adapter must contain. Shared with the test that pins it. */
    public static function block(BrowserSecurityPolicy $policy, string $format): string
    {
        // HSTS is handled separately in both formats, so the common set is the non-HSTS one.
        $headers = $policy->headers(secure: false);
        $hsts = array_diff_key($policy->headers(secure: true), $headers);

        if ($format === 'caddy') {
            $lines = ['header {'];
            foreach ($headers as $name => $value) {
                $lines[] = sprintf("\t%s %s", $name, self::quote($value));
            }
            // The development gateway is plain HTTP, so HSTS is deliberately absent there; the
            // production form below is what carries it.
            $lines[] = '}';

            return implode("\n", $lines);
        }

        // `onsuccess unset` before `always set`: Laravel's own middleware (ApplyBrowserSecurityHeaders)
        // sets these same seven headers on every response it answers, so without the unset Apache's
        // `Header always set` APPENDS a second copy rather than replacing PHP's — measured on a real
        // Apache 2.4 container serving this exact .htaccess against a PHP response, where every response
        // class routed to the front controller (including a 404) carried each header twice. `unset`
        // first empties whatever PHP sent (regardless of status; verified on 200, 404, 403 and 503
        // responses through the front controller), then `always set` states the one value that governs,
        // so the origin — not the application code answering a given request — is the single source of
        // this policy for every response, including ones Apache serves without PHP at all.
        $lines = ['<IfModule mod_headers.c>'];

        // X-Powered-By is not one of the seven ADR 0026 headers above: this application states no value
        // for it, it removes one PHP adds on its own. `expose_php` is php.ini-only (no .user.ini, no
        // ini_set() override) and the production host's control panel exposes no php.ini editor for
        // this account, so the only place left to enforce "no X-Powered-By reaches a client" is the web
        // server in front of PHP — measured live on the production host (2026-09-22, real Apache +
        // CloudLinux LSAPI): every PHP response carried `X-Powered-By: PHP/8.3.33` (production
        // readiness, item 2). BOTH `onsuccess unset` and `always unset` are needed, not either alone:
        // `onsuccess` reaches Apache's table for ordinary (2xx/3xx) responses, `always` reaches the
        // table used regardless of status — the same two-table split that made `Header always set`
        // alone duplicate a header above — and LSAPI has been observed populating either, so a response
        // class covered by only one unset directive is a response class this header can still reach.
        $lines[] = '    Header onsuccess unset X-Powered-By';
        $lines[] = '    Header always unset X-Powered-By';

        foreach ($headers as $name => $value) {
            $lines[] = sprintf('    Header onsuccess unset %s', $name);
            $lines[] = sprintf('    Header always set %s %s', $name, self::quote($value));
        }
        foreach ($hsts as $name => $value) {
            $lines[] = sprintf('    Header onsuccess unset %s', $name);
            $lines[] = sprintf('    Header always set %s %s "expr=%%{HTTPS} == \'on\'"', $name, self::quote($value));
        }
        $lines[] = '</IfModule>';

        return implode("\n", $lines);
    }

    private static function quote(string $value): string
    {
        return '"'.$value.'"';
    }
}
