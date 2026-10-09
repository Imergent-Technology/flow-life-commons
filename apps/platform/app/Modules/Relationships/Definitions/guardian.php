<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;

/*
 * The Guardian relationship (ADR 0038, G1–G7). Data only.
 * `default_role` stays null until WP2B. Recognition still requires recent verification (A5):
 * it confers Guardian eligibility. Field edits do not.
 */

return [
    'type' => 'guardian',
    'slug' => 'guardians',
    'version' => 1,
    'labels' => [
        'singular' => 'Guardian',
        'plural' => 'Guardians',
        'description' => 'A Person recognized as a Guardian. Recognition is not access to the software.',
    ],
    'states' => [
        'active' => [
            'label' => 'Active',
            'description' => 'Currently recognized as a Guardian.',
            'tone' => 'positive',
            'qualifies' => true,
        ],
        'inactive' => [
            'label' => 'Inactive',
            'description' => 'Formerly recognized. The record, its fields and its history are kept.',
            'tone' => 'neutral',
            'qualifies' => false,
        ],
    ],
    'initial_states' => ['active', 'inactive'],
    'transitions' => [
        'active' => ['inactive'],
        'inactive' => ['active'],
    ],
    'fields' => [
        'recognized_on' => [
            'value_type' => 'date',
            'not_after' => 'today',
            'required' => false,
            'visibility' => 'view',
            'label' => 'Guardian since',
            'help' => 'When this person became a Guardian, if known. The record\'s own date is when it was entered.',
            'order' => 1,
        ],
        'stewardship' => [
            'value_type' => 'text',
            'max_length' => 200,
            'required' => false,
            'visibility' => 'view',
            'label' => 'Area of stewardship',
            'order' => 2,
        ],
    ],
    'capabilities' => [
        'view' => Capability::ViewGuardians,
        'manage' => Capability::ManageGuardians,
    ],
    'features' => ['directory', 'people.lookup', 'people.create_person', 'people.read_basics'],
    'verification' => ['intake', 'status', 'delete'],
    'deletion' => true,
    'default_role' => null,
    'presentation' => ['position' => 10],
];
