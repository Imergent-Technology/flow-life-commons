<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\RelationshipRepository;

/**
 * The read-only integrity check (ADR 0038, F15), for types, states and field values.
 * Grant findings arrive with WP2B. It writes nothing.
 */
final readonly class CheckRelationships
{
    public function __construct(
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
    ) {}

    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        $now = RelationshipTime::now();
        $findings = [];
        $unknownTypes = [];
        foreach ($this->relationships->storedRelationships() as $row) {
            $type = $this->catalog->type($row['type']);
            if ($type === null) {
                $unknownTypes[$row['type']] = true;

                continue;
            }
            if (! array_key_exists($row['status'], $this->catalog->definition($type)->states)) {
                $findings[] = "unknown status {$row['status']} on {$row['type']}";
            }
        }
        foreach (array_keys($unknownTypes) as $type) {
            $findings[] = "unknown relationship type {$type}";
        }
        foreach ($this->relationships->storedFieldValues() as $row) {
            $type = $this->catalog->type($row['type']);
            if ($type === null) {
                continue;
            }
            $field = $this->catalog->definition($type)->field($row['field']);
            if ($field === null) {
                $findings[] = "unknown field {$row['field']} on {$row['type']}";

                continue;
            }
            if (! FieldValues::acceptsStored($field, $row['value'], $now)) {
                $findings[] = "invalid value for {$row['field']} on {$row['type']}";
            }
        }
        sort($findings);

        return $findings;
    }
}
