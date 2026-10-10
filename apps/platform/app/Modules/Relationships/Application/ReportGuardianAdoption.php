<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\PersonAccountPresence;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Shared\Domain\PersonId;

/**
 * The read-only Guardian adoption report (ADR 0038, M4). It writes nothing: no relationship,
 * no invitation, no role and no grant. The caller supplies who holds console access; this
 * report never infers a Guardian from a role. Many Console participants are not Guardians.
 */
final readonly class ReportGuardianAdoption
{
    public function __construct(
        private RelationshipRepository $relationships,
        private RelationshipCatalog $catalog,
        private FindPeople $people,
        private PersonAccountPresence $accounts,
        private CheckRelationships $check,
    ) {}

    /**
     * @param  list<PersonId>  $consoleAccess  People who hold console.access, from Access
     * @return array{text: string, consistent: bool}
     */
    public function __invoke(array $consoleAccess): array
    {
        $guardian = $this->catalog->type('guardian');
        $guardianIds = $guardian === null ? [] : $this->relationships->personIds($guardian->key, null);
        $rows = $guardian === null ? [] : $this->relationships->statusesFor($guardian->key, $guardianIds);

        $ids = $consoleAccess;
        foreach ($guardianIds as $id) {
            $ids[] = PersonId::fromString($id);
        }
        $found = ($this->people)($ids);
        $name = static function (string $id) use ($found): string {
            $summary = $found[$id] ?? null;

            return $summary === null ? '' : $summary->displayName;
        };

        $guardianSet = array_fill_keys($guardianIds, true);
        $holdingSet = [];
        foreach ($consoleAccess as $person) {
            $holdingSet[$person->value] = true;
        }

        $lines = [
            'Guardian adoption report',
            'This report writes nothing. It does not adopt anyone, invite anyone, assign a role, or create a relationship.',
            '',
            'Many Console participants are not Guardians.',
            'Holding console access is not Guardian recognition. Review each person before recording a Guardian relationship through ordinary Guardian intake.',
            '',
            'Console access without a Guardian relationship',
        ];
        $listed = 0;
        foreach ($consoleAccess as $person) {
            if (isset($guardianSet[$person->value])) {
                continue;
            }
            $listed++;
            $lines[] = '- '.$person->value.' '.$name($person->value);
        }
        if ($listed === 0) {
            $lines[] = '- none';
        }

        $lines[] = '';
        $lines[] = 'Guardian relationships whose Person has no Account';
        $noAccount = 0;
        $lacking = [];
        foreach ($guardianIds as $id) {
            $row = $rows[$id] ?? null;
            $label = '- '.($row['id'] ?? $id).' person '.$id.' '.$name($id).' status '.($row['status'] ?? '');
            if (! ($this->accounts)(PersonId::fromString($id))) {
                $noAccount++;
                $lines[] = $label;
            }
            if (! isset($holdingSet[$id])) {
                $lacking[] = $label;
            }
        }
        if ($noAccount === 0) {
            $lines[] = '- none';
        }

        $lines[] = '';
        $lines[] = 'Guardian relationships whose Person lacks console access';
        if ($lacking === []) {
            $lines[] = '- none';
        } else {
            array_push($lines, ...$lacking);
        }

        $lines[] = '';
        $lines[] = 'relationships:check';
        $findings = ($this->check)();
        if ($findings === []) {
            $lines[] = 'Relationships are consistent with their definitions.';
        } else {
            array_push($lines, ...$findings);
            $lines[] = count($findings).' finding(s).';
        }

        return ['text' => implode("\n", $lines)."\n", 'consistent' => $findings === []];
    }
}
