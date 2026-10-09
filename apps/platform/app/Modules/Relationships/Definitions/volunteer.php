<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;

/*
 * The Volunteer relationship (ADR 0038, V1–V2). Data only.
 * Every transition between two distinct states is allowed: a restricted graph would be approval.
 * Only deletion requires recent verification. There is no default role.
 */

return [
    'type' => 'volunteer',
    'slug' => 'volunteers',
    'version' => 1,
    'labels' => [
        'singular' => 'Volunteer',
        'plural' => 'Volunteers',
        'description' => 'A Person who volunteers with Flow Life. Volunteering grants no role and no Console access.',
    ],
    'states' => [
        'pending' => [
            'label' => 'Pending',
            'description' => 'Recorded as a Volunteer, not yet active.',
            'tone' => 'caution',
            'qualifies' => false,
        ],
        'active' => [
            'label' => 'Active',
            'description' => 'Currently volunteering.',
            'tone' => 'positive',
            'qualifies' => true,
        ],
        'inactive' => [
            'label' => 'Inactive',
            'description' => 'No longer active. The record, its fields and its history are kept.',
            'tone' => 'neutral',
            'qualifies' => false,
        ],
    ],
    'initial_states' => ['pending', 'active'],
    'transitions' => [
        'pending' => ['active', 'inactive'],
        'active' => ['inactive', 'pending'],
        'inactive' => ['active', 'pending'],
    ],
    'fields' => [
        'interests' => [
            'value_type' => 'long_text',
            'max_length' => 500,
            'required' => false,
            'visibility' => 'view',
            'label' => 'Interests and skills',
            'order' => 1,
        ],
        'availability' => [
            'value_type' => 'long_text',
            'max_length' => 500,
            'required' => false,
            'visibility' => 'view',
            'label' => 'Availability',
            'order' => 2,
        ],
    ],
    'capabilities' => [
        'view' => Capability::ViewVolunteers,
        'manage' => Capability::ManageVolunteers,
    ],
    'features' => ['directory', 'people.lookup', 'people.create_person', 'people.read_basics', 'people.edit_basics'],
    'verification' => ['delete'],
    'deletion' => true,
    'default_role' => null,
    'presentation' => ['position' => 20],
];
