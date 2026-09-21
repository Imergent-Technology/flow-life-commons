<?php

declare(strict_types=1);

namespace App\Modules\Security\Application;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * The browser security policy as a set of headers (ADR 0026), from `config/security.php`.
 *
 * It is a pure function of configuration and two facts about the request, so the same object answers
 * for the Laravel middleware and for the command that emits the web server's block. That is the point:
 * production serves the Console's files from Apache and the API from Laravel, and a policy stated twice
 * is a policy that disagrees with itself eventually.
 */
final readonly class BrowserSecurityPolicy
{
    public function __construct(private Config $config) {}

    /**
     * @param  bool  $secure  whether the request arrived over HTTPS; HSTS is sent only then
     * @param  bool  $api  API responses also get `Cache-Control: no-store`
     * @return array<string, string> header name => value
     */
    public function headers(bool $secure, bool $api = false): array
    {
        /** @var list<string> $csp */
        $csp = $this->config->array('security.csp');
        /** @var array<string, string> $headers */
        $headers = $this->config->array('security.headers');

        $all = ['Content-Security-Policy' => implode('; ', $csp), ...$headers];

        if ($secure) {
            $all['Strict-Transport-Security'] = $this->config->string('security.hsts');
        }

        if ($api) {
            // An API response carries the signed-in person's name, address, capabilities and factor
            // state. None of it should survive in a disk cache or come back from the back button on a
            // shared machine. The Console asks for `no-store` on its side too; this makes it the
            // server's rule rather than the client's manners.
            $all['Cache-Control'] = 'no-store';
        }

        return $all;
    }
}
