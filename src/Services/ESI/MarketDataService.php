<?php

namespace ManagerCore\Services\ESI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;

/**
 * MarketDataService - Fetches market data from ESI
 *
 * Based on go-evepraisal's ESI fetcher
 */
class MarketDataService
{
    /**
     * ESI base URL
     */
    protected $baseUrl;

    /**
     * Request timeout
     */
    protected $timeout;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->baseUrl = config('manager-core.esi.base_url', 'https://esi.evetech.net/latest');
        $this->timeout = config('manager-core.esi.timeout', 30);
    }

    /**
     * Update market prices for given type IDs.
     *
     * Dispatches to the right fetcher based on the market's type. Hub
     * markets (Jita, Amarr, etc.) use the public ESI region-orders
     * endpoint. Citadel markets (player-owned structures with a market
     * module) use the authenticated structure-orders endpoint with a
     * Bearer token from the market's configured auth character.
     *
     * Operators choose which markets are citadel vs hub via the Markets
     * admin page. The market_type column on manager_core_markets is
     * the discriminator.
     *
     * @param array $typeIds
     * @param string $market
     * @return void
     */
    public function updateMarketPrices(array $typeIds, $market = 'jita')
    {
        $marketConfig = \ManagerCore\Models\Market::getMarketConfig($market);

        if (!$marketConfig) {
            Log::error("[Manager Core] Unknown market: {$market}");
            return;
        }

        // Hub markets only path. Citadel markets are now served by third-
        // party providers (Goonpraisal/Janice) since CCP's structure-orders
        // ESI endpoint has unfixable pagination problems on large hubs.
        // If a citadel market reaches this method, log and bail — the
        // operator should configure a provider via the Markets admin UI.
        $marketType = $marketConfig['market_type'] ?? \ManagerCore\Models\Market::TYPE_HUB;
        if ($marketType === \ManagerCore\Models\Market::TYPE_CITADEL) {
            Log::info("[Manager Core] Market '{$market}' is a citadel — ESI direct fetch removed; configure a third-party provider (Goonpraisal/Janice) for this market.");
            return;
        }

        $regionId = $marketConfig['region_id'];
        $systemIds = $marketConfig['system_ids'] ?? [];

        // Freshness short-circuit. Skip type_ids whose buy AND sell rows
        // were updated within the configured hub TTL — most ticks during
        // an hour see the same subscribed types and re-fetching unchanged
        // prices wastes ESI budget. The configured TTL is operator-tunable
        // via Setting('pricing.hub_ttl_seconds') with a 30-minute default
        // that aligns with CCP's market-cache TTL.
        $hubTtlSeconds = (int) \ManagerCore\Helpers\Settings::get(
            'pricing.hub_ttl_seconds',
            'pricing.hub_ttl_seconds',
            1800  // 30 min default
        );
        $totalRequested = count($typeIds);
        $typeIds = $this->filterFreshHubTypes($typeIds, $market, $hubTtlSeconds);
        $skipped = $totalRequested - count($typeIds);
        if ($skipped > 0) {
            Log::info("[Manager Core] Skipped {$skipped}/{$totalRequested} types in '{$market}' — prices fresh within {$hubTtlSeconds}s TTL");
        }
        if (empty($typeIds)) {
            Log::info("[Manager Core] All requested types in '{$market}' are within TTL; nothing to fetch.");
            try {
                $row = \ManagerCore\Models\Market::where('key', $market)->first();
                if ($row) $row->recordRefresh(\ManagerCore\Models\Market::STATUS_OK);
            } catch (\Throwable $e) { /* non-fatal */ }
            return;
        }

        Log::info("[Manager Core] Fetching market orders for region {$regionId} ({$market}) - " . count($typeIds) . " types");

        $updatedCount = 0;
        $totalTypes = count($typeIds);

        // Process types in batches for concurrent requests.
        //
        // ESI's rate limit is ~100 req/sec sustained per IP for public
        // endpoints. Batch size 25 with 0.5s pacing between batches sits
        // safely at ~50 req/sec peak. Previous batches of 10 were overly
        // conservative — bumping to 25 gives ~2.5x speed on big refresh
        // jobs without putting us anywhere near the error budget.
        $batchSize = 25;
        $batches = array_chunk($typeIds, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                // Fetch first page for all types in this batch concurrently
                $responses = Http::pool(function ($pool) use ($batch, $regionId) {
                    foreach ($batch as $typeId) {
                        $url = "{$this->baseUrl}/markets/{$regionId}/orders/?datasource=tranquility&order_type=all&type_id={$typeId}&page=1";
                        $pool->as((string) $typeId)->connectTimeout(5)->timeout($this->timeout)->get($url);
                    }
                });

                // Process responses for this batch
                foreach ($batch as $typeId) {
                    try {
                        $response = $responses[$typeId];

                        if ($response->failed()) {
                            if ($response->status() === 404) {
                                // No orders for this type, skip silently
                                continue;
                            }
                            Log::error("[Manager Core] ESI request failed for type {$typeId} - Status: {$response->status()}");
                            continue;
                        }

                        $typeOrders = [];
                        $orders = $response->json();

                        if (empty($orders)) {
                            continue;
                        }

                        // Filter by system if specified
                        foreach ($orders as $order) {
                            if (empty($systemIds) || in_array($order['system_id'] ?? 0, $systemIds)) {
                                $typeOrders[] = $order;
                            }
                        }

                        // Check if there are more pages
                        $totalPages = (int) $response->header('X-Pages', 1);

                        // Limit to reasonable number of pages to prevent memory issues
                        $maxPages = 10;
                        if ($totalPages > $maxPages) {
                            Log::warning("[Manager Core] Type {$typeId} has {$totalPages} pages, limiting to {$maxPages} pages");
                            $totalPages = $maxPages;
                        }

                        // Fetch remaining pages if any (sequentially for this type)
                        if ($totalPages > 1) {
                            for ($page = 2; $page <= $totalPages; $page++) {
                                try {
                                    $url = "{$this->baseUrl}/markets/{$regionId}/orders/?datasource=tranquility&order_type=all&type_id={$typeId}&page={$page}";
                                    $pageResponse = Http::connectTimeout(5)->timeout($this->timeout)->get($url);

                                    if (!$pageResponse->successful()) {
                                        break;
                                    }

                                    $pageOrders = $pageResponse->json();
                                    if (empty($pageOrders)) {
                                        break;
                                    }

                                    // Filter by system if specified
                                    foreach ($pageOrders as $order) {
                                        if (empty($systemIds) || in_array($order['system_id'] ?? 0, $systemIds)) {
                                            $typeOrders[] = $order;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    Log::error("[Manager Core] Error fetching page {$page} for type {$typeId}: " . $e->getMessage());
                                    break;
                                }
                            }
                        }

                        // Calculate and save prices for this type
                        if (!empty($typeOrders)) {
                            $this->calculateAndSavePrices($typeId, $typeOrders, $market);
                            $updatedCount++;
                        }

                        // Clear memory after processing each type
                        unset($typeOrders, $orders, $response);

                    } catch (\Exception $e) {
                        Log::error("[Manager Core] Error processing type {$typeId}: " . $e->getMessage());
                        continue;
                    }
                }

                // Clear batch responses from memory
                unset($responses);

                // Log progress after each batch
                $processedSoFar = min(($batchIndex + 1) * $batchSize, $totalTypes);
                Log::info("[Manager Core] Processed {$processedSoFar}/{$totalTypes} types, updated {$updatedCount} with prices");

                // Small delay between batches to respect rate limits and prevent overwhelming the server
                if ($batchIndex < count($batches) - 1) {
                    usleep(500000); // 0.5 second delay between batches
                }

            } catch (\Exception $e) {
                Log::error("[Manager Core] Error processing batch {$batchIndex}: " . $e->getMessage());
                continue;
            }
        }

        Log::info("[Manager Core] Completed: Updated prices for {$updatedCount}/{$totalTypes} types in {$market}");

        // Record successful refresh on the Market row so the admin UI +
        // diagnostics show "last updated N minutes ago". Wrapped in
        // a try because hub markets seeded only in config (not in DB)
        // won't have a row to update — that's fine, log already captured it.
        try {
            $marketRow = \ManagerCore\Models\Market::where('key', $market)->first();
            if ($marketRow) {
                $marketRow->recordRefresh(\ManagerCore\Models\Market::STATUS_OK);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
    /**
     * Filter a list of type_ids down to those that DON'T have a fresh
     * MarketPrice row for this market within the TTL window.
     *
     * "Fresh" means BOTH sides (buy + sell) updated within $ttlSeconds.
     * If only buy or only sell exists, the type still gets re-fetched —
     * catches the case where ESI started returning one-sided orders.
     *
     * Returns an empty array when every requested type is fresh (caller
     * short-circuits without making any ESI calls).
     *
     * Used by the hub-market refresh path to skip wasted re-fetches of
     * types whose prices haven't aged out yet. Significant speedup on
     * frequent appraisals that hit the same subscribed type pool.
     */
    protected function filterFreshHubTypes(array $typeIds, string $market, int $ttlSeconds): array
    {
        if ($ttlSeconds <= 0 || empty($typeIds)) {
            return $typeIds;
        }
        $threshold = now()->subSeconds($ttlSeconds);

        // Find which (type_id, market) pairs have BOTH sides fresh.
        // GROUP BY + HAVING is fast on the existing (type_id, market)
        // index; no full table scan.
        try {
            $freshTypeIds = MarketPrice::where('market', $market)
                ->whereIn('type_id', $typeIds)
                ->where('updated_at', '>=', $threshold)
                ->groupBy('type_id')
                ->havingRaw('COUNT(*) >= 2')  // both buy + sell rows present
                ->pluck('type_id')
                ->map(fn($id) => (int) $id)
                ->toArray();
        } catch (\Throwable $e) {
            // Table missing during install / fresh boot — assume nothing is
            // fresh so the refresh proceeds normally.
            return $typeIds;
        }

        $freshSet = array_flip($freshTypeIds);
        return array_values(array_filter($typeIds,
            fn($id) => !isset($freshSet[(int) $id])
        ));
    }

    /**
     * Calculate price statistics from orders and save to database
     *
     * Based on go-evepraisal's getPriceAggregatesForOrders
     *
     * @param int $typeId
     * @param array $orders
     * @param string $market
     * @return void
     */
    protected function calculateAndSavePrices($typeId, array $orders, $market)
    {
        $buyOrders = [];
        $sellOrders = [];

        foreach ($orders as $order) {
            if ($order['is_buy_order']) {
                $buyOrders[] = $order;
            } else {
                $sellOrders[] = $order;
            }
        }

        // Calculate buy price statistics
        if (!empty($buyOrders)) {
            $buyStats = $this->calculatePriceStats($buyOrders);
            $this->savePriceStats($typeId, $market, 'buy', $buyStats);
        }

        // Calculate sell price statistics
        if (!empty($sellOrders)) {
            $sellStats = $this->calculatePriceStats($sellOrders);
            $this->savePriceStats($typeId, $market, 'sell', $sellStats);
        }

        // Update daily price history
        $this->updatePriceHistory($typeId, $market, $buyOrders, $sellOrders);
    }

    /**
     * Calculate price statistics from orders
     *
     * Implements statistical calculations from go-evepraisal
     *
     * @param array $orders
     * @return array
     */
    protected function calculatePriceStats(array $orders)
    {
        $prices = array_column($orders, 'price');
        $volumes = array_column($orders, 'volume_remain');

        sort($prices);

        $totalVolume = array_sum($volumes);
        $orderCount = count($orders);

        // Weighted average
        $weightedSum = 0;
        foreach ($orders as $order) {
            $weightedSum += $order['price'] * $order['volume_remain'];
        }
        $avg = $totalVolume > 0 ? $weightedSum / $totalVolume : 0;

        // Percentiles (simplified - not weighted)
        $min = min($prices);
        $max = max($prices);
        $median = $this->percentile($prices, 0.5);
        $percentile = $this->percentile($prices, 0.05); // 5th percentile

        // Standard deviation
        $stddev = $this->standardDeviation($prices);

        return [
            'min' => $min,
            'max' => $max,
            'avg' => $avg,
            'median' => $median,
            'percentile' => $percentile,
            'stddev' => $stddev,
            'volume' => $totalVolume,
            'order_count' => $orderCount,
        ];
    }

    /**
     * Calculate percentile from sorted array
     *
     * @param array $sortedValues
     * @param float $percentile
     * @return float
     */
    protected function percentile(array $sortedValues, $percentile)
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0;
        }

        $index = ($percentile * ($count - 1));
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        $fraction = $index - $lower;
        return $sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $fraction;
    }

    /**
     * Calculate standard deviation
     *
     * @param array $values
     * @return float
     */
    protected function standardDeviation(array $values)
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / $count);
    }

    /**
     * Save price statistics to database
     *
     * @param int $typeId
     * @param string $market
     * @param string $priceType
     * @param array $stats
     * @return void
     */
    protected function savePriceStats($typeId, $market, $priceType, array $stats)
    {
        MarketPrice::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'price_type' => $priceType,
            ],
            [
                'price_min' => $stats['min'],
                'price_max' => $stats['max'],
                'price_avg' => $stats['avg'],
                'price_median' => $stats['median'],
                'price_percentile' => $stats['percentile'],
                'price_stddev' => $stats['stddev'],
                'volume' => $stats['volume'],
                'order_count' => $stats['order_count'],
                'strategy' => 'orders',
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Update daily price history
     *
     * @param int $typeId
     * @param string $market
     * @param array $buyOrders
     * @param array $sellOrders
     * @return void
     */
    protected function updatePriceHistory($typeId, $market, array $buyOrders, array $sellOrders)
    {
        $date = now()->toDateString();

        $avgBuy = !empty($buyOrders) ? array_sum(array_column($buyOrders, 'price')) / count($buyOrders) : 0;
        $avgSell = !empty($sellOrders) ? array_sum(array_column($sellOrders, 'price')) / count($sellOrders) : 0;
        $maxBuy = !empty($buyOrders) ? max(array_column($buyOrders, 'price')) : 0;
        $minSell = !empty($sellOrders) ? min(array_column($sellOrders, 'price')) : 0;

        $totalVolume = array_sum(array_column(array_merge($buyOrders, $sellOrders), 'volume_remain'));

        PriceHistory::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'date' => $date,
            ],
            [
                'avg_buy' => $avgBuy,
                'avg_sell' => $avgSell,
                'max_buy' => $maxBuy,
                'min_sell' => $minSell,
                'total_volume' => $totalVolume,
            ]
        );
    }
}
