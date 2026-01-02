<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Market Pricing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for market data fetching and pricing calculations
    |
    */

    'pricing' => [

        // Update frequency in minutes (default: 240 = 4 hours)
        'update_frequency' => env('MANAGER_CORE_PRICE_UPDATE_FREQUENCY', 240),

        // Default market/region for pricing
        'default_market' => env('MANAGER_CORE_DEFAULT_MARKET', 'jita'),

        // Available markets with their region IDs
        'markets' => [
            'jita' => [
                'region_id' => 10000002,
                'system_ids' => [30000142], // Jita
                'name' => 'Jita',
            ],
            'amarr' => [
                'region_id' => 10000043,
                'system_ids' => [30002187], // Amarr
                'name' => 'Amarr',
            ],
            'dodixie' => [
                'region_id' => 10000032,
                'system_ids' => [30002659], // Dodixie
                'name' => 'Dodixie',
            ],
            'hek' => [
                'region_id' => 10000042,
                'system_ids' => [30002053], // Hek
                'name' => 'Hek',
            ],
            'rens' => [
                'region_id' => 10000030,
                'system_ids' => [30002510], // Rens
                'name' => 'Rens',
            ],
        ],

        // Price percentiles to calculate (for buy/sell orders)
        'percentiles' => [
            'buy' => 0.99,  // 99th percentile for buy orders
            'sell' => 0.01, // 1st percentile for sell orders (lowest)
        ],

        // Minimum order volume to consider for pricing (avoid outliers)
        'min_order_volume' => 2,

        // Price history retention in days
        'history_retention_days' => 90,

    ],

    /*
    |--------------------------------------------------------------------------
    | Appraisal Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for item appraisal functionality
    |
    */

    'appraisal' => [

        // Default price percentage (100 = market price, 90 = 90% of market)
        'default_percentage' => 100,

        // Appraisal retention in days (0 = keep forever)
        'retention_days' => 30,

        // Maximum items per appraisal
        'max_items' => 1000,

    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin Bridge Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for inter-plugin communication
    |
    */

    'bridge' => [

        // Enable plugin discovery
        'auto_discover' => true,

        // Compatible plugin namespaces to discover
        'compatible_plugins' => [
            'MiningManager',
            'BuybackManager',
            'StructureManager',
            'DiscordPings',
        ],

        // Cache discovered plugins (in minutes)
        'cache_duration' => 60,

    ],

    /*
    |--------------------------------------------------------------------------
    | ESI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for EVE Swagger Interface
    |
    */

    'esi' => [

        // ESI base URL
        'base_url' => 'https://esi.evetech.net/latest',

        // Request timeout in seconds
        'timeout' => 30,

        // Retry failed requests
        'retry' => true,
        'max_retries' => 3,

        // Rate limiting (requests per second)
        'rate_limit' => 20,

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache settings for various components
    |
    */

    'cache' => [

        // Type database cache duration (in minutes)
        'type_db_duration' => 1440, // 24 hours

        // Market prices cache duration (in minutes)
        'prices_duration' => 60, // 1 hour

        // Appraisal results cache duration (in minutes)
        'appraisal_duration' => 10,

    ],

];
