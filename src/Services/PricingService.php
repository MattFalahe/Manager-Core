<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use ManagerCore\Jobs\RefreshMarketPricesJob;
use ManagerCore\Models\Market;
use ManagerCore\Models\MarketPrice;
use ManagerCore\Models\PriceHistory;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Services\PriceProviders\FuzzworkPriceProvider;
use ManagerCore\Services\PriceProviders\GoonpraisalPriceProvider;
use ManagerCore\Services\PriceProviders\JanicePriceProvider;
/**
 * PricingService - Central pricing service for all plugins
 *
 * Provides market pricing data fetched from ESI
 * Allows plugins to subscribe to specific type IDs
 * Calculates appraisals with custom modifiers
 */
class PricingService implements \ManagerCore\Contracts\PricingServiceInterface
{
    use PriceVariants;

    /** The topic a fetch of provider variants is announced on. */
    public const VARIANTS_REFRESHED_TOPIC = 'pricing.variants_refreshed';

    /**
     * How long a variant's first fetch is not queued again, for a plugin
     * that subscribes on every request.
     */
    public const VARIANT_FIRST_FETCH_SECONDS = 900;

    /**
     * How old a price kept for a plugin's provider override may be before a
     * read asks the provider again. Twice the 4-hourly refresh, so only a
     * stalled refresh leaves one this old.
     */
    public const OVERRIDE_STALE_SECONDS = 28800;

    /**
     * The setting naming the provider that stands in for a hub market's own
     * when it fails, and its value for no stand-in at all.
     */
    public const FALLBACK_SETTING = 'pricing.fallback_provider';
    public const FALLBACK_DEFAULT = 'fuzzwork';
    public const FALLBACK_NONE = 'none';

    /** Providers that can stand in for a hub market's own. SeAT's prices never do. */
    public const FALLBACK_PROVIDERS = ['fuzzwork', 'esi', 'janice', 'goonpraisal'];

    /** What each provider is called in a market's status and in the log. */
    public const PROVIDER_LABELS = [
        'esi' => 'MCPraisal',
        'janice' => 'Janice',
        'fuzzwork' => 'Fuzzwork',
        'goonpraisal' => 'Goonpraisal',
        'seat' => 'SeAT Price Provider',
    ];

    /** The provider behind each strategy a price is stored with. */
    public const STRATEGY_PROVIDERS = [
        'fuzzwork' => 'fuzzwork',
        'janice' => 'janice',
        'goonpraisal' => 'goonpraisal',
        'orders' => 'esi',
        'esi' => 'esi',
        'seat-price-provider' => 'seat',
    ];

    /**
     * How many of the asked types that already have a price an empty answer
     * has to cover before it counts as the provider failing, rather than a
     * list of items nobody trades.
     */
    public const PROVIDER_DOWN_MIN_TYPES = 5;

    /** What set a refresh off, as pricing.market_refreshed reports it. */
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_SUBSCRIPTION = 'subscription';
    public const TRIGGER_RETRY = 'retry';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_REQUEST = 'request';

    /** How long types a refresh failed to fetch wait for their one more try. */
    public const RETRY_DELAY_MINUTES = 10;

    /**
     * The setting that holds when a scheduled refresh last stored a price.
     * The newest price in the table can't say that once a plugin may ask for
     * prices between the scheduled runs.
     */
    public const LAST_SCHEDULED_SETTING = 'pricing.last_scheduled_refresh_at';

    /**
     * How long a market counts as being refreshed if the refresh never says
     * it is done. Past the longest refresh, so only a killed one gets here.
     */
    private const REFRESHING_KEPT_SECONDS = 900;

    /**
     * How much younger than asked a price may be and still be fetched again.
     * A fetch lands a few seconds after the ask that queued it, so a plugin
     * asking on a fixed cadence would find its last fetch just short of the
     * age at the next round, and wait one round more every time: asked for
     * hourly every 15 minutes, prices came 75 minutes apart.
     */
    public const REQUEST_TOLERANCE_SECONDS = 300;

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
            $prices = $this->fetchPricesForTypes($typeIds, $market, $priceType);

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

        // A plugin whose preference names a provider gets that provider's
        // prices, kept for it apart from the market's own (see
        // overridePrices). Without one, the market's own.
        if ($pluginKeyForOverride !== null) {
            $pref = \ManagerCore\Models\PricingPreference::forPlugin($pluginKeyForOverride);
            $override = $pref?->provider_override;
            if ($override !== null && $override !== '') {
                return $this->overridePrices($pluginKeyForOverride, $override, (string) $market, $typeIds, $priceType);
            }
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
            $prices = $this->fetchPricesForTypes($typeIds, $market, $priceType);

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

        // An override is read from the prices kept for the plugin through
        // that provider (see overridePrices).
        if ($providerOverride !== null && $providerOverride !== '') {
            $rawStats = $this->overridePrices($pluginKey, $providerOverride, $market, [$typeId], $fetchSide)[$typeId] ?? null;
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

        // An override is read from the prices kept for the plugin through
        // that provider (see overridePrices).
        if ($providerOverride !== null && $providerOverride !== '') {
            $rawByType = $this->overridePrices($pluginKey, $providerOverride, $market, $typeIds, $fetchSide);
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
        $stored = $this->fetchPricesForTypes(array_map('intval', $typeIds), $market, $fetchSide);
        $out = [];
        foreach ($typeIds as $typeId) {
            $stats = $stored[(int) $typeId] ?? null;
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
        return $this->fetchPricesForTypes([$typeId], $market, $priceType)[$typeId] ?? null;
    }

    /**
     * Each type's stored price at a market, in fetchPriceForType's shape,
     * keyed by type id in the order asked. One query per thousand types
     * rather than one per type and side: Mining Manager asks for about 500
     * at once.
     *
     * @return array<int, array|null>
     */
    protected function fetchPricesForTypes(array $typeIds, $market, $priceType): array
    {
        $sides = array_values(array_intersect(['buy', 'sell'], $priceType === 'both' ? ['buy', 'sell'] : [$priceType]));

        $rows = [];
        if (!empty($sides) && !empty($typeIds)) {
            foreach ($this->storedPriceRows(array_values(array_unique(array_map('intval', $typeIds))), (string) $market, $sides) as $row) {
                $rows[(int) $row->type_id][$row->price_type] = $row;
            }
        }

        $prices = [];
        foreach ($typeIds as $typeId) {
            $result = [];
            foreach ($sides as $side) {
                $row = $rows[(int) $typeId][$side] ?? null;
                $result[$side] = $row ? $this->formatPriceStats($row) : null;
            }

            $prices[$typeId] = $priceType === 'both' ? $result : ($result[$priceType] ?? null);
        }

        return $prices;
    }

    /** The stored rows for these types and sides at a market. */
    protected function storedPriceRows(array $typeIds, string $market, array $sides): iterable
    {
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            yield from MarketPrice::where('market', $market)
                ->whereIn('type_id', $chunk)
                ->whereIn('price_type', $sides)
                ->get();
        }
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
            'provider' => self::providerOfStrategy($price->strategy),
            'updated_at' => $price->updated_at,
        ];
    }

    /**
     * The provider that wrote a price, from the strategy stored with it, or
     * null for a strategy no provider here writes. A plugin reads this
     * rather than keeping its own list of strategy names.
     */
    public static function providerOfStrategy(?string $strategy): ?string
    {
        return self::STRATEGY_PROVIDERS[strtolower(trim((string) $strategy))] ?? null;
    }

    /**
     * An override provider's prices asked for now and kept nowhere: for a
     * market Manager Core has no row for, and while the variant tables can't
     * be read. Every other override read goes through overridePrices.
     *
     * A provider that fails gets the market's fallback provider, as a
     * refresh does (see fetchWithFallback), and whatever neither could fetch
     * keeps the last price MC has cached for the market (see standInsFor). A
     * type the provider answered with no price stays null: that is what it
     * says the item is worth, and reduceStatsToFloat returns null for it.
     *
     * @param string $priceType 'both' | 'buy' | 'sell'
     * @return array<int, array|null>
     */
    protected function fetchLivePricesViaOverride(string $providerKey, string $market, array $typeIds, string $priceType): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        // Not stored: the market's rows are its own provider's. MCPraisal
        // works a hub out without storing it too; a structure it reads only
        // for a market of its own (see ESIPriceProvider).
        $outcome = $this->fetchWithFallback($providerKey, $market, $typeIds, false);
        $raw = $this->standInsFor($outcome, $market) + $outcome['prices'];

        $out = [];
        foreach ($typeIds as $typeId) {
            $out[$typeId] = self::sideOf(self::withProviders(is_array($raw[$typeId] ?? null) ? $raw[$typeId] : null), $priceType);
        }

        return $out;
    }

    /**
     * What stands in for the types a live read's provider didn't price: the
     * fallback's price where it stood in, and the market's last cached price
     * where nothing did, each as the price alone (see priceOnly).
     *
     * @return array<int, ?array>
     */
    protected function standInsFor(array $outcome, string $market): array
    {
        $standIns = [];
        foreach ($outcome['stood_in'] as $typeId) {
            $standIns[(int) $typeId] = $this->priceOnly($outcome['prices'][$typeId] ?? null);
        }

        $cached = $this->fetchPricesForTypes($outcome['unanswered'], $market, 'both');
        foreach ($outcome['unanswered'] as $typeId) {
            $standIns[(int) $typeId] = $this->priceOnly($cached[$typeId] ?? null);
        }

        return $standIns;
    }

    /** Each side of a live answer names the provider that wrote it, as a stored one does. */
    protected static function withProviders(?array $perType): ?array
    {
        if ($perType === null) {
            return null;
        }

        foreach (['buy', 'sell'] as $side) {
            if (is_array($perType[$side] ?? null) && !array_key_exists('provider', $perType[$side])) {
                $perType[$side]['provider'] = self::providerOfStrategy($perType[$side]['strategy'] ?? null);
            }
        }

        return $perType;
    }

    /** Both sides, or the one side asked for, as fetchPricesForTypes gives them. */
    protected static function sideOf(?array $perType, string $priceType): ?array
    {
        if ($priceType === 'both') {
            return $perType;
        }

        return is_array($perType[$priceType] ?? null) ? $perType[$priceType] : null;
    }

    /** Whether a provider gave this side a price. */
    protected static function sidePriced(?array $side): bool
    {
        return $side !== null && ((float) ($side['min'] ?? 0) > 0 || (float) ($side['max'] ?? 0) > 0);
    }

    /**
     * A stand-in's answer as the price alone: the lowest sell and the
     * highest buy, the same in every figure, the way Janice answers. It
     * stands in for the price the plugin's provider would give, not for an
     * order book, and a plugin reading another figure still gets that price:
     * Mining Manager 3.0.0 reads the lowest figure of each side, which in a
     * full order book is the cheapest buy order.
     */
    protected function priceOnly(?array $stats): ?array
    {
        if ($stats === null) {
            return null;
        }

        foreach (['buy' => 'max', 'sell' => 'min'] as $side => $best) {
            $s = is_array($stats[$side] ?? null) ? $stats[$side] : null;
            $price = $s !== null ? (float) ($s[$best] ?? 0) : 0.0;

            $stats[$side] = $price > 0
                ? ['min' => $price, 'max' => $price, 'avg' => $price, 'median' => $price, 'percentile' => $price, 'stddev' => 0.0] + $s
                : null;
        }

        return $stats;
    }

    /**
     * A provider's prices for these types, with a stand-in when it fails.
     *
     * A provider fails outright when it prices nothing and either a request
     * failed or the answer was empty for types that already have a price.
     * Then the fallback for this market is asked, the same way: stored
     * under this market by a refresh, returned only by a live read. A type
     * the provider answered with no price is never a failure, since that is
     * what it says the item is worth. Types a request failed for are listed
     * so a refresh can try them again later; they keep their cached price
     * meanwhile, as does everything a provider and its fallback both miss.
     *
     * @return array{prices: array, asked: int, failed: int[], unanswered: int[], stood_in: int[], priced: int[], provider: string,
     *               error: ?string, retryable: bool, status: ?string, outright: bool, fallback: ?array}
     */
    protected function fetchWithFallback(string $providerKey, string $market, array $typeIds, bool $persist): array
    {
        $provider = $this->getPriceProvider($providerKey);
        $label = self::PROVIDER_LABELS[$providerKey] ?? $provider->getName();

        $freshIds = null;

        if (!$provider->isAvailable()) {
            $prices = [];
            $failures = ['type_ids' => $typeIds, 'error' => "{$label} is not set up", 'retryable' => false, 'status' => Market::STATUS_NO_PROVIDER];
        } else {
            try {
                $prices = $provider->getPrices($typeIds, $market, $persist);
                $failures = $provider->lastFetchFailures();
                $freshIds = $provider->lastFreshTypeIds();
            } catch (\Throwable $e) {
                Log::error("[Manager Core] {$label} failed pricing '{$market}': " . $e->getMessage());
                $prices = [];
                $failures = ['type_ids' => $typeIds, 'error' => $e->getMessage(), 'retryable' => true, 'status' => Market::STATUS_PROVIDER_DOWN];
            }
        }

        $failed = array_values(array_intersect($typeIds, array_map('intval', $failures['type_ids'] ?? [])));

        // An empty answer only counts against a third-party service, which
        // can answer with nothing while it is in trouble. MCPraisal reads
        // CCP's own order book, so an empty answer from it is the market.
        $priced = $this->pricedTypeIds($prices, $failed, $freshIds);
        $outright = empty($priced)
            && (!empty($failed) || ($freshIds === null && $this->countCached($market, $typeIds) >= self::PROVIDER_DOWN_MIN_TYPES));

        $outcome = [
            'prices' => $prices,
            'asked' => count($typeIds),
            'failed' => $failed,
            'unanswered' => $failed,
            'stood_in' => [],
            'priced' => $priced,
            'provider' => $label,
            'error' => $failures['error'] ?? null,
            'retryable' => (bool) ($failures['retryable'] ?? true),
            'status' => $failures['status'] ?? null,
            'outright' => $outright,
            'fallback' => null,
        ];

        if (!$outright) {
            if (!empty($failed)) {
                Log::warning("[Manager Core] {$label} could not fetch " . count($failed) . ' of ' . count($typeIds) . " type(s) for '{$market}': {$outcome['error']}");
            }

            return $outcome;
        }

        $outcome['error'] = $outcome['error'] ?? 'answered with no prices at all';

        // An outright failure leaves every type unanswered, including any it
        // answered with nothing: that answer is part of the failure.
        $outcome['unanswered'] = $typeIds;

        $fallback = $this->fallbackFor($market, $providerKey);
        if ($fallback !== null) {
            [$fresh, $fallbackError] = $this->askFallback($fallback, $market, $typeIds, $persist);

            foreach ($fresh as $typeId => $stats) {
                $outcome['prices'][$typeId] = $stats;
            }
            $outcome['failed'] = array_values(array_diff($failed, array_keys($fresh)));
            $outcome['unanswered'] = array_values(array_diff($typeIds, array_keys($fresh)));
            $outcome['stood_in'] = array_keys($fresh);
            $outcome['priced'] = array_map('intval', array_keys($fresh));
            $outcome['fallback'] = $fallback + ['priced' => count($fresh), 'error' => $fallbackError];

            Log::warning("[Manager Core] {$label} failed for '{$market}' ({$outcome['error']}); {$fallback['label']} priced " . count($fresh) . ' of ' . count($typeIds) . ' type(s) instead'
                . ($fallbackError !== null ? " ({$fallbackError})" : '') . '.');
        } else {
            Log::warning("[Manager Core] {$label} failed for '{$market}' ({$outcome['error']}); nothing stands in for it there, so the cached prices stay.");
        }

        return $outcome;
    }

    /**
     * The fallback's prices for these types, and why it priced none if it
     * didn't. A refresh stores them under this market. A fallback that reads
     * another market (a structure in the same system, read through ESI)
     * updates that market's own prices, which are then copied here.
     *
     * @return array{0: array<int, array>, 1: ?string}
     */
    protected function askFallback(array $fallback, string $market, array $typeIds, bool $persist): array
    {
        $sameMarket = $fallback['market'] === $market;

        try {
            $provider = $this->getPriceProvider($fallback['provider']);
            $raw = $provider->getPrices($typeIds, $fallback['market'], $persist && $sameMarket);
            $failures = $provider->lastFetchFailures();
            $freshIds = $provider->lastFreshTypeIds();
        } catch (\Throwable $e) {
            return [[], $e->getMessage()];
        }

        $fresh = [];
        foreach ($this->pricedTypeIds($raw, array_map('intval', $failures['type_ids'] ?? []), $freshIds) as $typeId) {
            $fresh[$typeId] = $raw[$typeId];
        }

        if ($persist && !$sameMarket) {
            foreach ($fresh as $typeId => $stats) {
                $this->storeStats($typeId, $market, $stats);
            }
        }

        return [$fresh, empty($fresh) ? ($failures['error'] ?? 'it had no prices for them either') : null];
    }

    /**
     * Every market, with what a plugin needs to offer it to its users: the
     * provider that prices it, whether it is enabled, how its last refresh
     * went, whether that provider prices the market's own place, and what
     * every provider could do there for a plugin that names one (see
     * PriceVariants::providersAt).
     *
     * @return array<string, array{key: string, name: string, market_type: string, provider: string, provider_label: string,
     *               is_enabled: bool, last_refresh_at: ?string, last_refresh_status: ?string, prices_own_place: bool, place_reason: ?string,
     *               providers: array<string, array{label: string, own: bool, available: bool, prices_place: bool, place_reason: ?string}>}>
     */
    public function listMarkets(bool $enabledOnly = true): array
    {
        $markets = [];
        $available = [];

        foreach ($this->effectiveMarkets($enabledOnly) as $key => $row) {
            $key = (string) $key;
            $provider = (string) ($row['provider'] ?? 'fuzzwork');
            $reason = $this->placeReason($provider, $key, $row['provider_slug'] ?? null);
            $refreshedAt = $row['last_refresh_at'] ?? null;

            $markets[$key] = [
                'key' => $key,
                'name' => (string) ($row['name'] ?? $key),
                'market_type' => (string) ($row['market_type'] ?? Market::TYPE_HUB),
                'provider' => $provider,
                'provider_label' => self::PROVIDER_LABELS[$provider] ?? ucfirst($provider),
                'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                'last_refresh_at' => $refreshedAt instanceof \DateTimeInterface
                    ? $refreshedAt->format(\DateTimeInterface::ATOM)
                    : ($refreshedAt !== null ? (string) $refreshedAt : null),
                'last_refresh_status' => isset($row['last_refresh_status']) ? (string) $row['last_refresh_status'] : null,
                'prices_own_place' => $reason === null,
                'place_reason' => $reason,
                'providers' => $this->providersAt($key, ['provider' => $provider] + $row, $available),
            ];
        }

        return $markets;
    }

    /** The markets as the Markets page has them, config hubs included. */
    protected function effectiveMarkets(bool $enabledOnly): array
    {
        return Market::getEffectiveMarkets($enabledOnly);
    }

    /**
     * Why a provider does not price a market at the market's own place, or
     * null when it does. Fuzzwork and Janice price anything they don't know
     * at Jita 4-4 and say nothing, so a plugin showing those prices under
     * the market's name would be showing Jita's. The Markets form refuses a
     * new market of that kind on the same rule (MarketsController::placeError).
     */
    public function placeReason(string $provider, string $marketKey, ?string $slug = null): ?string
    {
        $slug = trim((string) $slug);

        switch ($provider) {
            case 'esi':
                return null;

            case 'fuzzwork':
                return FuzzworkPriceProvider::tracksMarket($marketKey)
                    ? null
                    : 'Fuzzwork prices only the five trade hubs, so it prices this market at Jita 4-4.';

            case 'janice':
                return JanicePriceProvider::tracksMarket($slug) || JanicePriceProvider::tracksMarket($marketKey)
                    ? null
                    : 'Janice prices only the five trade hubs and this market names none of them, so it is priced at Jita 4-4.';

            case 'goonpraisal':
                // A market routed through Goonpraisal with a slug is asked
                // for by that slug. Only one without is looked up.
                return $slug !== '' || GoonpraisalPriceProvider::marketSlug($marketKey) !== null
                    ? null
                    : 'Goonpraisal has no market by this name.';

            case 'seat':
                return 'A SeAT price provider gives one price for every place, not this market\'s own.';

            default:
                return "Manager Core has no provider called '{$provider}'.";
        }
    }

    /**
     * What stands in for a market's provider when it fails, or null.
     *
     * A hub market asks the provider chosen in Settings, or Fuzzwork or
     * MCPraisal when the choice is the market's own provider or can't price
     * the market. A citadel market never borrows a hub's prices, which would
     * be stored as the citadel's: a structure read through ESI asks
     * Goonpraisal when Goonpraisal tracks its system, and a Goonpraisal
     * market reads a structure in the same system that another market reads
     * through ESI. SeAT's prices never stand in.
     *
     * @return array{provider: string, market: string, label: string}|null
     */
    public function fallbackFor(string $market, string $provider): ?array
    {
        $choice = (string) \ManagerCore\Helpers\Settings::get(self::FALLBACK_SETTING, null, self::FALLBACK_DEFAULT);
        if ($choice === self::FALLBACK_NONE) {
            return null;
        }

        $config = Market::getMarketConfig($market);

        if (($config['market_type'] ?? Market::TYPE_HUB) === Market::TYPE_CITADEL) {
            if ($provider !== 'goonpraisal' && GoonpraisalPriceProvider::marketSlug($market) !== null) {
                return ['provider' => 'goonpraisal', 'market' => $market, 'label' => 'Goonpraisal'];
            }

            $sibling = $provider !== 'esi' ? $this->structureMarketInSystem($market, $config) : null;

            return $sibling !== null
                ? ['provider' => 'esi', 'market' => $sibling, 'label' => "MCPraisal (through the '{$sibling}' market)"]
                : null;
        }

        foreach (array_unique([$choice, 'fuzzwork', 'esi']) as $candidate) {
            if ($candidate === $provider || !in_array($candidate, self::FALLBACK_PROVIDERS, true)) {
                continue;
            }

            $canPrice = match ($candidate) {
                'fuzzwork' => FuzzworkPriceProvider::tracksMarket($market),
                'janice' => JanicePriceProvider::tracksMarket($market),
                'goonpraisal' => GoonpraisalPriceProvider::marketSlug($market) !== null,
                default => !empty($config['region_id']),
            };

            if ($canPrice && $this->getPriceProvider($candidate)->isAvailable()) {
                return ['provider' => $candidate, 'market' => $market, 'label' => self::PROVIDER_LABELS[$candidate]];
            }
        }

        return null;
    }

    /**
     * Another enabled market that reads a structure in this market's system
     * through ESI, matched by the system's ID or its name.
     */
    protected function structureMarketInSystem(string $market, ?array $config): ?string
    {
        $systemId = (int) ($config['system_id'] ?? 0);
        $systemName = strtoupper(trim((string) ($config['system_name'] ?? '')));

        if ($systemId === 0 && $systemName === '') {
            return null;
        }

        try {
            $candidates = Market::where('market_type', Market::TYPE_CITADEL)
                ->where('provider', 'esi')
                ->where('is_enabled', true)
                ->get();
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->key === $market || !$candidate->isStructureMarket()) {
                continue;
            }

            if (($systemId !== 0 && (int) $candidate->system_id === $systemId)
                || ($systemName !== '' && strtoupper(trim((string) $candidate->system_name)) === $systemName)) {
                return $candidate->key;
            }
        }

        return null;
    }

    /**
     * Types with a fresh buy or sell price in a provider's answer: not one
     * its report says failed, and when it lists what it priced fresh, one of
     * those. MCPraisal hands the rest back at their cached price.
     *
     * @param int[]|null $freshIds
     * @return int[]
     */
    protected function pricedTypeIds(array $prices, array $failedIds, ?array $freshIds = null): array
    {
        $failed = array_flip($failedIds);
        $fresh = $freshIds === null ? null : array_flip($freshIds);
        $priced = [];

        foreach ($prices as $typeId => $stats) {
            if (isset($failed[(int) $typeId]) || ($fresh !== null && !isset($fresh[(int) $typeId])) || !is_array($stats)) {
                continue;
            }

            foreach (['buy', 'sell'] as $side) {
                $s = $stats[$side] ?? null;
                if (is_array($s) && ((float) ($s['min'] ?? 0) > 0 || (float) ($s['max'] ?? 0) > 0)) {
                    $priced[] = (int) $typeId;
                    break;
                }
            }
        }

        return $priced;
    }

    /** How many of these types have a cached price at this market. */
    protected function countCached(string $market, array $typeIds): int
    {
        try {
            return (int) MarketPrice::where('market', $market)
                ->whereIn('type_id', $typeIds)
                ->distinct()
                ->count('type_id');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Write one type's stats the way a provider would, for a fallback that
     * priced it from another market's read. A side with no price is left as
     * it is.
     */
    protected function storeStats(int $typeId, string $market, array $stats): void
    {
        foreach (['buy', 'sell'] as $side) {
            $s = is_array($stats[$side] ?? null) ? $stats[$side] : null;
            if ($s === null || ((float) ($s['min'] ?? 0) <= 0 && (float) ($s['max'] ?? 0) <= 0)) {
                continue;
            }

            MarketPrice::updateOrCreate(
                [
                    'type_id' => $typeId,
                    'market' => $market,
                    'price_type' => $side,
                ],
                [
                    'price_min' => (float) ($s['min'] ?? 0),
                    'price_max' => (float) ($s['max'] ?? 0),
                    'price_avg' => (float) ($s['avg'] ?? 0),
                    'price_median' => (float) ($s['median'] ?? 0),
                    'price_percentile' => (float) ($s['percentile'] ?? 0),
                    'price_stddev' => (float) ($s['stddev'] ?? 0),
                    'volume' => (int) ($s['volume'] ?? 0),
                    'order_count' => (int) ($s['order_count'] ?? 0),
                    'strategy' => (string) ($s['strategy'] ?? 'fallback'),
                    'updated_at' => now(),
                ]
            );
        }

        $buy = is_array($stats['buy'] ?? null) ? $stats['buy'] : [];
        $sell = is_array($stats['sell'] ?? null) ? $stats['sell'] : [];

        PriceHistory::recordDay(
            $typeId,
            $market,
            (float) ($buy['avg'] ?? 0),
            (float) ($buy['max'] ?? 0),
            (float) ($sell['avg'] ?? 0),
            (float) ($sell['min'] ?? 0),
            (int) (($buy['volume'] ?? 0) + ($sell['volume'] ?? 0))
        );
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

        // A first fetch for what has none yet: types nobody had subscribed
        // at this market, and subscribed ones still without a stored price.
        if ($immediateRefresh) {
            $toFetch = array_values(array_unique(array_merge(
                array_map('intval', array_diff($typeIds, $existingTypeIds)),
                $this->typeIdsWithoutPrice((string) $market, $typeIds)
            )));

            if (!empty($toFetch)) {
                $this->queueFirstFetch((string) $market, $toFetch);
            }
        }
    }

    /**
     * Queue the first fetch for these types on the worker, so a plugin
     * subscribing hundreds of types from a settings save doesn't wait on the
     * provider. If the queue is down, the scheduled refresh picks them up.
     *
     * One job per market per window. A plugin that subscribes from its boot
     * path does so on every request, and with a market that kept failing
     * that queued a job or two a second. A call inside the window is not
     * lost, though: its types wait in the cache, and one more job at the end
     * of the window fetches the types that were asked for inside it. Only
     * those: nothing is fetched that a plugin did not send.
     */
    protected function queueFirstFetch(string $market, array $typeIds): void
    {
        if (Cache::add(self::dispatchDebounceKey($market), now()->toIso8601String(), self::DEBOUNCE_WINDOW_SECONDS)) {
            Log::info("[Manager Core] Dispatching RefreshMarketPricesJob for " . count($typeIds) . " new/missing types in '{$market}'");
            $this->dispatchRefresh($market, $typeIds);

            return;
        }

        if (!$this->keepWaiting($market, $typeIds)) {
            Log::warning("[Manager Core] Could not keep " . count($typeIds) . " type(s) waiting for '{$market}', so they are fetched now");
            $this->dispatchRefresh($market, $typeIds);

            return;
        }

        // The job forgets this key as it starts, so the key outlives the
        // wait only when the job is lost, and then not for long.
        if (Cache::add(self::catchUpKey($market), now()->toIso8601String(), self::DEBOUNCE_WINDOW_SECONDS * 2)) {
            Log::info("[Manager Core] '{$market}' had a fetch queued in the last " . self::DEBOUNCE_WINDOW_SECONDS
                . "s, so the types asked for since are fetched in " . self::DEBOUNCE_WINDOW_SECONDS . 's');
            $this->dispatchRefresh($market, [], true, self::DEBOUNCE_WINDOW_SECONDS);
        }
    }

    /**
     * The fetch queued for the end of a debounce window: the types plugins
     * asked for inside it.
     */
    public function refreshWaiting(string $market): void
    {
        // Forgotten before the types are taken, so a subscribe that lands
        // after that queues a fetch of its own rather than counting on this one.
        Cache::forget(self::catchUpKey($market));

        $typeIds = $this->takeWaiting($market);
        if (empty($typeIds)) {
            return;
        }

        $this->refreshMarket($market, $typeIds, null, true, self::TRIGGER_SUBSCRIPTION);
    }

    /**
     * Add types to the ones waiting for the market's window to end. False
     * when they could not be kept, and the caller fetches them itself.
     */
    protected function keepWaiting(string $market, array $typeIds): bool
    {
        $key = self::waitingKey($market);
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        try {
            // A plugin asking for the same types on every request finds them
            // waiting already, and takes no lock to find that out.
            if (empty(array_diff($typeIds, (array) Cache::get($key, [])))) {
                return true;
            }

            $this->withWaitingLock($market, function () use ($key, $typeIds) {
                $waiting = array_values(array_unique(array_merge((array) Cache::get($key, []), $typeIds)));
                Cache::put($key, $waiting, self::WAITING_KEPT_SECONDS);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Keeping types waiting for '{$market}' failed: " . $e->getMessage());

            return false;
        }
    }

    /**
     * The types waiting at a market, which stop waiting. If the lock can't
     * be had this throws, and the job that asked is tried again.
     *
     * @return int[]
     */
    protected function takeWaiting(string $market): array
    {
        $key = self::waitingKey($market);
        $taken = [];

        $this->withWaitingLock($market, function () use ($key, &$taken) {
            $taken = (array) Cache::get($key, []);
            Cache::forget($key);
        });

        return array_values(array_unique(array_map('intval', $taken)));
    }

    /**
     * Run $do while nothing else reads or writes the market's waiting
     * types: the list is read, changed and written back, and two plugins
     * subscribing at once would each write it without the other's types.
     */
    protected function withWaitingLock(string $market, callable $do): void
    {
        Cache::lock(self::waitingKey($market) . '.lock', 10)->block(3, $do);
    }

    /** Queue a refresh job, now or after a wait. */
    protected function dispatchRefresh(string $market, array $typeIds, bool $waiting = false, int $delaySeconds = 0): void
    {
        $pending = RefreshMarketPricesJob::dispatch($market, array_values($typeIds), false, $waiting);

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Which of these types have no stored price at the market, on either side.
     *
     * @return int[]
     */
    protected function typeIdsWithoutPrice(string $market, array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        $priced = [];
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $rows = MarketPrice::where('market', $market)->whereIn('type_id', $chunk)->distinct()->pluck('type_id');
            foreach ($rows as $typeId) {
                $priced[(int) $typeId] = true;
            }
        }

        return array_values(array_filter($typeIds, fn ($typeId) => !isset($priced[$typeId])));
    }

    /**
     * How long a market waits between first fetches. 30 seconds is long
     * enough to swallow per-request storms and short enough that prices for
     * a newly subscribed type still arrive promptly.
     */
    public const DEBOUNCE_WINDOW_SECONDS = 30;

    /**
     * How long waiting types are kept. Well past the window, so that if the
     * job due to take them is lost, the next one still finds them.
     */
    private const WAITING_KEPT_SECONDS = 3600;

    /**
     * Cache key for the debounce gate. Per-market so a hub-market
     * dispatch doesn't block an unrelated citadel-market dispatch.
     */
    private static function dispatchDebounceKey(string $market): string
    {
        return 'mc.pricing.refresh_dispatch.' . $market;
    }

    /** Cache key that marks a market as having its end-of-window fetch queued. */
    private static function catchUpKey(string $market): string
    {
        return 'mc.pricing.refresh_window_end.' . $market;
    }

    /** Cache key for the types asked for inside a market's window. */
    private static function waitingKey(string $market): string
    {
        return 'mc.pricing.refresh_waiting.' . $market;
    }

    /**
     * Fetch again, between the scheduled refreshes, the types a plugin names
     * whose stored price at the market is older than the plugin can use.
     *
     * For a plugin whose users watch a price move. The scheduled refresh
     * stays as it is for everyone else: only a plugin listed under
     * pricing.refresh_on_request.plugins may ask, only for types it is
     * subscribed to at the market, and no type is fetched more often than
     * the market's provider allows, however often it is asked for. The
     * fetch runs on the worker and is announced to the asking plugin alone.
     *
     * The common ask finds nothing old enough, and costs two reads.
     *
     * With a provider named, the ask is for the plugin's variants through
     * that provider (see PriceVariants), on the same rules.
     *
     * @param int[] $typeIds
     * @return array{queued: int, fresh: int, reason: ?string} queued: types
     *         now being fetched; fresh: types young enough already; reason:
     *         why nothing can be fetched for this plugin (not_allowed,
     *         unknown_market, unknown_provider, not_subscribed), or null
     */
    public function refreshIfOlder(string $pluginName, array $typeIds, string $market, int $maxAgeSeconds, ?string $provider = null): array
    {
        $answer = fn (int $queued, int $fresh, ?string $reason = null) => ['queued' => $queued, 'fresh' => $fresh, 'reason' => $reason];

        if (!in_array($pluginName, (array) config('manager-core.pricing.refresh_on_request.plugins', []), true)) {
            return $answer(0, 0, 'not_allowed');
        }

        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if (empty($typeIds)) {
            return $answer(0, 0);
        }

        if (!isset($this->effectiveMarkets(true)[$market])) {
            return $answer(0, 0, 'unknown_market');
        }

        if ($provider !== null && trim($provider) !== '') {
            return $this->refreshVariantsIfOlder($pluginName, $typeIds, $market, self::providerKey($provider), $maxAgeSeconds);
        }

        $typeIds = $this->subscribedTypeIds($pluginName, $market, $typeIds);
        if (empty($typeIds)) {
            return $answer(0, 0, 'not_subscribed');
        }

        $floor = $this->requestFloorSeconds($market);
        $old = $this->typeIdsOlderThan($market, $typeIds, max($maxAgeSeconds, $floor) - self::REQUEST_TOLERANCE_SECONDS);
        $fresh = count($typeIds) - count($old);

        $due = $this->notAskedFor($market, $old, $floor - self::REQUEST_TOLERANCE_SECONDS);
        if (empty($due)) {
            return $answer(0, $fresh);
        }

        $this->rememberAsked($market, $due, $floor);
        Log::info("[Manager Core] '{$pluginName}' asked for " . count($due) . " type(s) in '{$market}' to be fetched again");
        $this->dispatchRequested($market, $due, $pluginName);

        return $answer(count($due), $fresh);
    }

    /**
     * The fetch a plugin asked for, from the job refreshIfOlder queued. The
     * job may have waited on the queue while a scheduled refresh ran, so
     * what has a new price by now is left out.
     *
     * A type that fails is not tried again from here: the plugin asks again,
     * and gets it once the provider's wait is over.
     */
    public function refreshRequested(string $market, array $typeIds, string $pluginName): void
    {
        $typeIds = $this->typeIdsOlderThan($market, array_map('intval', $typeIds),
            $this->requestFloorSeconds($market) - self::REQUEST_TOLERANCE_SECONDS);
        if (empty($typeIds)) {
            return;
        }

        $this->refreshMarket($market, $typeIds, null, false, self::TRIGGER_REQUEST, $pluginName);
    }

    /**
     * The youngest a stored price at this market may be before a plugin's
     * ask fetches it again. Set per provider: one Janice key serves every
     * plugin, and the others publish no faster than this anyway.
     */
    protected function requestFloorSeconds(string $market): int
    {
        return $this->providerFloorSeconds($this->resolveMarketProvider($market) ?? 'fuzzwork');
    }

    /**
     * Which of these types the plugin is subscribed to at the market.
     *
     * @return int[]
     */
    protected function subscribedTypeIds(string $pluginName, string $market, array $typeIds): array
    {
        $subscribed = [];
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $rows = TypeSubscription::where('plugin_name', $pluginName)->where('market', $market)
                ->whereIn('type_id', $chunk)->pluck('type_id');
            foreach ($rows as $typeId) {
                $subscribed[] = (int) $typeId;
            }
        }

        return array_values(array_unique($subscribed));
    }

    /**
     * Which of these types have no price at the market stored in the last
     * $seconds, on either side. Both sides are stored by the same fetch, and
     * a side nobody has an order on keeps its old time.
     *
     * @return int[]
     */
    protected function typeIdsOlderThan(string $market, array $typeIds, int $seconds): array
    {
        $typeIds = array_values(array_unique($typeIds));
        $since = now()->subSeconds($seconds);

        $young = [];
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $rows = MarketPrice::where('market', $market)->whereIn('type_id', $chunk)
                ->where('updated_at', '>=', $since)->distinct()->pluck('type_id');
            foreach ($rows as $typeId) {
                $young[(int) $typeId] = true;
            }
        }

        return array_values(array_filter($typeIds, fn ($typeId) => !isset($young[$typeId])));
    }

    /**
     * Leave out the types already asked for in the last $seconds. A type the
     * provider has no price for, or failed on, stays old however often it is
     * fetched, and without this every ask would fetch it again.
     *
     * @return int[]
     */
    protected function notAskedFor(string $market, array $typeIds, int $seconds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $asked = (array) Cache::get(self::askedKey($market), []);
        } catch (\Throwable $e) {
            return $typeIds;
        }

        $since = now()->getTimestamp() - $seconds;

        return array_values(array_filter($typeIds, fn ($typeId) => ($asked[$typeId] ?? 0) <= $since));
    }

    /** Note that these types were asked for now, and drop the notes too old to matter. */
    protected function rememberAsked(string $market, array $typeIds, int $seconds): void
    {
        try {
            $now = now()->getTimestamp();
            $asked = array_filter((array) Cache::get(self::askedKey($market), []), fn ($at) => $at > $now - $seconds);

            foreach ($typeIds as $typeId) {
                $asked[$typeId] = $now;
            }

            Cache::put(self::askedKey($market), $asked, $seconds);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not note what was asked for in '{$market}': " . $e->getMessage());
        }
    }

    /** These types were not fetched after all, so the next ask may have them. */
    protected function forgetAsked(string $market, array $typeIds): void
    {
        try {
            $asked = (array) Cache::get(self::askedKey($market), []);
            foreach ($typeIds as $typeId) {
                unset($asked[$typeId]);
            }

            Cache::put(self::askedKey($market), $asked, $this->requestFloorSeconds($market));
        } catch (\Throwable $e) {
            // The note runs out by itself.
        }
    }

    /** Queue the fetch a plugin asked for. Not through the first-fetch window: these types have prices. */
    protected function dispatchRequested(string $market, array $typeIds, string $pluginName): void
    {
        RefreshMarketPricesJob::dispatch($market, array_values($typeIds), false, false, $pluginName);
    }

    /** Cache key for when each type at a market was last asked for by a plugin. */
    private static function askedKey(string $market): string
    {
        return 'mc.pricing.refresh_asked.' . $market;
    }

    /** Cache key that marks a market as being refreshed right now. */
    private static function refreshingKey(string $market): string
    {
        return 'mc.pricing.refreshing.' . $market;
    }

    /**
     * Unregister type subscriptions owned by a given plugin: all of them,
     * one market's, or only the named types.
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
     * @param int[]|null  $typeIds     Only these types, for a plugin that
     *                                 stops needing some. null = every type
     *                                 in scope. An empty list removes none.
     * @return int Count of subscription rows removed.
     */
    public function unregisterTypes($pluginName, $market = null, ?array $typeIds = null): int
    {
        // An empty list names no types, so it removes none. Only null means
        // every type: a plugin passing what it worked out to drop must not
        // lose the whole market when that came out empty.
        if ($typeIds !== null) {
            $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

            if (empty($typeIds)) {
                return 0;
            }
        }

        $count = $this->deleteSubscriptions((string) $pluginName, $market !== null ? (string) $market : null, $typeIds);

        $what = $typeIds === null ? "{$count} type subscriptions" : "{$count} of " . count($typeIds) . ' named type subscriptions';
        $where = $market !== null ? "in market '{$market}'" : 'across all markets';
        Log::info("[Manager Core] Plugin '{$pluginName}' unregistered {$what} {$where}");

        return $count;
    }

    /** Delete a plugin's subscriptions: all of them, one market's, or the named types. */
    protected function deleteSubscriptions(string $pluginName, ?string $market, ?array $typeIds): int
    {
        $scope = function () use ($pluginName, $market) {
            $query = TypeSubscription::where('plugin_name', $pluginName);

            return $market !== null ? $query->where('market', $market) : $query;
        };

        if ($typeIds === null) {
            return (int) $scope()->delete();
        }

        $count = 0;
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $count += (int) $scope()->whereIn('type_id', $chunk)->delete();
        }

        return $count;
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
     * @param string|null $providerOverride Optional provider key to ask alone
     *                                  instead of the per-market routing:
     *                                  'esi' (a.k.a. MCPraisal), 'janice',
     *                                  'fuzzwork', 'goonpraisal', 'seat'. It
     *                                  stores nothing unless it is the market's
     *                                  own provider (see askProviderAlone).
     *                                  Null = use the per-market provider on
     *                                  manager_core_markets.
     * @param string      $trigger      What set the refresh off, for the
     *                                  pricing.market_refreshed event: one of
     *                                  the TRIGGER_ constants.
     * @return void
     */
    public function updatePrices($market = 'jita', $typeIds = null, ?string $providerOverride = null, string $trigger = self::TRIGGER_SCHEDULE)
    {
        $this->refreshMarket((string) $market, $typeIds, $providerOverride, true, $trigger);
    }

    /**
     * What one provider says these types cost at a market, for a provider
     * picked on one appraisal or by a Diagnostics test. Stored under the
     * market only when it is the market's own provider; see askProviderAlone.
     *
     * @return array<int, array{buy: ?array, sell: ?array}|null> keyed by type
     *         id; null, or a null side, where the provider gave no price
     */
    public function pricesFromProvider(string $market, array $typeIds, string $providerKey): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        return empty($typeIds) ? [] : $this->askProviderAlone($market, $typeIds, $providerKey);
    }

    /**
     * A provider picked for one appraisal or one test is asked on its own:
     * nothing stands in for it, nothing is tried again, and the market's
     * status stays about the market's own provider.
     *
     * Only the market's own provider stores what it fetches, as a refresh
     * would. Every plugin reading the market gets the market's rows, so
     * another provider's answer goes to the caller alone. MCPraisal reads a
     * structure only for a market set to it, so at any other market it
     * fetches without storing too (see ESIPriceProvider).
     */
    protected function askProviderAlone(string $market, array $typeIds, string $providerKey): array
    {
        $provider = $this->getPriceProvider($providerKey);

        if (!$provider->isAvailable()) {
            Log::error("[Manager Core] Price provider '{$provider->getName()}' is not available");

            return [];
        }

        $stores = $providerKey === ($this->resolveMarketProvider($market) ?? 'fuzzwork');

        $prices = $provider->getPrices($typeIds, $market, $stores);

        if ($stores) {
            $this->bumpPriceCacheVersion($market);
        }

        // MCPraisal answers with the cached price for anything it couldn't
        // fetch now. Only what it fetched is its answer.
        $fresh = $provider->lastFreshTypeIds();
        if ($fresh !== null) {
            $fresh = array_flip(array_map('intval', $fresh));
            foreach (array_keys($prices) as $typeId) {
                if (!isset($fresh[(int) $typeId])) {
                    $prices[$typeId] = null;
                }
            }
        }

        return $prices;
    }

    /**
     * One more try for types a refresh failed to fetch, from the job that
     * refresh queued. A second failure waits for the next scheduled refresh
     * rather than queueing a third try.
     */
    public function retryPrices(string $market, array $typeIds): void
    {
        $this->refreshMarket($market, $typeIds, null, false, self::TRIGGER_RETRY);
    }

    /**
     * @param string      $trigger What set the refresh off (a TRIGGER_ constant).
     * @param string|null $for     The one plugin the refresh is for, when it
     *                             was made at a plugin's request. Null when it
     *                             is for every plugin subscribed to the types.
     */
    protected function refreshMarket(string $market, $typeIds, ?string $providerOverride, bool $mayRetry,
        string $trigger = self::TRIGGER_SCHEDULE, ?string $for = null): void
    {
        // Only a refresh of everything subscribed is the scheduled one: a test
        // or an appraisal names its types and says nothing about the schedule.
        $everything = $typeIds === null;

        // If no specific type IDs provided, get all subscribed types. A
        // variant through the market's own provider is the market's own
        // price, so it is fetched here too.
        if ($typeIds === null) {
            $subscribedTypes = $this->getSubscribedTypes($market);

            $typeIds = array_merge(
                isset($subscribedTypes[$market]) ? $subscribedTypes[$market]->pluck('type_id')->unique()->toArray() : [],
                $this->variantTypeIdsFor($market, $this->resolveMarketProvider($market) ?? 'fuzzwork')
            );

            if (empty($typeIds)) {
                Log::info("[Manager Core] No subscribed types for market: {$market}");
                return;
            }
        }

        $typeIds = array_values(array_unique(array_map('intval', (array) $typeIds)));
        if (empty($typeIds)) {
            return;
        }

        // A plugin's ask gives way to a refresh of the same market already
        // running: that one is fetching the same prices or just has. Every
        // other refresh runs as it always did, marked or not.
        $marked = $this->markRefreshing($market);
        if (!$marked && $trigger === self::TRIGGER_REQUEST) {
            Log::info("[Manager Core] '{$market}' is being refreshed already, so the " . count($typeIds) . ' type(s) asked for wait for the next ask');
            $this->forgetAsked($market, $typeIds);

            return;
        }

        try {
            $this->fetchForMarket($market, $typeIds, $providerOverride, $mayRetry, $trigger, $for, $everything);
        } finally {
            if ($marked) {
                $this->doneRefreshing($market);
            }
        }
    }

    /** Note that a market is being refreshed. False when another refresh has it marked. */
    protected function markRefreshing(string $market): bool
    {
        try {
            return (bool) Cache::add(self::refreshingKey($market), now()->toIso8601String(), self::REFRESHING_KEPT_SECONDS);
        } catch (\Throwable $e) {
            // Without a cache nothing can be marked, and the refresh matters more.
            return true;
        }
    }

    protected function doneRefreshing(string $market): void
    {
        try {
            Cache::forget(self::refreshingKey($market));
        } catch (\Throwable $e) {
            // The mark runs out by itself.
        }
    }

    /** Ask the market's provider, store what it answers and say so. */
    protected function fetchForMarket(string $market, array $typeIds, ?string $providerOverride, bool $mayRetry,
        string $trigger, ?string $for, bool $everything): void
    {
        // Resolution order for which provider class to use:
        //   1. Explicit override (from per-appraisal selector or test)
        //   2. Per-market provider stored on the manager_core_markets row
        //   3. Hard-coded 'fuzzwork' fail-safe
        // The "global default" Setting was removed in v1.0.0; per-market
        // routing is the single source of truth.
        $effectiveProvider = $providerOverride
            ?? $this->resolveMarketProvider($market)
            ?? 'fuzzwork';

        Log::info("[Manager Core] Updating prices for " . count($typeIds) . " types in market: {$market}"
            . ($providerOverride
                ? " (provider override: {$providerOverride})"
                : " (provider: {$effectiveProvider})"));

        if ($providerOverride !== null) {
            $this->askProviderAlone($market, $typeIds, $providerOverride);

            return;
        }

        $outcome = $this->fetchWithFallback($effectiveProvider, $market, $typeIds, true);
        $retrying = $mayRetry && $this->scheduleRetry($market, $outcome);
        $this->recordMarketOutcome($market, $outcome, $retrying);
        $this->copyToVariants($market, $effectiveProvider, $outcome, $trigger, $for);

        // Bump the per-market cache version so any cached getPrice/getPrices
        // results for this market are invalidated. Without this, a previous
        // broken run (e.g. memory exhaustion, empty ESI response) could leave
        // an empty-price cache entry that masks the freshly-written DB rows
        // for up to `cache.prices_duration` minutes. The bump is cheap
        // (single Redis INCR) and ensures the very next getPrice call reads
        // straight from the DB.
        $this->bumpPriceCacheVersion($market);

        if ($everything && $trigger === self::TRIGGER_SCHEDULE && !empty($outcome['priced'])) {
            $this->noteScheduledRefresh();
        }

        $this->announceRefresh($market, $outcome['priced'] ?? [], count($typeIds), $trigger, $for);
    }

    /**
     * Keep when the scheduled refresh last stored a price, for the Watchdog
     * and Diagnostics. A run that stored nothing is not noted: to them it is
     * as good as no run.
     */
    protected function noteScheduledRefresh(): void
    {
        try {
            \ManagerCore\Models\Setting::set(self::LAST_SCHEDULED_SETTING, now()->toIso8601String(), 'pricing');
        } catch (\Throwable $e) {
            Log::warning('[Manager Core] Could not note the scheduled refresh: ' . $e->getMessage());
        }
    }

    /** When a scheduled refresh last stored a price, or null if none has since this was first kept. */
    public static function lastScheduledRefreshAt(): ?\Carbon\Carbon
    {
        try {
            $at = \ManagerCore\Models\Setting::get(self::LAST_SCHEDULED_SETTING);

            return is_string($at) && $at !== '' ? \Carbon\Carbon::parse($at) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tell plugins that keep their own copy of prices that a market's are
     * in, once per refresh and after they are stored and readable. A refresh
     * that stored nothing has nothing to announce: if its retry prices
     * something, the retry announces it.
     *
     * `plugins` is who the refresh was for. A plugin not named in it need
     * not read anything: a few types another plugin just subscribed, or a
     * refresh one plugin asked for between scheduled ones, is not a reason
     * for every plugin at the market to copy all its prices again.
     */
    protected function announceRefresh(string $market, array $pricedIds, int $asked, string $trigger, ?string $for): void
    {
        if (empty($pricedIds)) {
            return;
        }

        try {
            $this->publishRefreshed([
                'market' => $market,
                // To the microsecond: it is in the event's key, and two refreshes of
                // one market in the same second are still two events.
                'refreshed_at' => now()->format('Y-m-d\TH:i:s.uP'),
                'plugins' => $for !== null ? [$for] : $this->subscribersOf($market, $pricedIds),
                'type_count' => $asked,
                'stored_count' => count($pricedIds),
                'trigger' => $trigger,
            ]);
        } catch (\Throwable $e) {
            // The prices are stored either way. A plugin that missed the event
            // still reads them on its own schedule.
            Log::warning("[Manager Core] Could not announce the refresh of '{$market}': " . $e->getMessage());
        }
    }

    /** Publish pricing.market_refreshed. */
    protected function publishRefreshed(array $payload): void
    {
        \ManagerCore\Topics::publish('pricing.market_refreshed', $payload);
    }

    /**
     * The plugins subscribed to any of these types at the market.
     *
     * @return string[]
     */
    protected function subscribersOf(string $market, array $typeIds): array
    {
        $plugins = [];
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $names = TypeSubscription::where('market', $market)->whereIn('type_id', $chunk)->distinct()->pluck('plugin_name');
            foreach ($names as $name) {
                $plugins[(string) $name] = true;
            }
        }

        $plugins = array_keys($plugins);
        sort($plugins);

        return $plugins;
    }

    /**
     * Queue one more try, ten minutes on, for the types a request failed
     * for. Not when trying again can't help (a refused key, a missing
     * setting), and not when this market already has a try queued: those
     * types keep their cached price until the next scheduled refresh.
     * Returns whether a try is coming.
     */
    protected function scheduleRetry(string $market, array $outcome): bool
    {
        if (empty($outcome['failed']) || !$outcome['retryable']) {
            return false;
        }

        try {
            if (!Cache::add('mc.pricing.retry.' . $market, now()->toIso8601String(), self::RETRY_DELAY_MINUTES * 60)) {
                return false;
            }

            RefreshMarketPricesJob::dispatch($market, $outcome['failed'], true)
                ->delay(now()->addMinutes(self::RETRY_DELAY_MINUTES));

            return true;
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not queue another try for '{$market}': " . $e->getMessage());

            return false;
        }
    }

    /**
     * Keep how the market's own provider did on the market's row, for the
     * Markets page and the Watchdog. A refresh that lost a few types still
     * counts as working: they keep their cached price and are asked for
     * again, and the row notes them.
     */
    protected function recordMarketOutcome(string $market, array $outcome, bool $retrying): void
    {
        try {
            $row = Market::where('key', $market)->first();
            if (!$row) {
                return;
            }

            $error = rtrim((string) $outcome['error'], '. ');
            $again = $retrying ? ' Asked for again in ' . self::RETRY_DELAY_MINUTES . ' minutes.' : '';

            if (!$outcome['outright']) {
                $row->recordRefresh(
                    Market::STATUS_OK,
                    empty($outcome['failed'])
                        ? null
                        : count($outcome['failed']) . " of {$outcome['asked']} type(s) failed ({$error}), so they keep their cached price." . $again
                );

                return;
            }

            $message = "{$outcome['provider']}: {$error}.";
            $fallback = $outcome['fallback'];

            if ($fallback === null) {
                $message .= ' Nothing stands in for it at this market, so the cached prices stay.';
            } elseif ($fallback['priced'] > 0) {
                $message .= " {$fallback['label']} priced {$fallback['priced']} of {$outcome['asked']} type(s) meanwhile.";
            } else {
                $message .= " {$fallback['label']} could not stand in either (" . rtrim((string) $fallback['error'], '. ') . '), so the cached prices stay.';
            }

            $row->recordRefresh(
                $outcome['status'] ?? ($outcome['retryable'] ? Market::STATUS_PROVIDER_DOWN : Market::STATUS_ERROR),
                $message . $again
            );
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not record how the refresh of '{$market}' went: " . $e->getMessage());
        }
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
            $provider = Market::where('key', $marketKey)->value('provider');
            return !empty($provider) ? (string) $provider : null;
        } catch (\Throwable $e) {
            // Table missing during install / fresh boot; fall back.
            return null;
        }
    }
}
