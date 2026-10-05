<?php

declare(strict_types=1);

namespace App\Modules\Security\Application;

/**
 * The PHP settings of the process running the production check, read through one seam so the check can be tested against a host's
 * values rather than the test container's. It reads THIS process's ini, which on the production host is the CLI's (`ea-php83-cli`),
 * not necessarily the web server's (LSAPI): a check built on it is necessary, never sufficient, and the command says so.
 */
final readonly class PhpIni
{
    /** @param  array<string, string>  $overrides  values to report instead of the real ones (tests only) */
    public function __construct(private array $overrides = []) {}

    public function get(string $name): string|false
    {
        return $this->overrides[$name] ?? ini_get($name);
    }

    /** A size setting in bytes (`20M` is 20,971,520); 0 means unlimited, as PHP reads it. Null when it cannot be read. */
    public function bytes(string $name): ?int
    {
        $value = $this->get($name);
        if ($value === false) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/\A\d+[KMG]?\z/i', $value) !== 1) {
            return null;
        }

        return ini_parse_quantity($value);
    }
}
