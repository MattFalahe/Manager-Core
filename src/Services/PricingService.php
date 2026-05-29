<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use ManagerCore\Jobs\RefreshMarketPricesJob;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\TypeSubscription;
/**
 * PricingService - Central pricing service for all plugins
 *
 * Provides market pricing data fetched from ESI
 * Allows plugins to subscribe to specific type IDs
 * Calculates appraisals with custom modifiers
 */
class PricingService implements \ManagerCore\Contracts\PricingServiceInterface
{

    /**
     * Get current price for one or more items.
     *
     * SHAPE QUIRK (preserved for backward compat — see getPrices() below
     * for the always-keyed version):
     *
     *   - When called with a SCALAR typeId or a 1-element array, returns
     *     just the inner price-stats array directly (no typeId key).
     *   - When called with a 2+-element array, returns a keyed map
     *     `[typeId => stats]`.
     *
     * The collapse-when-single behaviour was historically convenient for
     * "give me one price" callers, but inconsistent for callers that
     * always pass an array (e.g. plugins doing batch lookups where the
     * array length depends on user input). Those callers had to handle
     * two different shapes — see Mining Manager's
     * `normaliseBridgeGetPricesShape` for an example workaround.
     *
     * Use `getPrices(array $typeIds, ...)` instead when you want the
     * uniform keyed shape regardless of array length. Use this method
     * (`getPrice`) only when you specifically want the historical scalar
     * shortcut — typically with a single int typeId.
     *
     * @param int|array $typeIds
     * @param string $market
     * @param string $priceType (buy|sell|both)
     * @return array
     */
    public function getPrice($typeIds, $market = 'jita', $priceType = 'both')
    {
        $typeIds = is_array($typeIds) ? $typeIds : [$typeIds];
        // Cache-version pattern: every cache key embeds the current per-market
        // version. When `updatePrices()` writes fresh data, it bumps the
        // version, which automatically invalidates every cached lookup for
        // this market. This is essential when a previous broken fetch cached
        // empty/zero results (e.g. memory exhaustion, ESI failure) — without
        // the bump the appraisal would keep reading the stale zero out of
        // cache for `cache.prices_duration` minutes even after fresh prices
        // landed in the DB.
        $version = $this->priceCacheVersion($market);
        $cacheKey = 'mc_prices_v' . $version . '_' . md5($market . '|' . implode(',', $typeIds) . '|' . $priceType);

        // H8 fix: respect the user's pricing.cache_ttl setting if configured (in seconds),
        // otherwise fall back to config('manager-core.cache.prices_duration') in minutes.
        $cacheTtlSeconds = (int) \ManagerCore\Helpers\Settings::get(
            'pricing.cache_ttl',
            null,
            ((int) config('manager-core.cache.prices_duration', 60)) * 60
        );

        return Cache::remember($cacheKey, $cacheTtlSeconds, function () use ($typeIds, $market, $priceType) {
            $prices = [];

            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->fetchPriceForType($typeId, $market, $priceType);
            }

            return count($prices) === 1 ? reset($prices) : $prices;
        });
    }

    /**
     * Get current prices for a list of items, always returning a uniform
     * `[typeId => stats]` keyed map regardless of input length.
     *
     * Companion to `getPrice` without the single-element collapse quirk.
     * This is the method `pricing.getPrices` capability lambda dispatches
     * to — subscribers that always pass an array and want a stable shape
     * (i.e. every batch caller) should use this.
     *
     * @param int[]  $typeIds
     * @param string $market
     * @param string $priceType (buy|sell|both)
     * @return array `[typeId => priceStats]` — empty array when input is empty.
     */
    public function getPrices(array $typeIds, $market = 'jita', $priceType = 'both', ?string $pluginKeyForOverride = null): array
    {
        if (empty($typeIds)) {
            return [];
        }

        // Per-plugin provider override path (Option B). When the caller
        // passes a plugin key AND that plugin has a non-null
        // provider_override on its pref row, do a LIVE batch fetch via
        // the override provider instead of reading MC's local cache.
        // The cache can't store per-provider variants for the same market
        // (no provider column on manager_core_market_prices), so an
        // override-using plugin needs a cache-bypass path. Consumer
        // plugins typically have their own local cache that absorbs the
        // per-read upstream cost.
        if ($pluginKeyForOverride !== null) {
            $pref = \ManagerCore\Models\PricingPreference::forPlugin($pluginKeyForOverride);
            $override = $pref?->provider_override;
            if ($override !== null && $override !== '') {
                return $this->fetchLivePricesViaOverride($override, $market, $typeIds, $priceType);
            }
            // No override → fall through to the cached path below
        }

        $version = $this->priceCacheVersion($market);
        $cacheKey = 'mc_prices_keyed_v' . $version . '_' . md5($market . '|' . implode(',', $typeIds) . '|' . $priceType);

        // A4 fix: respect operator's pricing.cache_ttl Setting (in seconds), then
        // fall back to config('manager-core.cache.prices_duration') (in minutes).
        $cacheTtlSeconds = (int) \ManagerCore\Helpers\Settings::get(
            'pricing.cache_ttl',
            null,
            ((int) config('manager-core.cache.prices_duration', 60)) * 60
        );

        return Cache::remember($cacheKey, $cacheTtlSeconds, function () use ($typeIds, $market, $priceType) {
            $prices = [];

            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->fetchPriceForType($typeId, $market, $priceType);
            }

            // No collapse — always [typeId => stats] regardless of count.
            return $prices;
        });
    }

    /**
     * Get a single ISK price for a type, using the calling plugin's
     * registered preference (market + price_type).
     *
     * Resolution:
     *   1. Look up `manager_core_pricing_preferences` for the plugin_key.
     *   2. If no row, fall back to the global default (jita sell).
     *   3. Pull the price stats for that market + the buy/sell side.
     *   4. Reduce the stats to a single float per the price_type:
     *        - 'sell' → sell.min  (cheapest sell order = what you'd pay)
     *        - 'buy'  → buy.max   (highest buy order = what you'd get)
     *        - 'avg'  → midpoint of sell.min and buy.max when both exist;
     *                   falls back to whichever side is available.
     *   5. Returns null when no price is available at all.
     *
     * @param string $pluginKey  e.g. 'structure-manager'
     * @param int    $typeId
     * @return float|null
     */
    public function priceForPlugin(string $pluginKey, int $typeId): ?float
    {
        $pref = \ManagerCore\Models\PricingPreference::forPlugin($pluginKey);

        $market         = $pref?->market         ?? 'jita';
        $priceType      = $pref?->price_type     ?? 'sell';
        $providerOverride = $pref?->provider_override; // null = use markets.provider

        // For 'avg' we need both sides; otherwise fetch only the one side.
        $needBoth   = $priceType === 'avg';
        $fetchSide  = $needBoth ? 'both' : $priceType;

        // Per-plugin provider override (Option B): if pref carries an
        // override, live-fetch via that provider. Bypasses MC's local
        // cache because the cache is keyed (type_id, market, price_type)
        // with no provider column, so cached Janice-Jita and Fuzzwork-Jita
        // would clash for the same market. The override is the plugin's
        // own choice; if MM wants Janice, MM pays the per-read Janice cost
        // (which is fine because MM has its own local cache that absorbs
        // the cadence).
        if ($providerOverride !== null && $providerOverride !== '') {
            $rawStats = $this->fetchLivePriceViaOverride($providerOverride, $market, $typeId, $fetchSide);
        } else {
            $rawStats = $this->fetchPriceForType($typeId, $market, $fetchSide);
        }

        if ($rawStats === null) {
            return null;
        }

        return $this->reduceStatsToFloat($rawStats, $priceType);
    }

    /**
     * Batch version of priceForPlugin. Returns `[typeId => ?float]` for
     * every typeId in the input. Single market + price_type lookup so
     * we don't re-resolve the preference per type.
     *
     * @param string $pluginKey
     * @param int[]  $typeIds
     * @return array<int, ?float>
     */
    public function pricesForPlugin(string $pluginKey, array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $pref = \ManagerCore\Models\PricingPreference::forPlugin($pluginKey);

        $market           = $pref?->market           ?? 'jita';
        $priceType        = $pref?->price_type       ?? 'sell';
        $providerOverride = $pref?->provider_override; // null = use markets.provider
        $needBoth         = $priceType === 'avg';
        $fetchSide        = $needBoth ? 'both' : $priceType;

        // Override path: one batch live-fetch to the override provider
        // (provider's getPrices is batch-aware so this is one upstream
        // call for all types, not N calls). See priceForPlugin for the
        // cache-bypass rationale.
        if ($providerOverride !== null && $providerOverride !== '') {
            $rawByType = $this->fetchLivePricesViaOverride($providerOverride, $market, $typeIds, $fetchSide);
            $out = [];
            foreach ($typeIds as $typeId) {
                $stats = $rawByType[(int) $typeId] ?? null;
                $out[(int) $typeId] = $stats === null
                    ? null
                    : $this->reduceStatsToFloat($stats, $priceType);
            }
            return $out;
        }

        // No override: read from MC's local cache (current behaviour).
        $out = [];
        foreach ($typeIds as $typeId) {
            $stats = $this->fetchPriceForType((int) $typeId, $market, $fetchSide);
            $out[(int) $typeId] = $stats === null
                ? null
                : $this->reduceStatsToFloat($stats, $priceType);
        }
        return $out;
    }

    /**
     * Reduce a raw stats array (from fetchPriceForType) to a single float
     * according to the requested price_type. Centralised so the singular
     * and batch callers stay in lockstep.
     *
     * @param array  $stats     Either ['min'=>..., 'max'=>...] (single side) or
     *                          ['buy'=>[...], 'sell'=>[...]] (both sides).
     * @param string $priceType 'sell' | 'buy' | 'avg'
     */
    protected function reduceStatsToFloat($stats, string $priceType): ?float
    {
        // Single-side shape: 'sell' or 'buy' fetch returns the inner array directly.
        // The 'price' the calling plugin wants per the convention:
        //   - 'sell' price_type → use sell.min (cheapest sell order = the actual cost
        //                         of buying this item right now)
        //   - 'buy'  price_type → use buy.max  (highest buy order = the actual revenue
        //                         from selling this item right now)
        if ($priceType === 'sell' && isset($stats['min'])) {
            return (float) $stats['min'];
        }
        if ($priceType === 'buy' && isset($stats['max'])) {
            return (float) $stats['max'];
        }

        // Both-side shape (price_type='avg' requested 'both')
        if ($priceType === 'avg') {
            $sellMin = $stats['sell']['min'] ?? null;
            $buyMax  = $stats['buy']['max']  ?? null;
            if ($sellMin !== null && $buyMax !== null) {
                return ((float) $sellMin + (float) $buyMax) / 2.0;
            }
            // Fall back to whichever side is present
            if ($sellMin !== null) return (float) $sellMin;
            if ($buyMax  !== null) return (float) $buyMax;
        }

        return null;
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
     * Per-plugin provider override — live fetch for a single type.
     *
     * Called from priceForPlugin when the pref carries a non-null
     * provider_override. Skips MC's local cache (because the cache
     * can't store per-provider variants for the same market) and
     * calls the override provider directly.
     *
     * Returns the same shape as fetchPriceForType: either ['buy' => stats,
     * 'sell' => stats] (when $priceType='both') or just the single-side
     * inner stats array.
     *
     * @return array|null
     */
    protected function fetchLivePriceViaOverride(string $providerKey, string $market, int $typeId, string $priceType)
    {
        $byType = $this->fetchLivePricesViaOverride($providerKey, $market, [$typeId], $priceType);
        return $byType[$typeId] ?? null;
    }

    /**
     * Per-plugin provider override — live batch fetch for many types.
     *
     * Called from pricesForPlugin. Routes through the override provider's
     * getPrices() method (one batch upstream call regardless of $typeIds
     * length, per the PriceProviderInterface contract). Returns
     * `[typeId => stats]` where stats matches the fetchPriceForType shape
     * (either both-sides ['buy' => ..., 'sell' => ...] or single-side
     * inner stats, depending on $priceType).
     *
     * Failures (provider unavailable, missing credentials, upstream error,
     * type not found) → null in the per-type map. Lets reduceStatsToFloat
     * gracefully return null for missing types.
     *
     * @param string $providerKey 'esi' | 'janice' | 'fuzzwork' | 'goonpraisal' | 'seat'
     * @param string $market      Market key, e.g. 'jita'
     * @param array  $typeIds     Type IDs to fetch
     * @param string $priceType   'both' | 'buy' | 'sell'
     * @return array<int, array|null>
     */
    protected function fetchLivePricesViaOverride(string $providerKey, string $market, array $typeIds, string $priceType): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $provider = $this->getPriceProvider($providerKey);

            // Defensive: skip the upstream call if the provider isn't
            // configured (e.g. operator picked Janice override without
            // setting a key). Returns null per-type so reduceStatsToFloat
            // gives null floats, which consumer plugins handle as "no price".
            if (method_exists($provider, 'isAvailable') && !$provider->isAvailable()) {
                Log::warning("[Manager Core] provider_override '{$providerKey}' is not configured; returning nulls for live fetch", [
                    'market' => $market,
                    'types' => count($typeIds),
                ]);
                return array_fill_keys(array_map('intval', $typeIds), null);
            }

            // PriceProviderInterface contract: getPrices returns
            //   [type_id => ['buy' => stats, 'sell' => stats] | null]
            // Pass $persist=false so the provider builds + returns the
            // in-memory map WITHOUT writing to manager_core_market_prices.
            // The override path bypasses MC's local cache by design — if
            // we persisted here, MM-via-Janice writes would clobber the
            // cron's Fuzzwork-Jita data and SM (no override) would then
            // read Janice prices on the next cache hit. Caveat: MCPraisal
            // (ESI) provider can't honour $persist=false (MarketDataService
            // is the whole architecture there) and logs a warning — see
            // ESIPriceProvider docblock.
            $raw = $provider->getPrices(array_map('intval', $typeIds), $market, false);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] provider_override live fetch failed via '{$providerKey}': " . $e->getMessage(), [
                'market' => $market,
                'types' => count($typeIds),
            ]);
            return array_fill_keys(array_map('intval', $typeIds), null);
        }

        $out = [];
        foreach ($typeIds as $typeId) {
            $tid = (int) $typeId;
            $perType = is_array($raw[$tid] ?? null) ? $raw[$tid] : null;

            if ($perType === null) {
                $out[$tid] = null;
                continue;
            }

            if ($priceType === 'both') {
                $out[$tid] = $perType;
            } else {
                // Single-side requested: hand back just that side's
                // inner stats, matching fetchPriceForType's shape.
                $out[$tid] = is_array($perType[$priceType] ?? null) ? $perType[$priceType] : null;
            }
        }
        return $out;
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
        // A4 fix: respect operator's pricing.default_market Setting before falling back to config.
        $market = $config['market']
            ?? \ManagerCore\Helpers\Settings::get('pricing.default_market', 'pricing.default_market', 'jita');
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

            // Safely handle null buy/sell arrays
            $buyPrice = isset($prices['buy']['max']) ? (float) $prices['buy']['max'] : 0;
            $sellPrice = isset($prices['sell']['min']) ? (float) $prices['sell']['min'] : 0;

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
     * Register type IDs that a plugin needs pricing for.
     *
     * @param string $pluginName     Subscriber identifier (must match what
     *                                the plugin uses for its capability
     *                                registrations and in unregisterTypes).
     * @param array  $typeIds        EVE invType IDs to subscribe.
     * @param string $market         Market identifier: jita, amarr, dodixie,
     *                                hek, rens.
     * @param int    $priority       1-10. **Advisory-only today.** The
     *                                column exists, the subscriptions UI
     *                                displays it, and this signature
     *                                accepts it — but `updatePrices()`
     *                                does not consult it (every subscribed
     *                                type is fetched on the same schedule
     *                                regardless). Reserved for future
     *                                fetch-budget prioritization (e.g.
     *                                limit ESI calls per cycle, fetch
     *                                high-priority subscribers' types
     *                                first). Subscribers should pass the
     *                                value they would WANT honoured if
     *                                priority logic were implemented; the
     *                                column then carries the right intent
     *                                when that change ships. Default 1.
     * @param bool   $immediateRefresh When true, dispatches a
     *                                RefreshMarketPricesJob to populate
     *                                prices for newly-subscribed types
     *                                via the queue. When false, leaves
     *                                the price fetch to the next scheduled
     *                                `manager-core:update-prices` cron tick
     *                                (default 4h). Default true.
     * @return void
     */
    public function registerTypes($pluginName, array $typeIds, $market = 'jita', $priority = 1, $immediateRefresh = true)
    {
        // Find which type IDs are new (not yet subscribed by any plugin for this market)
        $existingTypeIds = TypeSubscription::where('market', $market)
            ->whereIn('type_id', $typeIds)
            ->pluck('type_id')
            ->toArray();

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

        // Determine which types need an immediate price fetch
        // Types that had no prior subscription OR have no cached price yet
        if ($immediateRefresh) {
            $newTypeIds = array_diff($typeIds, $existingTypeIds);

            // Also check for types that exist in subscriptions but have no price data
            if (empty($newTypeIds)) {
                $pricedTypeIds = MarketPrice::where('market', $market)
                    ->whereIn('type_id', $typeIds)
                    ->pluck('type_id')
                    ->toArray();

                $missingPriceIds = array_diff($typeIds, $pricedTypeIds);

                if (!empty($missingPriceIds)) {
                    $newTypeIds = $missingPriceIds;
                }
            }

            if (!empty($newTypeIds)) {
                // Queue the refresh rather than running it synchronously.
                //
                // Pre-fix this called $this->updatePrices(...) inline, which
                // for plugins subscribing 200+ types (e.g. Mining Manager's
                // moon ores + ice + fuel + gas) meant N synchronous ESI HTTP
                // calls during the original caller's request. Plugin authors
                // typically invoke registerTypes from a settings-save HTTP
                // handler — the synchronous fetch could exceed PHP's
                // max_execution_time and certainly the user's patience.
                //
                // RefreshMarketPricesJob runs the same updatePrices() path
                // on the queue worker, so the registerTypes call returns
                // promptly and prices populate within seconds (default Redis
                // queue) on the worker side. If the queue is unavailable
                // (config issue, broken worker), the scheduled
                // `manager-core:update-prices` cron picks up the new types
                // on its next 4-hourly tick — operators are not blocked.
                //
                // Defense-in-depth debounce — Cache::add returns true only
                // when the key didn't exist. If a misbehaving plugin calls
                // registerTypes(immediateRefresh=true) from its boot path
                // (which runs on every HTTP request), this gate ensures we
                // dispatch AT MOST one job per market per DEBOUNCE_WINDOW.
                // Without it we saw 1-2 RefreshMarketPricesJob per second
                // in Horizon when a plugin had per-request subscribe calls
                // and the citadel market was intermittently failing —
                // every request found prices "missing" and queued another
                // refresh. See SM commit history around 2026-05-18.
                $debounceKey = self::dispatchDebounceKey($market);
                $first = Cache::add($debounceKey, now()->toIso8601String(), self::DEBOUNCE_WINDOW_SECONDS);
                if (!$first) {
                    Log::debug("[Manager Core] Skipping RefreshMarketPricesJob dispatch for '{$market}': debounce window ({$market} dispatched in last " . self::DEBOUNCE_WINDOW_SECONDS . "s)");
                } else {
                    Log::info("[Manager Core] Dispatching RefreshMarketPricesJob for " . count($newTypeIds) . " new/missing types in '{$market}'");
                    RefreshMarketPricesJob::dispatch($market, array_values($newTypeIds));
                }
            }
        }
    }

    /**
     * Debounce window for the dispatch gate above. 30 seconds is long
     * enough to swallow per-request storms and short enough that an
     * admin-triggered "refresh now" still feels responsive (worst case
     * they wait 30s to retry).
     */
    public const DEBOUNCE_WINDOW_SECONDS = 30;

    /**
     * Cache key for the debounce gate. Per-market so a hub-market
     * dispatch doesn't block an unrelated citadel-market dispatch.
     */
    private static function dispatchDebounceKey(string $market): string
    {
        return 'mc.pricing.refresh_dispatch.' . $market;
    }

    /**
     * Unregister all type subscriptions owned by a given plugin, optionally
     * scoped to a single market.
     *
     * Used by plugins switching away from Manager Core as their pricing
     * provider — e.g. Mining Manager's `unsubscribeFromManagerCore` switching
     * back to Janice/Fuzzwork. Without this, MC's scheduled `update-prices`
     * cron keeps fetching prices for types nobody reads anymore, wasting ESI
     * budget.
     *
     * Idempotent: if the plugin has no subscriptions (or only had subs in
     * markets other than the one given), returns 0. Safe to call repeatedly.
     *
     * Companion to `registerTypes`. Exposed via the `pricing.unsubscribeTypes`
     * PluginBridge capability so subscribers don't have to reach directly into
     * the database table — keeps the contract symmetric with `subscribeTypes`.
     *
     * @param string      $pluginName  The plugin's registered name (must match
     *                                 what was passed to registerTypes).
     * @param string|null $market      Optional market filter. null = remove
     *                                 ALL the plugin's subscriptions across
     *                                 every market.
     * @return int Count of subscription rows removed.
     */
    public function unregisterTypes($pluginName, $market = null): int
    {
        $query = TypeSubscription::where('plugin_name', $pluginName);

        if ($market !== null) {
            $query->where('market', $market);
        }

        $count = $query->delete();

        if ($market !== null) {
            Log::info("[Manager Core] Plugin '{$pluginName}' unregistered {$count} type subscriptions in market '{$market}'");
        } else {
            Log::info("[Manager Core] Plugin '{$pluginName}' unregistered {$count} type subscriptions across all markets");
        }

        return (int) $count;
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
     * Update market prices using configured price provider.
     *
     * @param string      $market       Market key (jita, citadel keys, etc.)
     * @param array|null  $typeIds      Specific type IDs to update (null = all subscribed)
     * @param string|null $providerOverride Optional provider key to use instead
     *                                  of the per-market routing — used by the
     *                                  per-appraisal price-provider selector
     *                                  and the Diagnostic page's Test Provider
     *                                  button. Values: 'esi' (a.k.a. MCPraisal),
     *                                  'janice', 'fuzzwork', 'goonpraisal',
     *                                  'seat'. Null = use the per-market
     *                                  provider on manager_core_markets.
     * @return void
     */
    public function updatePrices($market = 'jita', $typeIds = null, ?string $providerOverride = null)
    {
        // If no specific type IDs provided, get all subscribed types
        if ($typeIds === null) {
            $subscribedTypes = $this->getSubscribedTypes($market);

            if (!isset($subscribedTypes[$market])) {
                Log::info("[Manager Core] No subscribed types for market: {$market}");
                return;
            }

            $typeIds = $subscribedTypes[$market]->pluck('type_id')->unique()->toArray();
        }

        // Resolution order for which provider class to use:
        //   1. Explicit override (from per-appraisal selector or test)
        //   2. Per-market provider stored on the manager_core_markets row
        //   3. Hard-coded 'fuzzwork' fail-safe inside getPriceProvider()
        // The "global default" Setting was removed in v1.0.0; per-market
        // routing is the single source of truth.
        $effectiveProvider = $providerOverride
            ?? $this->resolveMarketProvider($market);

        Log::info("[Manager Core] Updating prices for " . count($typeIds) . " types in market: {$market}"
            . ($providerOverride
                ? " (provider override: {$providerOverride})"
                : " (provider: " . ($effectiveProvider ?? 'fail-safe fuzzwork') . ")"));

        $priceProvider = $this->getPriceProvider($effectiveProvider);

        if (!$priceProvider->isAvailable()) {
            Log::error("[Manager Core] Price provider '{$priceProvider->getName()}' is not available");
            return;
        }

        Log::info("[Manager Core] Using price provider: {$priceProvider->getName()}");

        $priceProvider->getPrices($typeIds, $market);

        // Bump the per-market cache version so any cached getPrice/getPrices
        // results for this market are invalidated. Without this, a previous
        // broken run (e.g. memory exhaustion, empty ESI response) could leave
        // an empty-price cache entry that masks the freshly-written DB rows
        // for up to `cache.prices_duration` minutes. The bump is cheap
        // (single Redis INCR) and ensures the very next getPrice call reads
        // straight from the DB.
        $this->bumpPriceCacheVersion($market);
    }

    /**
     * Per-market cache version, used as a prefix on every getPrice/getPrices
     * cache key. Reads the current version (defaults to 1) without
     * incrementing.
     */
    protected function priceCacheVersion(string $market): int
    {
        return (int) Cache::get($this->priceCacheVersionKey($market), 1);
    }

    /**
     * Bump the per-market cache version. Every cached getPrice/getPrices
     * entry that was built under the previous version becomes unreachable
     * (its key includes the old version) and will be lazily evicted by the
     * cache backend. Subsequent reads build fresh entries under the new
     * version.
     */
    protected function bumpPriceCacheVersion(string $market): void
    {
        $key = $this->priceCacheVersionKey($market);
        try {
            // Cache::increment requires the key to exist with an int value;
            // seed it first if missing so the very first bump still works
            // on cache drivers (file, array) that don't support implicit
            // increment from null.
            if (!Cache::has($key)) {
                Cache::forever($key, 1);
            }
            Cache::increment($key);
        } catch (\Throwable $e) {
            // Cache backend doesn't support increment? Fall back to a
            // timestamp-style bump so we still invalidate. If even that
            // fails we swallow it — bumping the cache version is best-effort
            // and must never block the price update from completing.
            try {
                Cache::forever($key, (int) (microtime(true) * 1000));
            } catch (\Throwable $e2) {
                Log::warning("[Manager Core] Could not bump price cache version for '{$market}': " . $e2->getMessage());
            }
        }
    }

    /**
     * Cache key for the per-market price-cache version counter.
     */
    private function priceCacheVersionKey(string $market): string
    {
        return 'mc_prices_version_' . $market;
    }

    /**
     * Get the configured price provider instance.
     *
     * Provider keys:
     *   - 'esi' (default) — MCPraisal, MC's own ESI-driven fetcher
     *   - 'janice'        — Janice appraisal service (requires API key)
     *   - 'fuzzwork'      — Fuzzwork community market aggregator
     *   - 'goonpraisal'   — Goonpraisal appraisal service (no auth, public)
     *   - 'seat'          — SeAT prices-core plugin chain
     *
     * @param string|null $override When supplied, uses this provider regardless
     *                              of any per-market routing. Lets per-appraisal
     *                              + per-market selections actually take effect.
     *                              When NULL, falls back to the hard-coded
     *                              'fuzzwork' fail-safe — there is no "global
     *                              default" Setting anymore. The resolution
     *                              chain is: per-appraisal override → per-market
     *                              provider column → this fail-safe. Callers
     *                              that want the per-market provider should pass
     *                              `Market::resolveProviderFor($marketKey)` or
     *                              equivalent rather than rely on this fallback.
     * @return \ManagerCore\Services\PriceProviders\PriceProviderInterface
     */
    protected function getPriceProvider(?string $override = null)
    {
        // Fail-safe is hardcoded 'fuzzwork' (not config-driven). Operators can
        // no longer set a "global default" via Settings — instead they pick the
        // provider per-market on the Markets admin page, which is what
        // updatePrices() actually consults. This fail-safe only fires if
        // somehow no per-market provider AND no override is available.
        $provider = $override ?? 'fuzzwork';

        switch ($provider) {
            case 'janice':
                return new \ManagerCore\Services\PriceProviders\JanicePriceProvider();

            case 'fuzzwork':
                return new \ManagerCore\Services\PriceProviders\FuzzworkPriceProvider();

            case 'goonpraisal':
                return new \ManagerCore\Services\PriceProviders\GoonpraisalPriceProvider();

            case 'seat':
                return new \ManagerCore\Services\PriceProviders\SeatPriceProvider();

            case 'esi':
            default:
                return new \ManagerCore\Services\PriceProviders\ESIPriceProvider();
        }
    }

    /**
     * Resolve the effective provider for a given market key.
     *
     * Walks the manager_core_markets table to find the configured
     * `provider` for this market. Returns null if the market isn't in
     * the DB or has no provider set — getPriceProvider() then falls
     * back to its hard-coded 'fuzzwork' fail-safe (the "global default"
     * Setting was removed in v1.0.0).
     *
     * This is what makes per-market provider routing actually work:
     * an operator who sets market 'insmother-c-j6mt' → provider
     * 'goonpraisal' gets Goonpraisal prices for that market without
     * touching anything else.
     */
    protected function resolveMarketProvider(string $marketKey): ?string
    {
        try {
            $row = \DB::table('manager_core_markets')
                ->where('key', $marketKey)
                ->first(['provider']);
            return $row && !empty($row->provider) ? $row->provider : null;
        } catch (\Throwable $e) {
            // Table missing during install / fresh boot; fall back.
            return null;
        }
    }
}
