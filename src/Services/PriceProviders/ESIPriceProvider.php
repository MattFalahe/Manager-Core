<?php

namespace ManagerCore\Services\PriceProviders;

use ManagerCore\Models\MarketPrice;
use ManagerCore\Services\ESI\MarketDataService;
use Illuminate\Support\Facades\Log;

/**
 * ESI-based price provider (fetches live market data)
 */
class ESIPriceProvider implements PriceProviderInterface
{
    /**
     * @var MarketDataService
     */
    protected $marketDataService;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->marketDataService = new MarketDataService();
    }

    /**
     * Get prices for given type IDs.
     *
     * MCPraisal's $persist=false caveat: MarketDataService is the entire
     * ESI fetching architecture and writes to MarketPrice as a side effect
     * — there's no efficient way to fetch from CCP without persisting.
     * When an operator sets MCPraisal as a per-plugin provider_override,
     * we honour the call but the side-effect writes WILL update MC's
     * shared cache rows. If another plugin reads the same market it
     * would see this plugin's MCPraisal data until the next per-market
     * cron tick overwrites it again. Operators using MCPraisal as an
     * override should be aware of this — it's documented in the in-app
     * Help under Pricing → Per-plugin provider override.
     *
     * Janice / Fuzzwork / Goonpraisal don't have this caveat — they
     * properly support $persist=false (build the in-memory map, skip
     * the DB write).
     */
    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        Log::info("[Manager Core] Fetching prices from ESI for " . count($typeIds) . " types in {$market}");

        if (!$persist) {
            Log::warning("[Manager Core] MCPraisal: \$persist=false requested but not supported — MarketDataService side-effect-persists. Override-using plugin will cache-pollute the shared (type_id, market, price_type) rows. See Help → Pricing → Per-plugin provider override.");
        }

        // Use the existing MarketDataService to fetch and calculate prices.
        // Writes to MarketPrice rows as a side effect.
        $this->marketDataService->updateMarketPrices($typeIds, $market);

        // Build return value by reading back from the cache. Required for
        // the Option B override path (2026-05-29) which expects a populated
        // [type_id => [buy, sell]] map per the PriceProviderInterface contract.
        return $this->readFromCache($typeIds, $market);
    }

    /**
     * Read freshly-persisted prices back from the cache to build the
     * return value. Mirrors PricingService::fetchPriceForType + formatPriceStats
     * shape so callers see the documented contract.
     *
     * @return array<int, array|null>
     */
    protected function readFromCache(array $typeIds, string $market): array
    {
        $out = array_fill_keys(array_map('intval', $typeIds), null);
        if (empty($typeIds)) return $out;

        $rows = MarketPrice::where('market', $market)
            ->whereIn('type_id', $typeIds)
            ->get()
            ->groupBy('type_id');

        $buildStats = function ($row): array {
            return [
                'min' => (float) $row->price_min,
                'max' => (float) $row->price_max,
                'avg' => (float) $row->price_avg,
                'median' => (float) $row->price_median,
                'percentile' => (float) $row->price_percentile,
                'stddev' => (float) $row->price_stddev,
                'volume' => (int) $row->volume,
                'order_count' => (int) $row->order_count,
                'strategy' => (string) ($row->strategy ?? 'esi'),
                'updated_at' => optional($row->updated_at)->toIso8601String(),
            ];
        };

        foreach ($typeIds as $tid) {
            $tidInt = (int) $tid;
            $group = $rows->get($tidInt);
            if ($group === null || $group->isEmpty()) {
                continue; // stays null
            }
            $buyRow  = $group->firstWhere('price_type', 'buy');
            $sellRow = $group->firstWhere('price_type', 'sell');
            if (!$buyRow && !$sellRow) continue;
            $out[$tidInt] = [
                'buy'  => $buyRow  ? $buildStats($buyRow)  : null,
                'sell' => $sellRow ? $buildStats($sellRow) : null,
            ];
        }

        return $out;
    }

    /**
     * Get the name of this price provider.
     *
     * Branded "MCPraisal" in the UI to signal "Manager Core's own ESI-driven
     * pricing" — distinguishes it from Janice / Fuzzwork /
     * SeAT, all of which are upstream third-party services. Internally this
     * class still drives the citadel + hub fetchers in MarketDataService.
     */
    public function getName(): string
    {
        return 'MCPraisal (Manager Core ESI)';
    }

    /**
     * Check if this price provider is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true; // ESI is always available
    }
}
