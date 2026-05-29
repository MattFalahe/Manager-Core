<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Services\PricingService;
use ManagerCore\Services\AppraisalService;
use ManagerCore\Models\PluginRegistry;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\Appraisal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Comprehensive Manager Core diagnostic command
 *
 * Diagnoses all Manager Core systems and provides detailed health reports
 */
class DiagnoseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager-core:diagnose
                            {--detailed : Show detailed breakdown of each system}
                            {--test-esi : Test ESI connectivity and fetch a sample price}
                            {--test-parser : Test parser with sample data}
                            {--show-subscriptions : Show detailed type subscription breakdown}
                            {--show-prices : Show sample prices from cache}
                            {--check-staleness : Check for stale prices}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comprehensive Manager Core diagnostics - Check plugin bridge, pricing, appraisals, and more';

    /**
     * Plugin Bridge service
     */
    protected $bridge;

    /**
     * Pricing service
     */
    protected $pricing;

    /**
     * Appraisal service
     */
    protected $appraisal;

    /**
     * Create a new command instance.
     */
    public function __construct(PluginBridge $bridge, PricingService $pricing, AppraisalService $appraisal)
    {
        parent::__construct();
        $this->bridge = $bridge;
        $this->pricing = $pricing;
        $this->appraisal = $appraisal;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Manager Core - Comprehensive Diagnostic Report          ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // 1. System Overview
        $this->checkSystemOverview();
        $this->newLine();

        // 2. Plugin Bridge Status
        $this->checkPluginBridge();
        $this->newLine();

        // 3. Type Subscriptions
        $this->checkTypeSubscriptions();
        $this->newLine();

        // 4. Market Price Status
        $this->checkMarketPrices();
        $this->newLine();

        // 5. Appraisal System
        $this->checkAppraisalSystem();
        $this->newLine();

        // 6. Cache Health
        $this->checkCacheHealth();
        $this->newLine();

        // Optional: Test ESI
        if ($this->option('test-esi')) {
            $this->testESIConnectivity();
            $this->newLine();
        }

        // Optional: Test Parser
        if ($this->option('test-parser')) {
            $this->testParser();
            $this->newLine();
        }

        // Optional: Show detailed subscriptions
        if ($this->option('show-subscriptions')) {
            $this->showDetailedSubscriptions();
            $this->newLine();
        }

        // Optional: Show sample prices
        if ($this->option('show-prices')) {
            $this->showSamplePrices();
            $this->newLine();
        }

        // Optional: Check staleness
        if ($this->option('check-staleness')) {
            $this->checkPriceStaleness();
            $this->newLine();
        }

        // 7. Recommendations
        $this->provideRecommendations();

        return Command::SUCCESS;
    }

    /**
     * Check system overview
     */
    protected function checkSystemOverview()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 SYSTEM OVERVIEW');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $config = config('manager-core');

        $this->table(
            ['Component', 'Status', 'Details'],
            [
                [
                    'Manager Core Plugin',
                    '✅',
                    'Version ' . ($config['version'] ?? '1.0.0')
                ],
                [
                    'Database Connection',
                    $this->testDatabaseConnection() ? '✅' : '❌',
                    DB::connection()->getDatabaseName()
                ],
                [
                    'Cache Driver',
                    '✅',
                    config('cache.default')
                ],
                [
                    'Price Update Frequency',
                    '📅',
                    ($config['pricing']['update_frequency'] ?? 240) . ' minutes'
                ],
                [
                    'Cache Duration',
                    '⏰',
                    ($config['cache']['prices_duration'] ?? 60) . ' minutes'
                ],
            ]
        );
    }

    /**
     * Check plugin bridge status
     */
    protected function checkPluginBridge()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔌 PLUGIN BRIDGE STATUS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $statistics = $this->bridge->getStatistics();

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                [
                    'Total Plugins Registered',
                    $statistics['total_plugins'],
                    $this->getStatusIcon($statistics['total_plugins'] > 0)
                ],
                [
                    'Active Plugins',
                    $statistics['active_plugins'],
                    $this->getStatusIcon($statistics['active_plugins'] > 0)
                ],
                [
                    'Total Capabilities',
                    $statistics['total_capabilities'],
                    '📋'
                ],
            ]
        );

        // Show registered plugins
        if ($this->option('detailed')) {
            $this->newLine();
            $this->line('  <fg=cyan>Registered Plugins:</>');;

            $plugins = PluginRegistry::all();
            if ($plugins->isEmpty()) {
                $this->warn('  No plugins registered yet');
                $this->line('  Plugins will auto-register when they boot');
            } else {
                $pluginData = [];
                foreach ($plugins as $plugin) {
                    $pluginData[] = [
                        $plugin->plugin_name,
                        $plugin->is_active ? '✅ Active' : '❌ Inactive',
                        $plugin->last_seen_at ? $plugin->last_seen_at->diffForHumans() : 'Never',
                        count($plugin->capabilities ?? []),
                    ];
                }

                $this->table(
                    ['Plugin Name', 'Status', 'Last Seen', 'Capabilities'],
                    $pluginData
                );
            }
        }
    }

    /**
     * Check type subscriptions
     */
    protected function checkTypeSubscriptions()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📝 TYPE SUBSCRIPTIONS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $totalSubscriptions = TypeSubscription::count();
        $uniqueTypes = TypeSubscription::distinct('type_id')->count();
        $pluginCount = TypeSubscription::distinct('plugin_name')->count();

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                [
                    'Total Subscriptions',
                    $totalSubscriptions,
                    $this->getStatusIcon($totalSubscriptions > 0)
                ],
                [
                    'Unique Type IDs',
                    $uniqueTypes,
                    $this->getStatusIcon($uniqueTypes > 0)
                ],
                [
                    'Subscribing Plugins',
                    $pluginCount,
                    $this->getStatusIcon($pluginCount > 0)
                ],
            ]
        );

        // Show per-plugin breakdown
        $subscriptions = TypeSubscription::select('plugin_name', 'market', DB::raw('count(*) as count'))
            ->groupBy('plugin_name', 'market')
            ->orderBy('count', 'desc')
            ->get();

        if ($subscriptions->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=cyan>Subscriptions by Plugin:</>');;

            $subData = [];
            foreach ($subscriptions as $sub) {
                $subData[] = [
                    $sub->plugin_name,
                    $sub->market,
                    number_format($sub->count),
                    '✅'
                ];
            }

            $this->table(
                ['Plugin', 'Market', 'Type IDs', 'Status'],
                $subData
            );
        } else {
            $this->newLine();
            $this->warn('  ⚠️  No plugin subscriptions yet');
            $this->line('  Plugins should call $pricing->registerTypes() during boot');
        }
    }

    /**
     * Check market prices
     */
    protected function checkMarketPrices()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💰 MARKET PRICE STATUS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $totalPrices = MarketPrice::count();
        $uniqueTypes = MarketPrice::distinct('type_id')->count();
        $latestUpdate = MarketPrice::max('updated_at');

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                [
                    'Total Price Records',
                    number_format($totalPrices),
                    $this->getStatusIcon($totalPrices > 0)
                ],
                [
                    'Unique Type IDs',
                    number_format($uniqueTypes),
                    $this->getStatusIcon($uniqueTypes > 0)
                ],
                [
                    'Last Price Update',
                    $latestUpdate ? Carbon::parse($latestUpdate)->diffForHumans() : 'Never',
                    $latestUpdate ? '✅' : '❌'
                ],
            ]
        );

        // Per-market breakdown
        $priceStats = MarketPrice::select('market', 'price_type', DB::raw('count(distinct type_id) as types'))
            ->groupBy('market', 'price_type')
            ->get();

        if ($priceStats->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=cyan>Prices by Market:</>');;

            $marketData = [];
            foreach ($priceStats as $stat) {
                $marketData[] = [
                    ucfirst($stat->market),
                    ucfirst($stat->price_type),
                    number_format($stat->types),
                    '✅'
                ];
            }

            $this->table(
                ['Market', 'Type', 'Type IDs', 'Status'],
                $marketData
            );
        }

        // Check age of prices
        $staleThreshold = Carbon::now()->subHours(6);
        $stalePrices = MarketPrice::where('updated_at', '<', $staleThreshold)->count();

        if ($stalePrices > 0) {
            $this->newLine();
            $this->warn("  ⚠️  {$stalePrices} price records are over 6 hours old");
            $this->line('  Consider running: php artisan manager-core:update-prices');
        }
    }

    /**
     * Check appraisal system
     */
    protected function checkAppraisalSystem()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📦 APPRAISAL SYSTEM');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $totalAppraisals = Appraisal::count();
        $last24h = Appraisal::where('created_at', '>', Carbon::now()->subDay())->count();
        $latestAppraisal = Appraisal::latest()->first();

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                [
                    'Total Appraisals',
                    number_format($totalAppraisals),
                    $this->getStatusIcon($totalAppraisals >= 0)
                ],
                [
                    'Last 24 Hours',
                    number_format($last24h),
                    '📊'
                ],
                [
                    'Latest Appraisal',
                    $latestAppraisal ? $latestAppraisal->created_at->diffForHumans() : 'Never',
                    $latestAppraisal ? '✅' : '⚪'
                ],
            ]
        );

        if ($latestAppraisal && $this->option('detailed')) {
            $this->newLine();
            $this->line('  <fg=cyan>Latest Appraisal Details:</>');;
            $this->line("  • ID: {$latestAppraisal->appraisal_id}");
            $this->line("  • Items: {$latestAppraisal->items()->count()}");
            $this->line("  • Buy Total: " . number_format($latestAppraisal->total_buy, 2) . " ISK");
            $this->line("  • Sell Total: " . number_format($latestAppraisal->total_sell, 2) . " ISK");
            $this->line("  • Market: {$latestAppraisal->market}");
            $this->line("  • Created: {$latestAppraisal->created_at->diffForHumans()}");
        }
    }

    /**
     * Check cache health
     */
    protected function checkCacheHealth()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔄 CACHE HEALTH');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $cacheDriver = config('cache.default');
        $cacheWorks = $this->testCache();

        // Historical data
        $historyCount = PriceHistory::count();
        $oldestHistory = PriceHistory::min('date');
        $newestHistory = PriceHistory::max('date');

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                [
                    'Cache Driver',
                    $cacheDriver,
                    $cacheWorks ? '✅' : '❌'
                ],
                [
                    'Cache Test',
                    $cacheWorks ? 'Passed' : 'Failed',
                    $cacheWorks ? '✅' : '❌'
                ],
                [
                    'Price History Records',
                    number_format($historyCount),
                    $this->getStatusIcon($historyCount > 0)
                ],
                [
                    'History Date Range',
                    $oldestHistory && $newestHistory
                        ? Carbon::parse($oldestHistory)->format('Y-m-d') . ' to ' . Carbon::parse($newestHistory)->format('Y-m-d')
                        : 'No history',
                    $oldestHistory ? '✅' : '⚪'
                ],
            ]
        );
    }

    /**
     * Test ESI connectivity
     */
    protected function testESIConnectivity()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🌐 ESI CONNECTIVITY TEST');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $this->line('  Testing ESI connection by fetching Tritanium (34) price in Jita...');
        $this->newLine();

        try {
            $start = microtime(true);

            // Use MarketDataService to test
            $esiService = new \ManagerCore\Services\ESI\MarketDataService();
            $success = $esiService->updatePrices(['jita' => [34]]);

            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($success) {
                $this->info("  ✅ ESI connection successful! ({$duration}ms)");

                // Check if price was stored
                $price = MarketPrice::where('type_id', 34)
                    ->where('market', 'jita')
                    ->first();

                if ($price) {
                    $this->line("  • Tritanium sell avg: " . number_format($price->price_avg ?? 0, 2) . " ISK");
                    $this->line("  • Tritanium sell min: " . number_format($price->price_min ?? 0, 2) . " ISK");
                    $this->line("  • Data freshness: " . $price->updated_at->diffForHumans());
                } else {
                    $this->warn('  ⚠️  Price fetched but not found in database');
                }
            } else {
                $this->error('  ❌ ESI request failed');
            }

        } catch (\Exception $e) {
            $this->error('  ❌ ESI test failed: ' . $e->getMessage());
        }
    }

    /**
     * Test parser with sample data
     */
    protected function testParser()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔍 PARSER TEST');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $testInputs = [
            [
                'name' => 'Cargo Scan',
                'input' => "Tritanium\t1000\nPyerite\t500\nMexallon\t250"
            ],
            [
                'name' => 'Listing Format',
                'input' => "1000 Tritanium\n500 Pyerite\n250 Mexallon"
            ],
            [
                'name' => 'Asset List',
                'input' => "Tritanium x1000\nPyerite x500\nMexallon x250"
            ],
        ];

        $results = [];
        foreach ($testInputs as $test) {
            try {
                $parsed = $this->appraisal->getParserService()->parse($test['input']);
                $results[] = [
                    $test['name'],
                    $parsed['success'] ? '✅ Success' : '❌ Failed',
                    $parsed['success'] ? count($parsed['items']) . ' items' : 'N/A',
                    $parsed['parser'] ?? 'Unknown'
                ];
            } catch (\Exception $e) {
                $results[] = [
                    $test['name'],
                    '❌ Error',
                    $e->getMessage(),
                    'N/A'
                ];
            }
        }

        $this->table(
            ['Format', 'Status', 'Items Parsed', 'Parser Used'],
            $results
        );
    }

    /**
     * Show detailed subscriptions
     */
    protected function showDetailedSubscriptions()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📋 DETAILED SUBSCRIPTION BREAKDOWN');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $subscriptions = TypeSubscription::orderBy('priority', 'desc')
            ->orderBy('plugin_name')
            ->limit(20)
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->warn('  No subscriptions found');
            return;
        }

        $subData = [];
        foreach ($subscriptions as $sub) {
            $subData[] = [
                $sub->plugin_name,
                $sub->type_id,
                ucfirst($sub->market),
                $sub->priority,
                $sub->created_at->format('Y-m-d H:i')
            ];
        }

        $this->table(
            ['Plugin', 'Type ID', 'Market', 'Priority', 'Subscribed At'],
            $subData
        );

        $total = TypeSubscription::count();
        if ($total > 20) {
            $this->line("  <fg=yellow>Showing first 20 of {$total} total subscriptions</>");
        }
    }

    /**
     * Show sample prices
     */
    protected function showSamplePrices()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💵 SAMPLE PRICES');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $prices = MarketPrice::where('market', 'jita')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        if ($prices->isEmpty()) {
            $this->warn('  No prices in cache yet');
            $this->line('  Run: php artisan manager-core:update-prices');
            return;
        }

        $priceData = [];
        foreach ($prices as $price) {
            $priceData[] = [
                $price->type_id,
                ucfirst($price->price_type),
                number_format($price->price_avg, 2),
                number_format($price->price_min, 2) . ' / ' . number_format($price->price_max, 2),
                number_format($price->volume ?? 0),
                $price->updated_at->diffForHumans()
            ];
        }

        $this->table(
            ['Type ID', 'Type', 'Avg (ISK)', 'Min / Max', 'Volume', 'Updated'],
            $priceData
        );
    }

    /**
     * Check price staleness
     */
    protected function checkPriceStaleness()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('⏰ PRICE STALENESS CHECK');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $now = Carbon::now();
        $thresholds = [
            'Fresh (< 1 hour)' => $now->copy()->subHour(),
            'Recent (< 4 hours)' => $now->copy()->subHours(4),
            'Stale (< 24 hours)' => $now->copy()->subDay(),
            'Very Stale (> 24 hours)' => $now->copy()->subWeek(),
        ];

        $stalenessData = [];
        $fresh = MarketPrice::where('updated_at', '>', $thresholds['Fresh (< 1 hour)'])->count();
        $recent = MarketPrice::whereBetween('updated_at', [$thresholds['Recent (< 4 hours)'], $thresholds['Fresh (< 1 hour)']])->count();
        $stale = MarketPrice::whereBetween('updated_at', [$thresholds['Stale (< 24 hours)'], $thresholds['Recent (< 4 hours)']])->count();
        $veryStale = MarketPrice::where('updated_at', '<', $thresholds['Stale (< 24 hours)'])->count();

        $stalenessData[] = ['Fresh (< 1 hour)', $fresh, '✅'];
        $stalenessData[] = ['Recent (< 4 hours)', $recent, '✅'];
        $stalenessData[] = ['Stale (< 24 hours)', $stale, $stale > 0 ? '⚠️' : '✅'];
        $stalenessData[] = ['Very Stale (> 24 hours)', $veryStale, $veryStale > 0 ? '❌' : '✅'];

        $this->table(
            ['Age Category', 'Count', 'Status'],
            $stalenessData
        );

        if ($veryStale > 0) {
            $this->newLine();
            $this->warn("  ⚠️  {$veryStale} prices are over 24 hours old!");
            $this->line('  Run: php artisan manager-core:update-prices --force');
        }
    }

    /**
     * Provide recommendations
     */
    protected function provideRecommendations()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💡 RECOMMENDATIONS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $recommendations = [];

        // Check if any prices exist
        if (MarketPrice::count() === 0) {
            $recommendations[] = [
                '❌',
                'No market prices in cache',
                'php artisan manager-core:update-prices --market=jita'
            ];
        }

        // Check if subscriptions exist
        if (TypeSubscription::count() === 0) {
            $recommendations[] = [
                '⚠️',
                'No plugin subscriptions',
                'Install compatible plugins (Mining Manager, etc.)'
            ];
        }

        // Check for stale prices
        $stalePrices = MarketPrice::where('updated_at', '<', Carbon::now()->subDay())->count();
        if ($stalePrices > 50) {
            $recommendations[] = [
                '⚠️',
                "{$stalePrices} prices over 24 hours old",
                'php artisan manager-core:update-prices --force'
            ];
        }

        // Check if schedule is running
        $latestUpdate = MarketPrice::max('updated_at');
        if ($latestUpdate && Carbon::parse($latestUpdate)->diffInHours(now(), true) > 6) {
            $recommendations[] = [
                '⚠️',
                'No price updates in 6+ hours',
                'Check if Laravel scheduler is running'
            ];
        }

        // Check for plugin bridge issues
        if (PluginRegistry::where('is_active', true)->count() === 0) {
            $recommendations[] = [
                'ℹ️',
                'No active plugins detected',
                'This is normal if no compatible plugins installed'
            ];
        }

        if (empty($recommendations)) {
            $this->info('  ✅ Everything looks good! No issues found.');
        } else {
            $this->table(
                ['Status', 'Issue', 'Solution'],
                $recommendations
            );
        }

        $this->newLine();
        $this->line('  <fg=cyan>Tip:</> Run with --detailed for more information');
        $this->line('  <fg=cyan>Tip:</> Run with --test-esi to test ESI connectivity');
        $this->line('  <fg=cyan>Tip:</> Run with --test-parser to test parsing system');
        $this->line('  <fg=cyan>Tip:</> Run with --show-subscriptions for detailed subscription list');
        $this->line('  <fg=cyan>Tip:</> Run with --check-staleness to analyze price freshness');
    }

    /**
     * Test database connection
     */
    protected function testDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Test cache functionality
     */
    protected function testCache(): bool
    {
        try {
            $testKey = 'mc_cache_test_' . time();
            Cache::put($testKey, 'test', 60);
            $value = Cache::get($testKey);
            Cache::forget($testKey);
            return $value === 'test';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get status icon
     */
    protected function getStatusIcon(bool $isGood): string
    {
        return $isGood ? '✅' : '❌';
    }
}
