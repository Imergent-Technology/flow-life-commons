<?php

declare(strict_types=1);

namespace App\Modules\Health\Application;

final readonly class HealthReport
{
    /**
     * @param  array<string, bool>  $checks  check name => passed
     */
    public function __construct(
        private array $checks,
    ) {}

    public function healthy(): bool
    {
        return ! in_array(false, $this->checks, true);
    }

    /**
     * @return array{status: string, service: string, api_version: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->healthy() ? 'ok' : 'degraded',
            'service' => 'flowlife-platform',
            'api_version' => 'v1',
            'checks' => array_map(
                static fn (bool $passed): string => $passed ? 'ok' : 'fail',
                $this->checks,
            ),
        ];
    }
}
