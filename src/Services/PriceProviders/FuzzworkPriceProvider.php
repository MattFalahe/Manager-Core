<?php

namespace ManagerCore\Services\PriceProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;

/**
 * Fuzzwork market aggregator price provider.
 *
 * Reads from https://market.fuzzwork.co.uk/aggregates/ — a community-run
 * aggregation of CCP's public market data, cached and exposed without
 * needing an ESI character. No API key required.
 *
 * Region-based: each call targets a single EVE region. We translate the
 * MC market key (jita / amarr / dodixie / hek / rens) to the corresponding
 * trade-hub region. Citadel markets and custom regional markets fall back
 * to The Forge (Jita region) because Fuzzwork can't see player citadels.
 *
 * Response shape per type:
 *   {
 *     "<type_id>": {
 *       "buy":  { "max": "1.2", "min": "1.0", "avg": "1.1", "median": "...",
 *                 "percentile": "...", "stddev": "...", "volume": "...", "orderCount": "..." },
 *       "sell": { "min": ..., "max": ..., ... }
 *     }
 *   }
 *
 * Min for sell-side, max for buy-side — the same convention MC's own
 * MarketDataService follows when distilling order books.
 */
class FuzzworkPriceProvider implements PriceProviderInterface
{
    private const BASE_URL = 'https://market.fuzzwork.co.uk/aggregates/';
    private const HTTP_TIMEOUT = 15;
    private const BATCH_SIZE = 100;

    /**
     * EVE region IDs for the main trade hubs. Anything not in this map
     * (custom citadel markets, regional markets the operator added) falls
     * back to The Forge (10000002 — Jita's region).
     */
    private const HUB_REGIONS = [
        'jita' => 10000002,     // The Forge
        'amarr' => 10000043,    // Domain
        'dodixie' => 10000032,  // Sinq Laison
        'hek' => 10000042,      // Metropolis
        'rens' => 10000030,     // Heimatar
    ];

    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        // Initialise result map so missing-data per-type returns null
        // (consumers check `is_array(...)` before reading stats fields).
        $out = array_fill_keys(array_map('intval', $typeIds), null);

        if (empty($typeIds)) {
            return $out;
        }

        $regionId = self::HUB_REGIONS[strtolower($market)] ?? self::HUB_REGIONS['jita'];
        if (!isset(self::HUB_REGIONS[strtolower($market)])) {
            Log::info("[Manager Core] Fuzzwork: market '{$market}' isn't a hub Fuzzwork tracks; falling back to The Forge (Jita region)");
        }

        $persistTag = $persist ? '' : ' (no-persist override mode)';
        Log::info("[Manager Core] Fetching prices from Fuzzwork for " . count($typeIds) . " types in region {$regionId} ({$market}){$persistTag}");

        $updatedCount = 0;
        foreach (array_chunk($typeIds, self::BATCH_SIZE) as $batchIndex => $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->acceptJson()
                    ->get(self::BASE_URL, [
                        'region' => $regionId,
                        'types' => implode(',', $chunk),
                    ]);

                if (!$response->successful()) {
                    Log::warning("[Manager Core] Fuzzwork batch {$batchIndex} HTTP error", [
                        'status' => $response->status(),
                        'region' => $regionId,
                    ]);
                    continue;
                }

                $data = $response->json() ?: [];
                foreach ($chunk as $tid) {
                    $tidInt = (int) $tid;
                    $row = is_array($data[$tid] ?? null) ? $data[$tid] : null;
                    if ($row === null) {
                        continue;
                    }
                    // Build the in-memory return value from the upstream row.
                    // Required by Option B's fetchLivePricesViaOverride path
                    // which deliberately bypasses MC's local cache.
                    $out[$tidInt] = $this->buildStatsFromFuzzworkRow($row);
                    if ($persist) {
                        $this->storeFromFuzzworkRow($tidInt, $market, $row);
                    }
                    $updatedCount++;
                }
            } catch (\Throwable $e) {
                Log::error("[Manager Core] Fuzzwork batch {$batchIndex} exception: " . $e->getMessage());
                continue;
            }
        }

        Log::info("[Manager Core] Fuzzwork completed: " . ($persist ? 'updated' : 'fetched') . " {$updatedCount}/" . count($typeIds) . " types in {$market}");
        return $out;
    }

    /**
     * Build the in-memory stats map for one Fuzzwork row. Same shape as
     * PricingService::formatPriceStats produces from a MarketPrice model.
     * Used by the Option B no-persist path so callers can read prices
     * without going through the DB.
     *
     * @return array{buy: ?array, sell: ?array}
     */
    protected function buildStatsFromFuzzworkRow(array $row): array
    {
        $build = function (?array $bucket): ?array {
            if (!is_array($bucket)) return null;
            $min = (float) ($bucket['min'] ?? 0);
            $max = (float) ($bucket['max'] ?? 0);
            $avg = (float) ($bucket['avg'] ?? 0);
            if ($min == 0.0 && $max == 0.0 && $avg == 0.0) {
                return null; // No orders — null beats a zero-stats row
            }
            return [
                'min' => $min,
                'max' => $max,
                'avg' => $avg,
                'median' => (float) ($bucket['median'] ?? $avg),
                'percentile' => (float) ($bucket['percentile'] ?? $min),
                'stddev' => (float) ($bucket['stddev'] ?? 0),
                'volume' => (int) ($bucket['volume'] ?? 0),
                'order_count' => (int) ($bucket['orderCount'] ?? $bucket['order_count'] ?? 0),
                'strategy' => 'fuzzwork',
                'updated_at' => now()->toIso8601String(),
            ];
        };

        return [
            'buy'  => $build(is_array($row['buy']  ?? null) ? $row['buy']  : null),
            'sell' => $build(is_array($row['sell'] ?? null) ? $row['sell'] : null),
        ];
    }

    /**
     * Persist a single Fuzzwork row to MC's MarketPrice + PriceHistory tables.
     * Splits into buy + sell rows because that's how the rest of MC's pricing
     * surface (getPrice, appraisal flow, plugin bridge) expects to read them.
     */
    protected function storeFromFuzzworkRow(int $typeId, string $market, array $row): void
    {
        $buyBucket = is_array($row['buy'] ?? null) ? $row['buy'] : null;
        $sellBucket = is_array($row['sell'] ?? null) ? $row['sell'] : null;

        if ($buyBucket !== null) {
            $this->saveBucket($typeId, $market, 'buy', $buyBucket);
        }
        if ($sellBucket !== null) {
            $this->saveBucket($typeId, $market, 'sell', $sellBucket);
        }

        $this->updateHistory($typeId, $market, $buyBucket, $sellBucket);
    }

    /**
     * Save a buy or sell bucket from Fuzzwork into the MarketPrice table.
     * Fuzzwork returns numeric values as strings; we cast explicitly so
     * downstream stats math doesn't get tripped up by string arithmetic.
     */
    protected function saveBucket(int $typeId, string $market, string $priceType, array $bucket): void
    {
        $min = (float) ($bucket['min'] ?? 0);
        $max = (float) ($bucket['max'] ?? 0);
        $avg = (float) ($bucket['avg'] ?? 0);
        if ($min == 0.0 && $max == 0.0 && $avg == 0.0) {
            // No orders — don't pollute the cache with a zero row. The next
            // appraisal's Jita fallback will pick up the gap.
            return;
        }

        MarketPrice::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'price_type' => $priceType,
            ],
            [
                'price_min' => $min,
                'price_max' => $max,
                'price_avg' => $avg,
                'price_median' => (float) ($bucket['median'] ?? $avg),
                'price_percentile' => (float) ($bucket['percentile'] ?? $min),
                'price_stddev' => (float) ($bucket['stddev'] ?? 0),
                'volume' => (int) ($bucket['volume'] ?? 0),
                'order_count' => (int) ($bucket['orderCount'] ?? $bucket['order_count'] ?? 0),
                'strategy' => 'fuzzwork',
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Update the daily PriceHistory row so trend graphs include
     * Fuzzwork-driven points too. avg_buy / avg_sell mirror MC's existing
     * convention (avg of all orders in bucket).
     */
    protected function updateHistory(int $typeId, string $market, ?array $buyBucket, ?array $sellBucket): void
    {
        $date = now()->toDateString();
        $avgBuy = $buyBucket ? (float) ($buyBucket['avg'] ?? 0) : 0;
        $avgSell = $sellBucket ? (float) ($sellBucket['avg'] ?? 0) : 0;
        $maxBuy = $buyBucket ? (float) ($buyBucket['max'] ?? 0) : 0;
        $minSell = $sellBucket ? (float) ($sellBucket['min'] ?? 0) : 0;
        $vol = (int) (($buyBucket['volume'] ?? 0) + ($sellBucket['volume'] ?? 0));

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
                'total_volume' => $vol,
            ]
        );
    }

    public function getName(): string
    {
        return 'Fuzzwork Market Aggregator';
    }

    /**
     * Fuzzwork is always available — no API key required, no SeAT plugin
     * dependency. The HTTP layer handles outage cases at fetch time.
     */
    public function isAvailable(): bool
    {
        return true;
    }
}
