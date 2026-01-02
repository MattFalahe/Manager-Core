<?php

return [
    'manager-core' => [
        'label'       => 'Manager Core',
        'description' => 'Grants access to Manager Core plugin features',
        'division'    => 'financial',
        'permissions' => [
            'view' => [
                'label'       => 'View',
                'description' => 'View Manager Core dashboard',
            ],
            'appraisal' => [
                'label'       => 'Appraisal',
                'description' => 'Create and view appraisals',
            ],
            'pricing.view' => [
                'label'       => 'View Pricing',
                'description' => 'View market pricing data',
            ],
            'pricing.manage' => [
                'label'       => 'Manage Pricing',
                'description' => 'Manage pricing settings and subscriptions',
            ],
            'bridge.view' => [
                'label'       => 'View Bridge',
                'description' => 'View plugin bridge status and registered plugins',
            ],
            'bridge.manage' => [
                'label'       => 'Manage Bridge',
                'description' => 'Manage plugin bridge settings and integrations',
            ],
        ],
    ],
];
