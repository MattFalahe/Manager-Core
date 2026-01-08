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
     * Update market prices for given type IDs
     *
     * @param array $typeIds
     * @param string $market
     * @return void
     */
    public function updateMarketPrices(array $typeIds, $market = 'jita')
    {
        $marketConfig = config("manager-core.pricing.markets.{$market}");

        if (!$marketConfig) {
            Log::error("[Manager Core] Unknown market: {$market}");
            return;
        }

        $regionId = $marketConfig['region_id'];
        $systemIds = $marketConfig['system_ids'] ?? [];

        Log::info("[Manager Core] Fetching market orders for region {$regionId} ({$market}) - " . count($typeIds) . " types");

        $updatedCount = 0;
        $totalTypes = count($typeIds);

        // Process types in batches for concurrent requests
        // ESI rate limit is 20 requests/second, so we use batches of 10 to stay safe
        $batchSize = 10;
        $batches = array_chunk($typeIds, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                // Fetch first page for all types in this batch concurrently
                $responses = Http::pool(function ($pool) use ($batch, $regionId) {
                    $requests = [];
                    foreach ($batch as $typeId) {
                        $url = "{$this->baseUrl}/markets/{$regionId}/orders/?datasource=tranquility&order_type=all&type_id={$typeId}&page=1";
                        $requests[$typeId] = $pool->timeout($this->timeout)->get($url);
                    }
                    return $requests;
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
                                    $pageResponse = Http::timeout($this->timeout)->get($url);

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
