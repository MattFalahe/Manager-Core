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
     * With $persist=false a hub's order book is worked out without storing
     * it, so a plugin asking MCPraisal about a market another provider
     * prices leaves that market's prices alone, as Janice, Fuzzwork and
     * Goonpraisal do. A structure is read only for a market set to
     * MCPraisal, and what it reads there is that market's own price, so a
     * structure market is stored whichever way it is asked.
     */
    public function getPrices(array $typeIds, string $market, bool $persist = true): array
    {
        Log::info("[Manager Core] Fetching prices from ESI for " . count($typeIds) . " types in {$market}" . ($persist ? '' : ' (not stored)'));

        if (!$persist && !$this->marketDataService->isStructureMarket($market)) {
            $out = array_fill_keys(array_map('intval', $typeIds), null);
            foreach ($this->marketDataService->collectMarketPrices($typeIds, $market) as $typeId => $stats) {
                $out[(int) $typeId] = $stats;
            }

            return $out;
        }

        // Use the existing MarketDataService to fetch and calculate prices.
        // Writes to MarketPrice rows as a side effect.
        $this->marketDataService->updateMarketPrices($typeIds, $market);

        // Build return value by reading back from the cache. Required for
        // the Option B override path (2026-05-29) which expects a populated
        // [type_id => [buy, sell]] map per the PriceProviderInterface contract.
        // A type the fetch failed for, or one with no orders now, comes back
        // at its cached price too, so lastFreshTypeIds(), not this map, says
        // what is fresh.
        return $this->readFromCache($typeIds, $market);
    }

    public function lastFetchFailures(): array
    {
        return $this->marketDataService->lastFetchFailures();
    }

    public function lastFreshTypeIds(): ?array
    {
        return $this->marketDataService->lastFreshTypeIds();
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
