<?php

declare(strict_types=1);

namespace App\Modules\Health\Http;

use App\Modules\Health\Application\CheckHealth;
use Illuminate\Http\JsonResponse;

/**
 * Infrastructure verification endpoint, not a product feature.
 * Public and unauthenticated: it must never expose more than coarse status.
 */
final class HealthController
{
    public function __invoke(CheckHealth $checkHealth): JsonResponse
    {
        $report = $checkHealth();

        return response()->json($report->toArray(), $report->healthy() ? 200 : 503);
    }
}
