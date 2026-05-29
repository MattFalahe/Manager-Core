<?php

namespace ManagerCore;

use Seat\Services\AbstractSeatPlugin;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use ManagerCore\Console\Commands\UpdateMarketPricesCommand;
use ManagerCore\Console\Commands\CleanupOldPricesCommand;
use ManagerCore\Console\Commands\DiagnosePluginBridgeCommand;
use ManagerCore\Console\Commands\DiagnoseBridgeCommand;
use ManagerCore\Helpers\EveFormatting;
use ManagerCore\Database\Seeders\ScheduleSeeder;

class ManagerCoreServiceProvider extends AbstractSeatPlugin
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Register API middleware
        $this->app['router']->aliasMiddleware('mc-api-auth', \ManagerCore\Http\Middleware\ApiTokenAuth::class);
        $this->app['router']->aliasMiddleware('mc-api-rate-limit', \ManagerCore\Http\Middleware\ApiRateLimit::class);

        // Check if routes are cached before loading
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
            include __DIR__ . '/Http/api_routes.php';
        }

        $this->loadTranslationsFrom(__DIR__ . '/Resources/lang/', 'manager-core');
        $this->loadViewsFrom(__DIR__ . '/Resources/views/', 'manager-core');

        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations/');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateMarketPricesCommand::class,
                CleanupOldPricesCommand::class,
                DiagnosePluginBridgeCommand::class,
                DiagnoseBridgeCommand::class,
                \ManagerCore\Console\Commands\DiagnoseCommand::class,
                \ManagerCore\Console\Commands\DiagnoseESICommand::class,
                \ManagerCore\Console\Commands\CleanupEventLogCommand::class,
                \ManagerCore\Console\Commands\CleanupStaleSubscriptionsCommand::class,
                \ManagerCore\Console\Commands\PollEsiNotificationsCommand::class,
                \ManagerCore\Console\Commands\SweepSeatNotificationsCommand::class,
                \ManagerCore\Console\Commands\WatchdogCommand::class,
            ]);
        }

        // Register Blade directives
        $this->registerBladeDirectives();

        // Add publications
        $this->add_publications();

        // Boot the plugin bridge
        $this->bootPluginBridge();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Register sidebar configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/Menu/package.sidebar.php',
            'package.sidebar'
        );

        // Register permissions
        $this->registerPermissions(
            __DIR__ . '/Config/Permissions/manager-core.permissions.php',
            'manager-core'
        );

        // Register config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/manager-core.config.php',
            'manager-core'
        );

        // Register core services as singletons
        $this->app->singleton(\ManagerCore\Services\PluginBridge::class);
        $this->app->singleton(\ManagerCore\Services\PricingService::class);
        $this->app->singleton(\ManagerCore\Services\AppraisalService::class);
        $this->app->singleton(\ManagerCore\Services\ParserService::class);
        $this->app->singleton(\ManagerCore\Services\SdeService::class);
        $this->app->singleton(\ManagerCore\Services\EventBus::class);
        $this->app->singleton(\ManagerCore\Services\ESI\EsiNotificationRegistry::class);
        $this->app->singleton(\ManagerCore\Services\Watchdog\WatchdogService::class);

        // M24: Bind public interfaces to the concrete singletons so consumer
        // plugins can depend on the contract instead of the concrete class.
        $this->app->bind(\ManagerCore\Contracts\PluginBridgeInterface::class, \ManagerCore\Services\PluginBridge::class);
        $this->app->bind(\ManagerCore\Contracts\PricingServiceInterface::class, \ManagerCore\Services\PricingService::class);
        $this->app->bind(\ManagerCore\Contracts\EventBusInterface::class, \ManagerCore\Services\EventBus::class);
        $this->app->bind(\ManagerCore\Contracts\SdeServiceInterface::class, \ManagerCore\Services\SdeService::class);

        // Add database seeders
        $this->add_database_seeders();
    }

    /**
     * Register Blade directives for EVE formatting
     *
     * @return void
     */
    private function registerBladeDirectives()
    {
        Blade::directive('isk', function ($expression) {
            return "<?php echo \ManagerCore\Helpers\EveFormatting::isk((float)({$expression})); ?>";
        });

        Blade::directive('iskFull', function ($expression) {
            return "<?php echo \ManagerCore\Helpers\EveFormatting::iskFull((float)({$expression})); ?>";
        });

        Blade::directive('volume', function ($expression) {
            return "<?php echo \ManagerCore\Helpers\EveFormatting::volume((float)({$expression})); ?>";
        });

        Blade::directive('eveNumber', function ($expression) {
            return "<?php echo \ManagerCore\Helpers\EveFormatting::number((float)({$expression})); ?>";
        });

        Blade::directive('typeIcon', function ($expression) {
            return "<?php echo \ManagerCore\Helpers\EveFormatting::typeDisplay({$expression}); ?>";
        });
    }

    /**
     * Boot the Plugin Bridge system
     *
     * This discovers and registers all compatible plugins
     *
     * @return void
     */
    private function bootPluginBridge()
    {
        $bridge = $this->app->make(\ManagerCore\Services\PluginBridge::class);
        $bridge->discover();

        // Register Manager Core's capabilities
        $this->registerCapabilities($bridge);
    }

    /**
     * Register Manager Core's capabilities with the Plugin Bridge
     *
     * @param \ManagerCore\Services\PluginBridge $bridge
     * @return void
     */
    private function registerCapabilities($bridge)
    {
        try {
            // Register pricing capabilities
            $bridge->registerCapability('ManagerCore', 'pricing.getPrice', function ($typeId, $market = 'jita', $priceType = 'both') {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->getPrice($typeId, $market, $priceType);
            });

            // Routes to PricingService::getPrices (plural) which always
            // returns the uniform [typeId => stats] keyed shape regardless
            // of array length. Pre-fix this dispatched to getPrice (singular)
            // which collapsed to the inner stats shape for 1-element arrays —
            // forcing every subscriber doing batch lookups to defensively
            // detect and re-wrap the response. See Mining Manager's
            // PriceProviderService::normaliseBridgeGetPricesShape for the
            // workaround that's now redundant.
            //
            // Backward compat: any caller that previously got the collapsed
            // shape now gets [typeId => stats] instead. For 1-element calls,
            // they'd extract via reset() or array_values()[0] — both still
            // work after this change.
            // 4th arg `$pluginKeyForOverride` (added Option B 2026-05-29).
            // When set AND that plugin has a non-null provider_override on
            // its manager_core_pricing_preferences row, MC does a LIVE
            // batch fetch via the override provider instead of reading
            // its local cache. Lets a single consumer plugin (e.g. Mining
            // Manager) route through Janice while other plugins reading
            // the same market continue to use the per-market provider
            // (e.g. Fuzzwork). Backwards compat: 3-arg calls work
            // unchanged — they take the per-market routing path.
            $bridge->registerCapability('ManagerCore', 'pricing.getPrices', function ($typeIds, $market = 'jita', $priceType = 'both', ?string $pluginKeyForOverride = null) {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->getPrices(
                    is_array($typeIds) ? $typeIds : [$typeIds],
                    $market,
                    $priceType,
                    $pluginKeyForOverride
                );
            });

            $bridge->registerCapability('ManagerCore', 'pricing.getTrend', function ($typeId, $market = 'jita', $days = 7) {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->getTrend($typeId, $market, $days);
            });

            // 5th arg `$immediateRefresh` (default true) is forwarded to
            // PricingService::registerTypes. Subscribers calling through the
            // bridge can pass false on boot-path subscribes (where they don't
            // want a price-refresh job dispatched on every request) while
            // keeping true for settings-save paths (where prices should
            // populate promptly via the queue).
            //
            // The registerTypes side already accepted this 5th arg; the
            // capability lambda just didn't plumb it through, so subscribers
            // had no way to opt out from the bridge entry point.
            $bridge->registerCapability('ManagerCore', 'pricing.subscribeTypes', function ($pluginName, $typeIds, $market = 'jita', $priority = 1, $immediateRefresh = true) {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->registerTypes($pluginName, $typeIds, $market, $priority, $immediateRefresh);
            });

            // Symmetric to pricing.subscribeTypes — lets plugins remove their
            // subscriptions when switching providers (e.g. Mining Manager
            // switching back to Janice/Fuzzwork from MC). Without this,
            // subscribers had to reach directly into
            // manager_core_type_subscriptions via raw DB queries (fragile
            // under schema drift, bypasses any future audit/observer logic).
            //
            // $market=null removes ALL the plugin's subscriptions; pass a
            // specific market string to scope. Returns the deleted count.
            $bridge->registerCapability('ManagerCore', 'pricing.unsubscribeTypes', function ($pluginName, $market = null) {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->unregisterTypes($pluginName, $market);
            });

            // Per-plugin pricing preference: one row in
            // manager_core_pricing_preferences per consumer plugin, declaring
            // which market and which side of the spread to use. Plugins call
            // pricing.registerPreference at boot to set their default; admins
            // override via MC's admin UI. PricingService::priceForPlugin
            // reads the preference and returns a single float per type.
            //
            // priceForPlugin returns ?float (one type). pricesForPlugin
            // returns [typeId => ?float] (batch — preferred for any caller
            // that already knows the full type-id list, e.g. SM Economics
            // walking fuel-block + magmatic-gas + strontium + charters).
            $bridge->registerCapability('ManagerCore', 'pricing.priceForPlugin', function ($pluginKey, $typeId) {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->priceForPlugin((string) $pluginKey, (int) $typeId);
            });

            $bridge->registerCapability('ManagerCore', 'pricing.pricesForPlugin', function ($pluginKey, $typeIds) {
                $pricingService = app(\ManagerCore\Services\PricingService::class);
                return $pricingService->pricesForPlugin(
                    (string) $pluginKey,
                    is_array($typeIds) ? $typeIds : [$typeIds]
                );
            });

            // Idempotent default-registration: pass plugin_key + market +
            // price_type. First-time call creates the row; subsequent calls
            // refresh ONLY when the admin has not overridden (admin override
            // wins permanently). See PricingPreference::registerDefault for
            // the exact precedence rules.
            $bridge->registerCapability('ManagerCore', 'pricing.registerPreference', function ($pluginKey, $market, $priceType, $notes = null) {
                return \ManagerCore\Models\PricingPreference::registerDefault(
                    (string) $pluginKey,
                    (string) $market,
                    (string) $priceType,
                    $notes !== null ? (string) $notes : null
                );
            });

            // Read-side counterpart to registerPreference. Consumer plugins
            // (Mining Manager etc.) call this to surface "what MC currently
            // has configured for me" in their own settings UI — so the
            // operator sees the centrally-configured state without bouncing
            // to MC's page just to check.
            //
            // Returns null when no preference row exists yet (plugin hasn't
            // registered, or MC was just installed). Otherwise:
            //   [
            //     'market'              => string,  // market key, e.g. 'jita'
            //     'market_name'         => string,  // human label, e.g. 'Jita (The Forge)'
            //     'price_type'          => string,  // 'sell' | 'buy' | 'avg'
            //     'admin_overridden'    => bool,    // true if operator-edited
            //     'provider_override'   => string|null, // per-plugin provider override (Option B); null = use market provider
            //     'market_provider'     => string,  // the market's configured provider key
            //     'effective_provider'  => string,  // override OR market_provider — what reads ACTUALLY route through
            //     'provider_label'      => string,  // human label of effective_provider
            //   ]
            //
            // Note: the previous 'provider' field is now 'effective_provider'
            // for clarity. Consumer plugins reading getPreferenceForPlugin
            // for status display should switch to 'effective_provider' +
            // 'provider_override' for the full picture. 'provider' kept as
            // an alias of effective_provider for back-compat.
            $bridge->registerCapability('ManagerCore', 'pricing.getPreferenceForPlugin', function (string $pluginKey): ?array {
                try {
                    $pref = \ManagerCore\Models\PricingPreference::where('plugin_key', $pluginKey)->first();
                    if (!$pref) {
                        return null;
                    }
                    $market = \ManagerCore\Models\Market::where('key', $pref->market)->first();
                    $marketProvider = $market->provider ?? 'fuzzwork';
                    $providerOverride = $pref->provider_override ?? null;
                    if ($providerOverride === '') {
                        $providerOverride = null;
                    }
                    $effective = $providerOverride ?? $marketProvider;
                    $labelOf = fn(string $p): string => match($p) {
                        'esi' => 'MCPraisal (Manager Core ESI)',
                        'fuzzwork' => 'Fuzzwork',
                        'janice' => 'Janice',
                        'goonpraisal' => 'Goonpraisal',
                        'seat' => 'SeAT Price Provider',
                        default => ucfirst($p),
                    };
                    return [
                        'market'             => $pref->market,
                        'market_name'        => $market->name ?? $pref->market,
                        'price_type'         => $pref->price_type,
                        'admin_overridden'   => (bool) ($pref->admin_overridden ?? false),
                        'provider_override'  => $providerOverride,
                        'market_provider'    => $marketProvider,
                        'effective_provider' => $effective,
                        'provider_label'     => $labelOf($effective),
                        // Back-compat: pre-Option-B callers reading 'provider'
                        // get the effective provider, which is what they
                        // expected (the one that actually serves their reads).
                        'provider'           => $effective,
                    ];
                } catch (\Throwable $e) {
                    return null;
                }
            });

            // Register appraisal capabilities
            $bridge->registerCapability('ManagerCore', 'appraisal.create', function ($rawInput, $options = []) {
                $appraisalService = app(\ManagerCore\Services\AppraisalService::class);
                return $appraisalService->createAppraisal($rawInput, $options);
            });

            $bridge->registerCapability('ManagerCore', 'appraisal.get', function ($appraisalId, $privateToken = null) {
                $appraisalService = app(\ManagerCore\Services\AppraisalService::class);
                return $appraisalService->getAppraisal($appraisalId, $privateToken);
            });

            // Register SDE capabilities
            $bridge->registerCapability('ManagerCore', 'sde.typeName', function ($typeId) {
                return app(\ManagerCore\Services\SdeService::class)->typeName($typeId);
            });

            $bridge->registerCapability('ManagerCore', 'sde.typeInfo', function ($typeId) {
                return app(\ManagerCore\Services\SdeService::class)->typeInfo($typeId);
            });

            $bridge->registerCapability('ManagerCore', 'sde.typeNames', function ($typeIds) {
                return app(\ManagerCore\Services\SdeService::class)->typeNames($typeIds);
            });

            $bridge->registerCapability('ManagerCore', 'sde.typeIconUrl', function ($typeId, $variation = 'icon', $size = 64) {
                return app(\ManagerCore\Services\SdeService::class)->typeIconUrl($typeId, $variation, $size);
            });

            // Register event bus capabilities.
            //
            // SECURITY NOTE on events.publish (audit 2026-05-29, finding H1):
            // The capability accepts $publisherPlugin as a parameter — it does
            // NOT verify that the calling plugin matches that publisher slug.
            // A buggy or hostile plugin could theoretically pass another
            // plugin's slug + an event name allowed under that plugin's
            // publisher_prefix and publish spoofed events. Mitigations:
            //   1. Publisher prefix allow-list in config('manager-core.events.publisher_prefixes')
            //      still applies — spoofed events must use an event name in
            //      the impersonated plugin's prefix list (already public config)
            //   2. REST API path (EventApiController::publish) forces publisher='api'
            //      independently — external token holders can only publish api./custom.
            //   3. In-process bridge calls have no further authentication
            //
            // Kept as a registered capability because Buyback Manager's
            // EventPublisher and other future publishers depend on it. Drop
            // (or add caller-attestation) when the plugin ecosystem grows
            // beyond Matt's own plugins, where threat model changes. For now
            // every in-ecosystem plugin is trusted-by-source.
            //
            // Consumer plugins SHOULD prefer the Topics facade
            // (\ManagerCore\Topics::publish) which centralizes attribution
            // and prevents accidental publisher-name typos — but the bridge
            // capability remains for cases where the publisher needs to be
            // dynamic.
            $bridge->registerCapability('ManagerCore', 'events.publish', function ($eventName, $publisherPlugin, $payload = []) {
                return app(\ManagerCore\Services\EventBus::class)->publish($eventName, $publisherPlugin, $payload);
            });

            $bridge->registerCapability('ManagerCore', 'events.subscribe', function ($subscriberPlugin, $eventPattern, $handlerCapability, $options = []) {
                return app(\ManagerCore\Services\EventBus::class)->subscribe($subscriberPlugin, $eventPattern, $handlerCapability, $options);
            });

            // Register ESI notification handler registration capability
            // Plugins call this at boot to subscribe to ESI notifications by type.
            // Handler contract: any class with static handle($notification): void method.
            $bridge->registerCapability('ManagerCore', 'esi.registerNotificationHandler', function (array $types, string $handlerClass, ?string $pluginName = null, array $options = []) {
                return app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class)
                    ->register($types, $handlerClass, $pluginName, $options);
            });

            $bridge->registerCapability('ManagerCore', 'esi.getRegisteredNotificationTypes', function () {
                return app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class)->getRegisteredTypes();
            });

            // M10: Self-register capability — plugins not in the hardcoded
            // compatible_plugins config can announce themselves via this bridge call
            $bridge->registerCapability('ManagerCore', 'bridge.registerSelf', function (string $pluginKey, array $info) use ($bridge) {
                return $bridge->registerSelf($pluginKey, $info);
            });

            // D7: read-only utility capabilities so MM/SM/etc. can drop direct
            // SQL reads of MC's tables. Each is a thin wrapper over a service
            // method; if MC's schema ever changes, callers don't break.

            $bridge->registerCapability('ManagerCore', 'pricing.getSubscribedTypeCount', function (string $pluginName, ?string $market = null): int {
                try {
                    $query = \ManagerCore\Models\TypeSubscription::where('plugin_name', $pluginName);
                    if ($market !== null) {
                        $query->where('market', $market);
                    }
                    return (int) $query->count();
                } catch (\Throwable $e) {
                    return 0;
                }
            });

            $bridge->registerCapability('ManagerCore', 'pricing.getMarketStats', function (string $market = 'jita'): array {
                try {
                    $now = \Carbon\Carbon::now();
                    $base = \ManagerCore\Models\MarketPrice::where('market', $market);
                    $lastUpdated = (clone $base)->max('updated_at');
                    return [
                        'market' => $market,
                        'total_prices' => (int) (clone $base)->count(),
                        'last_updated' => $lastUpdated ? \Carbon\Carbon::parse($lastUpdated)->toIso8601String() : null,
                        'fresh_count' => (int) (clone $base)->where('updated_at', '>=', $now->copy()->subHour())->count(),
                        'stale_count' => (int) (clone $base)->where('updated_at', '<', $now->copy()->subHours(6))->count(),
                    ];
                } catch (\Throwable $e) {
                    return [
                        'market' => $market,
                        'total_prices' => 0,
                        'last_updated' => null,
                        'fresh_count' => 0,
                        'stale_count' => 0,
                        'error' => 'unavailable',
                    ];
                }
            });

            $bridge->registerCapability('ManagerCore', 'events.getSubscriptions', function (string $subscriberPlugin) {
                try {
                    return app(\ManagerCore\Services\EventBus::class)
                        ->getSubscriptions($subscriberPlugin);
                } catch (\Throwable $e) {
                    return collect();
                }
            });

            // D7: Lets a consumer plugin import a batch of ESI key holders
            // without touching MC's schema directly. Used by Structure Manager's
            // historical key-pool migration. Idempotent — uses upsert semantics
            // keyed on character_id.
            //
            // $rows is an array of arrays with at minimum 'character_id'; other
            // recognized fields: corporation_id, character_name, enabled,
            // last_polled_at, last_poll_status, last_error, consecutive_failures,
            // total_polls, total_notifications_found.
            $bridge->registerCapability('ManagerCore', 'esi.importKeyHolders', function (array $rows): int {
                if (empty($rows)) {
                    return 0;
                }
                try {
                    if (!\Illuminate\Support\Facades\Schema::hasTable('manager_core_esi_key_holders')) {
                        return 0;
                    }
                    $imported = 0;
                    $now = now();
                    foreach ($rows as $row) {
                        if (empty($row['character_id'])) {
                            continue;
                        }
                        $values = array_intersect_key($row, array_flip([
                            'corporation_id', 'character_name', 'enabled',
                            'last_polled_at', 'last_poll_status', 'last_error',
                            'consecutive_failures', 'total_polls', 'total_notifications_found',
                        ]));
                        $values['updated_at'] = $now;
                        \Illuminate\Support\Facades\DB::table('manager_core_esi_key_holders')
                            ->updateOrInsert(
                                ['character_id' => (int) $row['character_id']],
                                array_merge(['created_at' => $now], $values)
                            );
                        $imported++;
                    }
                    return $imported;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[Manager Core] esi.importKeyHolders failed: ' . $e->getMessage());
                    return 0;
                }
            });

            // D8: Bridge introspection — consumers can ask "what capabilities
            // does this MC version expose?" without hardcoding capability
            // names. Returns [pluginKey => [capability1, capability2, ...]].
            $bridge->registerCapability('ManagerCore', 'bridge.discoverCapabilities', function (?string $pluginKey = null) use ($bridge): array {
                $stats = $bridge->getStatistics();
                $capabilities = [];
                // Reflect from the in-memory map via PluginRegistry (best-effort)
                try {
                    $query = \ManagerCore\Models\PluginRegistry::query();
                    if ($pluginKey !== null) {
                        $query->where('plugin_name', $pluginKey);
                    }
                    foreach ($query->get() as $row) {
                        $capabilities[$row->plugin_name] = $row->capabilities ?? [];
                    }
                } catch (\Throwable $e) {
                    // PluginRegistry table missing — return empty
                }
                return $capabilities;
            });

            // Pricing-preferences URL. Consumer plugins that want to deep-link
            // operators into MC's Pricing Preferences page (e.g. "Configure
            // pricing centrally in Manager Core →") call this to get a
            // resolved route URL without hard-coding the path. Stays accurate
            // if we ever rename the route. Returns null when not resolvable
            // (the route group is admin-only — non-superusers get nothing).
            //
            // Usage from consumer plugin's blade:
            //   $mcPricingUrl = \ManagerCore\Services\PluginBridge::call(
            //       'ManagerCore',
            //       'pricing.preferencesUrl'
            //   );
            //   <a href="{{ $mcPricingUrl ?? '#' }}">Configure in MC</a>
            $bridge->registerCapability('ManagerCore', 'pricing.preferencesUrl', function (): ?string {
                try {
                    return route('manager-core.pricing-preferences.index');
                } catch (\Throwable $e) {
                    return null;
                }
            });

            // D5: Version gate. Consumer plugins call this once at boot to
            // verify they're paired with a compatible MC version. Throws
            // RuntimeException if MC is older than $minVersion. Returns true
            // when the version requirement is satisfied so callers can use
            // the boolean form: `if (!Bridge::call('ManagerCore', 'bridge.requireMinimumVersion', '1.0.0')) return;`
            $bridge->registerCapability('ManagerCore', 'bridge.requireMinimumVersion', function (string $minVersion, bool $throwOnFailure = false): bool {
                $current = defined('\\ManagerCore\\ManagerCore::VERSION')
                    ? \ManagerCore\ManagerCore::VERSION
                    : '0.0.0';

                if (version_compare($current, $minVersion, '>=')) {
                    return true;
                }

                $msg = "[Manager Core] requireMinimumVersion: installed {$current} < required {$minVersion}";
                \Illuminate\Support\Facades\Log::warning($msg);
                if ($throwOnFailure) {
                    throw new \RuntimeException($msg);
                }
                return false;
            });

            Log::info('[Manager Core] Registered capabilities with Plugin Bridge');
        } catch (\Exception $e) {
            Log::warning('[Manager Core] Could not register capabilities: ' . $e->getMessage());
        }
    }

    /**
     * Add content which must be published.
     */
    private function add_publications()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/Config/manager-core.config.php' => config_path('manager-core.php'),
        ], ['config', 'seat']);

        // Publish assets
        $this->publishes([
            __DIR__ . '/Resources/assets' => public_path('vendor/manager-core'),
        ], ['public', 'seat']);
    }

    /**
     * Register database seeders
     */
    private function add_database_seeders()
    {
        $this->registerDatabaseSeeders([
            ScheduleSeeder::class,
        ]);
    }

    /**
     * Get the plugin name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Manager Core';
    }

    /**
     * Get the plugin repository URL.
     *
     * @return string
     */
    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/MattFalahe/manager-core';
    }

    /**
     * Get the packagist package name.
     *
     * @return string
     */
    public function getPackagistPackageName(): string
    {
        return 'manager-core';
    }

    /**
     * Get the packagist vendor name.
     *
     * @return string
     */
    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
