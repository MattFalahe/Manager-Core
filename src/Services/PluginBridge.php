<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Exceptions\CapabilityCallException;
use ManagerCore\Exceptions\CapabilityNotFoundException;
use ManagerCore\Models\PluginRegistry;

/**
 * PluginBridge - Central hub for inter-plugin communication
 *
 * This service allows Manager Core and other plugins to:
 * - Discover and register compatible plugins
 * - Share services and capabilities
 * - Communicate via events and method calls
 *
 * Plugin statuses:
 * - active (green):   Installed AND actively sharing data with Manager Core
 * - installed (yellow): Installed but not using Manager Core services
 * - error (red):      Installed but communication error detected
 * - progress (orange): In development / not yet released
 * - inactive (gray):  Not installed
 */
class PluginBridge implements \ManagerCore\Contracts\PluginBridgeInterface
{
    /**
     * Registered plugins
     *
     * @var array
     */
    protected $plugins = [];

    /**
     * Plugin capabilities registry
     *
     * @var array
     */
    protected $capabilities = [];

    /**
     * Pending capability list for plugins not yet in registry (boot-order safety)
     *
     * Holds [pluginName => [capability1, capability2, ...]] until updateRegistry
     * either creates the row or finds it. Flushed on every registerCapability call.
     *
     * @var array<string, array<string>>
     */
    protected $pendingCapabilities = [];

    /**
     * Last error from a call() that returned null
     *
     * @var \Throwable|null
     */
    protected $lastError = null;

    /**
     * Cache key for plugin discovery
     */
    const CACHE_KEY = 'mc_plugin_bridge_registry';

    /**
     * Discover and register all compatible plugins
     *
     * @param bool $forceRefresh Skip cache and re-scan
     * @return void
     */
    public function discover(bool $forceRefresh = false)
    {
        // H8: DB setting (Settings UI) overrides config. If the DB has the key,
        // its value is authoritative; otherwise fall back to config.
        try {
            $dbEnabled = \ManagerCore\Models\Setting::get('bridge.auto_discover', '__MC_NOT_SET__');
        } catch (\Throwable $e) {
            $dbEnabled = '__MC_NOT_SET__';
        }
        if ($dbEnabled !== '__MC_NOT_SET__') {
            $enabled = $dbEnabled === true || $dbEnabled === 1 || $dbEnabled === '1' || $dbEnabled === 'true';
            if (!$enabled) {
                return;
            }
        } elseif (!config('manager-core.bridge.auto_discover', true)) {
            return;
        }

        // Default 5 minutes — was 60. Plugin install/uninstall reflects within minutes.
        $cacheDuration = (int) config('manager-core.bridge.cache_duration', 5);

        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        // Detect class-list changes — auto-invalidate when the set of installed
        // plugin service-provider classes changes (composer install/remove)
        $compatiblePlugins = config('manager-core.bridge.compatible_plugins', []);
        $installedSet = [];
        foreach ($compatiblePlugins as $key => $cfg) {
            $cls = $cfg['class'] ?? null;
            if ($cls && class_exists($cls)) {
                $installedSet[] = $key;
            }
        }
        $signature = md5(implode(',', $installedSet));
        $cachedSignature = Cache::get(self::CACHE_KEY . ':signature');
        if ($cachedSignature !== null && $cachedSignature !== $signature) {
            Log::info('[Manager Core] Plugin set changed — invalidating Plugin Bridge cache');
            Cache::forget(self::CACHE_KEY);
        }
        Cache::put(self::CACHE_KEY . ':signature', $signature, ($cacheDuration * 60) * 24);

        $this->plugins = Cache::remember(self::CACHE_KEY, $cacheDuration * 60, function () {
            return $this->scanForPlugins();
        });

        // Always flush pending capabilities for any plugin that appeared
        foreach (array_keys($this->plugins) as $pluginKey) {
            if (!empty($this->pendingCapabilities[$pluginKey])) {
                $this->flushPendingCapabilities($pluginKey);
            }
        }

        Log::debug('[Manager Core] Plugin Bridge discovered ' . count($this->plugins) . ' plugins');
    }

    /**
     * Scan for compatible plugins and determine their connection status
     *
     * @return array
     */
    protected function scanForPlugins()
    {
        $discoveredPlugins = [];
        $compatiblePlugins = config('manager-core.bridge.compatible_plugins', []);

        // Pricing-type subscriptions
        $activeSubscriptions = $this->getActivePricingSubscriptionCounts();

        // M8: also count EventBus subscriptions, ESI handler registrations, and capabilities
        $activeEventSubs = $this->getActiveEventSubscriptions();
        $activeEsiHandlers = $this->getActiveEsiHandlers();
        $recentEventPublishers = $this->getRecentEventPublishers();

        // 2026-05-12 diagnostic-surface improvements: per-plugin "last event
        // activity" timestamps. These let operators tell a healthy plugin
        // ("active 30s ago") apart from a stale one ("idle 6h+") without
        // having to dig into manager_core_event_log directly.
        $lastPublishedAt = $this->getLastEventPublishedPerPlugin();
        $lastReceivedAt  = $this->getLastEventReceivedPerPlugin();

        // 6-state model: error signals. A plugin is "Error" when an event
        // dispatch to it is actively failing — an open circuit breaker, or
        // repeated failed dispatches in the last hour.
        $errorCounts  = $this->getSubscriberErrorCounts(60);
        $openCircuits = $this->getOpenCircuitCounts();

        foreach ($compatiblePlugins as $pluginKey => $pluginConfig) {
            $providerClass = $pluginConfig['class'] ?? null;
            $subscriptionName = $pluginConfig['subscription_name'] ?? null;

            if (!$providerClass) {
                continue;
            }

            $installed = class_exists($providerClass);

            // Multiple integration signals
            $pricingSubCount = $subscriptionName && isset($activeSubscriptions[$subscriptionName])
                ? $activeSubscriptions[$subscriptionName]
                : 0;
            $eventSubCount = $activeEventSubs[$pluginKey] ?? 0;
            $esiHandlerCount = $activeEsiHandlers[$pluginKey] ?? 0;
            $publishedRecently = $recentEventPublishers[$pluginKey] ?? 0;
            $capabilityCount = isset($this->capabilities[$pluginKey]) ? count($this->capabilities[$pluginKey]) : 0;

            $integrationCount = $pricingSubCount + $eventSubCount + $esiHandlerCount + $publishedRecently;
            $errorCount       = $errorCounts[$pluginKey] ?? 0;
            $openCircuitCount = $openCircuits[$pluginKey] ?? 0;

            // 6-state model. "Channels" = distinct integration channels the
            // plugin has wired or used: pricing, event subscriptions, ESI
            // handlers, event publishing. "Live traffic" = a real event
            // actually flowed (published or received) within the last 24h.
            $channels = 0;
            if ($pricingSubCount > 0)   { $channels++; }
            if ($eventSubCount > 0)     { $channels++; }
            if ($esiHandlerCount > 0)   { $channels++; }
            if ($publishedRecently > 0) { $channels++; }

            $receivedRecently = isset($lastReceivedAt[$pluginKey])
                && $this->isWithinDay($lastReceivedAt[$pluginKey]);
            $liveTraffic = $publishedRecently > 0 || $receivedRecently;

            // Error means "broken right now": an open circuit breaker, or
            // 3+ failed dispatches in the last hour. Transient blips don't count.
            $hasError = $openCircuitCount > 0 || $errorCount >= 3;

            $state  = $this->determinePluginState($installed, $hasError, $channels, $liveTraffic, $capabilityCount);
            $status = $this->legacyStatusFor($state);   // back-compat alias

            $discoveredPlugins[$pluginKey] = [
                'name' => $pluginConfig['name'],
                'class' => $providerClass,
                'package' => $pluginConfig['package'],
                'icon' => $pluginConfig['icon'] ?? 'fa-puzzle-piece',
                'installed' => $installed,
                'state' => $state,
                'state_reason' => $this->describePluginState(
                    $state, $pricingSubCount, $eventSubCount, $esiHandlerCount,
                    $publishedRecently, $capabilityCount, $liveTraffic,
                    $errorCount, $openCircuitCount
                ),
                'status' => $status,   // legacy 3+1 value — DiagnosticController + getStatistics still read this
                'subscription_count' => $pricingSubCount,                  // legacy field name kept for back-compat
                'integration' => [
                    'pricing_subscriptions' => $pricingSubCount,
                    'event_subscriptions' => $eventSubCount,
                    'esi_handlers' => $esiHandlerCount,
                    'events_published_24h' => $publishedRecently,
                    'capabilities' => $capabilityCount,
                    'channels' => $channels,
                    'live_traffic' => $liveTraffic,
                    'error_count' => $errorCount,
                    'open_circuits' => $openCircuitCount,
                ],
                // 2026-05-12: timestamps for the bridge dashboard's
                // "Last Activity" column. Either or both may be null
                // if the plugin has never published or received an event.
                'last_published_at' => $lastPublishedAt[$pluginKey] ?? null,
                'last_received_at'  => $lastReceivedAt[$pluginKey] ?? null,
            ];

            // Update database registry
            if ($installed) {
                $this->updateRegistry($pluginKey, $providerClass, $status, $integrationCount, $state);

                // Now that the registry row exists, flush any buffered capabilities
                $this->flushPendingCapabilities($pluginKey);

                // L6: prune capabilities that the plugin no longer registers
                $this->pruneStaleCapabilities($pluginKey);

                Log::debug("[Manager Core] Discovered plugin: {$pluginConfig['name']} (status: {$status}, integration: {$integrationCount})");
            }
        }

        return $discoveredPlugins;
    }

    /**
     * L6: Reconcile PluginRegistry.capabilities against the in-memory map.
     *
     * If a plugin used to register 'foo.bar' but no longer does, the DB row
     * still lists it. This compares the persisted list against the live
     * $this->capabilities[$pluginName] map and trims anything that's no
     * longer present. Only run after the plugin has had a chance to call
     * registerCapability() during boot.
     */
    protected function pruneStaleCapabilities(string $pluginName): void
    {
        try {
            $plugin = PluginRegistry::where('plugin_name', $pluginName)->first();
            if (!$plugin) {
                return;
            }

            $persisted = $plugin->capabilities ?? [];
            $live = isset($this->capabilities[$pluginName]) ? array_keys($this->capabilities[$pluginName]) : [];

            // Only prune when we know the plugin booted in this process — i.e.
            // we have at least one live capability registered. If $live is empty
            // it means the plugin's service provider hasn't run in this request
            // (e.g. an artisan command), and we'd incorrectly drop everything.
            if (empty($live)) {
                return;
            }

            $stale = array_values(array_diff($persisted, $live));
            if (empty($stale)) {
                return;
            }

            $cleaned = array_values(array_intersect($persisted, $live));
            $plugin->update(['capabilities' => $cleaned]);

            Log::info("[Manager Core] Pruned stale capabilities from {$pluginName}", ['pruned' => $stale]);
        } catch (\Throwable $e) {
            // Non-fatal — capabilities map will self-heal on next boot
            Log::warning("[Manager Core] Could not prune stale capabilities for {$pluginName}: " . $e->getMessage());
        }
    }

    /**
     * M8: count EventBus subscriptions per plugin.
     *
     * @return array [plugin_name => count]
     */
    protected function getActiveEventSubscriptions(): array
    {
        try {
            return DB::table('manager_core_event_subscriptions')
                ->where('is_active', true)
                ->select('subscriber_plugin', DB::raw('COUNT(*) as count'))
                ->groupBy('subscriber_plugin')
                ->pluck('count', 'subscriber_plugin')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * M8: count ESI notification handlers per plugin (from in-memory registry).
     *
     * @return array [plugin_name => count]
     */
    protected function getActiveEsiHandlers(): array
    {
        try {
            $registry = app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class);
            $byPlugin = $registry->getRegistrationsByPlugin();
            $counts = [];
            foreach ($byPlugin as $plugin => $data) {
                $counts[$plugin] = count($data['handlers'] ?? []);
            }
            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * M8: count publishers that published an event in the last 24h.
     *
     * @return array [plugin_name => count_of_distinct_event_names]
     */
    protected function getRecentEventPublishers(): array
    {
        try {
            return DB::table('manager_core_event_log')
                ->where('created_at', '>=', now()->subDay())
                ->select('publisher_plugin', DB::raw('COUNT(DISTINCT event_name) as count'))
                ->groupBy('publisher_plugin')
                ->pluck('count', 'publisher_plugin')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 2026-05-12: per-plugin timestamp of the most recent event publish
     * (from manager_core_event_log).
     *
     * Used by the bridge dashboard "Last Activity" column. A plugin that
     * publishes frequently shows "30s ago"; one that hasn't published in
     * hours shows the staleness so operators notice quickly.
     *
     * Returns a map keyed by publisher_plugin → Carbon-parseable string
     * (or null/missing if the plugin has never published).
     *
     * @return array<string, string>
     */
    protected function getLastEventPublishedPerPlugin(): array
    {
        try {
            return DB::table('manager_core_event_log')
                ->select('publisher_plugin', DB::raw('MAX(created_at) as last_at'))
                ->groupBy('publisher_plugin')
                ->pluck('last_at', 'publisher_plugin')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 2026-05-12: per-plugin timestamp of the most recent event RECEPTION.
     *
     * Best-effort approximation since the event_log table records publishes,
     * not receptions, directly. We approximate "received recently" by
     * looking at event_log rows where this plugin's subscriber_plugin
     * pattern matched (subscriber_count >= 1) and the event_name fits one
     * of their subscription patterns. For a clean view, this uses the most
     * recent published event whose pattern matches any of the plugin's
     * active subscriptions.
     *
     * @return array<string, string>
     */
    protected function getLastEventReceivedPerPlugin(): array
    {
        try {
            // Get each plugin's active event_pattern subscriptions
            $subs = DB::table('manager_core_event_subscriptions')
                ->where('is_active', true)
                ->select('subscriber_plugin', 'event_pattern')
                ->get()
                ->groupBy('subscriber_plugin');

            $result = [];

            foreach ($subs as $pluginKey => $rows) {
                // Translate fnmatch patterns to SQL LIKE. fnmatch('structure.alert.*', X)
                // is equivalent to LIKE 'structure.alert.%'. Replace '*' with '%' and
                // '?' with '_'. (Subscription patterns rarely use '?', but we cover it
                // for completeness.)
                $likePatterns = $rows->pluck('event_pattern')
                    ->map(fn($p) => str_replace(['*', '?'], ['%', '_'], $p))
                    ->all();

                if (empty($likePatterns)) {
                    continue;
                }

                $query = DB::table('manager_core_event_log')
                    ->where('subscriber_count', '>=', 1);

                // OR-chain the LIKE conditions for this plugin's patterns
                $query->where(function ($q) use ($likePatterns) {
                    foreach ($likePatterns as $pattern) {
                        $q->orWhere('event_name', 'LIKE', $pattern);
                    }
                });

                $maxAt = $query->max('created_at');
                if ($maxAt) {
                    $result[$pluginKey] = $maxAt;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get pricing-type subscription counts grouped by plugin name.
     *
     * L5 — renamed from `getActiveSubscriptions` for clarity. The previous name
     * was misleading because it counted only `manager_core_type_subscriptions`
     * (pricing) rows, not event_subscriptions or any other "active" signal.
     *
     * @return array [plugin_name => count]
     */
    protected function getActivePricingSubscriptionCounts(): array
    {
        try {
            return DB::table('manager_core_type_subscriptions')
                ->select('plugin_name', DB::raw('COUNT(*) as count'))
                ->groupBy('plugin_name')
                ->pluck('count', 'plugin_name')
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('[Manager Core] Could not query subscriptions: ' . $e->getMessage());
            return [];
        }
    }

    // A10: removed deprecated `getActiveSubscriptions()` alias (was protected,
    // never called externally, last surviving caller updated to use
    // getActivePricingSubscriptionCounts() in scanForPlugins). If a downstream
    // plugin extended PluginBridge and overrode the protected method, they'll
    // need to rename — but that's an unsupported extension pattern.

    // ---------------------------------------------------------------------
    // 6-state plugin model (offline / standalone / discovered / partial /
    // full / error). Replaces the old binary active-vs-installed split.
    // ---------------------------------------------------------------------

    /**
     * Per-plugin count of failed event dispatches in a recent window.
     *
     * Scans manager_core_event_log rows whose status is failed/partial_failure
     * and tallies the per-error `subscriber` field. Used to flag the Error
     * state — a plugin whose handler is actively rejecting events.
     *
     * @param int $windowMinutes lookback window
     * @return array [plugin_name => failed_dispatch_count]
     */
    protected function getSubscriberErrorCounts(int $windowMinutes = 60): array
    {
        try {
            $rows = DB::table('manager_core_event_log')
                ->where('created_at', '>=', now()->subMinutes($windowMinutes))
                ->whereIn('status', ['failed', 'partial_failure'])
                ->whereNotNull('errors')
                ->pluck('errors');

            $counts = [];
            foreach ($rows as $errorsJson) {
                $errs = is_string($errorsJson) ? json_decode($errorsJson, true) : $errorsJson;
                if (!is_array($errs)) {
                    continue;
                }
                foreach ($errs as $err) {
                    $subscriber = is_array($err) ? ($err['subscriber'] ?? null) : null;
                    if ($subscriber) {
                        $counts[$subscriber] = ($counts[$subscriber] ?? 0) + 1;
                    }
                }
            }
            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Per-plugin count of EventBus circuit breakers currently open.
     *
     * The EventBus opens a circuit (cache key `mc_cb_<hash>_open`) after a
     * subscriber's handler fails repeatedly. Mirrors EventBus::circuitBreakerKey()
     * so the key derivation stays consistent.
     *
     * @return array [plugin_name => open_circuit_count]
     */
    protected function getOpenCircuitCounts(): array
    {
        try {
            $subs = DB::table('manager_core_event_subscriptions')
                ->where('is_active', true)
                ->select('subscriber_plugin', 'handler_capability')
                ->get();

            $counts = [];
            foreach ($subs as $sub) {
                $cbKey = 'mc_cb_' . md5($sub->subscriber_plugin . '|' . $sub->handler_capability);
                if (Cache::get($cbKey . '_open')) {
                    $counts[$sub->subscriber_plugin] = ($counts[$sub->subscriber_plugin] ?? 0) + 1;
                }
            }
            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Is a timestamp string within the last 24 hours?
     */
    protected function isWithinDay($dateString): bool
    {
        if (!$dateString) {
            return false;
        }
        try {
            return \Carbon\Carbon::parse($dateString)->gt(now()->subDay());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve a plugin's 6-state value from its integration signals.
     *
     * - offline    : service-provider class not loadable
     * - error      : an event dispatch is actively failing
     * - full       : 2+ integration channels wired AND live traffic in 24h
     * - partial    : at least one channel wired (but not "full")
     * - discovered : registered bridge capabilities, but no wiring / no traffic
     * - standalone : installed, zero Manager Core integration (opted out)
     */
    protected function determinePluginState(bool $installed, bool $hasError, int $channels, bool $liveTraffic, int $capabilityCount): string
    {
        if (!$installed) {
            return 'offline';
        }
        if ($hasError) {
            return 'error';
        }
        if ($channels >= 2 && $liveTraffic) {
            return 'full';
        }
        if ($channels >= 1) {
            return 'partial';
        }
        if ($capabilityCount > 0) {
            return 'discovered';
        }
        return 'standalone';
    }

    /**
     * Map a 6-state value back to the legacy 3+1 status string so existing
     * consumers (DiagnosticController, getStatistics) keep working unchanged.
     */
    protected function legacyStatusFor(string $state): string
    {
        switch ($state) {
            case 'full':
            case 'partial':
                return 'active';
            case 'error':
                return 'error';
            case 'offline':
                return 'inactive';
            case 'discovered':
            case 'standalone':
            default:
                return 'installed';
        }
    }

    /**
     * Build a plain-English explanation of why a plugin is in its state.
     * Shown on the bridge dashboard so operators understand the colour.
     */
    protected function describePluginState(
        string $state,
        int $pricing,
        int $eventSubs,
        int $esi,
        int $published,
        int $caps,
        bool $liveTraffic,
        int $errorCount,
        int $openCircuits
    ): string {
        switch ($state) {
            case 'offline':
                return 'Service provider class not loadable — the plugin is not installed via composer.';

            case 'standalone':
                return 'Installed, but not integrating with Manager Core (no capabilities, subscriptions, or events).';

            case 'discovered':
                return $caps . ' ' . ($caps === 1 ? 'capability' : 'capabilities')
                    . ' registered with the bridge, but no pricing / event / ESI wiring and no event traffic.';

            case 'error':
                $bits = [];
                if ($openCircuits > 0) {
                    $bits[] = $openCircuits . ' subscription circuit breaker' . ($openCircuits === 1 ? '' : 's') . ' open';
                }
                if ($errorCount > 0) {
                    $bits[] = $errorCount . ' failed dispatch' . ($errorCount === 1 ? '' : 'es') . ' in the last hour';
                }
                return 'Communication failure: ' . implode('; ', $bits) . '.';

            case 'partial':
            case 'full':
                $wired = [];
                if ($pricing > 0) {
                    $wired[] = $pricing . ' pricing ' . ($pricing === 1 ? 'subscription' : 'subscriptions');
                }
                if ($eventSubs > 0) {
                    $wired[] = $eventSubs . ' event ' . ($eventSubs === 1 ? 'subscription' : 'subscriptions');
                }
                if ($esi > 0) {
                    $wired[] = $esi . ' ESI ' . ($esi === 1 ? 'handler' : 'handlers');
                }
                if ($published > 0) {
                    $wired[] = $published . ' event ' . ($published === 1 ? 'type' : 'types') . ' published in 24h';
                }
                $label = $state === 'full' ? 'Full data exchange' : 'Partial data exchange';
                $desc = $label . ': ' . (empty($wired) ? 'integrating' : implode(', ', $wired)) . '.';
                if ($state === 'partial' && !$liveTraffic) {
                    $desc .= ' Wired, but no event traffic in the last 24h.';
                }
                return $desc;
        }

        return '';
    }

    /**
     * M10: Self-register a plugin with the bridge at runtime.
     *
     * Plugins NOT listed in the hardcoded compatible_plugins config can call
     * this from their service-provider boot() to appear in the bridge UI.
     * The plugin's package, name, icon, and provider class are persisted to
     * PluginRegistry so the discover() pass picks them up on the next scan.
     *
     * Example (from any plugin's boot()):
     *
     *   if (class_exists(\ManagerCore\Services\PluginBridge::class)) {
     *       app(\ManagerCore\Services\PluginBridge::class)->registerSelf('my-plugin', [
     *           'name' => 'My Plugin',
     *           'class' => \MyPlugin\MyPluginServiceProvider::class,
     *           'package' => 'vendor/my-plugin',
     *           'icon' => 'fa-cog',
     *           'subscription_name' => null,
     *       ]);
     *   }
     *
     * Idempotent — repeated calls don't create duplicate rows.
     *
     * @param string $pluginKey URL-safe plugin slug (e.g. 'my-plugin')
     * @param array $info ['name'=>str, 'class'=>FQN, 'package'=>str, 'icon'=>str, 'subscription_name'=>?str]
     * @return void
     */
    public function registerSelf(string $pluginKey, array $info): void
    {
        $providerClass = $info['class'] ?? null;
        if (!$providerClass || !class_exists($providerClass)) {
            return;
        }

        // Update in-memory plugins map for this request lifecycle
        $this->plugins[$pluginKey] = [
            'name' => $info['name'] ?? $pluginKey,
            'class' => $providerClass,
            'package' => $info['package'] ?? $pluginKey,
            'icon' => $info['icon'] ?? 'fa-puzzle-piece',
            'installed' => true,
            'status' => 'installed',
            'subscription_count' => 0,
            'self_registered' => true,
        ];

        // Persist to registry so it survives until the next discover() pass
        try {
            PluginRegistry::updateOrCreate(
                ['plugin_name' => $pluginKey],
                [
                    'plugin_class' => $providerClass,
                    'is_active' => true,
                    'metadata' => [
                        'self_registered' => true,
                        'name' => $info['name'] ?? $pluginKey,
                        'package' => $info['package'] ?? null,
                        'icon' => $info['icon'] ?? null,
                        'subscription_name' => $info['subscription_name'] ?? null,
                    ],
                    'last_seen_at' => now(),
                ]
            );

            // Flush any pending capabilities for this plugin
            $this->flushPendingCapabilities($pluginKey);

            Log::debug("[Manager Core] Plugin '{$pluginKey}' self-registered with the Plugin Bridge");
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not persist self-registration for {$pluginKey}: " . $e->getMessage());
        }
    }

    /**
     * Update plugin registry in database
     *
     * @param string $pluginName
     * @param string $providerClass
     * @param string $status        Legacy 3+1 status (active/installed/inactive/error)
     * @param int $subscriptionCount
     * @param string|null $state     6-state granular value (full/partial/discovered/standalone/offline/error)
     * @return void
     */
    protected function updateRegistry($pluginName, $providerClass, $status = 'installed', $subscriptionCount = 0, $state = null)
    {
        try {
            PluginRegistry::updateOrCreate(
                ['plugin_name' => $pluginName],
                [
                    'plugin_class' => $providerClass,
                    'is_active' => $status === 'active',
                    'metadata' => [
                        'status' => $status,
                        'state' => $state,
                        'subscription_count' => $subscriptionCount,
                    ],
                    'last_seen_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            // Gracefully handle missing tables during installation/migration
            Log::warning("[Manager Core] Could not update plugin registry for {$pluginName}: " . $e->getMessage());
        }
    }

    /**
     * Check if a plugin is available
     *
     * @param string $pluginName
     * @return bool
     */
    public function hasPlugin($pluginName)
    {
        return isset($this->plugins[$pluginName]) && ($this->plugins[$pluginName]['installed'] ?? false);
    }

    /**
     * Get plugin information
     *
     * @param string $pluginName
     * @return array|null
     */
    public function getPlugin($pluginName)
    {
        return $this->plugins[$pluginName] ?? null;
    }

    /**
     * Get all registered plugins
     *
     * @return array
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Register a capability that this or another plugin provides
     *
     * Resilient to boot-order: if the plugin's registry row doesn't exist yet
     * (because the plugin booted before MC's discover() ran), the capability
     * is buffered in $pendingCapabilities and flushed on the next discover().
     *
     * @param string $pluginName
     * @param string $capability
     * @param callable $handler
     * @return void
     */
    public function registerCapability($pluginName, $capability, callable $handler)
    {
        if (!isset($this->capabilities[$pluginName])) {
            $this->capabilities[$pluginName] = [];
        }

        $this->capabilities[$pluginName][$capability] = $handler;

        // Buffer the capability name for DB persistence
        if (!isset($this->pendingCapabilities[$pluginName])) {
            $this->pendingCapabilities[$pluginName] = [];
        }
        if (!in_array($capability, $this->pendingCapabilities[$pluginName])) {
            $this->pendingCapabilities[$pluginName][] = $capability;
        }

        // Try to flush to DB now (works if registry row exists already)
        $persisted = $this->flushPendingCapabilities($pluginName);

        // M9: log accurately — distinguish "in memory only" from "persisted to registry"
        if ($persisted) {
            Log::debug("[Manager Core] Plugin '{$pluginName}' registered capability '{$capability}' (in-memory + persisted)");
        } else {
            Log::debug("[Manager Core] Plugin '{$pluginName}' registered capability '{$capability}' (in-memory only — registry row not yet present, will persist on next discover())");
        }
    }

    /**
     * Flush buffered capabilities to PluginRegistry for a given plugin
     *
     * Called from registerCapability and from discover() after registry rows
     * are created, so capabilities registered before the plugin's row existed
     * are persisted on the next opportunity.
     *
     * @param string $pluginName
     * @return bool true if capabilities were persisted to the DB, false if still buffered
     */
    protected function flushPendingCapabilities($pluginName): bool
    {
        if (empty($this->pendingCapabilities[$pluginName])) {
            return true; // nothing to flush — treat as "persisted"
        }

        try {
            $plugin = PluginRegistry::where('plugin_name', $pluginName)->first();
            if (!$plugin) {
                // Registry row doesn't exist yet — keep buffered for next attempt
                return false;
            }

            $existing = $plugin->capabilities ?? [];
            $merged = array_values(array_unique(array_merge($existing, $this->pendingCapabilities[$pluginName])));

            if ($merged !== $existing) {
                $plugin->update(['capabilities' => $merged]);
            }

            unset($this->pendingCapabilities[$pluginName]);
            return true;
        } catch (\Exception $e) {
            // Table may not exist during install/migration — keep buffered
            Log::warning("[Manager Core] Could not flush capabilities for {$pluginName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a plugin has a specific capability
     *
     * @param string $pluginName
     * @param string $capability
     * @return bool
     */
    public function hasCapability($pluginName, $capability)
    {
        return isset($this->capabilities[$pluginName][$capability]);
    }

    /**
     * Call a plugin capability (lenient — returns null on missing or thrown).
     *
     * For backward compatibility, this swallows both "capability not registered"
     * AND "capability handler threw" and returns null. Callers that need to
     * distinguish should use callOrFail() (throws) or check getLastError()
     * after a null return.
     *
     * @param string $pluginName
     * @param string $capability
     * @param mixed ...$args
     * @return mixed
     */
    public function call($pluginName, $capability, ...$args)
    {
        $this->lastError = null;

        try {
            return $this->callOrFail($pluginName, $capability, ...$args);
        } catch (CapabilityNotFoundException $e) {
            $this->lastError = $e;
            Log::warning("[Manager Core] Capability '{$capability}' not found for plugin '{$pluginName}'");
            return null;
        } catch (CapabilityCallException $e) {
            $this->lastError = $e;
            Log::error("[Manager Core] Error calling {$pluginName}.{$capability}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Call a plugin capability, throwing on missing or handler errors.
     *
     * - CapabilityNotFoundException: capability is not registered for this plugin
     * - CapabilityCallException: capability handler threw (wraps original exception)
     *
     * Use this when you need to react differently to missing-vs-error, such as
     * in retry logic or when the EventBus needs to record genuine handler failures.
     *
     * @param string $pluginName
     * @param string $capability
     * @param mixed ...$args
     * @return mixed
     * @throws CapabilityNotFoundException
     * @throws CapabilityCallException
     */
    public function callOrFail($pluginName, $capability, ...$args)
    {
        if (!$this->hasCapability($pluginName, $capability)) {
            throw new CapabilityNotFoundException($pluginName, $capability);
        }

        try {
            $result = call_user_func($this->capabilities[$pluginName][$capability], ...$args);
            // L4: record actual activity (capability invoked successfully). Throttled
            // to avoid hammering the DB on hot capabilities.
            $this->touchActivity($pluginName);
            return $result;
        } catch (\Throwable $e) {
            throw new CapabilityCallException($pluginName, $capability, $e);
        }
    }

    /**
     * L4: Touch a plugin's last_seen_at on real activity (capability invocation).
     * Throttled to once per minute per plugin per process to avoid write
     * pressure on hot paths.
     */
    protected function touchActivity(string $pluginName): void
    {
        $cacheKey = 'mc_bridge_activity_' . $pluginName;

        try {
            $stamp = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($stamp && (time() - $stamp) < 60) {
                return;
            }

            \Illuminate\Support\Facades\Cache::put($cacheKey, time(), 120);

            PluginRegistry::where('plugin_name', $pluginName)
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable $e) {
            // Non-fatal — don't let activity tracking break dispatch
        }
    }

    /**
     * Get the last error from a call() that returned null
     *
     * @return \Throwable|null
     */
    public function getLastError(): ?\Throwable
    {
        return $this->lastError;
    }

    /**
     * @deprecated Use the EventBus pub/sub system instead.
     *
     * notify() was the original point-to-point notification mechanism but no
     * plugin in the ecosystem registers a 'notify' capability — every consumer
     * uses EventBus::publish() / subscribe() / subscribeHandler() now. This
     * method is kept as a no-op back-compat shim and may be removed in a
     * future release.
     *
     * Migration: replace
     *     $bridge->notify('seat-discord-pings', 'mining.tax', ['amount' => 1234])
     * with
     *     $eventBus->publish('mining.tax_created', 'mining-manager', ['amount' => 1234])
     *
     * Returns false instead of null so existing consumer code that checks the
     * return value still behaves consistently.
     *
     * @param string $pluginName
     * @param string $type
     * @param array $data
     * @return bool
     */
    public function notify($pluginName, $type, array $data)
    {
        if ($this->hasPlugin($pluginName) && $this->hasCapability($pluginName, 'notify')) {
            return $this->call($pluginName, 'notify', $type, $data);
        }

        return false;
    }

    /**
     * Publish an event through the EventBus
     *
     * Convenience method so plugins can use the bridge they already have.
     *
     * @param string $eventName e.g., 'mining.tax_created'
     * @param string $publisherPlugin e.g., 'mining-manager'
     * @param array $payload
     * @return array Results summary
     */
    public function publishEvent(string $eventName, string $publisherPlugin, array $payload = []): array
    {
        try {
            $eventBus = app(EventBus::class);

            return $eventBus->publish($eventName, $publisherPlugin, $payload);
        } catch (\Exception $e) {
            Log::error("[Manager Core] Failed to publish event: " . $e->getMessage());
            return ['dispatched' => 0, 'failed' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Clear plugin discovery cache
     *
     * @return void
     */
    public function clearCache()
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('[Manager Core] Plugin Bridge cache cleared');
    }

    /**
     * Get plugin statistics
     *
     * @return array
     */
    public function getStatistics()
    {
        $installed = array_filter($this->plugins, fn($p) => $p['installed'] ?? false);
        $active = array_filter($this->plugins, fn($p) => ($p['status'] ?? '') === 'active');

        // 6-state breakdown for the bridge dashboard's stat boxes.
        $stateCounts = [
            'full' => 0, 'partial' => 0, 'discovered' => 0,
            'standalone' => 0, 'offline' => 0, 'error' => 0,
        ];
        foreach ($this->plugins as $p) {
            $s = $p['state'] ?? 'offline';
            if (isset($stateCounts[$s])) {
                $stateCounts[$s]++;
            }
        }

        return [
            'total_plugins' => count($this->plugins),
            'installed_plugins' => count($installed),
            'active_plugins' => count($active),
            'total_capabilities' => array_sum(array_map('count', $this->capabilities)),
            'total_subscriptions' => array_sum(array_column($this->plugins, 'subscription_count')),
            'state_counts' => $stateCounts,
            'plugins' => $this->plugins,
        ];
    }
}
