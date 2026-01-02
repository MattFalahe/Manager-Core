<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Services\ESI\MarketDataService;

/**
 * PricingService - Central pricing service for all plugins
 *
 * Provides market pricing data fetched from ESI
 * Allows plugins to subscribe to specific type IDs
 * Calculates appraisals with custom modifiers
 */
class PricingService
{
    /**
     * Market Data Service
     *
     * @var MarketDataService
     */
    protected $esiService;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->esiService = new MarketDataService();
    }

    /**
     * Get current price for one or more items
     *
     * @param int|array $typeIds
     * @param string $market
     * @param string $priceType (buy|sell|both)
     * @return array
     */
    public function getPrice($typeIds, $market = 'jita', $priceType = 'both')
    {
        $typeIds = is_array($typeIds) ? $typeIds : [$typeIds];
        $cacheKey = 'manager_core_prices_' . md5($market . implode(',', $typeIds) . $priceType);
        $cacheDuration = config('manager-core.cache.prices_duration', 60);

        return Cache::remember($cacheKey, $cacheDuration * 60, function () use ($typeIds, $market, $priceType) {
            $prices = [];

            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->fetchPriceForType($typeId, $market, $priceType);
            }

            return count($prices) === 1 ? reset($prices) : $prices;
        });
    }

    /**
     * Fetch price for a specific type
     *
     * @param int $typeId
     * @param string $market
     * @param string $priceType
     * @return array|null
     */
    protected function fetchPriceForType($typeId, $market, $priceType)
    {
        $result = [];

        if ($priceType === 'both' || $priceType === 'buy') {
            $buyPrice = MarketPrice::where('type_id', $typeId)
                ->where('market', $market)
                ->where('price_type', 'buy')
                ->first();

            $result['buy'] = $buyPrice ? $this->formatPriceStats($buyPrice) : null;
        }

        if ($priceType === 'both' || $priceType === 'sell') {
            $sellPrice = MarketPrice::where('type_id', $typeId)
                ->where('market', $market)
                ->where('price_type', 'sell')
                ->first();

            $result['sell'] = $sellPrice ? $this->formatPriceStats($sellPrice) : null;
        }

        return $priceType === 'both' ? $result : ($result[$priceType] ?? null);
    }

    /**
     * Format price statistics from database model
     *
     * @param MarketPrice $price
     * @return array
     */
    protected function formatPriceStats($price)
    {
        return [
            'min' => (float) $price->price_min,
            'max' => (float) $price->price_max,
            'avg' => (float) $price->price_avg,
            'median' => (float) $price->price_median,
            'percentile' => (float) $price->price_percentile,
            'stddev' => (float) $price->price_stddev,
            'volume' => $price->volume,
            'order_count' => $price->order_count,
            'strategy' => $price->strategy,
            'updated_at' => $price->updated_at,
        ];
    }

    /**
     * Appraise items with optional modifiers
     *
     * @param array $items Format: [['type_id' => X, 'quantity' => Y], ...]
     * @param array $config
     * @return array
     */
    public function appraise(array $items, array $config = [])
    {
        $market = $config['market'] ?? config('manager-core.pricing.default_market', 'jita');
        $basePercentage = $config['base_percentage'] ?? 100;
        $categoryModifiers = $config['category_modifiers'] ?? [];
        $excludedTypes = $config['excluded_types'] ?? [];

        $appraisalItems = [];
        $totalBuy = 0;
        $totalSell = 0;
        $totalVolume = 0;

        foreach ($items as $item) {
            $typeId = $item['type_id'];
            $quantity = $item['quantity'];

            // Check if excluded
            if (in_array($typeId, $excludedTypes)) {
                continue;
            }

            // Get prices
            $prices = $this->getPrice($typeId, $market);

            if (!$prices) {
                Log::warning("[Manager Core] No price data for type_id: {$typeId}");
                continue;
            }

            // Apply modifiers
            $modifier = $this->calculateModifier($typeId, $basePercentage, $categoryModifiers);

            $buyPrice = $prices['buy']['max'] ?? 0;
            $sellPrice = $prices['sell']['min'] ?? 0;

            $adjustedBuyPrice = $buyPrice * ($modifier / 100);
            $adjustedSellPrice = $sellPrice * ($modifier / 100);

            $itemBuyTotal = $adjustedBuyPrice * $quantity;
            $itemSellTotal = $adjustedSellPrice * $quantity;

            $totalBuy += $itemBuyTotal;
            $totalSell += $itemSellTotal;
            $totalVolume += ($item['volume'] ?? 0) * $quantity;

            $appraisalItems[] = [
                'type_id' => $typeId,
                'type_name' => $item['type_name'] ?? 'Unknown',
                'quantity' => $quantity,
                'buy_price' => $adjustedBuyPrice,
                'sell_price' => $adjustedSellPrice,
                'buy_total' => $itemBuyTotal,
                'sell_total' => $itemSellTotal,
                'modifier' => $modifier,
                'prices' => $prices,
            ];
        }

        return [
            'items' => $appraisalItems,
            'totals' => [
                'buy' => $totalBuy,
                'sell' => $totalSell,
                'volume' => $totalVolume,
            ],
            'market' => $market,
            'config' => $config,
        ];
    }

    /**
     * Calculate price modifier for a type
     *
     * @param int $typeId
     * @param float $basePercentage
     * @param array $categoryModifiers
     * @return float
     */
    protected function calculateModifier($typeId, $basePercentage, $categoryModifiers)
    {
        // TODO: Implement category-based modifiers
        // This would require fetching the item's category from SDE or ESI
        return $basePercentage;
    }

    /**
     * Register type IDs that a plugin needs pricing for
     *
     * @param string $pluginName
     * @param array $typeIds
     * @param string $market
     * @param int $priority
     * @return void
     */
    public function registerTypes($pluginName, array $typeIds, $market = 'jita', $priority = 1)
    {
        foreach ($typeIds as $typeId) {
            TypeSubscription::updateOrCreate(
                [
                    'plugin_name' => $pluginName,
                    'type_id' => $typeId,
                    'market' => $market,
                ],
                [
                    'priority' => $priority,
                ]
            );
        }

        Log::info("[Manager Core] Plugin '{$pluginName}' registered " . count($typeIds) . " type IDs for market '{$market}'");
    }

    /**
     * Get all subscribed type IDs across all plugins
     *
     * @param string|null $market
     * @return Collection
     */
    public function getSubscribedTypes($market = null)
    {
        $query = TypeSubscription::query();

        if ($market) {
            $query->where('market', $market);
        }

        return $query->get()->groupBy('market');
    }

    /**
     * Get price trend for an item
     *
     * @param int $typeId
     * @param string $market
     * @param int $days
     * @return array
     */
    public function getTrend($typeId, $market = 'jita', $days = 7)
    {
        $history = PriceHistory::where('type_id', $typeId)
            ->where('market', $market)
            ->where('date', '>=', now()->subDays($days))
            ->orderBy('date', 'asc')
            ->get();

        if ($history->isEmpty()) {
            return [
                'trend' => 'unknown',
                'change_percent' => 0,
                'data' => [],
            ];
        }

        $first = $history->first();
        $last = $history->last();

        $changePercent = $first->avg_sell > 0
            ? (($last->avg_sell - $first->avg_sell) / $first->avg_sell) * 100
            : 0;

        return [
            'trend' => $changePercent > 5 ? 'rising' : ($changePercent < -5 ? 'falling' : 'stable'),
            'change_percent' => round($changePercent, 2),
            'data' => $history->map(function ($record) {
                return [
                    'date' => $record->date,
                    'avg_sell' => $record->avg_sell,
                    'avg_buy' => $record->avg_buy,
                    'volume' => $record->total_volume,
                ];
            })->toArray(),
        ];
    }

    /**
     * Update market prices from ESI
     *
     * @param string $market
     * @return void
     */
    public function updatePrices($market = 'jita')
    {
        $subscribedTypes = $this->getSubscribedTypes($market);

        if (!isset($subscribedTypes[$market])) {
            Log::info("[Manager Core] No subscribed types for market: {$market}");
            return;
        }

        $typeIds = $subscribedTypes[$market]->pluck('type_id')->unique()->toArray();

        Log::info("[Manager Core] Updating prices for " . count($typeIds) . " types in market: {$market}");

        $this->esiService->updateMarketPrices($typeIds, $market);
    }
}
