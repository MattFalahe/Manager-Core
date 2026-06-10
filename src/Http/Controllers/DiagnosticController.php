<?php

namespace ManagerCore\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Models\ApiToken;
use ManagerCore\Models\Market;
use ManagerCore\Models\EventLog;
use ManagerCore\Models\EventSubscription;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\PluginRegistry;
use ManagerCore\Models\Setting;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Services\EventBus;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Services\PricingService;
use ManagerCore\Services\SdeService;
use ManagerCore\Services\Watchdog\WatchdogService;

class DiagnosticController extends Controller
{
    protected PluginBridge $bridge;
    protected PricingService $pricing;
    protected SdeService $sde;
    protected EventBus $eventBus;

    public function __construct(PluginBridge $bridge, PricingService $pricing, SdeService $sde, EventBus $eventBus)
    {
        $this->bridge = $bridge;
        $this->pricing = $pricing;
        $this->sde = $sde;
        $this->eventBus = $eventBus;
    }

    /**
     * GET /diagnostic - Main diagnostic page
     */
    public function index()
    {
        // Wrap queries in try/catch so the page loads even if migrations are pending
        $safe = function ($query, $default = 0) {
            try { return $query(); } catch (\Exception $e) { return $default; }
        };

        $summary = [
            'price_records' => $safe(fn() => MarketPrice::count()),
            'unique_types' => $safe(fn() => MarketPrice::distinct('type_id')->count('type_id')),
            'subscriptions' => $safe(fn() => TypeSubscription::count()),
            'subscribing_plugins' => $safe(fn() => TypeSubscription::distinct('plugin_name')->count('plugin_name')),
            'active_plugins' => $safe(fn() => PluginRegistry::where('is_active', true)->count()),
            'total_plugins' => count(config('manager-core.bridge.compatible_plugins', [])),
            'event_subscriptions' => $safe(fn() => EventSubscription::active()->count()),
            'events_today' => $safe(fn() => EventLog::where('created_at', '>=', now()->startOfDay())->count()),
            'api_tokens' => $safe(fn() => ApiToken::where('is_active', true)->count()),
            'history_records' => $safe(fn() => PriceHistory::count()),
            'latest_price_update' => $safe(fn() => MarketPrice::max('updated_at'), null),
            // No more "default provider" — resolution is per-market via the
            // markets table. Surface a count of distinct providers in use
            // so operators can see at a glance "is my install using just
            // Fuzzwork, or also Goonpraisal for my nullsec markets?"
            'providers_in_use' => $safe(fn() => Market::query()->whereNotNull('provider')->distinct()->pluck('provider')->values()->all(), []),
        ];

        $plugins = config('manager-core.bridge.compatible_plugins', []);
        // Diagnostic view shows every configured market regardless of
        // enable state — operators want to see "this market is disabled"
        // diagnostics too, not just hide them.
        $markets = Market::getEffectiveMarkets(false);

        return view('manager-core::diagnostic.index', compact('summary', 'plugins', 'markets'));
    }

    /**
     * GET /diagnostic/system-overview - Detailed system overview
     */
    public function systemOverview(): JsonResponse
    {
        $now = Carbon::now();

        $priceAge = [
            'fresh' => MarketPrice::where('updated_at', '>', $now->copy()->subHour())->count(),
            'recent' => MarketPrice::whereBetween('updated_at', [$now->copy()->subHours(4), $now->copy()->subHour()])->count(),
            'stale' => MarketPrice::whereBetween('updated_at', [$now->copy()->subDay(), $now->copy()->subHours(4)])->count(),
            'very_stale' => MarketPrice::where('updated_at', '<', $now->copy()->subDay())->count(),
        ];

        $historyRange = [
            'oldest' => PriceHistory::min('date'),
            'newest' => PriceHistory::max('date'),
            'total' => PriceHistory::count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'database' => DB::connection()->getDatabaseName(),
                'cache_driver' => config('cache.default'),
                // Removed 'price_provider' (the global default concept) —
                // see Markets count + providers_in_use on the overview card.
                'providers_in_use' => Market::query()->whereNotNull('provider')->distinct()->pluck('provider')->values()->all(),
                'update_frequency' => $this->resolvePriceRefreshIntervalMinutes() . ' minutes',
                'cache_duration' => config('manager-core.cache.prices_duration', 60) . ' minutes',
                'price_age' => $priceAge,
                'history' => $historyRange,
                'markets' => array_keys(Market::getEffectiveMarkets(false)),
            ],
        ]);
    }

    /**
     * POST /diagnostic/test-plugin/{plugin} - Test specific plugin connection
     */
    public function testPluginConnection(string $pluginKey): JsonResponse
    {
        $start = microtime(true);
        $config = config("manager-core.bridge.compatible_plugins.{$pluginKey}");

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => "Unknown plugin: {$pluginKey}",
            ]);
        }

        $result = [
            'plugin' => $pluginKey,
            'name' => $config['name'],
            'class' => $config['class'],
            'installed' => false,
            'class_exists' => false,
            'bridge_registered' => false,
            'capabilities' => [],
            'subscription_count' => 0,
            'status' => 'not_installed',
            'state' => null,
            'state_reason' => null,
            'integration' => [],
            'issues' => [],
        ];

        // Check if class exists
        $result['class_exists'] = class_exists($config['class']);
        $result['installed'] = $result['class_exists'];

        if (!$result['installed']) {
            $result['status'] = 'not_installed';
            $result['issues'][] = "Service provider class not found: {$config['class']}";
        } else {
            // Check bridge registration
            $bridgePlugin = $this->bridge->getPlugin($pluginKey);
            $result['bridge_registered'] = $bridgePlugin !== null;

            if (!$result['bridge_registered']) {
                $result['issues'][] = 'Plugin installed but not registered with Plugin Bridge';
                $result['status'] = 'installed';
            } else {
                // Status + granular 6-state come straight from the Plugin
                // Bridge, which is the single source of truth. The bridge
                // already weighs every integration channel (pricing subs,
                // event subs, ESI handlers, recent publishing, and outbound
                // publish relationships) to arrive at full/partial/discovered/
                // standalone. Do NOT recompute status here from pricing
                // subscriptions alone — that's the pre-6-state logic that
                // wrongly forced any plugin without a pricing sub (CWM, SM,
                // HR, SeAT Broadcast, Blueprint) back to "installed" even
                // when it was actively integrating via events/capabilities.
                $result['status'] = $bridgePlugin['status'] ?? 'installed';
                $result['state'] = $bridgePlugin['state'] ?? null;
                $result['state_reason'] = $bridgePlugin['state_reason'] ?? null;
                $result['integration'] = $bridgePlugin['integration'] ?? [];
            }

            // Pricing-subscription count. Informational only — surfaced in the
            // details panel, NOT used to drive the status badge. Only the
            // plugins with a subscription_name (Mining Manager, Buyback
            // Manager) route pricing through type-ID subscriptions; the rest
            // integrate via other channels and legitimately have zero here.
            $subName = $config['subscription_name'] ?? null;
            if ($subName) {
                $result['subscription_count'] = TypeSubscription::where('plugin_name', $subName)->count();
                if ($result['subscription_count'] === 0) {
                    $result['issues'][] = 'No pricing type subscriptions registered — this plugin is configured to use Manager Core pricing but has not subscribed any type IDs yet.';
                }
            }

            // Check plugin registry for capabilities
            $registry = PluginRegistry::where('plugin_name', $pluginKey)->first();
            if ($registry) {
                $result['capabilities'] = $registry->capabilities ?? [];
                $result['last_seen'] = $registry->last_seen_at ? $registry->last_seen_at->diffForHumans() : 'Never';
            }
        }

        $result['duration_ms'] = round((microtime(true) - $start) * 1000, 2);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * POST /diagnostic/test-all-plugins - Test all configured plugins
     */
    public function testAllPlugins(): JsonResponse
    {
        $plugins = config('manager-core.bridge.compatible_plugins', []);
        $results = [];

        foreach ($plugins as $key => $config) {
            $response = $this->testPluginConnection($key);
            $data = json_decode($response->getContent(), true);
            $results[$key] = $data['data'] ?? ['status' => 'error'];
        }

        $summary = [
            'total' => count($results),
            'active' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'active')),
            'installed' => count(array_filter($results, fn($r) => ($r['installed'] ?? false))),
            'not_installed' => count(array_filter($results, fn($r) => !($r['installed'] ?? false))),
        ];

        return response()->json(['success' => true, 'data' => ['plugins' => $results, 'summary' => $summary]]);
    }

    /**
     * POST /diagnostic/test-price-provider - Test a price provider
     */
    public function testPriceProvider(Request $request): JsonResponse
    {
        // No "global default" anymore — fall back to 'fuzzwork' literal
        // when the request doesn't specify a provider (the dropdown always
        // sends one, but defensive default for direct API calls).
        $provider = $request->input('provider', 'fuzzwork');
        $market = $request->input('market', 'jita');

        // Validate provider — keep in sync with PricingService::getPriceProvider
        // switch arms and Master Test's $valid array.
        $validProviders = ['esi', 'janice', 'fuzzwork', 'goonpraisal', 'seat'];
        if (!in_array($provider, $validProviders, true)) {
            return response()->json([
                'success' => false,
                'message' => "Unknown price provider '{$provider}'. Valid: " . implode(', ', $validProviders),
            ]);
        }

        // Validate market exists
        $marketConfig = Market::getMarketConfig($market);
        if (!$marketConfig) {
            return response()->json([
                'success' => false,
                'message' => "Unknown market: {$market}",
            ]);
        }

        // Test type IDs: Tritanium, Pyerite, Mexallon, Isogen, Nocxium, Veldspar
        $testTypeIds = [34, 35, 36, 37, 38, 1230];
        $start = microtime(true);

        try {
            // Pass the dropdown selection as the provider override so the
            // test actually exercises the requested provider, not whichever
            // one the per-market routing resolves to. Otherwise the dropdown
            // is purely decorative — the test always runs whichever provider
            // the markets row says, defeating the point of the picker.
            $this->pricing->updatePrices($market, $testTypeIds, $provider);
            $duration = round((microtime(true) - $start) * 1000, 2);

            // Check what was stored
            $results = [];
            $successCount = 0;
            $typeNames = $this->sde->typeNames($testTypeIds);

            foreach ($testTypeIds as $typeId) {
                $price = MarketPrice::where('type_id', $typeId)
                    ->where('market', $market)
                    ->where('price_type', 'sell')
                    ->first();

                $result = [
                    'type_id' => $typeId,
                    'type_name' => $typeNames[$typeId] ?? 'Unknown',
                    'has_price' => $price !== null,
                    'price_avg' => $price ? (float) $price->price_avg : null,
                    'price_min' => $price ? (float) $price->price_min : null,
                    'updated_at' => $price ? $price->updated_at->toIso8601String() : null,
                ];

                if ($price) {
                    $successCount++;
                }

                $results[] = $result;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => $provider,
                    'market' => $market,
                    'market_name' => $marketConfig['name'] ?? $market,
                    'region_id' => $marketConfig['region_id'] ?? null,
                    'duration_ms' => $duration,
                    'total_items' => count($testTypeIds),
                    'successful_items' => $successCount,
                    'results' => $results,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Price provider test failed: ' . $e->getMessage(),
                'data' => [
                    'provider' => $provider,
                    'market' => $market,
                    'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                    'error' => $e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * POST /diagnostic/test-market - Test a specific market's ESI connectivity and price availability
     */
    public function testMarket(Request $request): JsonResponse
    {
        $marketKey = $request->input('market');

        if (!$marketKey) {
            return response()->json(['success' => false, 'message' => 'Market key is required']);
        }

        $marketConfig = Market::getMarketConfig($marketKey);
        if (!$marketConfig) {
            return response()->json(['success' => false, 'message' => "Market '{$marketKey}' not found"]);
        }

        $regionId = $marketConfig['region_id'];
        $systemIds = $marketConfig['system_ids'] ?? [];
        $baseUrl = config('manager-core.esi.base_url', 'https://esi.evetech.net/latest');
        $timeout = config('manager-core.esi.timeout', 30);

        $tests = [];

        // Test 1: Can ESI reach this region?
        $start = microtime(true);
        try {
            $response = Http::connectTimeout(5)->timeout($timeout)->get("{$baseUrl}/markets/{$regionId}/orders/", [
                'datasource' => 'tranquility',
                'order_type' => 'sell',
                'type_id' => 34, // Tritanium
                'page' => 1,
            ]);

            $orders = $response->successful() ? $response->json() : [];
            $totalPages = (int) $response->header('X-Pages', 1);

            // Filter by system if specified
            $filteredOrders = $orders;
            if (!empty($systemIds)) {
                $filteredOrders = array_filter($orders, fn($o) => in_array($o['system_id'] ?? 0, $systemIds));
            }

            $tests['region_access'] = [
                'test' => "ESI region access (region {$regionId})",
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                'total_orders' => count($orders),
                'total_pages' => $totalPages,
                'filtered_orders' => count($filteredOrders),
                'system_filter' => !empty($systemIds) ? implode(', ', $systemIds) : 'None (whole region)',
            ];
        } catch (\Exception $e) {
            $tests['region_access'] = [
                'test' => "ESI region access (region {$regionId})",
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        // Test 2: Fetch prices for common items in this market
        $testTypeIds = [34, 35, 36, 37, 38]; // Tritanium through Nocxium
        $start = microtime(true);
        try {
            $this->pricing->updatePrices($marketKey, $testTypeIds);
            $fetchDuration = round((microtime(true) - $start) * 1000, 2);

            $typeNames = $this->sde->typeNames($testTypeIds);
            $priceResults = [];
            $withPrices = 0;

            foreach ($testTypeIds as $typeId) {
                $sell = MarketPrice::where('type_id', $typeId)->where('market', $marketKey)->where('price_type', 'sell')->first();
                $buy = MarketPrice::where('type_id', $typeId)->where('market', $marketKey)->where('price_type', 'buy')->first();

                $hasSell = $sell && $sell->price_avg > 0;
                $hasBuy = $buy && $buy->price_avg > 0;
                if ($hasSell || $hasBuy) $withPrices++;

                $priceResults[] = [
                    'type_id' => $typeId,
                    'type_name' => $typeNames[$typeId] ?? 'Unknown',
                    'sell_avg' => $hasSell ? (float) $sell->price_avg : null,
                    'sell_volume' => $hasSell ? $sell->volume : 0,
                    'sell_orders' => $hasSell ? $sell->order_count : 0,
                    'buy_avg' => $hasBuy ? (float) $buy->price_avg : null,
                    'buy_volume' => $hasBuy ? $buy->volume : 0,
                    'buy_orders' => $hasBuy ? $buy->order_count : 0,
                ];
            }

            $tests['price_fetch'] = [
                'test' => 'Price fetch for 5 mineral types',
                'success' => $withPrices > 0,
                'duration_ms' => $fetchDuration,
                'types_with_prices' => $withPrices,
                'types_tested' => count($testTypeIds),
                'prices' => $priceResults,
            ];
        } catch (\Exception $e) {
            $tests['price_fetch'] = [
                'test' => 'Price fetch for 5 mineral types',
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        // Test 3: Compare with Jita (if this isn't Jita)
        if ($marketKey !== 'jita') {
            $jitaPrices = MarketPrice::where('market', 'jita')->where('price_type', 'sell')->whereIn('type_id', $testTypeIds)->pluck('price_avg', 'type_id');
            $localPrices = MarketPrice::where('market', $marketKey)->where('price_type', 'sell')->whereIn('type_id', $testTypeIds)->pluck('price_avg', 'type_id');

            $comparisons = [];
            foreach ($testTypeIds as $typeId) {
                $jitaPrice = (float) ($jitaPrices[$typeId] ?? 0);
                $localPrice = (float) ($localPrices[$typeId] ?? 0);
                $diff = $jitaPrice > 0 ? round((($localPrice - $jitaPrice) / $jitaPrice) * 100, 1) : null;

                $comparisons[] = [
                    'type_id' => $typeId,
                    'jita_price' => $jitaPrice > 0 ? $jitaPrice : null,
                    'local_price' => $localPrice > 0 ? $localPrice : null,
                    'diff_percent' => $diff,
                ];
            }

            $tests['jita_comparison'] = [
                'test' => 'Price comparison vs Jita',
                'success' => true,
                'comparisons' => $comparisons,
            ];
        }

        $allPassed = collect($tests)->every(fn($t) => $t['success'] ?? false);

        return response()->json([
            'success' => true,
            'data' => [
                'market' => $marketKey,
                'market_name' => $marketConfig['name'] ?? $marketKey,
                'region_id' => $regionId,
                'system_ids' => $systemIds,
                'is_custom' => $marketConfig['is_custom'] ?? false,
                'overall' => $allPassed ? 'healthy' : ($tests['region_access']['success'] ?? false ? 'partial' : 'failed'),
                'tests' => $tests,
            ],
        ]);
    }

    /**
     * GET /diagnostic/subscription-health - Check subscription health
     */
    public function subscriptionHealth(): JsonResponse
    {
        $staleThreshold = Carbon::now()->subHours(6);
        $markets = TypeSubscription::distinct('market')->pluck('market');
        $results = [];

        foreach ($markets as $market) {
            $subscribed = TypeSubscription::where('market', $market)
                ->distinct('type_id')
                ->pluck('type_id');

            $withPrices = MarketPrice::where('market', $market)
                ->whereIn('type_id', $subscribed)
                ->pluck('type_id');

            $stale = MarketPrice::where('market', $market)
                ->whereIn('type_id', $subscribed)
                ->where('updated_at', '<', $staleThreshold)
                ->pluck('type_id');

            $missing = $subscribed->diff($withPrices);

            $results[$market] = [
                'total_subscribed' => $subscribed->count(),
                'with_prices' => $withPrices->count(),
                'fresh' => $withPrices->count() - $stale->count(),
                'stale' => $stale->count(),
                'missing' => $missing->count(),
                'missing_type_ids' => $missing->take(20)->values()->toArray(),
                'health' => $missing->isEmpty() && $stale->isEmpty() ? 'healthy' : ($missing->count() > 10 ? 'critical' : 'warning'),
            ];
        }

        // Per-plugin summary
        $pluginSummary = TypeSubscription::select('plugin_name', 'market', DB::raw('COUNT(*) as count'))
            ->groupBy('plugin_name', 'market')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'markets' => $results,
                'plugins' => $pluginSummary,
                'total_subscriptions' => TypeSubscription::count(),
                'total_unique_types' => TypeSubscription::distinct('type_id')->count('type_id'),
            ],
        ]);
    }

    /**
     * GET /diagnostic/cache-health - Cache health
     */
    public function cacheHealth(): JsonResponse
    {
        $cacheWorks = false;
        try {
            $key = 'mc_diag_test_' . time();
            Cache::put($key, 'test', 60);
            $cacheWorks = Cache::get($key) === 'test';
            Cache::forget($key);
        } catch (\Exception $e) {
            // Cache test failed
        }

        $now = Carbon::now();

        return response()->json([
            'success' => true,
            'data' => [
                'cache_driver' => config('cache.default'),
                'cache_works' => $cacheWorks,
                'prices' => [
                    'total' => MarketPrice::count(),
                    'fresh_1h' => MarketPrice::where('updated_at', '>', $now->copy()->subHour())->count(),
                    'stale_4h' => MarketPrice::where('updated_at', '<', $now->copy()->subHours(4))->count(),
                    'stale_24h' => MarketPrice::where('updated_at', '<', $now->copy()->subDay())->count(),
                    'latest' => MarketPrice::max('updated_at'),
                    'oldest' => MarketPrice::min('updated_at'),
                ],
                'history' => [
                    'total' => PriceHistory::count(),
                    'date_range' => [
                        'oldest' => PriceHistory::min('date'),
                        'newest' => PriceHistory::max('date'),
                    ],
                    'retention_days' => config('manager-core.pricing.history_retention_days', 90),
                ],
                'sde_cache_ttl' => config('manager-core.cache.type_db_duration', 1440) . ' minutes',
                'price_cache_ttl' => config('manager-core.cache.prices_duration', 60) . ' minutes',
            ],
        ]);
    }

    /**
     * GET /diagnostic/event-bus-health - Event bus status
     */
    public function eventBusHealth(): JsonResponse
    {
        $stats = $this->eventBus->getStatistics();
        $recentEvents = $this->eventBus->getEventLog(20);
        $subscriptions = $this->eventBus->getAllSubscriptions();

        return response()->json([
            'success' => true,
            'data' => [
                'statistics' => $stats,
                'subscriptions' => $subscriptions->map(function ($sub) {
                    return [
                        'subscriber_plugin' => $sub->subscriber_plugin,
                        'event_pattern' => $sub->event_pattern,
                        'handler' => $sub->handler_class ? $sub->handler_class . '@' . $sub->handler_method : $sub->handler_capability,
                        'is_queued' => $sub->is_queued,
                        'priority' => $sub->priority,
                        'is_active' => $sub->is_active,
                    ];
                }),
                'recent_events' => $recentEvents->map(function ($log) {
                    return [
                        'event_name' => $log->event_name,
                        'publisher' => $log->publisher_plugin,
                        'subscriber_count' => $log->subscriber_count,
                        'status' => $log->status,
                        'created_at' => $log->created_at ? $log->created_at->diffForHumans() : null,
                    ];
                }),
            ],
        ]);
    }

    /**
     * GET /diagnostic/integration-matrix — the cross-plugin communication graph.
     *
     * Joins the three data sources MC already has into one topic-centric view
     * so an operator can answer "is my ecosystem actually talking?" at a glance,
     * instead of mentally cross-referencing the subscriptions list against the
     * recent-events list:
     *
     *   - Topics::all()                       → who PUBLISHES each topic
     *   - manager_core_event_subscriptions    → who SUBSCRIBES (wildcard-matched
     *                                            with fnmatch, the same matcher
     *                                            EventBus uses to dispatch)
     *   - manager_core_event_log              → last published + last delivered
     *
     * Status per topic:
     *   flowing          — has subscribers AND a clean delivery on record
     *   wired_idle       — has subscribers but no successful delivery on record
     *                      yet. Either never published, OR the publishes that
     *                      exist predate the subscription / reached zero
     *                      subscribers at the time (e.g. the event fired once
     *                      before the consumer plugin was installed, and hasn't
     *                      fired since). Benign — normal for rare events like
     *                      member milestones / blueprint requests.
     *   failing          — there is an ACTUAL failed dispatch on record
     *                      (status 'failed' / 'partial_failure') that hasn't been
     *                      superseded by a later clean delivery. This is the only
     *                      "something is broken" state — absence of a delivery
     *                      record alone is NOT failing (that's wired_idle).
     *   orphan_publisher — fires but nobody subscribes (often intentional
     *                      forward-registration; the publish side shipped first)
     *   dormant          — registered in Topics but never published, no subscribers
     */
    public function integrationMatrix(): JsonResponse
    {
        try {
            // 1. Registry: topic => publisher (+ metadata we don't need here)
            $registry = \ManagerCore\Topics::all();

            // 2. Active subscriptions (pattern strings, wildcard or exact)
            $subs = DB::table('manager_core_event_subscriptions')
                ->where('is_active', true)
                ->get(['subscriber_plugin', 'event_pattern']);

            // 3. event_log aggregates per event_name. Three distinct signals so
            //    we don't mistake "reached nobody" for "delivery failed":
            //    - last_published : most recent publish of any kind
            //    - last_delivered : most recent CLEAN dispatch that reached ≥1
            //                       subscriber (status 'dispatched', count > 0).
            //                       A publish that reached zero subscribers
            //                       (count = 0, e.g. fired before the consumer
            //                       was subscribed) is NOT a delivery.
            //    - last_failure   : most recent ACTUAL failure (status 'failed'
            //                       or 'partial_failure'). This is what drives
            //                       the 'failing' classification — not the mere
            //                       absence of a delivery record.
            $logAgg = DB::table('manager_core_event_log')
                ->select(
                    'event_name',
                    DB::raw('MAX(created_at) as last_published'),
                    DB::raw("MAX(CASE WHEN status = 'dispatched' AND subscriber_count > 0 THEN created_at ELSE NULL END) as last_delivered"),
                    DB::raw("MAX(CASE WHEN status IN ('failed', 'partial_failure') THEN created_at ELSE NULL END) as last_failure")
                )
                ->groupBy('event_name')
                ->get()
                ->keyBy('event_name');

            $rows = [];
            $summary = ['flowing' => 0, 'wired_idle' => 0, 'failing' => 0, 'orphan_publisher' => 0, 'dormant' => 0];

            foreach ($registry as $topic => $meta) {
                $publisher = is_array($meta) ? ($meta['publisher'] ?? 'unknown') : 'unknown';

                // Match subscribers via fnmatch (member.* matches member.contribution.*).
                // Exclude a plugin subscribing to its own published topic — that
                // isn't a cross-plugin link.
                $subscribers = [];
                foreach ($subs as $s) {
                    if ($s->subscriber_plugin === $publisher) {
                        continue;
                    }
                    if (@fnmatch((string) $s->event_pattern, (string) $topic)) {
                        $subscribers[$s->subscriber_plugin] = true;
                    }
                }
                $subscribers = array_keys($subscribers);

                $log = $logAgg[$topic] ?? null;
                $lastPublished = $log->last_published ?? null;
                $lastDelivered = $log->last_delivered ?? null;
                $lastFailure   = $log->last_failure ?? null;

                if (empty($subscribers)) {
                    $status = $lastPublished ? 'orphan_publisher' : 'dormant';
                } elseif ($lastFailure !== null && ($lastDelivered === null || $lastFailure >= $lastDelivered)) {
                    // A genuine failed/partial dispatch that a later clean
                    // delivery hasn't superseded. Datetime strings compare
                    // chronologically (YYYY-MM-DD HH:MM:SS). This is the ONLY
                    // path to 'failing' — absence of delivery alone is benign.
                    $status = 'failing';
                } elseif ($lastDelivered !== null) {
                    $status = 'flowing';
                } else {
                    // Subscribed, no clean delivery and no failure on record.
                    // The publishes that exist (if any) reached zero subscribers
                    // at the time — they predate this subscription, or the event
                    // simply hasn't fired since the consumer started listening.
                    $status = 'wired_idle';
                }
                $summary[$status]++;

                $rows[] = [
                    'topic' => $topic,
                    'publisher' => $publisher,
                    'subscribers' => $subscribers,
                    'last_published' => $lastPublished ? Carbon::parse($lastPublished)->diffForHumans() : null,
                    'last_delivered' => $lastDelivered ? Carbon::parse($lastDelivered)->diffForHumans() : null,
                    'status' => $status,
                ];
            }

            // Sort worst-first so problems surface at the top: failing, then
            // orphan publishers, then the healthy/idle/dormant rows.
            $order = ['failing' => 0, 'orphan_publisher' => 1, 'flowing' => 2, 'wired_idle' => 3, 'dormant' => 4];
            usort($rows, function ($a, $b) use ($order) {
                $cmp = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
                return $cmp !== 0 ? $cmp : strcmp($a['topic'], $b['topic']);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'rows' => $rows,
                    'summary' => $summary,
                    'total' => count($rows),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to build integration matrix: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /diagnostic/capabilities — capability inventory across all plugins.
     *
     * Surfaces the bridge.discoverCapabilities introspection in the diagnostic
     * UI so operators can see at a glance:
     *   - Which plugins have registered capabilities with the bridge
     *   - Which capability names are available (so they can be called from
     *     other plugins, the REST API, custom scripts, etc.)
     *   - Whether MC's own capability surface is complete
     *
     * Cross-references in-memory + persisted (PluginRegistry) capabilities so
     * a plugin that registered capabilities this request but isn't yet in the
     * registry still shows up.
     */
    public function capabilitiesOverview(): JsonResponse
    {
        $persisted = [];
        try {
            $persisted = $this->bridge->call('ManagerCore', 'bridge.discoverCapabilities') ?? [];
        } catch (\Throwable $e) {
            $persisted = [];
        }

        // Also reflect from getStatistics() for in-memory-only state
        $stats = $this->bridge->getStatistics();
        $inMemoryByPlugin = [];
        foreach ($stats['plugins'] ?? [] as $pluginKey => $pluginInfo) {
            $inMemoryByPlugin[$pluginKey] = []; // statistics doesn't carry capability names; covered by $persisted
        }

        // Union — keep one entry per plugin with capability names sorted
        $byPlugin = [];
        foreach ($persisted as $plugin => $caps) {
            $caps = is_array($caps) ? $caps : [];
            sort($caps);
            $byPlugin[$plugin] = [
                'plugin' => $plugin,
                'capabilities' => $caps,
                'capability_count' => count($caps),
            ];
        }

        // Sort plugins alphabetically for stable display
        ksort($byPlugin);

        return response()->json([
            'success' => true,
            'data' => [
                'plugins' => array_values($byPlugin),
                'total_plugins_with_capabilities' => count($byPlugin),
                'total_capabilities' => array_sum(array_map(fn($p) => $p['capability_count'], $byPlugin)),
            ],
        ]);
    }

    /**
     * POST /diagnostic/test-event - Publish a test event
     */
    public function testEventPublish(): JsonResponse
    {
        $result = $this->eventBus->publish('manager-core.diagnostic.test', 'manager-core', [
            'test' => true,
            'timestamp' => now()->toIso8601String(),
            'triggered_by' => auth()->user()->name ?? 'Unknown',
        ]);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => "Test event published: {$result['dispatched']} dispatched, {$result['failed']} failed",
        ]);
    }

    /**
     * GET /diagnostic/api-health - API token health
     */
    public function apiHealth(): JsonResponse
    {
        $tokens = ApiToken::withCount([])->get();
        $activeTokens = $tokens->where('is_active', true);
        $recentlyUsed = $tokens->where('last_used_at', '!=', null)
            ->sortByDesc('last_used_at')
            ->take(10);

        return response()->json([
            'success' => true,
            'data' => [
                'total_tokens' => $tokens->count(),
                'active_tokens' => $activeTokens->count(),
                'expired_tokens' => $tokens->filter(fn($t) => $t->expires_at && $t->expires_at->isPast())->count(),
                'recently_used' => $recentlyUsed->map(function ($token) {
                    return [
                        'name' => $token->name,
                        'prefix' => $token->token_prefix,
                        'last_used' => $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never',
                        'last_ip' => $token->last_used_ip,
                        'rate_limit' => $token->rate_limit,
                        'is_active' => $token->is_active,
                    ];
                })->values(),
                'max_per_user' => config('manager-core.api.max_tokens_per_user', 5),
                'default_rate_limit' => config('manager-core.api.default_rate_limit', 60),
            ],
        ]);
    }

    /**
     * POST /diagnostic/test-esi - Test ESI connectivity
     */
    public function testEsiConnectivity(): JsonResponse
    {
        $baseUrl = config('manager-core.esi.base_url', 'https://esi.evetech.net/latest');
        $timeout = config('manager-core.esi.timeout', 30);

        $tests = [];

        // Test 1: ESI status endpoint
        $start = microtime(true);
        try {
            $response = Http::connectTimeout(5)->timeout($timeout)->get("{$baseUrl}/status/?datasource=tranquility");
            $tests['status'] = [
                'endpoint' => '/status/',
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                'data' => $response->successful() ? $response->json() : null,
            ];
        } catch (\Exception $e) {
            $tests['status'] = [
                'endpoint' => '/status/',
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        // Test 2: Market orders for Tritanium in Jita region
        $start = microtime(true);
        try {
            $response = Http::connectTimeout(5)->timeout($timeout)->get("{$baseUrl}/markets/10000002/orders/?datasource=tranquility&order_type=sell&type_id=34&page=1");
            $orderCount = $response->successful() ? count($response->json()) : 0;
            $tests['market_orders'] = [
                'endpoint' => '/markets/10000002/orders/ (Tritanium sell)',
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                'order_count' => $orderCount,
            ];
        } catch (\Exception $e) {
            $tests['market_orders'] = [
                'endpoint' => '/markets/10000002/orders/ (Tritanium sell)',
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        $allPassed = collect($tests)->every(fn($t) => $t['success'] ?? false);

        return response()->json([
            'success' => true,
            'data' => [
                'base_url' => $baseUrl,
                'overall' => $allPassed ? 'healthy' : 'issues_detected',
                'tests' => $tests,
            ],
        ]);
    }

    /**
     * GET /diagnostic/settings-health - Settings health
     */
    public function settingsHealth(): JsonResponse
    {
        $dbSettings = Setting::all()->keyBy('key');
        $config = config('manager-core');

        $settings = [];

        // Pricing settings — keep in sync with the Settings page form fields.
        // The 'pricing.provider' (global default) setting was removed in
        // v1.0.0; only credentials + chain configs remain (per-market routing
        // on the Markets page is the source of truth for which provider is
        // used for any given lookup).
        $pricingKeys = [
            'pricing.seat_provider' => ['config_path' => 'pricing.seat_provider', 'label' => 'SeAT Provider Chain'],
            'pricing.janice_api_key' => ['config_path' => 'pricing.janice.api_key', 'label' => 'Janice API Key (set/missing)'],
            'pricing.goonpraisal_contact_email' => ['config_path' => 'pricing.goonpraisal.contact_email', 'label' => 'Goonpraisal Contact Email'],
        ];

        foreach ($pricingKeys as $key => $meta) {
            $dbVal = $dbSettings->get($key);
            $configVal = data_get($config, $meta['config_path']);

            $settings[] = [
                'key' => $key,
                'label' => $meta['label'],
                'group' => 'Pricing',
                'value' => $dbVal ? $dbVal->value : $configVal,
                'source' => $dbVal ? 'database' : ($configVal !== null ? 'config' : 'default'),
            ];
        }

        // Cache settings
        $cacheKeys = [
            'cache.prices_duration' => 'Price Cache Duration (min)',
            'cache.type_db_duration' => 'SDE Cache Duration (min)',
            'cache.appraisal_duration' => 'Appraisal Cache Duration (min)',
        ];

        foreach ($cacheKeys as $path => $label) {
            $settings[] = [
                'key' => $path,
                'label' => $label,
                'group' => 'Cache',
                'value' => data_get($config, $path),
                'source' => 'config',
            ];
        }

        // API settings
        $apiKeys = [
            'api.default_rate_limit' => 'Default Rate Limit (req/min)',
            'api.max_tokens_per_user' => 'Max Tokens Per User',
            'api.token_prefix' => 'Token Prefix',
        ];

        foreach ($apiKeys as $path => $label) {
            $settings[] = [
                'key' => $path,
                'label' => $label,
                'group' => 'API',
                'value' => data_get($config, $path),
                'source' => 'config',
            ];
        }

        // Event settings
        $eventKeys = [
            'events.event_log_retention_days' => 'Event Log Retention (days)',
            'events.max_listeners_per_event' => 'Max Listeners Per Event',
        ];

        foreach ($eventKeys as $path => $label) {
            $settings[] = [
                'key' => $path,
                'label' => $label,
                'group' => 'Events',
                'value' => data_get($config, $path),
                'source' => 'config',
            ];
        }

        return response()->json(['success' => true, 'data' => ['settings' => $settings]]);
    }

    /**
     * GET /diagnostic/master-test — run every health check at once.
     *
     * One "is Manager Core healthy?" sweep. Each check returns pass / warn /
     * fail / info with a short message and optional detail. Reuses the same
     * queries the individual tabs run — this just gathers them behind one
     * button so an operator gets a single verdict.
     */
    public function masterTest(): JsonResponse
    {
        $checks = [];

        // Each check runs inside try/catch so one failure can't abort the sweep.
        $add = function (string $name, string $category, callable $fn) use (&$checks) {
            try {
                $checks[] = array_merge(['name' => $name, 'category' => $category], $fn());
            } catch (\Throwable $e) {
                $checks[] = [
                    'name' => $name,
                    'category' => $category,
                    'status' => 'fail',
                    'message' => 'Check threw an exception: ' . $e->getMessage(),
                ];
            }
        };

        // 1. Database connection
        $add('Database connection', 'Infrastructure', function () {
            $name = DB::connection()->getDatabaseName();
            DB::connection()->getPdo();
            return ['status' => 'pass', 'message' => "Connected to database '{$name}'."];
        });

        // 2. Core tables present
        $add('Core tables present', 'Infrastructure', function () {
            $required = [
                'manager_core_market_prices', 'manager_core_price_history',
                'manager_core_type_subscriptions', 'manager_core_appraisals',
                'manager_core_plugin_registry', 'manager_core_settings',
                'manager_core_markets', 'manager_core_event_subscriptions',
                'manager_core_event_log', 'manager_core_api_tokens',
            ];
            $missing = array_values(array_filter($required, fn($t) => !Schema::hasTable($t)));
            return empty($missing)
                ? ['status' => 'pass', 'message' => count($required) . ' core tables present.']
                : ['status' => 'fail', 'message' => count($missing) . ' table(s) missing — run migrations.', 'detail' => implode(', ', $missing)];
        });

        // 3. Cache read/write
        $add('Cache read/write', 'Infrastructure', function () {
            $key = 'mc_master_test_' . time();
            Cache::put($key, 'ok', 60);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $ok
                ? ['status' => 'pass', 'message' => 'Cache (' . config('cache.default') . ') read/write OK.']
                : ['status' => 'fail', 'message' => 'Cache read/write test failed on driver ' . config('cache.default') . '.'];
        });

        // 4. MC services resolve
        $add('Manager Core services', 'Infrastructure', function () {
            $services = [
                'EventBus' => EventBus::class,
                'PluginBridge' => PluginBridge::class,
                'PricingService' => PricingService::class,
                'SdeService' => SdeService::class,
            ];
            $bad = [];
            foreach ($services as $label => $cls) {
                if (!(app($cls) instanceof $cls)) {
                    $bad[] = $label;
                }
            }
            return empty($bad)
                ? ['status' => 'pass', 'message' => 'All 4 core services resolve from the container.']
                : ['status' => 'fail', 'message' => 'Service(s) failed to resolve: ' . implode(', ', $bad)];
        });

        // 5. SDE type lookups
        $add('SDE type lookups', 'Data', function () {
            $expect = [34 => 'Tritanium', 35 => 'Pyerite', 36 => 'Mexallon'];
            $names = $this->sde->typeNames(array_keys($expect));
            $bad = [];
            foreach ($expect as $id => $want) {
                if (($names[$id] ?? null) !== $want) {
                    $bad[] = $id;
                }
            }
            return empty($bad)
                ? ['status' => 'pass', 'message' => 'SDE resolves known mineral type IDs.']
                : ['status' => 'warn', 'message' => 'SDE did not resolve type ID(s): ' . implode(', ', $bad) . ' — the SDE may not be imported.'];
        });

        // 6. Per-market provider config — scans every market row and
        // validates each has a known provider value AND that any provider
        // requiring credentials (Janice key, etc.) has them. Replaces the
        // old "Price provider config" check that validated a single global
        // default — the global default concept was removed in v1.0.0.
        $add('Per-market provider config', 'Configuration', function () {
            // Keep in sync with PricingService::getPriceProvider's switch arms
            // and MarketsController::VALID_PROVIDERS. Five providers post the
            // 2026-05-27 third-party provider pivot.
            $valid = ['esi', 'janice', 'fuzzwork', 'goonpraisal', 'seat'];

            $markets = Market::where('is_enabled', true)->get(['key', 'name', 'provider']);
            if ($markets->isEmpty()) {
                return ['status' => 'warn', 'message' => 'No enabled markets — enable at least one on the Markets page so plugins have somewhere to read prices from.'];
            }

            $unknownProviders = [];
            $needsJaniceKey = false;
            $usingDefaultGoonpraisalEmail = false;
            $defaultEmail = 'mattfalahe@gmail.com';

            foreach ($markets as $m) {
                $p = $m->provider ?? '';
                if (!in_array($p, $valid, true)) {
                    $unknownProviders[] = $m->key . " => '" . $p . "'";
                }
                if ($p === 'janice') {
                    $needsJaniceKey = true;
                }
                if ($p === 'goonpraisal') {
                    $email = Setting::get('pricing.goonpraisal_contact_email')
                        ?? config('manager-core.pricing.goonpraisal.contact_email', $defaultEmail);
                    if ($email === $defaultEmail) {
                        $usingDefaultGoonpraisalEmail = true;
                    }
                }
            }

            if (!empty($unknownProviders)) {
                return ['status' => 'fail', 'message' => 'Market(s) using unknown provider: ' . implode(', ', $unknownProviders)];
            }

            if ($needsJaniceKey) {
                $key = Setting::get('pricing.janice_api_key') ?? config('manager-core.pricing.janice.api_key');
                if (empty($key)) {
                    return ['status' => 'fail', 'message' => 'A market routes through Janice but no API key is configured. Set it at Settings → Pricing.'];
                }
            }

            if ($usingDefaultGoonpraisalEmail) {
                return ['status' => 'warn', 'message' => 'Goonpraisal-routed markets are using the default maintainer contact email. Set your own at Settings → Pricing for accountability per their docs.'];
            }

            $providersInUse = $markets->pluck('provider')->unique()->values()->all();
            return ['status' => 'pass', 'message' => count($markets) . " enabled market(s) routing through " . count($providersInUse) . " provider(s): " . implode(', ', $providersInUse)];
        });

        // 7. Market price freshness
        //
        // Reads the freq from the schedules table — the ScheduleSeeder owns
        // the cron expression now (was operator-settable via Settings earlier
        // pre-v1.0.0). Falls back to 240min if the schedule row is missing
        // or the cron expression doesn't parse cleanly. The
        // pricing.update_frequency CONFIG (env-backed) is decorative now —
        // ScheduleSeeder hardcodes '0 *_/4 * * *' regardless.
        $add('Market price freshness', 'Pricing', function () {
            $total = MarketPrice::count();
            if ($total === 0) {
                return ['status' => 'warn', 'message' => 'No market prices cached yet — has manager-core:update-prices run?'];
            }
            $newest = MarketPrice::max('updated_at');
            $freq = $this->resolvePriceRefreshIntervalMinutes();
            $ageMin = $newest ? Carbon::parse($newest)->diffInMinutes(now()) : null;
            if ($ageMin !== null && $ageMin <= $freq * 2) {
                return ['status' => 'pass', 'message' => "Newest price {$ageMin} min old (cron runs every {$freq} min via ScheduleSeeder)."];
            }
            return ['status' => 'warn', 'message' => "Newest price is {$ageMin} min old — over twice the {$freq} min cron interval. The scheduler may be down.", 'detail' => "Last update: {$newest}"];
        });

        // 8. Event dispatch failures (24h)
        $add('Event dispatch failures (24h)', 'Event Bus', function () {
            $failed = EventLog::where('created_at', '>=', now()->subDay())
                ->whereIn('status', ['failed', 'partial_failure'])->count();
            return $failed === 0
                ? ['status' => 'pass', 'message' => 'No failed event dispatches in the last 24h.']
                : ['status' => 'warn', 'message' => "{$failed} event(s) failed or partially failed in the last 24h.", 'detail' => 'Use the Event Trace tab to inspect individual events.'];
        });

        // 9. Event subscriptions registered
        $add('Event subscriptions', 'Event Bus', function () {
            $count = EventSubscription::active()->count();
            return $count === 0
                ? ['status' => 'warn', 'message' => 'No active EventBus subscriptions — no plugin is listening for cross-plugin events.']
                : ['status' => 'pass', 'message' => "{$count} active EventBus subscription(s)."];
        });

        // 10. Forensic log retention
        $add('Forensic log retention', 'Maintenance', function () {
            $retention = (int) config('manager-core.events.event_log_retention_days', 30);
            $old = EventLog::where('created_at', '<', now()->subDays($retention))->count();
            return $old === 0
                ? ['status' => 'pass', 'message' => "Event log is within its {$retention}-day retention window."]
                : ['status' => 'warn', 'message' => "{$old} event-log row(s) older than the {$retention}-day retention — is manager-core:cleanup-events scheduled?"];
        });

        // 11. Failed queue jobs
        $add('Failed queue jobs', 'Maintenance', function () {
            try {
                $failed = DB::table('failed_jobs')->count();
            } catch (\Throwable $e) {
                return ['status' => 'info', 'message' => 'Could not read the failed_jobs table.'];
            }
            return $failed === 0
                ? ['status' => 'pass', 'message' => 'No failed queue jobs.']
                : ['status' => 'warn', 'message' => "{$failed} failed queue job(s) — check the SeAT queue worker."];
        });

        // 12. ESI key pool
        $add('ESI key pool', 'ESI', function () {
            if (!Schema::hasTable('manager_core_esi_key_holders')) {
                return ['status' => 'info', 'message' => 'ESI key pool table not present.'];
            }
            $total = DB::table('manager_core_esi_key_holders')->count();
            if ($total === 0) {
                return ['status' => 'info', 'message' => 'ESI key pool is empty — fast-poll has no directors to call.'];
            }
            $enabled = DB::table('manager_core_esi_key_holders')->where('enabled', true)->count();
            return $enabled === 0
                ? ['status' => 'warn', 'message' => "{$total} key holder(s) registered but none enabled — fast-poll is idle."]
                : ['status' => 'pass', 'message' => "{$enabled} of {$total} ESI key holder(s) enabled."];
        });

        $passed   = count(array_filter($checks, fn($c) => $c['status'] === 'pass'));
        $warnings = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
        $failures = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total' => count($checks),
                    'passed' => $passed,
                    'warnings' => $warnings,
                    'failures' => $failures,
                    'overall' => $failures > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'pass'),
                ],
                'checks' => $checks,
            ],
        ]);
    }

    /**
     * GET /diagnostic/event-trace — recent events for the Event Trace picker.
     */
    public function eventTrace(): JsonResponse
    {
        $events = EventLog::orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'events' => $events->map(fn($e) => [
                    'id' => $e->id,
                    'event_name' => $e->event_name,
                    'publisher' => $e->publisher_plugin,
                    'status' => $e->status,
                    'subscriber_count' => $e->subscriber_count,
                    'created_human' => $e->created_at ? $e->created_at->diffForHumans() : null,
                ])->values(),
            ],
        ]);
    }

    /**
     * GET /diagnostic/event-trace/{id} — full dispatch trace for one event.
     *
     * Walks a single event_log row through the pipeline: who published it,
     * which subscription patterns match its name, what each subscriber's
     * outcome was (dispatched / failed / circuit-open / inactive), and the
     * final audit status.
     */
    public function eventTraceDetail(int $id): JsonResponse
    {
        $event = EventLog::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => "Event #{$id} not found in the log."]);
        }

        // Which subscription patterns currently match this event's name?
        $matched = EventSubscription::all()
            ->filter(fn($sub) => fnmatch($sub->event_pattern, $event->event_name));

        // The row's errors JSON names which subscribers failed at dispatch time.
        $errors = is_array($event->errors) ? $event->errors : [];
        $errorBySubscriber = [];
        foreach ($errors as $err) {
            if (is_array($err) && !empty($err['subscriber'])) {
                $errorBySubscriber[$err['subscriber']] = $err['error'] ?? 'unknown';
            }
        }

        $subscriptions = [];
        foreach ($matched as $sub) {
            $err = $errorBySubscriber[$sub->subscriber_plugin] ?? null;
            $outcome = 'dispatched';
            if ($err !== null) {
                $outcome = ($err === 'circuit_open') ? 'circuit_open' : 'failed';
            }
            if (!$sub->is_active) {
                $outcome = 'inactive';
            }
            $subscriptions[] = [
                'subscriber' => $sub->subscriber_plugin,
                'pattern' => $sub->event_pattern,
                'handler' => $sub->handler_class
                    ? class_basename($sub->handler_class) . '@' . $sub->handler_method
                    : ($sub->handler_capability ?: '—'),
                'queued' => (bool) $sub->is_queued,
                'is_active' => (bool) $sub->is_active,
                'outcome' => $outcome,
                'error' => $err,
            ];
        }

        $dispatchedOk = count(array_filter($subscriptions, fn($s) => $s['outcome'] === 'dispatched'));
        $failedCount  = count(array_filter($subscriptions, fn($s) => in_array($s['outcome'], ['failed', 'circuit_open'], true)));

        $steps = [
            ['label' => 'Event published', 'detail' => "'{$event->event_name}' published by " . ($event->publisher_plugin ?: 'unknown')],
            ['label' => 'Subscriptions resolved', 'detail' => $matched->count() . ' subscription pattern(s) currently match this event name'],
            ['label' => 'Dispatched to subscribers', 'detail' => "{$dispatchedOk} dispatched OK, {$failedCount} failed or skipped"],
            ['label' => 'Audit row written', 'detail' => "Recorded with status '{$event->status}', subscriber_count " . ($event->subscriber_count ?? 0)],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'event_name' => $event->event_name,
                    'publisher' => $event->publisher_plugin,
                    'idempotency_key' => $event->idempotency_key,
                    'status' => $event->status,
                    'subscriber_count' => $event->subscriber_count,
                    'created_at' => $event->created_at ? $event->created_at->toIso8601String() : null,
                    'payload' => is_array($event->payload) ? $event->payload : [],
                ],
                'steps' => $steps,
                'subscriptions' => $subscriptions,
                'errors' => $errors,
                'note' => 'Subscriptions are matched against the current subscription table. A subscription added or removed since this event was published may not reflect what happened at dispatch time — the recorded status and subscriber_count are authoritative for that.',
            ],
        ]);
    }

    /**
     * Resolve the actual price-refresh cron interval (in minutes) from the
     * SeAT `schedules` table. ScheduleSeeder owns the cron expression
     * for `manager-core:update-prices` — the legacy `pricing.update_frequency`
     * config / Setting that used to drive the cron pre-v1.0.0 is decorative
     * now (ScheduleSeeder hardcodes the expression on every container boot).
     *
     * Parses common cron forms back into minutes (cron-expression literals
     * spelled out below to avoid the docblock-`*` `/`-terminates-the-block
     * trap — see feedback_seat_schedule_corrections.md):
     *   - "0 [star][slash]4 [star] [star] [star]" → 240
     *   - "0 [star] [star] [star] [star]"         → 60
     *   - "[star][slash]15 [star] [star] [star] [star]" → 15
     *   - "0 0 [star] [star] [star]"              → 1440 (daily)
     *
     * Falls back to 240 (the documented v1.0.0 cron value) when the row
     * is missing or the expression doesn't match a known pattern.
     */
    protected function resolvePriceRefreshIntervalMinutes(): int
    {
        $fallback = 240;
        try {
            $row = DB::table('schedules')
                ->where('command', 'manager-core:update-prices')
                ->first(['expression']);
            if (!$row || empty($row->expression)) {
                return $fallback;
            }
            $expr = trim((string) $row->expression);

            // Pattern: "0 */N * * *" (every N hours on the hour)
            if (preg_match('/^0 \*\/(\d+) \* \* \*$/', $expr, $m)) {
                return ((int) $m[1]) * 60;
            }
            // Pattern: "0 0 * * *" (daily at midnight)
            if ($expr === '0 0 * * *') {
                return 1440;
            }
            // Pattern: "0 * * * *" (hourly)
            if ($expr === '0 * * * *') {
                return 60;
            }
            // Pattern: "*/N * * * *" (every N minutes)
            if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expr, $m)) {
                return (int) $m[1];
            }
            return $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * GET endpoint behind the Notification Testing tab. Returns metadata
     * about each Watchdog check (name, label, description, enabled state)
     * plus the configured webhook URL kind so the UI can render per-check
     * "Send sample" buttons and label them accordingly.
     */
    public function watchdogTesting(): JsonResponse
    {
        try {
            $service = app(WatchdogService::class);
            $webhookUrl = $service->getWebhookUrl();
            $webhookKind = 'unknown';
            if ($webhookUrl !== '') {
                if (str_contains($webhookUrl, 'discord.com/api/webhooks') || str_contains($webhookUrl, 'discordapp.com/api/webhooks')) {
                    $webhookKind = 'discord';
                } elseif (str_contains($webhookUrl, 'hooks.slack.com/services')) {
                    $webhookKind = 'slack';
                }
            }

            $checks = collect($service->getChecks())->map(function ($c) use ($service) {
                return [
                    'name'        => $c->name(),
                    'label'       => $c->label(),
                    'description' => $c->description(),
                    'enabled'     => $service->isCheckEnabled($c->name()),
                ];
            })->all();

            return response()->json([
                'success' => true,
                'data' => [
                    'watchdog_enabled' => $service->isEnabled(),
                    'webhook_configured' => $webhookUrl !== '',
                    'webhook_kind' => $webhookKind,
                    'exclusion_windows' => $service->getExclusionWindows(),
                    'checks' => $checks,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load watchdog state: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST endpoint hit by the per-check "Send sample" buttons. Fires
     * the named check's sample alert via WatchdogService (which builds
     * a realistic alert shape + delivers to the configured webhook,
     * bypassing dedup + exclusion windows).
     *
     * Pass `check=test` (or omit check entirely) to fire the generic
     * test alert — same one the Settings page "Test webhook" button
     * sends. Useful for confirming the URL itself works before
     * iterating through per-check previews.
     */
    public function simulateWatchdog(Request $request): JsonResponse
    {
        $check = (string) $request->input('check', 'test');

        try {
            $service = app(WatchdogService::class);

            if ($check === 'test') {
                $result = $service->fireTestAlert();
                $result['check'] = 'test';
                return response()->json($result, $result['success'] ? 200 : 422);
            }

            $result = $service->simulateCheckAlert($check);
            $result['check'] = $check;
            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Simulation threw: ' . $e->getMessage(),
                'check' => $check,
            ], 500);
        }
    }
}
