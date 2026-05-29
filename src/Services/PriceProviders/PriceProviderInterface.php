<?php

namespace ManagerCore\Services\PriceProviders;

/**
 * Interface for price provider adapters
 */
interface PriceProviderInterface
{
    /**
     * Get prices for given type IDs.
     *
     * MUST return a populated map even when $persist=true — the per-plugin
     * provider_override path (PricingService::fetchLivePricesViaOverride)
     * relies on the return value because that path deliberately bypasses
     * MC's local cache. Implementations that only populated the DB as a
     * side-effect and returned [] silently broke Option B reads; the
     * 2026-05-29 contract clarification makes the return value the
     * authoritative source-of-truth for callers.
     *
     * Each `[buy]` and `[sell]` stats array follows
     * `PricingService::formatPriceStats` shape: keys `min`, `max`, `avg`,
     * `median`, `percentile`, `stddev`, `volume`, `order_count`,
     * `strategy`, `updated_at`. Missing-data per-type returns null
     * (e.g. `[34 => null]`) rather than omitting the key.
     *
     * @param array  $typeIds Array of type IDs to fetch prices for
     * @param string $market  Market to fetch prices for (jita, amarr, etc)
     * @param bool   $persist When true (default), provider also writes
     *                        results to manager_core_market_prices via
     *                        updateOrCreate — the path the scheduled cron
     *                        wants for the shared cache. When false, the
     *                        provider must NOT touch the DB — the
     *                        per-plugin provider_override path passes
     *                        false to avoid polluting the cache with one
     *                        plugin's override data (which would corrupt
     *                        every other plugin's reads at that market).
     * @return array<int, array|null>  [type_id => ['buy' => stats, 'sell' => stats] | null]
     */
    public function getPrices(array $typeIds, string $market, bool $persist = true): array;

    /**
     * Get the name of this price provider
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this price provider is available/configured
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
