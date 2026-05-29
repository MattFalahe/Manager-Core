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
                'name'       => 'Pricing Preferences',
                'icon'       => 'fas fa-tags',
                'route'      => 'manager-core.pricing-preferences.index',
                'permission' => 'global.superuser',
            ],
            [
                'name'       => 'Markets',
                'icon'       => 'fas fa-industry',
                'route'      => 'manager-core.markets.index',
                'permission' => 'global.superuser',
            ],
            [
                'name'       => 'Plugin Bridge',
                'icon'       => 'fas fa-plug',
                'route'      => 'manager-core.bridge.index',
                'permission' => 'manager-core.bridge.view',
            ],
            [
                'name'       => 'API Tokens',
                'icon'       => 'fas fa-key',
                'route'      => 'manager-core.api-tokens.index',
                'permission' => 'manager-core.api.manage',
            ],
            [
                'name'       => 'ESI Key Pool',
                'icon'       => 'fas fa-satellite-dish',
                'route'      => 'manager-core.esi-key-pool.index',
                'permission' => 'global.superuser',
            ],
            [
                'name'       => 'Diagnostics',
                'icon'       => 'fas fa-stethoscope',
                'route'      => 'manager-core.diagnostic.index',
                'permission' => 'global.superuser',
            ],
            [
                'name'       => 'Settings',
                'icon'       => 'fas fa-cogs',
                'route'      => 'manager-core.settings',
                'permission' => 'global.superuser',
            ],
            [
                'name'       => 'Help & Documentation',
                'icon'       => 'fas fa-question-circle',
                'route'      => 'manager-core.help',
                'permission' => 'manager-core.view',
            ],
        ],
    ],
];
