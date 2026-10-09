<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Application\Capability;
use App\Modules\Relationships\Application\DefinitionSource;
use App\Modules\Relationships\Application\RelationshipCatalog;

/**
 * The production Volunteer document plus a third type, both through {@see RelationshipCatalog::load}.
 * Guardian is omitted so this type may use the Guardian capability pair, which no loaded type already holds.
 */
final class ApprenticeCatalogSource implements DefinitionSource
{
    public function documents(): array
    {
        $volunteer = require dirname(__DIR__, 2).'/app/Modules/Relationships/Definitions/volunteer.php';
        assert(is_array($volunteer));
        /** @var array<string, mixed> $volunteer */

        return [$volunteer, self::apprentice()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function apprentice(): array
    {
        return [
            'type' => 'apprentice',
            'slug' => 'apprentices',
            'version' => 1,
            'labels' => [
                'singular' => 'Apprentice',
                'plural' => 'Apprentices',
                'description' => 'A test-only relationship type.',
            ],
            'states' => [
                'prospective' => [
                    'label' => 'Prospective',
                    'description' => 'Not yet placed.',
                    'tone' => 'caution',
                    'qualifies' => false,
                ],
                'placed' => [
                    'label' => 'Placed',
                    'description' => 'Placed with a mentor.',
                    'tone' => 'positive',
                    'qualifies' => true,
                ],
            ],
            'initial_states' => ['prospective'],
            'transitions' => [
                'prospective' => ['placed'],
                'placed' => [],
            ],
            'fields' => [
                'mentor' => [
                    'value_type' => 'text',
                    'max_length' => 100,
                    'required' => false,
                    'visibility' => 'manage',
                    'label' => 'Mentor',
                    'order' => 1,
                ],
            ],
            'capabilities' => [
                'view' => Capability::ViewGuardians,
                'manage' => Capability::ManageGuardians,
            ],
            'features' => ['directory', 'people.lookup'],
            'verification' => ['delete'],
            'deletion' => true,
            'default_role' => null,
            'presentation' => ['position' => 30],
        ];
    }
}
