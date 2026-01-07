<?php

return [
    'manager-core' => [
        'name'          => 'Manager Core',
        'icon'          => 'fas fa-calculator',
        'route_segment' => 'manager-core',
        'permission'    => 'manager-core.view',
        'entries'       => [
            [
                'name'       => 'Appraisal',
                'icon'       => 'fas fa-coins',
                'route'      => 'manager-core.appraisal.index',
                'permission' => 'manager-core.appraisal',
            ],
            [
                'name'       => 'Market Prices',
                'icon'       => 'fas fa-chart-line',
                'route'      => 'manager-core.pricing.index',
                'permission' => 'manager-core.pricing.view',
            ],
            [
                'name'       => 'Type Subscriptions',
                'icon'       => 'fas fa-rss',
                'route'      => 'manager-core.subscriptions.index',
                'permission' => 'manager-core.pricing.manage',
            ],
            [
                'name'       => 'Plugin Bridge',
                'icon'       => 'fas fa-plug',
                'route'      => 'manager-core.bridge.index',
                'permission' => 'manager-core.bridge.view',
            ],
            [
                'name'       => 'Help & Documentation',
                'icon'       => 'fas fa-question-circle',
                'route'      => 'manager-core.help',
                'permission' => 'manager-core.view',
            ],
            [
                'name'       => 'Settings',
                'icon'       => 'fas fa-cogs',
                'route'      => 'manager-core.settings',
                'permission' => 'global.superuser',
            ],
        ],
    ],
];
