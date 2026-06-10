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

        // (Removed) 'provider' — the "global default price provider"
        // concept was eliminated. Resolution chain is now per-appraisal
        // override → per-market provider column on manager_core_markets →
        // hard-coded 'fuzzwork' fail-safe inside PricingService.
        // Credentials below stay (each provider's API key / contact info
        // is still operator-configurable, just no longer a "default
        // provider" selector).

        // SeAT Price Provider to use (when provider is 'seat')
        // Leave empty to use SeAT's default, or specify a provider name (e.g., 'fuzzwork', etc.)
        'seat_provider' => env('MANAGER_CORE_SEAT_PRICE_PROVIDER', ''),

        // Janice config (https://janice.e-351.com)
        'janice' => [
            // API key — obtain from janice.e-351.com after signing in.
            'api_key' => env('MANAGER_CORE_JANICE_API_KEY', ''),
        ],

        // Goonpraisal config (https://appraise.gnf.lt)
        'goonpraisal' => [
            // Contact email embedded in the User-Agent header per their docs.
            // Defaults to the plugin maintainer's email so installs work
            // without operator config — operators are encouraged to set
            // their own contact email via the Settings page.
            'contact_email' => env('MANAGER_CORE_GOONPRAISAL_CONTACT_EMAIL', 'mattfalahe@gmail.com'),
        ],

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

        // Compatible plugins with their service provider classes
        'compatible_plugins' => [
            'mining-manager' => [
                'name' => 'Mining Manager',
                'class' => 'MiningManager\MiningManagerServiceProvider',
                'package' => 'mattfalahe/mining-manager',
                'icon' => 'fa-hammer',
                'subscription_name' => 'mining-manager',
            ],
            'corp-wallet-manager' => [
                'name' => 'Corp Wallet Manager',
                'class' => 'CorpWalletManager\CorpWalletManagerServiceProvider',
                'package' => 'mattfalahe/corp-wallet-manager',
                'icon' => 'fa-wallet',
                'subscription_name' => null,
            ],
            'structure-manager' => [
                'name' => 'Structure Manager',
                'class' => 'StructureManager\StructureManagerServiceProvider',
                'package' => 'mattfalahe/structure-manager',
                'icon' => 'fa-building',
                'subscription_name' => null,
            ],
            'seat-discord-pings' => [
                'name' => 'SeAT Broadcast',
                'class' => 'DiscordPings\DiscordPingsServiceProvider',
                'package' => 'mattfalahe/seat-discord-pings',
                'icon' => 'fab fa-discord',
                'subscription_name' => null,
            ],
            'blueprint-manager' => [
                'name' => 'Blueprint Manager',
                'class' => 'BlueprintManager\BlueprintManagerServiceProvider',
                'package' => 'mattfalahe/blueprint-manager',
                'icon' => 'fa-drafting-compass',
                'subscription_name' => null,
            ],
            'hr-manager' => [
                'name' => 'HR Manager',
                'class' => 'HrManager\HrManagerServiceProvider',
                'package' => 'mattfalahe/hr-manager',
                'icon' => 'fa-users',
                'subscription_name' => null,
            ],
            'buyback-manager' => [
                'name' => 'Buyback Manager',
                'class' => 'BuybackManager\BuybackManagerServiceProvider',
                'package' => 'mattfalahe/buyback-manager',
                'icon' => 'fa-shopping-cart',
                'subscription_name' => 'buyback-manager',
            ],
        ],

        // Cache discovered plugins (in minutes). Lower = plugin install/uninstall
        // reflects faster in the UI but more discovery scans. Auto-invalidated
        // when the set of installed plugins changes.
        'cache_duration' => 5,

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

    /*
    |--------------------------------------------------------------------------
    | Formatting Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for EVE value formatting helpers
    |
    */

    'formatting' => [

        // Default decimal places for ISK formatting
        'isk_decimals' => 2,

        // Default icon size in pixels
        'default_icon_size' => 32,

        // EVE image server base URL
        'icon_base_url' => 'https://images.evetech.net',

    ],

    /*
    |--------------------------------------------------------------------------
    | Event Bus Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the cross-plugin event pub/sub system
    |
    */

    'events' => [

        // How many days to retain event log entries
        'event_log_retention_days' => 30,

        // Default queue for async event handlers
        'default_queue' => 'default',

        // Maximum number of listeners per event dispatch (matched listeners only)
        'max_listeners_per_event' => 50,

        // Sync handlers slower than this trigger a warning log (recommendation
        // to switch to is_queued=true). Milliseconds.
        'sync_slow_threshold_ms' => 1000,

        // Maximum payload size in bytes — payloads larger than this are rejected
        // by publish() to prevent EventLog row bloat.
        'max_payload_bytes' => 524288, // 512 KB

        // Per-row max for the persisted EventLog payload column. Defense-in-depth
        // against MySQL max_allowed_packet — payloads larger than this get a
        // truncation marker in the row instead of the full body.
        'row_max_payload_bytes' => 65536, // 64 KB

        // M6: idempotency window — if a (publisher, idempotency_key) pair is
        // republished within this many seconds, the duplicate is suppressed.
        'idempotency_window_seconds' => 3600,

        // M12: circuit breaker — protect against subscribers that throw on every event
        'circuit_breaker' => [
            'failure_threshold' => 5,    // open circuit after N failures
            'window_seconds' => 300,     // failure-counting window (5 min)
            'cooldown_seconds' => 300,   // skip dispatch for this long once open
        ],

        // C1 mitigation: per-plugin defaults for ESI notification handlers.
        // Lets the operator force `queued => true` for plugins whose handlers
        // do slow work (Discord webhook POSTs etc.) without requiring a
        // change in the consumer plugin. Uses precedence:
        //   1. explicit ['queued' => bool] in register() call
        //   2. handler_defaults.<plugin>.queued
        //   3. handler_defaults.default.queued
        //   4. false (sync)
        //
        // Recommended: set 'structure-manager' to queued=true to keep
        // SM's Discord-bound handler from blocking MC's poll job.
        'handler_defaults' => [
            'default' => [
                'queued' => false,
                'queue' => null,
            ],
            'structure-manager' => [
                'queued' => true,
                'queue' => 'default',
            ],
        ],

        // Allowed event-name prefix per publisher (for events.publish API).
        // Format: ['plugin-name' => ['prefix1.', 'prefix2.']].
        // External tokens with publisher='api' get the 'api.' prefix only.
        // Set 'enforce_publisher_prefix' to false to disable allow-listing.
        'enforce_publisher_prefix' => true,
        'publisher_prefixes' => [
            'mining-manager' => ['mining.'],
            'structure-manager' => ['structure.', 'structure_manager.'],
            // CWM publishes BOTH wallet.* (corp-wallet-level signals) and
            // member.* (per-member contribution/tax signals — see the
            // Topics registry, where every member.contribution.* /
            // member.tax.* topic has publisher = corp-wallet-manager).
            // Without 'member.' here, enforce_publisher_prefix rejected
            // those at publish time and HR's member.* subscribers never
            // fired. Fixed 2026-06-08.
            'corp-wallet-manager' => ['wallet.', 'member.'],
            'buyback-manager' => ['buyback.'],
            'blueprint-manager' => ['blueprint.'],
            'hr-manager' => ['hr.'],
            'seat-discord-pings' => ['pings.'],
            'manager-core' => ['manager-core.', 'mc.', 'pricing.'],
            'api' => ['api.', 'custom.'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | REST API Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the token-authenticated REST API
    |
    */

    'api' => [

        // Default rate limit (requests per minute per token)
        'default_rate_limit' => 60,

        // M14: global rate limit across ALL tokens (per minute). Set to 0 to disable.
        // Default 600 = 10/sec — well below typical Laravel + nginx capacity.
        'global_rate_limit' => 600,

        // Token prefix for generated API keys
        'token_prefix' => 'mc_',

        // Maximum API tokens per user
        'max_tokens_per_user' => 5,

        // M16: per-user appraisal create quota (per hour)
        'appraisal_create_quota_per_hour' => 200,

        // L22: how many days to keep api_token_usage rows before pruning
        'token_usage_retention_days' => 30,

    ],

    /*
    |--------------------------------------------------------------------------
    | Settings Audit Configuration
    |--------------------------------------------------------------------------
    */

    'settings' => [
        // L14: how many days to keep settings_audit rows before pruning
        'audit_retention_days' => 365,
    ],

];
