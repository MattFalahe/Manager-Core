<?php

namespace ManagerCore\Console\Commands;

use Illuminate\Console\Command;
use ManagerCore\Services\ESI\MarketDataService;
use ManagerCore\Models\TypeSubscription;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

/**
 * Diagnose ESI connectivity and market data fetching
 */
class DiagnoseESICommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager-core:diagnose-esi
                            {--test-markets : Test all configured markets}
                            {--test-types : Test fetching for all subscribed type IDs}
                            {--show-limits : Show ESI rate limit status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose ESI connectivity, rate limits, and market data fetching';

    /**
     * ESI service
     */
    protected $esiService;

    /**
     * Statistics
     */
    protected $stats = [
        'requests' => 0,
        'successful' => 0,
        'failed' => 0,
        'total_time' => 0,
    ];

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->esiService = new MarketDataService();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Manager Core - ESI Diagnostic Report                    ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // 1. Basic ESI connectivity
        $this->testESIConnectivity();
        $this->newLine();

        // 2. Test ESI endpoints
        $this->testESIEndpoints();
        $this->newLine();

        // 3. Test markets if requested
        if ($this->option('test-markets')) {
            $this->testAllMarkets();
            $this->newLine();
        }

        // 4. Show rate limits if requested
        if ($this->option('show-limits')) {
            $this->showRateLimits();
            $this->newLine();
        }

        // 5. Test subscribed types if requested
        if ($this->option('test-types')) {
            $this->testSubscribedTypes();
            $this->newLine();
        }

        // 6. Summary
        $this->showSummary();

        return Command::SUCCESS;
    }

    /**
     * Test basic ESI connectivity
     */
    protected function testESIConnectivity()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🌐 ESI CONNECTIVITY TEST');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            $start = microtime(true);
            $response = Http::timeout(10)->get('https://esi.evetech.net/latest/status/');
            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                $data = $response->json();
                $this->info("  ✅ ESI is reachable ({$duration}ms)");
                $this->line("  • Server Version: " . ($data['server_version'] ?? 'Unknown'));
                $this->line("  • Players Online: " . number_format($data['players'] ?? 0));
                $this->line("  • Start Time: " . ($data['start_time'] ?? 'Unknown'));
            } else {
                $this->error('  ❌ ESI returned HTTP ' . $response->status());
            }

        } catch (\Exception $e) {
            $this->error('  ❌ Cannot reach ESI: ' . $e->getMessage());
        }
    }

    /**
     * Test ESI endpoints
     */
    protected function testESIEndpoints()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔍 ESI ENDPOINT TESTS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $endpoints = [
            [
                'name' => 'Universe Types (Tritanium)',
                'url' => 'https://esi.evetech.net/latest/universe/types/34/',
                'expected_field' => 'name'
            ],
            [
                'name' => 'Market Prices',
                'url' => 'https://esi.evetech.net/latest/markets/prices/',
                'expected_field' => null // Returns array
            ],
            [
                'name' => 'Market Orders (Jita - Page 1)',
                'url' => 'https://esi.evetech.net/latest/markets/10000002/orders/?order_type=all&page=1',
                'expected_field' => null // Returns array
            ],
        ];

        $results = [];
        foreach ($endpoints as $endpoint) {
            $this->stats['requests']++;
            $start = microtime(true);

            try {
                $response = Http::timeout(10)->get($endpoint['url']);
                $duration = round((microtime(true) - $start) * 1000, 2);
                $this->stats['total_time'] += $duration;

                if ($response->successful()) {
                    $data = $response->json();
                    $status = '✅ Success';

                    // Validate response
                    if ($endpoint['expected_field'] && !isset($data[$endpoint['expected_field']])) {
                        $status = '⚠️  Unexpected format';
                    }

                    $results[] = [
                        $endpoint['name'],
                        $status,
                        $duration . 'ms',
                        is_array($data) ? count($data) . ' items' : 'Object'
                    ];
                    $this->stats['successful']++;

                } else {
                    $results[] = [
                        $endpoint['name'],
                        '❌ HTTP ' . $response->status(),
                        $duration . 'ms',
                        'Failed'
                    ];
                    $this->stats['failed']++;
                }

            } catch (\Exception $e) {
                $duration = round((microtime(true) - $start) * 1000, 2);
                $results[] = [
                    $endpoint['name'],
                    '❌ Error',
                    $duration . 'ms',
                    substr($e->getMessage(), 0, 30)
                ];
                $this->stats['failed']++;
            }

            // Rate limit courtesy
            usleep(250000); // 250ms between requests
        }

        $this->table(
            ['Endpoint', 'Status', 'Response Time', 'Data'],
            $results
        );
    }

    /**
     * Test all configured markets
     */
    protected function testAllMarkets()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🏪 MARKET CONNECTIVITY TEST');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $markets = config('manager-core.pricing.markets', [
            'jita' => 10000002,
            'amarr' => 10000043,
            'dodixie' => 10000032,
            'rens' => 10000030,
            'hek' => 10000042,
        ]);

        $this->line('  Testing market order endpoints...');
        $this->newLine();

        $results = [];
        foreach ($markets as $name => $regionId) {
            $this->stats['requests']++;
            $start = microtime(true);

            try {
                $url = "https://esi.evetech.net/latest/markets/{$regionId}/orders/?order_type=all&page=1";
                $response = Http::timeout(10)->get($url);
                $duration = round((microtime(true) - $start) * 1000, 2);
                $this->stats['total_time'] += $duration;

                if ($response->successful()) {
                    $data = $response->json();
                    $results[] = [
                        ucfirst($name),
                        $regionId,
                        '✅ Accessible',
                        count($data) . ' orders',
                        $duration . 'ms'
                    ];
                    $this->stats['successful']++;
                } else {
                    $results[] = [
                        ucfirst($name),
                        $regionId,
                        '❌ HTTP ' . $response->status(),
                        'N/A',
                        $duration . 'ms'
                    ];
                    $this->stats['failed']++;
                }

            } catch (\Exception $e) {
                $duration = round((microtime(true) - $start) * 1000, 2);
                $results[] = [
                    ucfirst($name),
                    $regionId,
                    '❌ Error',
                    substr($e->getMessage(), 0, 20),
                    $duration . 'ms'
                ];
                $this->stats['failed']++;
            }

            // Rate limit courtesy
            usleep(250000); // 250ms between requests
        }

        $this->table(
            ['Market', 'Region ID', 'Status', 'Data', 'Response Time'],
            $results
        );
    }

    /**
     * Show ESI rate limit status
     */
    protected function showRateLimits()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 ESI RATE LIMIT INFORMATION');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Make a test request to get rate limit headers
        try {
            $response = Http::get('https://esi.evetech.net/latest/status/');

            $errorLimit = $response->header('X-Esi-Error-Limit-Remain');
            $errorReset = $response->header('X-Esi-Error-Limit-Reset');

            $this->table(
                ['Metric', 'Value', 'Notes'],
                [
                    ['Global Rate Limit', '20 req/sec', 'ESI documented limit'],
                    ['Burst Limit', '400 req/10sec', 'Maximum burst'],
                    ['Error Budget Remaining', $errorLimit ?? 'N/A', 'Errors allowed before ban'],
                    ['Error Budget Reset', $errorReset ? Carbon::now()->addSeconds($errorReset)->diffForHumans() : 'N/A', 'When error budget resets'],
                ]
            );

            if ($errorLimit && $errorLimit < 50) {
                $this->newLine();
                $this->warn("  ⚠️  WARNING: Error budget is low ({$errorLimit} remaining)!");
                $this->line('  Reduce ESI request frequency to avoid temporary ban');
            }

        } catch (\Exception $e) {
            $this->error('  ❌ Could not fetch rate limit data: ' . $e->getMessage());
        }
    }

    /**
     * Test subscribed types
     */
    protected function testSubscribedTypes()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔬 SUBSCRIBED TYPE ID TESTS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Get sample of subscribed types
        $subscriptions = TypeSubscription::distinct('type_id')
            ->limit(10)
            ->pluck('type_id');

        if ($subscriptions->isEmpty()) {
            $this->warn('  No type subscriptions found');
            $this->line('  Plugins should subscribe via $pricing->registerTypes()');
            return;
        }

        $this->line('  Testing sample of ' . $subscriptions->count() . ' subscribed type IDs...');
        $this->newLine();

        $bar = $this->output->createProgressBar($subscriptions->count());
        $bar->start();

        $results = [];
        foreach ($subscriptions as $typeId) {
            $this->stats['requests']++;
            $start = microtime(true);

            try {
                $response = Http::timeout(5)->get("https://esi.evetech.net/latest/universe/types/{$typeId}/");
                $duration = round((microtime(true) - $start) * 1000, 2);
                $this->stats['total_time'] += $duration;

                if ($response->successful()) {
                    $data = $response->json();
                    $results[] = [
                        $typeId,
                        $data['name'] ?? 'Unknown',
                        '✅ Valid',
                        $duration . 'ms'
                    ];
                    $this->stats['successful']++;
                } else {
                    $results[] = [
                        $typeId,
                        'N/A',
                        '❌ HTTP ' . $response->status(),
                        $duration . 'ms'
                    ];
                    $this->stats['failed']++;
                }

            } catch (\Exception $e) {
                $duration = round((microtime(true) - $start) * 1000, 2);
                $results[] = [
                    $typeId,
                    'N/A',
                    '❌ Error',
                    $duration . 'ms'
                ];
                $this->stats['failed']++;
            }

            $bar->advance();
            usleep(100000); // 100ms between requests
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Type ID', 'Name', 'Status', 'Response Time'],
            $results
        );
    }

    /**
     * Show summary
     */
    protected function showSummary()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 TEST SUMMARY');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $successRate = $this->stats['requests'] > 0
            ? round(($this->stats['successful'] / $this->stats['requests']) * 100, 1)
            : 0;

        $avgTime = $this->stats['requests'] > 0
            ? round($this->stats['total_time'] / $this->stats['requests'], 2)
            : 0;

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Total Requests', $this->stats['requests'], '📊'],
                ['Successful', $this->stats['successful'], '✅'],
                ['Failed', $this->stats['failed'], $this->stats['failed'] > 0 ? '❌' : '✅'],
                ['Success Rate', $successRate . '%', $successRate >= 95 ? '✅' : '⚠️'],
                ['Average Response Time', $avgTime . 'ms', $avgTime < 500 ? '✅' : '⚠️'],
                ['Total Time', round($this->stats['total_time'], 2) . 'ms', '⏱️'],
            ]
        );

        if ($this->stats['failed'] > 0) {
            $this->newLine();
            $this->warn("  ⚠️  {$this->stats['failed']} requests failed");
            $this->line('  Check network connectivity and ESI status');
        } else {
            $this->newLine();
            $this->info('  ✅ All ESI tests passed successfully!');
        }

        $this->newLine();
        $this->line('  💡 Tip: Use --test-markets to test all configured markets');
        $this->line('  💡 Tip: Use --show-limits to see ESI rate limit status');
        $this->line('  💡 Tip: Use --test-types to validate subscribed type IDs');
    }
}
