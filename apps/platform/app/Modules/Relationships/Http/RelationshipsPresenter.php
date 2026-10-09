<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\NamedPerson;
use App\Modules\Relationships\Application\RelationshipCandidate;
use App\Modules\Relationships\Application\RelationshipCandidateList;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipDirectoryEntry;
use App\Modules\Relationships\Application\RelationshipDirectoryPage;
use App\Modules\Relationships\Application\RelationshipHistoryEntry;
use App\Modules\Relationships\Application\RelationshipManagementView;
use App\Modules\Relationships\Application\RelationshipRecordView;
use App\Modules\Relationships\Application\RelationshipTypeView;
use App\Modules\Relationships\Domain\RelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipField;
use App\Modules\Relationships\Domain\RelationshipState;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the Relationships API (openapi/openapi.yaml). A Person is an id and a display name.
 * No email, phone, Account, role or field value that the capability may not see.
 */
final readonly class RelationshipsPresenter
{
    public function __construct(private RelationshipCatalog $catalog) {}

    /** @param  list<RelationshipTypeView>  $views
     * @return array<string, mixed>
     */
    public function types(array $views): array
    {
        return ['data' => array_map($this->type(...), $views)];
    }

    /** @return array<string, mixed> */
    public function type(RelationshipTypeView $view): array
    {
        $definition = $view->definition;

        return [
            'type' => $definition->type->key,
            'slug' => $definition->slug,
            'version' => $definition->version,
            'labels' => $definition->labels,
            'states' => array_map($this->state(...), array_values($definition->states)),
            'initial_states' => $definition->initialStates,
            'transitions' => $definition->transitions,
            'fields' => array_map($this->field(...), $view->fields),
            'features' => $definition->features,
            'presentation' => ['position' => $definition->position],
            'default_role' => $definition->defaultRole,
            'actions' => [
                'view' => $view->canView,
                'manage' => $view->canManage,
                'delete' => $view->canDelete,
                'provision_default_role' => $view->provisionDefaultRole,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function directory(RelationshipDirectoryPage $page): array
    {
        return [
            'data' => array_map(fn (RelationshipDirectoryEntry $entry): array => [
                'relationship_id' => $entry->id->value,
                'person' => $this->person($entry->person),
                'status' => $entry->status,
                'status_changed_at' => $this->instant($entry->statusChangedAt),
            ], $page->entries),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total],
        ];
    }

    /** @return array<string, mixed> */
    public function record(RelationshipRecordView $view): array
    {
        $definition = $this->catalog->type($view->type);

        return [
            'relationship_id' => $view->id->value,
            'type' => $view->type,
            'definition_version' => $view->definitionVersion,
            'person' => $this->person($view->person),
            'status' => [
                'key' => $view->status,
                'since' => $this->instant($view->statusSince),
                'by' => $this->person($view->statusBy),
            ],
            'revision' => $view->revision,
            'created_at' => $this->instant($view->createdAt),
            'created_by' => $this->person($view->createdBy),
            'fields' => (object) $this->fields($definition === null ? null : $this->catalog->definition($definition), $view->fields),
            'history' => array_map(fn (RelationshipHistoryEntry $entry): array => [
                'from_status' => $entry->fromStatus,
                'to_status' => $entry->toStatus,
                'changed_at' => $this->instant($entry->changedAt),
                'changed_by' => $this->person($entry->changedBy),
            ], $view->history),
        ];
    }

    /** @return array<string, mixed> */
    public function management(RelationshipManagementView $view): array
    {
        $type = $this->catalog->type($view->type);

        return [
            'relationship_id' => $view->id->value,
            'type' => $view->type,
            'person' => $this->person($view->person),
            'status' => $view->status,
            'revision' => $view->revision,
            'transitions' => $view->transitions,
            'fields' => (object) $this->fields($type === null ? null : $this->catalog->definition($type), $view->fields),
        ];
    }

    /** @return array<string, mixed> */
    public function candidates(RelationshipCandidateList $list): array
    {
        return [
            'data' => array_map(fn (RelationshipCandidate $candidate): array => [
                'person' => $this->person($candidate->person),
                'matched_on' => $candidate->matchedOn,
                'status' => $candidate->status,
            ], $list->candidates),
            'truncated' => $list->truncated,
        ];
    }

    /** @return array{id: string, display_name: string|null} */
    private function person(NamedPerson $person): array
    {
        return ['id' => $person->id->value, 'display_name' => $person->displayName];
    }

    /** @return array<string, mixed> */
    private function state(RelationshipState $state): array
    {
        return [
            'key' => $state->key,
            'label' => $state->label,
            'description' => $state->description,
            'tone' => $state->tone,
            'qualifies' => $state->qualifies,
        ];
    }

    /** @return array<string, mixed> */
    private function field(RelationshipField $field): array
    {
        return [
            'key' => $field->key,
            'value_type' => $field->valueType,
            'max_length' => $field->maxLength,
            'not_after' => $field->notAfter,
            'required' => $field->required,
            'visibility' => $field->visibility,
            'label' => $field->label,
            'help' => $field->help,
            'order' => $field->order,
            'group' => $field->group,
            'options' => $field->options,
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function fields(?RelationshipDefinition $definition, array $fields): array
    {
        $presented = [];
        foreach ($fields as $key => $value) {
            $field = $definition?->field($key);
            $presented[$key] = $field?->valueType === 'boolean' ? $value === 'true' : $value;
        }

        return $presented;
    }

    private function instant(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
