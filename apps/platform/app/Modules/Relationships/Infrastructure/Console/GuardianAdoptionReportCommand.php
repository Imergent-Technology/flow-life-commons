<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Infrastructure\Console;

use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\PeopleHoldingCapability;
use App\Modules\Relationships\Application\ReportGuardianAdoption;
use Illuminate\Console\Command;

/**
 * `relationships:guardian-adoption-report` (ADR 0038, M4). Read-only. Many Console
 * participants are not Guardians. Nothing here adopts, invites, assigns a role or
 * creates a relationship.
 */
final class GuardianAdoptionReportCommand extends Command
{
    protected $signature = 'relationships:guardian-adoption-report';

    protected $description = 'List Console participants and Guardian relationships for an administrator to review (read-only).';

    public function handle(PeopleHoldingCapability $holders, ReportGuardianAdoption $report): int
    {
        $result = $report(($holders)(Capability::ConsoleAccess));
        $this->output->write($result['text']);

        return $result['consistent'] ? self::SUCCESS : self::FAILURE;
    }
}
