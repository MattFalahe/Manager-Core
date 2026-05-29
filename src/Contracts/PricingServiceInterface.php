<?php

namespace ManagerCore\Contracts;

/**
 * Public contract for the Manager Core pricing service.
 *
 * Plugins integrating with MC should depend on THIS interface (or the
 * Pricing facade) rather than the concrete \ManagerCore\Services\PricingService
 * class. This insulates consumers from internal namespace refactors.
 *
 * Usage:
 *
 *   use ManagerCore\Facades\Pricing;
 *   $price = Pricing::getPrice(34, 'jita');
 *
 *   // Or via the container:
 *   $svc = app(\ManagerCore\Contracts\PricingServiceInterface::class);
 *   $svc->getPrice(34);
 */
interface PricingServiceInterface
{
    /**
     * Get current price for a single type ID, returning the inner price-stats array.
     *
     * @param int|array $typeIds
     * @param string $market
     * @param string $priceType
     * @return array|null
     */
    public function getPrice($typeIds, $market = 'jita', $priceType = 'both');

    /**
     * Always returns [typeId => stats] regardless of input length.
     *
     * The optional 4th arg `$pluginKeyForOverride` (Option B, 2026-05-29)
     * lets a caller route through a per-plugin provider_override stored
     * on manager_core_pricing_preferences. When set AND that plugin has
     * a non-null override, MC does a LIVE upstream fetch via the override
     * provider instead of reading its local cache. Backwards compat:
     * existing 3-arg callers behave exactly as before (cached read,
     * per-market provider routing).
     *
     * @param array $typeIds
     * @param string $market
     * @param string $priceType
     * @param string|null $pluginKeyForOverride
     * @return array
     */
    public function getPrices(array $typeIds, $market = 'jita', $priceType = 'both', ?string $pluginKeyForOverride = null): array;

    /**
     * Get a single ISK price for a type using the calling plugin's
     * registered preference (market + price_type + optional
     * provider_override). Reduces the underlying price stats to one float
     * per the configured price_type. Returns null when no price is
     * available at all.
     *
     * Honors `provider_override` automatically — operator-set per-plugin
     * provider routing takes effect without consumer-plugin code changes.
     */
    public function priceForPlugin(string $pluginKey, int $typeId): ?float;

    /**
     * Batch version of priceForPlugin. Returns `[typeId => ?float]` for
     * every typeId in the input. One pref lookup + (when provider_override
     * is set) one batch upstream call regardless of $typeIds length.
     *
     * @param int[] $typeIds
     * @return array<int, ?float>
     */
    public function pricesForPlugin(string $pluginKey, array $typeIds): array;

    /**
     * Get price trend over the last N days for a type.
     *
     * @return array
     */
    public function getTrend($typeId, $market = 'jita', $days = 7);

    /**
     * Register type IDs that a plugin depends on. The price polling worker
     * will keep these refreshed in the price cache.
     *
     * @return void
     */
    public function registerTypes(string $pluginName, array $typeIds, string $market = 'jita', int $priority = 1, bool $immediateRefresh = true);

    /**
     * Update prices in the cache for the given types.
     *
     * The optional third arg `$providerOverride` lets a caller pick a
     * specific provider for this run instead of the globally-configured
     * one — used by the per-appraisal price-provider selector. Existing
     * callers that pass only the first two args remain backwards compatible.
     *
     * @return void
     */
    public function updatePrices($market = 'jita', $typeIds = null, ?string $providerOverride = null);

    /**
     * Remove a plugin's pricing-type subscriptions.
     *
     * Called by plugins when switching providers AWAY from MC, so MC's polling
     * loop stops fetching types this plugin no longer needs. Without this,
     * dropped plugins leave orphan subscription rows that MC still polls.
     *
     * @param string $pluginName
     * @param string|null $market Market to limit the unsubscribe to, or null for all markets
     * @return int Number of subscription rows removed
     */
    public function unregisterTypes(string $pluginName, ?string $market = null): int;

    /**
     * Get the union of subscribed type IDs across all plugins for a market
     * (or all markets if $market is null). Used by the polling worker and
     * by diagnostic UIs.
     *
     * @param string|null $market
     * @return \Illuminate\Support\Collection Collection of TypeSubscription rows grouped by market
     */
    public function getSubscribedTypes($market = null);
}
