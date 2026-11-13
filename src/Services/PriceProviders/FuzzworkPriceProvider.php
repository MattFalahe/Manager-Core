<?php

namespace ManagerCore\Services\PriceProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\Market;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;

/**
 * Fuzzwork market aggregator price provider.
 *
 * Reads from https://market.fuzzwork.co.uk/aggregates/ — a community-run
 * aggregation of CCP's public market data, cached and exposed without
 * needing an ESI character. No API key required.
 *
 * Station-based: each call asks about one trade hub's station, translated
 * from the MC market key (jita / amarr / dodixie / hek / rens). Asked for a
 * region instead, Fuzzwork answers with every order in it, so Jita's sell
 * price would be the cheapest sell anywhere in The Forge, which can sit
 * systems away and below anything Jita 4-4 sells at. Citadel markets and
 * custom regional markets fall back to Jita 4-4 because Fuzzwork can't see
 * player citadels.
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
    use ReportsFetchFailures;

    private const BASE_URL = 'https://market.fuzzwork.co.uk/aggregates/';
    private const HTTP_TIMEOUT = 15;
    private const BATCH_SIZE = 100;

    /**
     * The station of each main trade hub. Anything not in this map (custom
     * citadel markets, regional markets the operator added) falls back to
     * Jita 4-4.
     */
    private const HUB_STATIONS = [
        'jita' => 60003760,     // Jita IV - Moon 4 - Caldari Navy Assembly Plant
        'amarr' => 60008494,    // Amarr VIII (Oris) - Emperor Family Academy
        'dodixie' => 60011866,  // Dodixie IX - Moon 20 - Federation Navy Assembly Plant
        'hek' => 60005686,      // Hek VIII - Moon 12 - Boundless Creation Factory
        'rens' => 60004588,     // Rens VI - Moon 8 - Brutor Tribe Treasury
    ];

    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        $this->resetFetchFailures();

        // Initialise result map so missing-data per-type returns null
        // (consumers check `is_array(...)` before reading stats fields).
        $out = array_fill_keys(array_map('intval', $typeIds), null);

        if (empty($typeIds)) {
            return $out;
        }

        $stationId = self::stationFor($market);
        if (!self::tracksMarket($market)) {
            Log::info("[Manager Core] Fuzzwork: market '{$market}' isn't a hub Fuzzwork tracks; falling back to Jita 4-4");
        }

        $persistTag = $persist ? '' : ' (no-persist override mode)';
        Log::info("[Manager Core] Fetching prices from Fuzzwork for " . count($typeIds) . " types at station {$stationId} ({$market}){$persistTag}");

        $updatedCount = 0;
        foreach (array_chunk($typeIds, self::BATCH_SIZE) as $batchIndex => $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->acceptJson()
                    ->get(self::BASE_URL, [
                        'station' => $stationId,
                        'types' => implode(',', $chunk),
                    ]);

                if (!$response->successful()) {
                    Log::warning("[Manager Core] Fuzzwork batch {$batchIndex} HTTP error", [
                        'status' => $response->status(),
                        'station' => $stationId,
                    ]);
                    $this->recordFetchFailure($chunk, 'Fuzzwork returned HTTP ' . $response->status(), true, Market::STATUS_PROVIDER_DOWN);
                    continue;
                }

                $data = $response->json();
                if (!is_array($data)) {
                    Log::warning("[Manager Core] Fuzzwork batch {$batchIndex} returned something other than prices");
                    $this->recordFetchFailure($chunk, 'Fuzzwork returned something other than prices', true, Market::STATUS_PROVIDER_DOWN);
                    continue;
                }

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
                $this->recordFetchFailure($chunk, 'Fuzzwork: ' . $e->getMessage(), true, Market::STATUS_PROVIDER_DOWN);
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
     * convention (avg of all orders in bucket). Fuzzwork answers a type with
     * no orders with zeroed buckets, which recordDay() leaves out.
     */
    protected function updateHistory(int $typeId, string $market, ?array $buyBucket, ?array $sellBucket): void
    {
        PriceHistory::recordDay(
            $typeId,
            $market,
            $buyBucket ? (float) ($buyBucket['avg'] ?? 0) : 0.0,
            $buyBucket ? (float) ($buyBucket['max'] ?? 0) : 0.0,
            $sellBucket ? (float) ($sellBucket['avg'] ?? 0) : 0.0,
            $sellBucket ? (float) ($sellBucket['min'] ?? 0) : 0.0,
            (int) (($buyBucket['volume'] ?? 0) + ($sellBucket['volume'] ?? 0))
        );
    }

    public function getName(): string
    {
        return 'Fuzzwork Market Aggregator';
    }

    /**
     * Whether Fuzzwork prices this market's own hub, rather than Jita 4-4 in
     * its place. What decides whether Fuzzwork can stand in for another
     * provider.
     */
    public static function tracksMarket(string $market): bool
    {
        return isset(self::HUB_STATIONS[strtolower($market)]);
    }

    /** The station Fuzzwork is asked about for this market. */
    public static function stationFor(string $market): int
    {
        return self::HUB_STATIONS[strtolower($market)] ?? self::HUB_STATIONS['jita'];
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
