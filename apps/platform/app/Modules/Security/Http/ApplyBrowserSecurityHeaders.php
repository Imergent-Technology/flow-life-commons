<?php

declare(strict_types=1);

namespace App\Modules\Security\Http;

use App\Modules\Security\Application\BrowserSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the browser security policy (ADR 0026) on every response Laravel produces: the API, `/up`, and
 * the framework's own error pages.
 *
 * It does NOT cover the Console's HTML and assets in production, because Laravel never sees them —
 * Apache serves those files directly (ADR 0016's single origin is one document root, not one PHP
 * process). That half is the web server's `.htaccess`, generated from the same configuration by
 * `php artisan security:headers`, and a test fails if the two drift.
 *
 * Headers are SET, not added: if a web server in front of this has already sent its own, one value
 * wins rather than two being merged into something neither intended.
 */
final readonly class ApplyBrowserSecurityHeaders
{
    public function __construct(private BrowserSecurityPolicy $policy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->policy->headers($request->secure(), $request->is('api', 'api/*')) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
