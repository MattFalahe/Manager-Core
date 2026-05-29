<?php

namespace ManagerCore\Services\PriceProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\Setting;

/**
 * Janice appraisal-service price provider.
 *
 * Reads from https://janice.e-351.com/api/rest/v2/pricer/{typeId}?market={id}.
 * Janice is an authenticated service — operators must paste an API key from
 * https://janice.e-351.com/ into MC Settings before this provider works.
 *
 * Returns BOTH sides on every call (immediatePrices.buyPrice +
 * immediatePrices.sellPrice), so MC's appraisal logic can pick either side.
 *
 * Market params:
 *   '2' = Jita (default)
 *   '1' = Amarr
 * For any non-Jita/Amarr market (hek, dodixie, rens, citadel markets),
 * we fall back to Jita because Janice doesn't track those hubs.
 *
 * Per-type call cadence: Janice's pricer endpoint is single-type, so we
 * iterate with a 50ms inter-request delay (matches BB Manager's tested-safe
 * cadence). Subscribers requesting hundreds of types should expect tens of
 * seconds; for that scale the operator probably wants MCPraisal or Fuzzwork.
 */
class JanicePriceProvider implements PriceProviderInterface
{
    private const PRICER_URL = 'https://janice.e-351.com/api/rest/v2/pricer';
    private const HTTP_TIMEOUT = 15;
    private const RATE_LIMIT_MICROSECONDS = 50000; // 50ms between requests

    /**
     * Markets Janice tracks → URL param. Anything not in this map falls
     * back to Jita.
     */
    private const MARKET_PARAMS = [
        'jita' => '2',
        'amarr' => '1',
    ];

    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = Setting::get('pricing.janice_api_key', config('manager-core.pricing.janice.api_key'));
    }

    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        $out = array_fill_keys(array_map('intval', $typeIds), null);

        if (empty($typeIds)) {
            return $out;
        }

        if (empty($this->apiKey)) {
            Log::warning("[Manager Core] Janice provider: no API key configured. Set pricing.janice_api_key in MC Settings.");
            return $out;
        }

        $marketParam = self::MARKET_PARAMS[strtolower($market)] ?? self::MARKET_PARAMS['jita'];
        if (!isset(self::MARKET_PARAMS[strtolower($market)])) {
            Log::info("[Manager Core] Janice: market '{$market}' isn't tracked by Janice; falling back to Jita");
        }

        $persistTag = $persist ? '' : ' (no-persist override mode)';
        Log::info("[Manager Core] Fetching prices from Janice for " . count($typeIds) . " types in {$market} (param={$marketParam}){$persistTag}");

        $updatedCount = 0;
        foreach ($typeIds as $typeId) {
            $tidInt = (int) $typeId;
            try {
                $url = sprintf('%s/%d?market=%s', self::PRICER_URL, $typeId, $marketParam);
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders([
                        'X-ApiKey' => $this->apiKey,
                        'accept' => 'application/json',
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    Log::warning("[Manager Core] Janice fetch failed for type {$typeId}", [
                        'status' => $response->status(),
                    ]);
                    usleep(self::RATE_LIMIT_MICROSECONDS);
                    continue;
                }

                $data = $response->json();
                if (!is_array($data)) {
                    usleep(self::RATE_LIMIT_MICROSECONDS);
                    continue;
                }

                // Build the in-memory return value first — required by
                // Option B's no-persist override path.
                $out[$tidInt] = $this->buildStatsFromJaniceResponse($data);
                if ($persist) {
                    $this->storeJaniceRow($typeId, $market, $data);
                }
                $updatedCount++;

                usleep(self::RATE_LIMIT_MICROSECONDS);
            } catch (\Throwable $e) {
                Log::error("[Manager Core] Janice fetch exception for type {$typeId}: " . $e->getMessage());
                continue;
            }
        }

        Log::info("[Manager Core] Janice completed: " . ($persist ? 'updated' : 'fetched') . " {$updatedCount}/" . count($typeIds) . " types in {$market}");
        return $out;
    }

    /**
     * Build the in-memory stats map for one Janice pricer response.
     * Mirrors storeJaniceRow's shape decisions: min/max/avg carry the
     * single representative price, median + percentile carry the
     * 5-day median.
     *
     * @return array{buy: ?array, sell: ?array}
     */
    protected function buildStatsFromJaniceResponse(array $data): array
    {
        $imm = is_array($data['immediatePrices'] ?? null) ? $data['immediatePrices'] : [];
        $top5 = is_array($data['top5AveragePrices'] ?? null) ? $data['top5AveragePrices'] : [];

        $buyPrice = (float) ($imm['buyPrice'] ?? 0);
        $sellPrice = (float) ($imm['sellPrice'] ?? 0);
        $top5Buy = (float) ($top5['buyPrice5DayMedian'] ?? $top5['buyPrice'] ?? $buyPrice);
        $top5Sell = (float) ($top5['sellPrice5DayMedian'] ?? $top5['sellPrice'] ?? $sellPrice);

        $buildSide = function (float $price, float $stable): ?array {
            if ($price <= 0) return null;
            return [
                'min' => $price,
                'max' => $price,
                'avg' => $price,
                'median' => $stable > 0 ? $stable : $price,
                'percentile' => $stable > 0 ? $stable : $price,
                'stddev' => 0.0,
                'volume' => 0,
                'order_count' => 0,
                'strategy' => 'janice',
                'updated_at' => now()->toIso8601String(),
            ];
        };

        return [
            'buy'  => $buildSide($buyPrice, $top5Buy),
            'sell' => $buildSide($sellPrice, $top5Sell),
        ];
    }

    /**
     * Persist a Janice pricer response into MarketPrice + PriceHistory.
     *
     * Janice's shape:
     *   {
     *     "immediatePrices": { "buyPrice": ..., "sellPrice": ... },
     *     "top5AveragePrices": { ... },
     *     "effectivePrices": { "buyPrice5DayMedian": ..., "splitPrice": ... },
     *     ...
     *   }
     *
     * MC's MarketPrice table expects min/max/avg/median/percentile per side.
     * Janice gives us a single representative price per side; we mirror it
     * across the columns rather than synthesising a fake distribution.
     */
    protected function storeJaniceRow(int $typeId, string $market, array $data): void
    {
        $imm = is_array($data['immediatePrices'] ?? null) ? $data['immediatePrices'] : [];
        $top5 = is_array($data['top5AveragePrices'] ?? null) ? $data['top5AveragePrices'] : [];

        $buyPrice = (float) ($imm['buyPrice'] ?? 0);
        $sellPrice = (float) ($imm['sellPrice'] ?? 0);
        $top5Buy = (float) ($top5['buyPrice5DayMedian'] ?? $top5['buyPrice'] ?? $buyPrice);
        $top5Sell = (float) ($top5['sellPrice5DayMedian'] ?? $top5['sellPrice'] ?? $sellPrice);

        if ($buyPrice > 0) {
            $this->saveSide($typeId, $market, 'buy', $buyPrice, $top5Buy);
        }
        if ($sellPrice > 0) {
            $this->saveSide($typeId, $market, 'sell', $sellPrice, $top5Sell);
        }

        $this->updateHistory($typeId, $market, $buyPrice, $sellPrice);
    }

    /**
     * Save one side (buy or sell) using Janice's single representative price.
     * min/max/avg all carry the same value because Janice doesn't expose a
     * distribution. median + percentile carry the 5-day median so callers
     * who specifically want "stable" pricing have a sensible signal.
     */
    protected function saveSide(int $typeId, string $market, string $priceType, float $price, float $stable): void
    {
        MarketPrice::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'price_type' => $priceType,
            ],
            [
                'price_min' => $price,
                'price_max' => $price,
                'price_avg' => $price,
                'price_median' => $stable > 0 ? $stable : $price,
                'price_percentile' => $stable > 0 ? $stable : $price,
                'price_stddev' => 0,
                'volume' => 0,         // Janice doesn't expose volume in pricer
                'order_count' => 0,
                'strategy' => 'janice',
                'updated_at' => now(),
            ]
        );
    }

    protected function updateHistory(int $typeId, string $market, float $buyPrice, float $sellPrice): void
    {
        if ($buyPrice <= 0 && $sellPrice <= 0) {
            return;
        }
        PriceHistory::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'date' => now()->toDateString(),
            ],
            [
                'avg_buy' => $buyPrice,
                'avg_sell' => $sellPrice,
                'max_buy' => $buyPrice,
                'min_sell' => $sellPrice,
                'total_volume' => 0,
            ]
        );
    }

    public function getName(): string
    {
        return 'Janice';
    }

    /**
     * Janice requires an API key. Without one the provider is dormant —
     * the settings page will show "(needs API key)" against the option.
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }
}
