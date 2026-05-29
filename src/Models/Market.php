<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Market — a market location MC can fetch prices for.
 *
 * Two market types:
 *
 *   1. HUB markets (Jita, Amarr, Dodixie, Hek, Rens, custom regional hubs)
 *      Use ESI's public /markets/{region_id}/orders/?type_id endpoint.
 *      No auth required, fast, type-filterable.
 *
 *   2. CITADEL markets (player-owned structures in nullsec)
 *      Use a third-party appraisal service (Goonpraisal, Janice, etc.).
 *      MC's own ESI scrape of /markets/structures/{id}/orders/ was tried
 *      in earlier versions but proved unreliable for large hubs because
 *      CCP's pagination collapses — pages 2..N return identical content
 *      to page 1. Third-party providers (Goonpraisal, Adam4EVE) have
 *      accumulated their own datasets and serve correct citadel prices.
 *
 * Provider routing:
 *
 *   - `provider` column selects the PriceProvider class for this market.
 *     Values mirror PricingService::getPriceProvider(): 'esi' (MCPraisal),
 *     'janice', 'fuzzwork', 'goonpraisal', 'seat'.
 *
 *   - `provider_slug` is the provider-specific market identifier.
 *     For Goonpraisal: 'insmother' / 'tenerifis' / 'catch' / etc.
 *     For Janice: their market name.
 *     For ESI / Fuzzwork: typically null (region_id + system_ids do the work).
 *
 * Per-plugin market selection (which market Buyback / Mining / etc. prefer)
 * is handled separately by `manager_core_pricing_preferences`.
 */
class Market extends Model
{
    protected $table = 'manager_core_markets';

    protected $fillable = [
        // Identity
        'key',
        'name',

        // Type + location
        'market_type',
        'region_id',
        'system_ids',
        'system_name',
        'system_id',
        'structure_name',

        // Provider routing (replaces the old citadel-auth columns)
        'provider',
        'provider_slug',

        // Lifecycle
        'is_enabled',
        'is_custom',

        // Refresh tracking — set by whichever provider runs the fetch.
        'last_refresh_at',
        'last_refresh_status',
        'last_refresh_error',
    ];

    protected $casts = [
        'system_ids' => 'array',
        'is_enabled' => 'boolean',
        'is_custom' => 'boolean',
        'system_id' => 'integer',
        'region_id' => 'integer',
        'last_refresh_at' => 'datetime',
    ];

    /**
     * Valid market_type values.
     */
    public const TYPE_HUB = 'hub';
    public const TYPE_CITADEL = 'citadel';

    /**
     * Valid last_refresh_status values. Used by providers when recording
     * refresh outcomes; consumed by the admin UI + diagnostics.
     */
    public const STATUS_OK = 'ok';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_ERROR = 'error';
    public const STATUS_NO_PROVIDER = 'no_provider';   // provider class not installed
    public const STATUS_PROVIDER_DOWN = 'provider_down'; // upstream third-party unavailable

    /**
     * Is this a citadel market (priced via third-party provider)?
     */
    public function isCitadel(): bool
    {
        return $this->market_type === self::TYPE_CITADEL;
    }

    /**
     * Is this a hub market (priced via ESI region endpoint)?
     */
    public function isHub(): bool
    {
        return $this->market_type === self::TYPE_HUB;
    }

    /**
     * Get all effective markets (config defaults merged with DB markets).
     *
     * Config markets provide the base hub set; DB markets can override the
     * same key or add brand-new ones. Citadel markets only live in the DB.
     *
     * @param bool $enabledOnly Only return enabled markets (default true)
     * @return array
     */
    public static function getEffectiveMarkets(bool $enabledOnly = true): array
    {
        // Start with config defaults. Provider defaults to 'fuzzwork' to
        // match (a) the seeded default in migration 000009 and (b) the
        // hard-coded fail-safe in PricingService::getPriceProvider. Audit
        // 2026-05-29 finding M4: was 'esi', which would silently change
        // the cost model if anyone disabled / deleted DB rows for a hub.
        $markets = config('manager-core.pricing.markets', []);
        foreach ($markets as $key => $cfg) {
            $markets[$key]['market_type'] = self::TYPE_HUB;
            $markets[$key]['provider']    = 'fuzzwork';
            $markets[$key]['is_custom']   = false;
        }

        // Merge in DB markets
        try {
            $query = static::query();
            if ($enabledOnly) {
                $query->where('is_enabled', true);
            }
            foreach ($query->get() as $market) {
                $markets[$market->key] = [
                    'name'                => $market->name,
                    'market_type'         => $market->market_type ?? self::TYPE_HUB,
                    'provider'            => $market->provider ?? 'esi',
                    'provider_slug'       => $market->provider_slug,
                    'region_id'           => $market->region_id,
                    'system_ids'          => $market->system_ids ?? [],
                    'system_name'         => $market->system_name,
                    'system_id'           => $market->system_id,
                    'structure_name'      => $market->structure_name,
                    'is_custom'           => $market->is_custom,
                    'last_refresh_at'     => $market->last_refresh_at,
                    'last_refresh_status' => $market->last_refresh_status,
                ];
            }
        } catch (\Exception $e) {
            // Table may not exist during installation/migration
        }

        return $markets;
    }

    /**
     * Get market config by key. Convenience wrapper for the common
     * "look up one market" case.
     *
     * NOTE: passes enabledOnly=false because by-key lookups are explicit —
     * the caller already knows which market they want, the enabled flag
     * just controls scheduler/dropdown filtering not addressability.
     * Without this, an operator who configured a disabled citadel market
     * would see "Unknown market" errors any time anything looked up the
     * row by key.
     */
    public static function getMarketConfig(string $key): ?array
    {
        $markets = static::getEffectiveMarkets(false);
        return $markets[$key] ?? null;
    }

    /**
     * Legacy alias for back-compat.
     */
    public static function getMarketsConfig(): array
    {
        return static::getEffectiveMarkets(true);
    }

    /**
     * Get all markets (enabled and disabled) ordered for the admin page.
     * Hub markets first, then citadel, alphabetical within each.
     */
    public static function getAllMarkets()
    {
        return static::orderBy('is_custom')
            ->orderBy('market_type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Update the refresh tracking fields after an attempted fetch.
     */
    public function recordRefresh(string $status, ?string $error = null): void
    {
        $this->last_refresh_at = now();
        $this->last_refresh_status = $status;
        $this->last_refresh_error = $error;
        $this->save();
    }

    /**
     * Human-friendly display label for the admin UI / diagnostics.
     */
    public function getDisplayLabelAttribute(): string
    {
        if ($this->isCitadel() && $this->structure_name) {
            return $this->system_name
                ? "{$this->system_name} - {$this->structure_name}"
                : $this->structure_name;
        }
        return $this->name;
    }
}
