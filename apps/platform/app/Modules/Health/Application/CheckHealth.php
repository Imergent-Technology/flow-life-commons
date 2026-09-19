<?php

declare(strict_types=1);

namespace App\Modules\Health\Application;

use Illuminate\Database\ConnectionInterface;
use Throwable;

final readonly class CheckHealth
{
    public function __construct(
        private ConnectionInterface $database,
    ) {}

    public function __invoke(): HealthReport
    {
        return new HealthReport([
            'database' => $this->databaseReachable(),
        ]);
    }

    private function databaseReachable(): bool
    {
        try {
            $this->database->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
